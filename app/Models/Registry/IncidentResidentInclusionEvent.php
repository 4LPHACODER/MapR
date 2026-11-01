<?php

namespace App\Models\Registry;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentResidentInclusionEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'action',
        'created_at',
        'inclusion_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function inclusion(): BelongsTo
    {
        return $this->belongsTo(IncidentResidentInclusion::class, 'inclusion_id');
    }
}
