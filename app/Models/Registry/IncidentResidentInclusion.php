<?php

namespace App\Models\Registry;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentResidentInclusion extends Model
{
    protected $fillable = [
        'inclusion_basis',
        'reference',
        'notes',
        'status',
        'removed_at',
        'disaster_incident_id',
        'resident_id',
        'added_by',
        'removed_by',
    ];

    protected function casts(): array
    {
        return [
            'removed_at' => 'datetime',
        ];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncidentResidentInclusionEvent::class, 'inclusion_id');
    }
}
