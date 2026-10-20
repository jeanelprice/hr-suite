<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\Currency;
use App\Enums\ProbationOutcome;
use App\Models\Concerns\Audited;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Contrat de travail (RG-22). Un avenant ne modifie jamais le contrat :
 * les valeurs en vigueur à une date se lisent avec valueAt().
 */
class Contract extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ContractType::class,
            'status' => ContractStatus::class,
            'currency' => Currency::class,
            'probation_outcome' => ProbationOutcome::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'probation_end_date' => 'date',
            'signed_at' => 'date',
            'terminated_at' => 'date',
            'probation_decided_at' => 'date',
            'base_salary' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function dutyStation(): BelongsTo
    {
        return $this->belongsTo(DutyStation::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function probationDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'probation_decided_by_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(ContractAmendment::class)->orderBy('effective_date')->orderBy('id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ContractAllocation::class)->orderBy('valid_from');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** Champ du contrat touché par chaque type d'avenant */
    public const AMENDED_FIELD = [
        'SALAIRE' => 'base_salary',
        'POSTE' => 'position_id',
        'LIEU' => 'duty_station_id',
        'PROLONGATION_CONTRAT' => 'end_date',
        'PROLONGATION_ESSAI' => 'probation_end_date',
    ];

    /**
     * Valeurs du contrat en vigueur à une date donnée, avenants compris.
     *
     * @return array{base_salary:string, position_id:?int, duty_station_id:?int, end_date:?string, probation_end_date:?string}
     */
    public function termsAt(CarbonInterface|string|null $date = null): array
    {
        $date = Carbon::parse($date ?? now())->toDateString();
        $terms = [
            'base_salary' => (string) $this->base_salary,
            'position_id' => $this->position_id,
            'duty_station_id' => $this->duty_station_id,
            'end_date' => $this->end_date?->toDateString(),
            'probation_end_date' => $this->probation_end_date?->toDateString(),
        ];
        foreach ($this->amendments as $amendment) {
            if ($amendment->effective_date->toDateString() <= $date) {
                $terms = array_merge($terms, $amendment->new_value);
            }
        }

        return $terms;
    }

    public function isFixedTerm(): bool
    {
        return $this->type !== ContractType::CDI;
    }
}
