<?php

namespace App\Models\Registry;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Barangay extends Model
{
    protected $fillable = [
        'barangay_code',
        'barangay_name',
        'city',
        'province',
        'region',
        'boundary_source',
        'boundary_verified_at',
    ];

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }
}
