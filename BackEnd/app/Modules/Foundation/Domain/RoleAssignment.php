<?php

namespace App\Modules\Foundation\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RoleAssignment extends Model
{
    protected $table = 'role_assignments';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
