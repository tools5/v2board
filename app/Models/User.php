<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    // Credentials must never leak through a generic Eloquent serialization.
    protected $hidden = [
        'password',
        'password_algo',
        'password_salt',
    ];
}
