<?php

namespace App\Modules\Foundation\Domain;

use Illuminate\Database\Eloquent\Model;

final class Permission extends Model
{
    protected $table = 'permissions';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
}
