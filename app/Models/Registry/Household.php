<?php

namespace App\Models\Registry;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    protected $fillable = [
        'household_reference',
        'household_status',
        'record_source',
        'record_verified_at',
        'building_id',
        'barangay_id',
        'source_household_size',
        'source_nuclear_families',
        'source_no_males',
        'source_no_females',
        'source_line_number',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class);
    }
}
