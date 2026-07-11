<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * OAuth 自动注册用户（独立于用户管理的 v2_user 列表）。
 * user_id 仅用于系统运行时（JWT / 订阅 / 订单 / 节点），不在「用户管理」展示。
 */
class OauthUser extends Model
{
    protected $table = 'v2_oauth_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function systemUser()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
