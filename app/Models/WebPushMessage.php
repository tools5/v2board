<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPushMessage extends Model
{
    protected $table = 'v2_web_push_message';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'actions' => 'array',
        'target_filter' => 'array',
    ];
}
