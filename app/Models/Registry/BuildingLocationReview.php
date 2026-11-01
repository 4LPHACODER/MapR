<?php

namespace App\Models\Registry;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuildingLocationReview extends Model
{
    protected $fillable = [
        'building_id',
        'previous_status',
        'new_status',
        'basis_or_reason',
        'reviewed_by',
        'reviewed_at',
        'selection_id',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
