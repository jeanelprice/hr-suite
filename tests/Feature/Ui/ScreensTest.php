<?php

namespace Tests\Feature\Ui;

use App\Enums\BudgetVersionStatus;
use App\Enums\DocStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Payment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\PurchaseRequestService;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chaque écran s'ouvre sans erreur, et les parcours principaux fonctionnent
 * depuis l'interface (bons de commande, paiements, budgets, révisions, administration).
 */
class ScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    private function as(string $who): User
    {
        $user = User::query()->where('email', [
            'de' => 'direction@humaniteresilience.org',
            'dea' => 'adjointe@humaniteresilience.org',
            'fin' => 'financier@humaniteresilience.org',
            'cp' => 'chef.projet@humaniteresilience.org',
            'cc' => 'controle@humaniteresilience.org',
        ][$who])->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    private function approvedRequest(int $qty = 100, int $price = 100): PurchaseRequest
    {
        $s = app(PurchaseRequestService::class);
        $cp = User::query()->where('email', 'chef.projet@humaniteresilience.org')->first();
        $de = User::query()->where('email', 'direction@humaniteresilience.org')->first();
        $da = $s->create($cp, Project::query()->first(), BudgetLine::query()->where('code', '2.1')->first(), 'Kits', \App\Enums\Currency::USD,
            [['description' => 'Kit abri', 'quantity' => $qty, 'unit' => 'kit', 'unit_price' => $price]]);
        $s->submit($cp, $da);

        return $s->approve($de, $da);
    }

    public function test_tous_les_ecrans_souvrent_pour_la_direction_executive(): void
    {
        $this->as('de');
        $da = $this->approvedRequest();
        $budget = Budget::query()->first();
        $project = Project::query()->first();
        $user = User::query()->first();

        $pages = [
            [Dashboard::class, []],
            [Resources\PurchaseRequests\Pages\ListPurchaseRequests::class, []],
            [Resources\PurchaseRequests\Pages\CreatePurchaseRequest::class, []],
            [Resources\PurchaseRequests\Pages\ViewPurchaseRequest::class, ['record' => $da->id]],
            [Resources\PurchaseOrders\Pages\ListPurchaseOrders::class, []],
            [Resources\PurchaseOrders\Pages\CreatePurchaseOrder::class, []],
            [Resources\Payments\Pages\ListPayments::class, []],
            [Resources\Suppliers\Pages\ManageSuppliers::class, []],
            [Resources\Budgets\Pages\ListBudgets::class, []],
            [Resources\Budgets\Pages\CreateBudget::class, []],
            [Resources\Budgets\Pages\ViewBudget::class, ['record' => $budget->id]],
            [Resources\Derogations\Pages\ListDerogations::class, []],
            [Resources\TreasuryAccounts\Pages\ManageTreasuryAccounts::class, []],
            [Resources\ExchangeRates\Pages\ManageExchangeRates::class, []],
            [Resources\Projects\Pages\ListProjects::class, []],
            [Resources\Projects\Pages\CreateProject::class, []],
            [Resources\Projects\Pages\ViewProject::class, ['record' => $project->id]],
            [Resources\Projects\Pages\EditProject::class, ['record' => $project->id]],
            [Resources\StatutoryObjectives\Pages\ManageStatutoryObjectives::class, []],
            [Resources\Users\Pages\ListUsers::class, []],
            [Resources\Users\Pages\CreateUser::class, []],
            [Resources\Users\Pages\EditUser::class, ['record' => $user->id]],
            [Resources\Delegations\Pages\ManageDelegations::class, []],
            [Resources\AuditLogs\Pages\ListAuditLogs::class, []],
        ];
        foreach ($pages as [$page, $params]) {
            try {
                Livewire::test($page, $params)->assertOk();
            } catch (\Throwable $e) {
                $this->fail(class_basename($page).' : '.$e->getMessage());
            }
        }

        // Onglets (relation managers) du budget et de l'utilisateur
        Livewire::test(Resources\Budgets\RelationManagers\LinesRelationManager::class, ['ownerRecord' => $budget, 'pageClass' => Resources\Budgets\Pages\ViewBudget::class])
            ->assertOk()->assertSee('Achats kits abris')->assertSee('50 000,00');
        Livewire::test(Resources\Budgets\RelationManagers\VersionsRelationManager::class, ['ownerRecord' => $budget, 'pageClass' => Resources\Budgets\Pages\ViewBudget::class])
            ->assertOk()->assertSee('v1');
        Livewire::test(Resources\Users\RelationManagers\ModuleAccessesRelationManager::class, ['ownerRecord' => $user, 'pageClass' => Resources\Users\Pages\EditUser::class])
            ->assertOk();
    }

    public function test_bon_de_commande_paiement_et_cloture_depuis_les_ecrans(): void
    {
        $da = $this->approvedRequest(400, 100);

        // Le chef de projet établit le BC à partir de la demande (préremplissage)
        $this->as('cp');
        Livewire::withQueryParams(['request' => $da->id])
            ->test(Resources\PurchaseOrders\Pages\CreatePurchaseOrder::class)
            ->assertSchemaStateSet(['purchase_request_id' => $da->id, 'currency' => \App\Enums\Currency::USD])
            ->fillForm(['supplier_id' => Supplier::query()->first()->id, 'items' => [['description' => 'Kit abri', 'quantity' => 400, 'unit' => 'kit', 'unit_price' => 95]]])
            ->call('create')
            ->assertHasNoFormErrors();
        $bc = PurchaseOrder::query()->firstOrFail();
        $this->assertSame('38000.00', $bc->amount);

        Livewire::test(Resources\PurchaseOrders\Pages\ViewPurchaseOrder::class, ['record' => $bc->id])->callAction('submit');

        $this->as('de');
        Livewire::test(Resources\PurchaseOrders\Pages\ViewPurchaseOrder::class, ['record' => $bc->id])->callAction('approve', ['comment' => 'OK']);
        $this->assertSame(PurchaseOrderStatus::VALIDE, $bc->fresh()->status);

        // Le Financier enregistre un paiement partiel
        $this->as('fin');
        Livewire::test(Resources\PurchaseOrders\Pages\ViewPurchaseOrder::class, ['record' => $bc->id])
            ->assertActionVisible('pay')
            ->callAction('pay', [
                'treasury_account_id' => TreasuryAccount::query()->where('name', 'Caisse Goma USD')->first()->id,
                'amount' => 20000, 'paid_at' => now()->toDateString(), 'method' => 'espèces',
            ]);
        $payment = Payment::query()->firstOrFail();
        $this->assertSame(DocStatus::SOUMIS, $payment->status);

        // La DE approuve depuis la liste des paiements
        $this->as('de');
        Livewire::test(Resources\Payments\Pages\ListPayments::class)
            ->assertOk()
            ->callTableAction('approvePayment', $payment, ['comment' => 'Vu']);
        $this->assertSame(DocStatus::VALIDE, $payment->fresh()->status);

        // Clôture par le Financier : reliquat libéré
        $this->as('fin');
        Livewire::test(Resources\PurchaseOrders\Pages\ViewPurchaseOrder::class, ['record' => $bc->id])
            ->callAction('close', ['reason' => 'Livraison partielle acceptée']);
        $this->assertSame(PurchaseOrderStatus::CLOTURE, $bc->fresh()->status);
    }

    public function test_saisie_dun_budget_puis_revision_depuis_les_ecrans(): void
    {
        $this->as('fin');
        $project = Project::query()->first();

        Livewire::test(Resources\Budgets\Pages\CreateBudget::class)
            ->fillForm(['project_id' => $project->id, 'year' => 2028, 'currency' => 'USD', 'lines' => [
                ['code' => '1.1', 'label' => 'Salaires', 'category' => null, 'amount' => 1000],
                ['code' => '2.1', 'label' => 'Achats', 'category' => null, 'amount' => 2000],
            ]])
            ->call('create')
            ->assertHasNoFormErrors();
        $budget2028 = Budget::query()->whereHas('fiscalYear', fn ($q) => $q->where('year', 2028))->firstOrFail();
        $version = $budget2028->versions()->first();

        Livewire::test(Resources\Budgets\RelationManagers\VersionsRelationManager::class, ['ownerRecord' => $budget2028, 'pageClass' => Resources\Budgets\Pages\ViewBudget::class])
            ->callTableAction('submitVersion', $version);
        $this->as('de');
        Livewire::test(Resources\Budgets\RelationManagers\VersionsRelationManager::class, ['ownerRecord' => $budget2028, 'pageClass' => Resources\Budgets\Pages\ViewBudget::class])
            ->callTableAction('approveVersion', $version, ['comment' => 'OK']);
        $this->assertSame(BudgetVersionStatus::EN_VIGUEUR, $version->fresh()->status);

        // Révision : seules les lignes modifiées sont prises en compte
        $this->as('fin');
        Livewire::test(Resources\Budgets\Pages\ViewBudget::class, ['record' => $budget2028->id])
            ->callAction('revise', [
                'reason' => 'Ajustement des achats',
                'lines' => [
                    ['code' => '1.1', 'label' => 'Salaires', 'amount' => '1000.00'],
                    ['code' => '2.1', 'label' => 'Achats', 'amount' => 3500],
                ],
            ]);
        $v2 = $budget2028->versions()->where('version_no', 2)->firstOrFail();
        $this->assertSame(BudgetVersionStatus::BROUILLON, $v2->status);
        $this->assertSame('3500.00', $v2->amounts()->whereHas('line', fn ($q) => $q->where('code', '2.1'))->value('amount'));
    }

    public function test_administration_reservee_et_octroi_des_habilitations(): void
    {
        $this->as('cp');
        Livewire::test(Resources\Users\Pages\ListUsers::class)->assertForbidden();
        Livewire::test(Resources\AuditLogs\Pages\ListAuditLogs::class)->assertForbidden();

        $this->as('cc');
        Livewire::test(Resources\AuditLogs\Pages\ListAuditLogs::class)->assertOk()->callAction('verify')->assertNotified('Journal intact');

        $de = $this->as('de');
        $cp = User::query()->where('email', 'chef.projet@humaniteresilience.org')->first();
        Livewire::test(Resources\Users\RelationManagers\ModuleAccessesRelationManager::class, ['ownerRecord' => $cp, 'pageClass' => Resources\Users\Pages\EditUser::class])
            ->callTableAction('create', data: ['module' => 'RH', 'level' => 'LECTURE'])
            ->assertHasNoTableActionErrors();
        $this->assertTrue($cp->fresh()->canInModule(\App\Enums\AppModule::RH));
        $this->assertSame($de->id, $cp->moduleAccesses()->where('module', 'RH')->value('granted_by_id'));
    }
}
