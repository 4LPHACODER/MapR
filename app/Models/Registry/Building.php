<?php

namespace App\Models\Registry;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    protected $fillable = [
        'building_reference',
        'building_name',
        'building_type',
        'address_description',
        'location_source',
        'location_verification_status',
        'location_verified_at',
        'barangay_id',
        'location_verified_by',
    ];

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }
}
