<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/** Échelon de l'échelle salariale (catégorie × étape, date d'effet) */
class SalaryGrade extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'effective_from' => 'date',
        ];
    }
}
