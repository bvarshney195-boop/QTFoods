<?php

namespace App\Modules\Foundation\Domain;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class User extends Authenticatable
{
    protected $table = 'users';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['password_hash', 'mfa_secret', 'deleted_email'];

    protected $authPasswordName = 'password_hash';

    protected $rememberTokenName = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'mfa_enabled_at' => 'immutable_datetime',
            'mfa_required_by_admin' => 'boolean',
            'deleted_at' => 'immutable_datetime',
            'is_demo' => 'boolean',
            'record_version' => 'integer',
        ];
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class, 'user_id');
    }
}
