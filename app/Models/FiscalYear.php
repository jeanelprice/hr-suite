<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'is_closed' => 'boolean'];
    }

    public static function forYear(int $year): self
    {
        return static::firstOrCreate(
            ['year' => $year],
            ['start_date' => "{$year}-01-01", 'end_date' => "{$year}-12-31"],
        );
    }
}
