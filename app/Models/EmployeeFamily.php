<?php

namespace App\Models;

use App\Enums\FamilyRelation;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Membre de la famille immédiate (Manuel §1.5) */
class EmployeeFamily extends Model
{
    use Audited;

    protected $table = 'employee_family';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'relation' => FamilyRelation::class,
            'birth_date' => 'date',
            'is_dependant' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
