<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPushSubscription extends Model
{
    protected $table = 'v2_web_push_subscription';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['endpoint', 'public_key', 'auth_token', 'endpoint_hash'];
}
