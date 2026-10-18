<?php

namespace Tests\Feature\Ui;

use App\Enums\DocStatus;
use App\Filament\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Filament\Resources\PurchaseRequests\Pages\ViewPurchaseRequest;
use App\Models\BudgetLine;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseRequestScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    private function as(string $email): User
    {
        $u = User::query()->where('email', $email)->firstOrFail();
        $this->actingAs($u);

        return $u;
    }

    public function test_liste_creation_soumission_validation_depuis_les_ecrans(): void
    {
        $this->as('chef.projet@humaniteresilience.org');
        Livewire::test(ListPurchaseRequests::class)->assertOk();

        $project = Project::query()->firstOrFail();
        $line = BudgetLine::query()->where('code', '2.1')->firstOrFail();

        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm([
                'project_id' => $project->id,
                'budget_line_id' => $line->id,
                'purpose' => 'Kits abris lot 1',
                'currency' => 'USD',
                'items' => [['description' => 'Kit abri', 'quantity' => 100, 'unit' => 'kit', 'unit_price' => 100]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $da = PurchaseRequest::query()->firstOrFail();
        $this->assertSame('10000.00', $da->estimated_amount);

        Livewire::test(ViewPurchaseRequest::class, ['record' => $da->id])
            ->assertOk()
            ->assertActionVisible('submit')
            ->assertActionHidden('approve')
            ->callAction('submit');
        $this->assertSame(DocStatus::SOUMIS, $da->fresh()->status);

        $this->as('direction@humaniteresilience.org');
        Livewire::test(ViewPurchaseRequest::class, ['record' => $da->id])
            ->assertActionVisible('approve')
            ->callAction('approve', ['comment' => 'OK']);
        $this->assertSame(DocStatus::VALIDE, $da->fresh()->status);
    }

    public function test_creation_bloquee_si_le_solde_est_insuffisant(): void
    {
        $this->as('chef.projet@humaniteresilience.org');
        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm([
                'project_id' => Project::query()->firstOrFail()->id,
                'budget_line_id' => BudgetLine::query()->where('code', '3.1')->firstOrFail()->id,
                'purpose' => 'Transport trop cher',
                'currency' => 'USD',
                'items' => [['description' => 'Camion', 'quantity' => 10, 'unit_price' => 1000]],
            ])
            ->call('create')
            ->assertNotified('Dépassement budgétaire bloqué');
        $this->assertSame(0, PurchaseRequest::query()->count());
    }

    public function test_le_comite_de_controle_ne_peut_pas_creer(): void
    {
        $this->as('controle@humaniteresilience.org');
        Livewire::test(ListPurchaseRequests::class)->assertOk();
        Livewire::test(CreatePurchaseRequest::class)->assertForbidden();
    }
}
