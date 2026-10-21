<?php

namespace Tests\Feature;

use App\Enums\EmployeeStatus;
use App\Enums\LeaveStatus;
use App\Enums\ContractType;
use App\Exceptions\BusinessRuleException;
use App\Models\BudgetLine;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use App\Notifications\HrNotification;
use App\Services\AttachmentService;
use App\Services\ContractService;
use App\Services\EmployeeService;
use App\Services\LeaveService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Congés et absences (RG-23) : décompte des jours, acquisition 1,5 j/mois et ancienneté,
 * année contractuelle et expiration, circuit supérieur puis RH/DE, règles par type.
 */
class LeaveTest extends TestCase
{
    use RefreshDatabase;

    private LeaveService $leaves;

    private User $de;

    private User $rh;

    private User $cp;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 09:00:00');
        $this->seed(DemoSeeder::class);
        $by = fn ($email) => User::query()->where('email', $email)->firstOrFail();
        $this->de = $by('direction@humaniteresilience.org');
        $this->rh = $by('rh@humaniteresilience.org');
        $this->cp = $by('chef.projet@humaniteresilience.org');
        $this->leaves = app(LeaveService::class);
        $this->actingAs($this->rh);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Agent avec compte, sous la chef de projet */
    private function agent(string $hired = '2025-04-15', string $status = 'CONFIRME', string $gender = 'M'): Employee
    {
        $user = User::query()->create(['name' => 'Agent '.uniqid(), 'email' => uniqid().'@test.org', 'password' => 'x', 'role' => 'STAFF_TERRAIN']);

        return app(EmployeeService::class)->create($this->rh, [
            'last_name' => 'Agent', 'first_name' => uniqid(), 'gender' => $gender, 'phone' => '1', 'hired_at' => $hired, 'status' => $status,
            'supervisor_id' => $this->cp->employee->id, 'user_id' => $user->id, 'position_id' => Position::query()->first()->id,
        ]);
    }

    private function rule(callable $fn, string $contains = ''): void
    {
        try {
            $fn();
            $this->fail('La règle aurait dû bloquer.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    private function request(Employee $e, string $code, string $start, string $end, array $extra = []): LeaveRequest
    {
        return $this->leaves->submit($e->user, $e, LeaveType::byCode($code), $extra + ['start_date' => $start, 'end_date' => $end]);
    }

    private function approve(LeaveRequest $r): LeaveRequest
    {
        $sup = $this->leaves->supervisorApprovers($r)->first();
        $this->leaves->supervisorDecision($sup, $r, true);
        $r->refresh();
        $this->leaves->finalDecision($this->leaves->finalApprovers($r)->first(), $r, true);

        return $r->fresh();
    }

    public function test_jours_ouvrables_hors_week_end_et_fériés_demi_journées(): void
    {
        // Du lundi 29/06 au vendredi 03/07/2026 : le 30/06 (Indépendance) est férié
        $this->assertSame(4.0, $this->leaves->countDays('2026-06-29', '2026-07-03'));
        // Du vendredi au lundi : 2 jours ouvrables ; demi-journée au départ
        $this->assertSame(1.5, $this->leaves->countDays('2026-10-23', '2026-10-26', true));
        $this->assertSame(0.5, $this->leaves->countDays('2026-10-23', '2026-10-23', false, true));
        $this->assertSame(0.0, $this->leaves->countDays('2026-10-24', '2026-10-25'));
        $this->assertSame(7.0, $this->leaves->countDays('2026-10-24', '2026-10-30', false, false, true));
    }

    public function test_acquisition_1_5_jour_par_mois_et_ancienneté_plafonnée(): void
    {
        $e = $this->agent('2025-04-15');
        $b = $this->leaves->balance($e);
        // 18 mois accomplis au 21/10/2026 : 12 en année 0, 6 en année 1 (depuis le 15/04/2026)
        $this->assertSame(1, $b['year']);
        $this->assertSame(9.0, $b['acquired']);
        $this->assertSame(18.0, $b['entitlement']);
        $this->assertSame('2026-04-15', $b['from']->toDateString());
        $this->assertSame(-18.0, (float) LeaveLedgerEntry::query()->where('employee_id', $e->id)->where('kind', 'EXPIRATION')->sum('days'), 'Année 0 non prise : expirée');

        $this->leaves->accrue($e); // idempotent
        $this->assertSame(18, LeaveLedgerEntry::query()->where('employee_id', $e->id)->where('kind', 'ACQUISITION')->count());

        $this->assertSame(19.0, $this->leaves->entitlement(5));
        $this->assertSame(20.0, $this->leaves->entitlement(10));
        $this->assertSame(24.0, $this->leaves->entitlement(40), 'Plafond de 24 jours');
    }

    public function test_circuit_supérieur_puis_rh_et_annulation_qui_rend_les_jours(): void
    {
        Notification::fake();
        $e = $this->agent();
        $r = $this->request($e, 'ANNUEL', '2026-11-23', '2026-11-27');
        $this->assertSame(5.0, (float) $r->days);
        $this->assertSame([$this->cp->id], $this->leaves->supervisorApprovers($r)->pluck('id')->all());
        Notification::assertSentTo($this->cp, HrNotification::class);
        $this->assertTrue($r->late_notice === false);

        $this->rule(fn () => $this->leaves->finalDecision($this->rh, $r, true), "n'attend pas");
        $this->rule(fn () => $this->leaves->supervisorDecision($this->rh, $r, true), "n'attend pas");
        $this->leaves->supervisorDecision($this->cp, $r, true);
        $this->leaves->finalDecision($this->rh, $r->fresh(), true);
        $this->assertSame(LeaveStatus::APPROUVEE, $r->fresh()->status);
        $this->assertSame(4.0, $this->leaves->balance($e)['available']); // 9 acquis − 5
        Notification::assertSentTo($e->user, HrNotification::class, fn ($n) => str_contains($n->subject, 'approuvée'));

        $this->leaves->cancel($e->user, $r->fresh(), 'Mission imprévue');
        $this->assertSame(9.0, $this->leaves->balance($e)['available']);
        try {
            DB::table('leave_ledger')->where('employee_id', $e->id)->delete();
            $this->fail('Le registre des congés doit être en ajout seul.');
        } catch (\Illuminate\Database\QueryException $ex) {
            $this->assertStringContainsString('ajout seul', $ex->getMessage());
        }
    }

    public function test_essai_avance_et_solde_insuffisant(): void
    {
        $trial = $this->agent('2026-08-01', 'EN_ESSAI');
        $this->rule(fn () => $this->request($trial, 'ANNUEL', '2026-11-02', '2026-11-03'), "période d'essai");

        $e = $this->agent(); // 9 jours disponibles, avance possible jusqu'à 9 jours
        $r = $this->request($e, 'ANNUEL', '2026-11-02', '2026-11-13'); // 10 jours
        $this->assertTrue($r->is_advance);
        $this->rule(fn () => $this->request($e, 'ANNUEL', '2026-12-01', '2026-12-11'), 'Solde insuffisant');
        $this->rule(fn () => $this->request($e, 'MALADIE', '2026-11-05', '2026-11-05'), 'chevauche');
    }

    public function test_maladie_justificatif_obligatoire_dès_deux_jours(): void
    {
        Storage::fake('local');
        $e = $this->agent();
        $r = $this->request($e, 'MALADIE', '2026-10-19', '2026-10-20');
        $this->leaves->supervisorDecision($this->cp, $r, true);
        $this->rule(fn () => $this->leaves->finalDecision($this->rh, $r->fresh(), true), 'certificat médical');

        Storage::disk('local')->put('documents/certif.pdf', "%PDF-1.4\n");
        app(AttachmentService::class)->store($e->user, $r, 'documents/certif.pdf', 'CERTIFICAT_MEDICAL', 'certif.pdf');
        $this->leaves->finalDecision($this->rh, $r->fresh(), true);
        $this->assertSame(LeaveStatus::APPROUVEE, $r->fresh()->status);
        $this->assertSame(9.0, $this->leaves->balance($e)['available'], 'La maladie ne touche pas le congé annuel');
    }

    public function test_maternité_quatorze_semaines_essai_prolongé_et_paie_aux_deux_tiers(): void
    {
        $e = $this->agent('2026-08-03', 'EN_ESSAI', 'F');
        $contracts = app(ContractService::class);
        $c = $contracts->create($this->rh, $e, [
            'type' => ContractType::CDD, 'start_date' => '2026-08-03', 'end_date' => '2027-06-30', 'probation_end_date' => '2026-11-02',
            'position_id' => $e->position_id, 'base_salary' => '600', 'currency' => 'USD',
        ], [['budget_line_id' => BudgetLine::query()->first()->id, 'percentage' => 100]]);
        $contracts->activate($this->rh, $c, '2026-08-01');

        $this->rule(fn () => $this->request($this->agent(), 'MATERNITE', '', '', ['expected_birth_date' => '2026-12-14']), 'ne concerne pas');
        $r = $this->request($e, 'MATERNITE', '', '', ['expected_birth_date' => '2026-12-14']);
        $this->assertSame('2026-11-02', $r->start_date->toDateString(), '6 semaines avant');
        $this->assertSame('2027-02-07', $r->end_date->toDateString());
        $this->assertSame(98.0, (float) $r->days);

        $this->approve($r);
        $this->assertSame('2027-02-08', $c->fresh()->termsAt()['probation_end_date'], "L'essai est prolongé de la durée du congé");

        $impact = $this->leaves->payImpact($e, '2026-11-01');
        $this->assertSame('MATERNITE', $impact[0]['type']);
        $this->assertEqualsWithDelta(0.6667, $impact[0]['pay_rate'], 0.0001);
    }

    public function test_compassion_études_et_approbation_de_la_de(): void
    {
        $e = $this->agent('2026-05-04');
        $this->rule(fn () => $this->request($e, 'COMPASSION', '2026-11-02', '2026-11-06'), 'limité à 4');
        $r = $this->request($e, 'COMPASSION', '2026-11-02', '2026-11-05');
        $this->leaves->supervisorDecision($this->cp, $r, true);
        $this->assertFalse($this->leaves->canApprove($this->rh, $r->fresh()), 'Compassion : approbation par la DE');
        $this->leaves->finalDecision($this->de, $r->fresh(), true);

        $this->rule(fn () => $this->request($e, 'ETUDES', '2026-12-01', '2026-12-04'), "12 mois d'ancienneté");
    }

    public function test_absence_non_autorisée_alerte_et_retenue(): void
    {
        Notification::fake();
        $e = $this->agent();
        $this->rule(fn () => $this->leaves->recordAbsence($e->user, $e, '2026-10-19', '2026-10-20', 'x'), 'Seul le RH');
        $this->leaves->recordAbsence($this->rh, $e, '2026-10-19', '2026-10-20', 'Sans nouvelles');
        Notification::assertSentTo($this->de, HrNotification::class, fn ($n) => str_contains($n->subject, 'Absence non autorisée'));
        Notification::assertSentTo($this->cp, HrNotification::class);
        $this->assertSame([['type' => 'ABSENCE', 'days' => 2.0, 'pay_rate' => 0.0]], $this->leaves->payImpact($e, '2026-10-01'));
    }

    public function test_congé_de_la_de_validé_par_la_de_adjointe_et_récupération(): void
    {
        $deEmp = $this->de->employee;
        $this->leaves->adjust($this->rh, $deEmp, 5, 'Reprise du solde au démarrage de HR Suite');
        $r = $this->leaves->submit($this->de, $deEmp, LeaveType::byCode('ANNUEL'), ['start_date' => '2026-12-21', 'end_date' => '2026-12-23']);
        $dea = User::query()->where('email', 'adjointe@humaniteresilience.org')->first();
        $this->assertSame([$dea->id], $this->leaves->supervisorApprovers($r)->pluck('id')->all());
        $this->assertFalse($this->leaves->finalApprovers($r)->contains('id', $this->de->id), 'Pas d\'auto-approbation');

        $e = $this->agent();
        $this->rule(fn () => $this->leaves->grantRecovery($e->user, $e, 1, 'x'), 'récupération');
        $this->leaves->grantRecovery($this->cp, $e, 1.5, 'Travail le 30 juin');
        $this->assertSame(1.5, $this->leaves->balance($e, 'RECUPERATION')['available']);
        $this->rule(fn () => $this->request($e, 'RECUPERATION', '2026-11-02', '2026-11-03'), 'Solde insuffisant');
        $this->assertSame(EmployeeStatus::CONFIRME, $e->status);
    }
}
