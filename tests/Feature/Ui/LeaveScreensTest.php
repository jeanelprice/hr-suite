<?php

namespace Tests\Feature\Ui;

use App\Enums\LeaveStatus;
use App\Filament\Pages\LeaveApprovals;
use App\Filament\Pages\MySpace;
use App\Filament\Resources;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Écrans des congés : Mon espace, Congés à valider, gestion RH, référentiels */
class LeaveScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 09:00:00');
        $this->seed(DemoSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(string $email): User
    {
        $u = User::query()->where('email', $email)->firstOrFail();
        $this->actingAs($u);

        return $u;
    }

    public function test_mon_espace_demande_puis_validation_par_la_supérieure_et_le_rh(): void
    {
        $this->as('financier@humaniteresilience.org');
        Livewire::test(MySpace::class)->assertOk()->assertSee('MUMBERE Patrick')->assertSee('Congé annuel')->assertSee('CG-2026-0001')
            ->callAction('request', [
                'leave_type_id' => LeaveType::byCode('ANNUEL')->id,
                'start_date' => '2026-12-21', 'end_date' => '2026-12-22', 'reason' => 'Fêtes',
            ])->assertHasNoActionErrors();
        $r = LeaveRequest::query()->latest('id')->firstOrFail();
        $this->assertSame(LeaveStatus::SOUMISE, $r->status);
        $this->assertSame(2.0, (float) $r->days);

        // Supérieure directe du Financier : la DE
        $this->as('direction@humaniteresilience.org');
        $this->assertSame('2', LeaveApprovals::getNavigationBadge(), 'Demande du Financier + celle de la chef de projet');
        Livewire::test(LeaveApprovals::class)->assertOk()->assertSee('MUMBERE Patrick')->assertSee('KAVIRA Grâce')
            ->callTableAction('validate', $r, ['comment' => 'OK']);
        $this->assertSame(LeaveStatus::VALIDEE_SUPERIEUR, $r->fresh()->status);

        $this->as('rh@humaniteresilience.org');
        Livewire::test(LeaveApprovals::class)->callTableAction('validate', $r->fresh());
        $this->assertSame(LeaveStatus::APPROUVEE, $r->fresh()->status);

        $this->as('financier@humaniteresilience.org');
        Livewire::test(MySpace::class)->assertSee('Approuvée')
            ->callTableAction('cancel', $r->fresh(), ['reason' => 'Changement de programme']);
        $this->assertSame(LeaveStatus::ANNULEE, $r->fresh()->status);
    }

    public function test_écrans_rh_et_référentiels_s_ouvrent(): void
    {
        $this->as('rh@humaniteresilience.org');
        $leave = LeaveRequest::query()->firstOrFail();
        $employee = Employee::query()->where('last_name', 'Mumbere')->firstOrFail();
        foreach ([
            [Dashboard::class, []],
            [MySpace::class, []],
            [LeaveApprovals::class, []],
            [Resources\LeaveRequests\Pages\ListLeaveRequests::class, []],
            [Resources\LeaveRequests\Pages\CreateLeaveRequest::class, []],
            [Resources\LeaveRequests\Pages\ViewLeaveRequest::class, ['record' => $leave->id]],
            [Resources\LeaveTypes\Pages\ManageLeaveTypes::class, []],
            [Resources\PublicHolidays\Pages\ManagePublicHolidays::class, []],
            [Resources\Employees\Pages\ViewEmployee::class, ['record' => $employee->id]],
        ] as [$page, $params]) {
            try {
                Livewire::test($page, $params)->assertOk();
            } catch (\Throwable $e) {
                $this->fail(class_basename($page).' : '.$e->getMessage());
            }
        }
        foreach ([Resources\Employees\RelationManagers\LeaveRequestsRelationManager::class, Resources\Employees\RelationManagers\LeaveLedgerRelationManager::class] as $rm) {
            Livewire::test($rm, ['ownerRecord' => $employee, 'pageClass' => Resources\Employees\Pages\ViewEmployee::class])->assertOk()->assertSee('CG-2026-0001');
        }
        Livewire::test(\App\Filament\Widgets\AbsencesThisWeek::class)->assertSee('MUMBERE Patrick')->assertSee("Absent aujourd'hui");
        Livewire::test(Resources\PublicHolidays\Pages\ManagePublicHolidays::class)->assertSee('Genocost');

        // Saisie RH pour le chauffeur (sans compte) et absence non autorisée
        $driver = Employee::query()->where('last_name', 'Bahati')->firstOrFail();
        Livewire::test(Resources\LeaveRequests\Pages\CreateLeaveRequest::class)
            ->fillForm(['employee_id' => $driver->id, 'leave_type_id' => LeaveType::byCode('COMPASSION')->id, 'start_date' => '2026-10-26', 'end_date' => '2026-10-27'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('COMPASSION', LeaveRequest::query()->latest('id')->first()->type->code);
        Livewire::test(Resources\LeaveRequests\Pages\ListLeaveRequests::class)
            ->callAction('absence', ['employee_id' => $driver->id, 'start_date' => '2026-10-28', 'end_date' => '2026-10-28', 'reason' => 'Absent sans prévenir']);
        $this->assertTrue(LeaveRequest::query()->whereHas('type', fn ($q) => $q->where('code', 'ABSENCE'))->exists());
    }

    public function test_accès_mon_espace_et_validation_réservés(): void
    {
        $this->as('controle@humaniteresilience.org'); // compte sans dossier du personnel
        Livewire::test(MySpace::class)->assertForbidden();
        Livewire::test(LeaveApprovals::class)->assertForbidden();

        $this->as('chef.projet@humaniteresilience.org'); // supérieure de deux agents, sans demande à valider
        Livewire::test(LeaveApprovals::class)->assertOk()->assertSee('Rien à valider');
        Livewire::test(Resources\LeaveRequests\Pages\CreateLeaveRequest::class)->assertForbidden();
    }
}
