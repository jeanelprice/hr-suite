<?php

namespace Tests\Feature\Ui;

use App\Enums\ContractStatus;
use App\Filament\Pages\OrgChart;
use App\Filament\Resources;
use App\Filament\Resources\Employees\RelationManagers\DocumentsRelationManager;
use App\Models\BudgetLine;
use App\Models\Contract;
use App\Models\DutyStation;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Écrans du module Personnel : ouverture, parcours dossier → contrat → avenant, droits */
class PersonnelScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Filament::setCurrentPanel('admin');
        Storage::fake('local');
    }

    private function as(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    public function test_tous_les_écrans_du_personnel_s_ouvrent_pour_le_rh(): void
    {
        $this->as('rh@humaniteresilience.org');
        $employee = Employee::query()->where('last_name', 'Paluku')->firstOrFail();
        $active = Contract::query()->where('status', ContractStatus::ACTIF->value)->firstOrFail();
        $draft = Contract::query()->where('status', ContractStatus::BROUILLON->value)->firstOrFail();

        $pages = [
            [Dashboard::class, []],
            [OrgChart::class, []],
            [Resources\Employees\Pages\ListEmployees::class, []],
            [Resources\Employees\Pages\CreateEmployee::class, []],
            [Resources\Employees\Pages\ViewEmployee::class, ['record' => $employee->id]],
            [Resources\Employees\Pages\EditEmployee::class, ['record' => $employee->id]],
            [Resources\Contracts\Pages\ListContracts::class, []],
            [Resources\Contracts\Pages\CreateContract::class, []],
            [Resources\Contracts\Pages\ViewContract::class, ['record' => $active->id]],
            [Resources\Contracts\Pages\EditContract::class, ['record' => $draft->id]],
            [Resources\Departments\Pages\ManageDepartments::class, []],
            [Resources\Positions\Pages\ManagePositions::class, []],
            [Resources\SalaryGrades\Pages\ManageSalaryGrades::class, []],
            [Resources\DutyStations\Pages\ManageDutyStations::class, []],
            [Resources\BoardMandates\Pages\ManageBoardMandates::class, []],
        ];
        foreach ($pages as [$page, $params]) {
            try {
                Livewire::test($page, $params)->assertOk();
            } catch (\Throwable $e) {
                $this->fail(class_basename($page).' : '.$e->getMessage());
            }
        }

        $managers = [
            Resources\Employees\RelationManagers\ContractsRelationManager::class,
            DocumentsRelationManager::class,
            Resources\Employees\RelationManagers\FamilyRelationManager::class,
            Resources\Employees\RelationManagers\DiplomasRelationManager::class,
            Resources\Employees\RelationManagers\DeclaredRelationsRelationManager::class,
            Resources\Employees\RelationManagers\DisciplinaryActionsRelationManager::class,
            Resources\Employees\RelationManagers\HistoryRelationManager::class,
        ];
        foreach ($managers as $manager) {
            Livewire::test($manager, ['ownerRecord' => $employee, 'pageClass' => Resources\Employees\Pages\ViewEmployee::class])->assertOk();
        }
        Livewire::test(Resources\Contracts\RelationManagers\AmendmentsRelationManager::class, ['ownerRecord' => $active, 'pageClass' => Resources\Contracts\Pages\ViewContract::class])->assertOk();

        Livewire::test(\App\Filament\Widgets\PersonnelDeadlines::class)->assertOk()->assertSee('BAHATI Jonas')->assertSee("Fin de période d'essai");
        Livewire::test(OrgChart::class)->assertSee('BYENDA MUHANYA Jean-El-Price')->assertSee('PALUKU SIKULI David');
    }

    public function test_dossier_avec_photo_puis_contrat_signé_et_avenant_depuis_les_écrans(): void
    {
        $this->as('rh@humaniteresilience.org');
        $position = Position::query()->where('title', 'Superviseur terrain abris')->firstOrFail();
        $station = DutyStation::query()->where('name', 'Kirotshe')->firstOrFail();

        Livewire::test(Resources\Employees\Pages\CreateEmployee::class)
            ->fillForm([
                'photo_path' => UploadedFile::fake()->image('photo.jpg', 400, 400),
                'last_name' => 'Mapendo', 'middle_name' => 'Kasereka', 'first_name' => 'Aline', 'gender' => 'F',
                'nationality' => 'Congolaise', 'phone' => '+243 999', 'employment_category' => 'TEMPS_PLEIN',
                'hired_at' => '2026-10-01', 'position_id' => $position->id, 'duty_station_id' => $station->id,
                'bank_account' => '12345-678',
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $employee = Employee::query()->where('last_name', 'Mapendo')->firstOrFail();
        $this->assertNotNull($employee->photo_path);
        Storage::disk('local')->assertExists($employee->photo_path);

        // Contrat prérempli depuis le dossier
        Livewire::withQueryParams(['employee' => $employee->id])
            ->test(Resources\Contracts\Pages\CreateContract::class)
            ->assertSchemaStateSet(['employee_id' => $employee->id, 'position_id' => $position->id, 'start_date' => '2026-10-01'])
            ->fillForm([
                'type' => 'CDD', 'end_date' => '2026-12-31', 'probation_end_date' => '2026-12-15',
                'salary_category' => 'C1', 'salary_step' => 1, 'base_salary' => 800, 'currency' => 'USD', 'weekly_hours' => 40,
                'allocations' => [['budget_line_id' => BudgetLine::query()->where('code', '1.1')->value('id'), 'percentage' => 100]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $contract = Contract::query()->where('employee_id', $employee->id)->firstOrFail();

        Livewire::test(Resources\Contracts\Pages\ViewContract::class, ['record' => $contract->id])
            ->callAction('activate', ['signed_at' => '2026-09-30'])
            ->callAction('amend', ['kind' => 'SALAIRE', 'salary' => 900, 'effective_date' => '2026-12-01', 'reason' => 'Promotion']);
        $contract->refresh();
        $this->assertSame(ContractStatus::ACTIF, $contract->status);
        $this->assertSame('900.00', $contract->termsAt('2026-12-01')['base_salary']);

        // Document joint au dossier
        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => Resources\Employees\Pages\ViewEmployee::class])
            ->callTableAction('upload', data: ['category' => 'CV', 'title' => 'CV', 'file' => UploadedFile::fake()->create('cv.pdf', 120, 'application/pdf')]);
        $this->assertSame(1, $employee->attachments()->count());

        // Carte de service et impressions
        Livewire::test(Resources\Employees\Pages\ViewEmployee::class, ['record' => $employee->id])->callAction('issueCard');
        $this->get(route('print.card', $employee))->assertOk()->assertSee('MAPENDO KASEREKA Aline')->assertSee('Carte de service');
        $this->get(route('print.contract', $contract))->assertOk()->assertSee('CONTRAT DE TRAVAIL')->assertSee('800,00')->assertSee('Promotion');
    }

    public function test_un_lecteur_rh_ne_voit_pas_les_données_sensibles(): void
    {
        $this->as('rh@humaniteresilience.org');
        $employee = Employee::query()->where('last_name', 'Paluku')->firstOrFail();
        $employee->update(['bank_account' => '99887-766', 'cnss_number' => 'CNSS-42']);
        Livewire::test(Resources\Employees\Pages\ViewEmployee::class, ['record' => $employee->id])->assertSee('99887-766');

        $this->as('chef.projet@humaniteresilience.org'); // module RH en lecture
        Livewire::test(Resources\Employees\Pages\ViewEmployee::class, ['record' => $employee->id])
            ->assertOk()->assertSee('PALUKU SIKULI David')->assertDontSee('99887-766')->assertDontSee('CNSS-42');
        Livewire::test(Resources\Employees\Pages\CreateEmployee::class)->assertForbidden();
        $this->assertFalse(DocumentsRelationManager::canViewForRecord($employee, Resources\Employees\Pages\ViewEmployee::class));
        $this->get(route('print.contract', Contract::query()->where('employee_id', $employee->id)->first()))->assertForbidden();

        $this->as('financier@humaniteresilience.org'); // pas de module RH
        Livewire::test(Resources\Employees\Pages\ListEmployees::class)->assertForbidden();
    }
}
