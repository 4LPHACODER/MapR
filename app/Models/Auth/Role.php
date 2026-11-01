<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
