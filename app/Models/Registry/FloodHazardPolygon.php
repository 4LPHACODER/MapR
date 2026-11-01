<?php

namespace App\Models\Registry;

use Illuminate\Database\Eloquent\Model;

class FloodHazardPolygon extends Model
{
    protected $fillable = [
        'source_object_id',
        'classification',
        'source',
    ];
}
