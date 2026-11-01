<?php

namespace App\Models\Registry;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resident extends Model
{
    protected $fillable = [
        'registry_reference',
        'first_name',
        'middle_name',
        'last_name',
        'birth_date',
        'sex',
        'residency_status',
        'record_source',
        'record_verified_at',
        'household_id',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'record_verified_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function vulnerabilities(): HasMany
    {
        return $this->hasMany(ResidentVulnerability::class);
    }

    public function inclusions(): HasMany
    {
        return $this->hasMany(IncidentResidentInclusion::class);
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => trim(collect([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])->filter()->implode(' ')));
    }
}
