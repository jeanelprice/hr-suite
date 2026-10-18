<?php

namespace Tests\Feature;

use App\Enums\AppModule;
use App\Enums\BudgetVersionStatus;
use App\Enums\Currency;
use App\Enums\ProjectStatus;
use App\Exceptions\BudgetExceededException;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\BudgetMovement;
use App\Models\Deadline;
use App\Models\Delegation;
use App\Models\Derogation;
use App\Models\ExchangeRate;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Notifications\HrNotification;
use App\Services\AuditService;
use App\Services\BudgetLedger;
use App\Services\BudgetService;
use App\Services\PaymentService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseRequestService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Circuit Finances & Achats rejoué sur une vraie base (SQLite en mémoire),
 * règle par règle (RG-01 à RG-16 et RG-20) du cahier des charges v1.1.
 */
class FinanceFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $de;

    private User $dea;

    private User $fin;

    private User $cp;

    private User $cc;

    private Project $project;

    private Budget $budget;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $u = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        $this->de = $u('direction@humaniteresilience.org');
        $this->dea = $u('adjointe@humaniteresilience.org');
        $this->fin = $u('financier@humaniteresilience.org');
        $this->cp = $u('chef.projet@humaniteresilience.org');
        $this->cc = $u('controle@humaniteresilience.org');
        $this->project = Project::query()->where('code', 'HR-ABR-2027')->firstOrFail();
        $this->budget = $this->project->budgets()->firstOrFail();
    }

    // ------------------------------------------------------------------ outils

    private function line(string $code): BudgetLine
    {
        return $this->budget->lines()->where('code', $code)->firstOrFail();
    }

    private function available(string $code): string
    {
        return app(BudgetLedger::class)->balance($this->line($code))->toArray()['available'];
    }

    private function kits(int $qty, int|string $price): array
    {
        return [['description' => 'Kit abri', 'quantity' => $qty, 'unit' => 'kit', 'unit_price' => $price]];
    }

    private function da(User $who, string $line, array $items, Currency $ccy = Currency::USD, ?string $request = null): PurchaseRequest
    {
        return app(PurchaseRequestService::class)->create($who, $this->project, $this->line($line), 'Achat test', $ccy, $items, $request);
    }

    /** DA créée, soumise et validée par la DE */
    private function approvedDa(string $line, array $items, Currency $ccy = Currency::USD): PurchaseRequest
    {
        $s = app(PurchaseRequestService::class);
        $da = $this->da($this->cp, $line, $items, $ccy);
        $s->submit($this->cp, $da);

        return $s->approve($this->de, $da);
    }

    private function expectRule(string $rule, callable $fn): void
    {
        try {
            $fn();
            $this->fail("La règle {$rule} aurait dû bloquer l'opération.");
        } catch (BusinessRuleException $e) {
            $this->assertSame($rule, $e->rule, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ tests

    public function test_rg13_budget_initial_valide_active_le_projet(): void
    {
        $this->assertSame(ProjectStatus::ACTIF, $this->project->status);
        $this->assertSame('60000.00', $this->available('2.1'));
    }

    public function test_fondation_c_habilitations_par_module(): void
    {
        $this->assertTrue($this->de->canInModule(AppModule::FINANCES, \App\Enums\AccessLevel::VALIDATION));
        $this->assertTrue($this->cp->canInModule(AppModule::FINANCES));
        $this->assertFalse($this->cp->canInModule(AppModule::FINANCES, \App\Enums\AccessLevel::CREATION));
        $this->assertFalse($this->cc->canInModule(AppModule::ACHATS, \App\Enums\AccessLevel::CREATION));

        // Un accès expiré ne compte plus (Fondation D)
        $this->cp->moduleAccesses()->where('module', 'ACHATS')->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($this->cp->fresh()->canInModule(AppModule::ACHATS));
    }

    public function test_rg02_rg07_rg08_pre_engagement_puis_blocage_et_alerte(): void
    {
        Notification::fake();
        $s = app(PurchaseRequestService::class);

        $da = $this->da($this->cp, '2.1', $this->kits(400, 100));
        $this->assertSame('40000.00', $da->estimated_amount);
        $this->assertSame('60000.00', $this->available('2.1'), 'la saisie ne réserve rien');

        $s->submit($this->cp, $da);
        $this->expectRule('VALIDATION_RESERVEE_DE', fn () => $s->approve($this->fin, $da));
        $s->approve($this->de, $da, 'OK');
        $this->assertSame('20000.00', $this->available('2.1'));

        // 25 000 > 20 000 : bloqué, rien n'est enregistré, alerte aux 4 destinataires, audit
        try {
            $this->da($this->cp, '2.1', $this->kits(250, 100));
            $this->fail('Le dépassement aurait dû être bloqué.');
        } catch (BudgetExceededException $e) {
            $this->assertSame('BUDGET_DEPASSE', $e->rule);
        }
        $this->assertSame(1, PurchaseRequest::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'OPERATION_BLOQUEE_BUDGET')->where('actor_id', $this->cp->id)->exists());

        $alerted = [];
        Notification::assertSentTo(
            [$this->cp, $this->de, $this->fin, $this->dea],
            HrNotification::class,
            function (HrNotification $n, array $channels, User $notifiable) use (&$alerted) {
                if (str_starts_with($n->subject, 'Opération bloquée')) {
                    $alerted[] = $notifiable->email;
                }

                return true;
            },
        );
        sort($alerted);
        $this->assertSame([
            'adjointe@humaniteresilience.org', 'chef.projet@humaniteresilience.org', 'direction@humaniteresilience.org', 'financier@humaniteresilience.org',
        ], array_values(array_unique($alerted)), "Comité de Contrôle exclu (lecture seule)");
        Notification::assertNotSentTo($this->cc, HrNotification::class);
    }

    public function test_circuit_complet_bc_paiements_cloture_et_devises(): void
    {
        $orders = app(PurchaseOrderService::class);
        $payments = app(PaymentService::class);
        $supplier = Supplier::query()->firstOrFail();
        $cashUsd = TreasuryAccount::query()->where('name', 'Caisse Goma USD')->firstOrFail();
        $cashCdf = TreasuryAccount::query()->where('name', 'Caisse Goma CDF')->firstOrFail();

        // RG-11 : DA en CDF convertie au taux du Financier (1 USD = 2 850 CDF)
        $daCdf = $this->approvedDa('2.1', [['description' => 'Bâche', 'quantity' => 1000, 'unit_price' => 28500]], Currency::CDF);
        $this->assertSame('10000.00', $daCdf->amount_budget_ccy);

        // RG-03 : BC inférieur à la DA → l'écart est libéré
        $da = $this->approvedDa('2.1', $this->kits(400, 100));
        $bc = $orders->create($this->cp, $da, $supplier, Currency::USD, $this->kits(400, 95));
        $this->expectRule('BC_EXISTANT', fn () => $orders->create($this->cp, $da, $supplier, Currency::USD, $this->kits(1, 1)));
        $orders->submit($this->cp, $bc);
        $orders->approve($this->de, $bc);
        $b = app(BudgetLedger::class)->balance($this->line('2.1'))->toArray();
        $this->assertSame(['10000.00', '38000.00', '12000.00'], [$b['pre_engaged'], $b['engaged'], $b['available']]);

        // RG-04 / RG-15 / RG-16 : paiements partiels plafonnés, double signature, devise du compte
        $p1 = $payments->prepare($this->fin, $bc, $cashUsd, 20000, method: 'espèces');
        $this->expectRule('PAIEMENT_SUPERIEUR_BC', fn () => $payments->prepare($this->fin, $bc, $cashUsd, 20000));
        $this->expectRule('DEVISE_COMPTE', fn () => $payments->prepare($this->fin, $bc, $cashCdf, 100));
        $this->expectRule('SEGREGATION_DES_TACHES', fn () => $payments->approve($this->fin, $p1));
        $payments->approve($this->de, $p1);
        $b = app(BudgetLedger::class)->balance($this->line('2.1'))->toArray();
        $this->assertSame(['18000.00', '20000.00', '12000.00'], [$b['engaged'], $b['spent'], $b['available']], "payer ne change pas le solde");

        // RG-05 : clôture → reliquat libéré
        $released = $orders->close($this->fin, $bc, 'Livraison partielle acceptée');
        $this->assertSame('18000.00', (string) $released);
        $this->assertSame('30000.00', $this->available('2.1'));

        // RG-11 : paiement en CDF après appréciation du franc (1 USD = 2 700 CDF)
        $bcCdf = $orders->create($this->cp, $daCdf, $supplier, Currency::CDF, [['description' => 'Bâche', 'quantity' => 1000, 'unit_price' => 28500]]);
        $orders->submit($this->cp, $bcCdf);
        $orders->approve($this->de, $bcCdf);
        ExchangeRate::query()->create(['rate_date' => now()->toDateString(), 'from_currency' => 'USD', 'to_currency' => 'CDF', 'rate' => '2700', 'entered_by_id' => $this->fin->id]);
        $pCdf = $payments->prepare($this->fin, $bcCdf, $cashCdf, 28500000);
        $this->assertSame('10555.56', $pCdf->amount_budget_ccy);
        $payments->approve($this->de, $pCdf);
        // engagement 10 000 libéré, dépense 10 555,56 : l'écart de 555,56 est pris sur le solde
        $this->assertSame('29444.44', $this->available('2.1'));
        $this->assertStringContainsString('écart de change', BudgetMovement::query()->where('source_id', $pCdf->id)->where('kind', 'DEPENSE')->value('note'));
    }

    public function test_rg14_suppleance_de_la_de_adjointe(): void
    {
        $s = app(PurchaseRequestService::class);
        $da = $this->da($this->de, '3.1', [['description' => 'Camion', 'quantity' => 2, 'unit_price' => 500]]);
        $s->submit($this->de, $da);
        $this->expectRule('SEGREGATION_DES_TACHES', fn () => $s->approve($this->de, $da));
        $s->approve($this->dea, $da);
        $this->assertTrue($da->approvals()->where('decision', 'APPROUVE')->first()->via_delegation);

        // Hors suppléance, la DE adjointe ne valide pas une opération du chef de projet
        $da2 = $this->da($this->cp, '3.1', [['description' => 'Rotation', 'quantity' => 1, 'unit_price' => 100]]);
        $s->submit($this->cp, $da2);
        $this->expectRule('SUPPLEANCE_NON_APPLICABLE', fn () => $s->approve($this->dea, $da2));

        // … sauf délégation en cours de la DE
        Delegation::query()->create([
            'delegator_id' => $this->de->id, 'delegate_id' => $this->dea->id, 'modules' => ['ACHATS'],
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay(), 'reason' => 'Mission de la DE à Kinshasa',
        ]);
        $s->approve($this->dea, $da2);
        $this->assertSame('VALIDE', $da2->fresh()->status->value);
    }

    public function test_rg09_rg10_derogation_puis_revision_et_regularisation(): void
    {
        $s = app(PurchaseRequestService::class);
        $budgets = app(BudgetService::class);
        $this->approvedDa('2.1', $this->kits(300, 100)); // reste 30 000

        // Demande de dérogation par le chef de projet : saisie acceptée, alerte envoyée
        $da = $this->da($this->cp, '2.1', $this->kits(350, 100), request: 'Afflux de 300 ménages déplacés à Kanyaruchinya');
        $s->submit($this->cp, $da);
        $this->expectRule('SUPPLEANCE_NON_APPLICABLE', fn () => $s->approve($this->dea, $da));
        $this->expectRule('BUDGET_DEPASSE', fn () => $s->approve($this->de, $da));
        $s->approve($this->de, $da, derogation: 'Urgence humanitaire validée en réunion de coordination');
        $this->assertSame('-5000.00', $this->available('2.1'));
        $d = Derogation::query()->firstOrFail();
        $this->assertSame('5000.00', $d->amount_over);
        $this->assertNull($d->regularized_at);
        $this->assertTrue(Deadline::query()->where('kind', 'DEROGATION_REGULARISATION')->where('subject_id', $d->id)->exists());

        // Révision sous le consommé (65 000) refusée
        $this->expectRule('REVISION_SOUS_CONSOMME', fn () => $budgets->revise($this->fin, $this->budget, 'Réduction', [['code' => '2.1', 'label' => 'Achats kits abris', 'amount' => '60000']]));

        $v2 = $budgets->revise($this->fin, $this->budget, 'Régularisation dérogation urgence', [
            ['code' => '2.1', 'label' => 'Achats kits abris', 'amount' => '70000'],
            ['code' => '4.1', 'label' => 'Imprévus', 'amount' => '2000'],
        ]);
        $this->assertSame(2, $v2->version_no);
        $budgets->submit($this->fin, $v2);
        $this->expectRule('SEGREGATION_DES_TACHES', fn () => $budgets->approve($this->fin, $v2));
        $budgets->approve($this->de, $v2);

        $v1 = $this->budget->versions()->where('version_no', 1)->first();
        $this->assertSame(BudgetVersionStatus::ARCHIVEE, $v1->status);
        $this->assertNotNull($v1->archived_at);
        $this->assertSame('60000.00', BudgetService::amountIn($v1, $this->line('2.1')), "l'ancienne version reste consultable");
        $this->assertSame('5000.00', $this->available('2.1'));
        $this->assertNotNull($d->fresh()->regularized_at);
        $this->assertTrue($this->line('4.1')->is_active);
    }

    public function test_rg05_annulation_dune_da_validee_libere_le_pre_engagement(): void
    {
        $da = $this->approvedDa('3.1', [['description' => 'Rotation', 'quantity' => 10, 'unit_price' => 500]]);
        $this->assertSame('3000.00', $this->available('3.1'));
        app(PurchaseRequestService::class)->cancel($this->cp, $da, 'Besoin couvert par un autre partenaire');
        $this->assertSame('8000.00', $this->available('3.1'));
    }

    public function test_rg20_audit_chaine_et_inalterable(): void
    {
        $this->approvedDa('2.1', $this->kits(10, 100));
        $result = app(AuditService::class)->verify();
        $this->assertTrue($result['intact']);
        $this->assertGreaterThan(30, $result['checked']);

        foreach (['UPDATE audit_logs SET action = "X"', 'DELETE FROM budget_movements'] as $sql) {
            try {
                DB::statement($sql);
                $this->fail("La base aurait dû refuser : {$sql}");
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('ajout seul', $e->getMessage());
            }
        }
    }

    public function test_rg01_tableau_de_bord_coherent_avec_le_grand_livre(): void
    {
        $this->approvedDa('2.1', $this->kits(100, 100));
        $dash = app(BudgetService::class)->dashboard($this->budget);
        foreach ($dash['lines'] as $l) {
            $calc = \App\Support\Money::str(\App\Support\Money::of($l['budgeted'])->minus($l['pre_engaged'])->minus($l['engaged'])->minus($l['spent']));
            $this->assertSame($calc, $l['available'], "ligne {$l['code']}");
        }
        $this->assertSame('92000.00', $dash['totals']['budgeted']);
        $this->assertSame('82000.00', $dash['totals']['available']);
    }
}
