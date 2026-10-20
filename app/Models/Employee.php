<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentCategory;
use App\Enums\Gender;
use App\Enums\IdDocumentType;
use App\Enums\MaritalStatus;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Dossier du personnel (formulaire biographique et formulaire d'urgence, Manuel §2.4.5–2.4.6).
 * Les informations médicales et le numéro de compte sont chiffrés en base (RG-26).
 */
class Employee extends Model
{
    use Audited;

    protected $guarded = ['id'];

    /** Champs jamais recopiés dans le journal d'audit, même chiffrés */
    public array $auditExclude = ['medical_notes', 'bank_account'];

    /** Rubriques sensibles (RG-26) : RH en création, Direction Exécutive et l'intéressé */
    public const SENSITIVE = [
        'id_type', 'id_number', 'id_expires_at', 'medical_notes', 'cnss_number', 'tax_number',
        'bank_name', 'bank_account',
    ];

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'marital_status' => MaritalStatus::class,
            'id_type' => IdDocumentType::class,
            'employment_category' => EmploymentCategory::class,
            'status' => EmployeeStatus::class,
            'birth_date' => 'date',
            'id_expires_at' => 'date',
            'hired_at' => 'date',
            'medical_notes' => 'encrypted',
            'bank_account' => 'encrypted',
        ];
    }

    /** Usage congolais : NOM Post-nom Prénom */
    public function getFullNameAttribute(): string
    {
        return trim(mb_strtoupper($this->last_name).' '.($this->middle_name ? mb_strtoupper($this->middle_name).' ' : '').$this->first_name);
    }

    public function getInitialsAttribute(): string
    {
        return mb_strtoupper(mb_substr($this->first_name, 0, 1).mb_substr($this->last_name, 0, 1));
    }

    /** Ancienneté en années complètes depuis l'entrée en fonction */
    public function seniorityYears(?\DateTimeInterface $at = null): int
    {
        return (int) $this->hired_at->diffInYears($at ?? now());
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function dutyStation(): BelongsTo
    {
        return $this->belongsTo(DutyStation::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'supervisor_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function diplomas(): HasMany
    {
        return $this->hasMany(EmployeeDiploma::class);
    }

    public function family(): HasMany
    {
        return $this->hasMany(EmployeeFamily::class);
    }

    public function declaredRelations(): HasMany
    {
        return $this->hasMany(EmployeeRelation::class);
    }

    public function serviceCards(): HasMany
    {
        return $this->hasMany(ServiceCard::class);
    }

    public function disciplinaryActions(): HasMany
    {
        return $this->hasMany(DisciplinaryAction::class);
    }

    public function exitRecord(): HasOne
    {
        return $this->hasOne(EmployeeExit::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(EmployeeHistory::class)->latest('id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function activeContract(): HasOne
    {
        return $this->hasOne(Contract::class)->where('status', ContractStatus::ACTIF->value)->latestOfMany('start_date');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** Liens de parenté déclarés dans les deux sens (RG-27) */
    public function isRelatedTo(Employee $other): bool
    {
        return EmployeeRelation::query()
            ->where(fn ($q) => $q->where('employee_id', $this->id)->where('related_employee_id', $other->id))
            ->orWhere(fn ($q) => $q->where('employee_id', $other->id)->where('related_employee_id', $this->id))
            ->exists();
    }
}
