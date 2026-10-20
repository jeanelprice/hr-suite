<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Diplôme d'un employé */
class EmployeeDiploma extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
