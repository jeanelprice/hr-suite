<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Donor extends Model
{
    use Audited;

    protected $guarded = ['id'];

    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class);
    }
}
