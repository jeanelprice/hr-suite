<?php

namespace Tests\Feature;

use App\Enums\AmendmentKind;
use App\Enums\AttachmentCategory;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\DisciplinaryMeasure;
use App\Enums\EmployeeStatus;
use App\Enums\ExitReason;
use App\Enums\ProbationOutcome;
use App\Enums\ServiceCardStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\BudgetLine;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Project;
use App\Models\User;
use App\Notifications\HrNotification;
use App\Services\AttachmentService;
use App\Services\ContractService;
use App\Services\EmployeeService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Module Personnel, étape 1 : dossier, ligne hiérarchique, contrats (RG-22),
 * données personnelles (RG-26), liens de parenté (RG-27), carte, discipline, sortie.
 */
class PersonnelTest extends TestCase
{
    use RefreshDatabase;

    private User $de;

    private User $rh;

    private User $cp;

    private User $fin;

    private EmployeeService $employees;

    private ContractService $contracts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $by = fn ($email) => User::query()->where('email', $email)->firstOrFail();
        $this->de = $by('direction@humaniteresilience.org');
        $this->rh = $by('rh@humaniteresilience.org');
        $this->cp = $by('chef.projet@humaniteresilience.org');
        $this->fin = $by('financier@humaniteresilience.org');
        $this->employees = app(EmployeeService::class);
        $this->contracts = app(ContractService::class);
        $this->actingAs($this->rh);
    }

    private function employee(array $extra = []): Employee
    {
        return $this->employees->create($this->rh, $extra + [
            'last_name' => 'Test', 'first_name' => 'Agent', 'gender' => 'F', 'phone' => '+243 1', 'hired_at' => '2026-09-01',
            'position_id' => Position::query()->first()->id,
        ]);
    }

    private function line(string $code = '1.1'): BudgetLine
    {
        return BudgetLine::query()->where('code', $code)->firstOrFail();
    }

    private function draft(Employee $e, array $terms = [], ?array $alloc = null): Contract
    {
        return $this->contracts->create($this->rh, $e, $terms + [
            'type' => ContractType::CDD, 'start_date' => '2026-09-01', 'end_date' => '2026-12-31', 'probation_end_date' => '2026-11-30',
            'position_id' => $e->position_id, 'base_salary' => '700', 'currency' => 'USD',
        ], $alloc ?? [['budget_line_id' => $this->line()->id, 'percentage' => 100]]);
    }

    private function assertRule(string $rule, callable $fn): void
    {
        try {
            $fn();
            $this->fail("La règle {$rule} aurait dû bloquer l'opération.");
        } catch (BusinessRuleException $e) {
            $this->assertSame($rule, $e->rule, $e->getMessage());
        }
    }

    public function test_matricule_historique_et_supérieur_recopié_sur_le_compte(): void
    {
        $chief = Employee::query()->where('user_id', $this->cp->id)->firstOrFail();
        $user = User::query()->create(['name' => 'Agent', 'email' => 'agent@test.org', 'password' => 'x', 'role' => 'STAFF_TERRAIN']);
        $e = $this->employee(['supervisor_id' => $chief->id, 'user_id' => $user->id]);

        $this->assertMatchesRegularExpression('/^HR-\d{4}$/', $e->matricule);
        $this->assertSame(EmployeeStatus::EN_ESSAI, $e->status);
        $this->assertSame($this->cp->id, $user->fresh()->supervisor_id, 'Le supérieur du dossier pilote les validations');
        $this->assertSame('Entrée en fonction', $e->history()->first()->field);

        $this->employees->update($this->rh, $e, ['duty_station_id' => \App\Models\DutyStation::query()->where('name', 'Kirotshe')->value('id')]);
        $this->assertSame('Kirotshe', $e->history()->first()->new_value);
    }

    public function test_rg27_un_parent_déclaré_ne_peut_pas_être_supérieur(): void
    {
        $chief = Employee::query()->where('user_id', $this->cp->id)->firstOrFail();
        $e = $this->employee();
        $this->employees->declareRelation($this->rh, $e, ['related_employee_id' => $chief->id, 'relation' => 'Frère / sœur']);

        $this->assertRule('RG-27', fn () => $this->employees->update($this->rh, $e, ['supervisor_id' => $chief->id]));

        $sub = Employee::query()->where('supervisor_id', $chief->id)->firstOrFail();
        $this->assertRule('RG-27', fn () => $this->employees->declareRelation($this->rh, $sub, ['related_employee_id' => $chief->id, 'relation' => 'Cousin(e)']));
        $this->assertRule('HIERARCHIE', fn () => $this->employees->update($this->rh, $chief, ['supervisor_id' => $sub->id]));
    }

    public function test_rg22_durée_projet_ventilation_et_essai(): void
    {
        $e = $this->employee();
        $project = Project::query()->where('code', 'HR-ABR-2027')->firstOrFail();

        $this->assertRule('RG-22', fn () => $this->draft($e, ['project_id' => $project->id, 'start_date' => '2027-01-01', 'end_date' => '2028-03-31', 'probation_end_date' => null]));
        $this->assertRule('RG-22', fn () => $this->draft($e, [], [['budget_line_id' => $this->line()->id, 'percentage' => 90]]));
        $this->assertRule('RG-22', fn () => $this->draft($e, ['probation_end_date' => '2027-06-30', 'end_date' => '2027-12-31']));

        // 60/40 jusqu'au 31/10, puis 100 % sur une seule ligne
        $c = $this->draft($e, [], [
            ['budget_line_id' => $this->line('1.1')->id, 'percentage' => 60, 'valid_to' => '2026-10-31'],
            ['budget_line_id' => $this->line('3.1')->id, 'percentage' => 40, 'valid_to' => '2026-10-31'],
            ['budget_line_id' => $this->line('1.1')->id, 'percentage' => 100, 'valid_from' => '2026-11-01'],
        ]);
        $this->assertCount(3, $c->allocations);
        $this->assertSame(ContractStatus::BROUILLON, $c->status);
        $this->assertMatchesRegularExpression('/^CT-\d{4}-\d{4}$/', $c->number);
        $this->assertSame(0, DB::table('budget_movements')->where('budget_line_id', $this->line()->id)->where('source_type', 'contract')->count(), 'RG-06 : aucun engagement à la signature');
    }

    public function test_un_seul_contrat_actif_et_poste_reporté_dans_le_dossier(): void
    {
        $e = $this->employee(['position_id' => null]);
        $position = Position::query()->where('title', 'Chauffeur')->firstOrFail();
        $c = $this->draft($e, ['position_id' => $position->id]);
        $this->contracts->activate($this->rh, $c, '2026-08-28');
        $this->assertSame($position->id, $e->fresh()->position_id);
        $this->assertTrue(Deadline::query()->where('subject_type', 'contract')->where('subject_id', $c->id)->where('kind', 'FIN_ESSAI')->exists());

        $c2 = $this->draft($e->fresh(), ['start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'probation_end_date' => null]);
        $this->assertRule('RG-22', fn () => $this->contracts->activate($this->rh, $c2, '2026-12-20'));
    }

    public function test_avenants_appliqués_à_leur_date_d_effet(): void
    {
        $c = $this->draft($this->employee());
        $this->contracts->activate($this->rh, $c, '2026-08-28');

        $this->contracts->amend($this->rh, $c, AmendmentKind::SALAIRE, '850', '2026-11-01', 'Révision');
        $c->refresh();
        $this->assertSame('700.00', $c->termsAt('2026-10-31')['base_salary']);
        $this->assertSame('850.00', $c->termsAt('2026-11-01')['base_salary']);
        $this->assertSame('700.00', (string) $c->base_salary, "Le contrat d'origine n'est jamais modifié");

        $this->contracts->amend($this->rh, $c, AmendmentKind::PROLONGATION_CONTRAT, '2027-03-31', '2026-12-01', 'Prolongation');
        $this->assertSame('2027-03-31', $c->fresh()->termsAt('2026-12-15')['end_date']);
        $this->assertRule('RG-22', fn () => $this->contracts->amend($this->rh, $c->fresh(), AmendmentKind::PROLONGATION_CONTRAT, '2027-02-01', '2026-12-01', 'Raccourcir'));
    }

    public function test_décision_de_fin_d_essai_réservée_à_la_de(): void
    {
        $e = $this->employee();
        $c = $this->draft($e);
        $this->contracts->activate($this->rh, $c, '2026-08-28');
        Notification::fake();

        $this->assertRule('RG-22', fn () => $this->contracts->decideProbation($this->rh, $c, ProbationOutcome::CONFIRME, 'OK'));
        $this->assertRule('RG-22', fn () => $this->contracts->decideProbation($this->de, $c, ProbationOutcome::PROLONGE, 'Sans date'));

        $this->contracts->decideProbation($this->de, $c->fresh(), ProbationOutcome::PROLONGE, 'Encore un mois', '2026-12-31');
        $this->assertSame('2026-12-31', $c->fresh()->termsAt()['probation_end_date']);

        $this->contracts->decideProbation($this->de, $c->fresh(), ProbationOutcome::CONFIRME, 'Évaluation satisfaisante');
        $this->assertSame(EmployeeStatus::CONFIRME, $e->fresh()->status);
        Notification::assertSentTo($this->rh, HrNotification::class);
    }

    public function test_sortie_contrat_clos_compte_désactivé_et_certificat(): void
    {
        $user = User::query()->create(['name' => 'Partant', 'email' => 'partant@test.org', 'password' => 'x', 'role' => 'STAFF_TERRAIN']);
        $e = $this->employee(['user_id' => $user->id]);
        $c = $this->draft($e);
        $this->contracts->activate($this->rh, $c, '2026-08-28');
        $this->employees->issueCard($this->rh, $e);
        $this->assertRule('CARTE', fn () => $this->employees->issueCard($this->rh, $e));

        $exit = $this->employees->recordExit($this->rh, $e, ['reason' => ExitReason::DEMISSION, 'exit_date' => '2026-10-15']);
        $this->assertSame(ContractStatus::RESILIE, $c->fresh()->status);
        $this->assertSame(EmployeeStatus::SORTI, $e->fresh()->status);
        $this->assertFalse($user->fresh()->is_active);

        $this->assertRule('SORTIE', fn () => $this->employees->issueClearance($this->rh, $exit));
        $this->employees->updateExit($this->rh, $exit, ['card_returned' => true, 'equipment_returned' => true, 'debrief_notes' => 'Entretien de fin de contrat fait.']);
        $this->employees->issueClearance($this->rh, $exit->fresh());
        $this->assertNotNull($exit->fresh()->clearance_issued_at);
        $this->assertSame(ServiceCardStatus::RESTITUEE, $e->serviceCards()->first()->status);
    }

    public function test_discipline_mise_en_demeure_et_suspension(): void
    {
        $e = $this->employee();
        $warning = $this->employees->recordDisciplinary($this->rh, $e, ['offence_level' => 'MAJEURE', 'measure' => DisciplinaryMeasure::MISE_EN_DEMEURE, 'decided_at' => '2026-10-01', 'facts' => 'Retards répétés']);
        $this->assertSame('2027-10-01', $warning->counts_until->toDateString());

        $this->employees->recordDisciplinary($this->rh, $e, ['offence_level' => 'FAUTE_GRAVE', 'measure' => DisciplinaryMeasure::SUSPENSION, 'decided_at' => '2026-10-10', 'facts' => 'Enquête en cours']);
        $this->assertSame(EmployeeStatus::SUSPENDU, $e->fresh()->status);
    }

    public function test_rg26_données_chiffrées_non_auditées_et_accès_aux_documents(): void
    {
        Storage::fake('local');
        $e = $this->employee(['bank_account' => '00012-3456789-01', 'medical_notes' => 'Allergie à la pénicilline']);

        $raw = DB::table('employees')->where('id', $e->id)->first();
        $this->assertStringNotContainsString('3456789', $raw->bank_account, 'Le compte est chiffré en base');
        $this->assertStringNotContainsString('pénicilline', (string) AuditLog::query()->where('entity_id', (string) $e->id)->get()->toJson());

        Storage::disk('local')->put('documents/cv.pdf', "%PDF-1.4\n%test\n");
        $doc = app(AttachmentService::class)->store($this->rh, $e, 'documents/cv.pdf', AttachmentCategory::CV, 'cv.pdf');
        $this->assertSame(64, strlen($doc->checksum));

        $this->actingAs($this->rh)->get(route('files.attachment', $doc))->assertOk();
        $this->actingAs($this->cp)->get(route('files.attachment', $doc))->assertForbidden(); // RH en lecture seulement
        $this->assertTrue(AuditLog::query()->where('action', 'PIECE_CONSULTEE')->exists());

        // Photo : visible du module RH (même en lecture) et de l'intéressé, pas des autres
        Storage::disk('local')->put('photos/p.jpg', UploadedFile::fake()->image('p.jpg')->getContent());
        $e->update(['photo_path' => 'photos/p.jpg']);
        $this->actingAs($this->cp)->get(route('files.photo', $e))->assertOk();
        $this->actingAs($this->fin)->get(route('files.photo', $e))->assertForbidden();
        $this->get(route('files.photo', Employee::query()->where('user_id', $this->fin->id)->first()))->assertNotFound(); // la sienne : autorisé, mais pas de photo
    }

    public function test_alertes_de_fin_de_contrat_et_de_fin_d_essai(): void
    {
        Notification::fake();
        $this->artisan('hr:echeances')->assertSuccessful();

        Notification::assertSentTo($this->rh, HrNotification::class, fn (HrNotification $n) => str_contains($n->subject, 'Contrat de BAHATI'));
        Notification::assertSentTo($this->de, HrNotification::class, fn (HrNotification $n) => str_contains($n->subject, "Période d'essai de PALUKU"));
        $this->assertSame(0, Deadline::query()->where('status', 'OUVERTE')->whereNull('last_reminded_at')->where('due_at', '<=', now())->count());
    }
}
