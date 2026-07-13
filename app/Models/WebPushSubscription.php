<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPushSubscription extends Model
{
    protected $table = 'v2_web_push_subscription';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['endpoint', 'public_key', 'auth_token', 'endpoint_hash'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'last_used_at' => 'timestamp',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
