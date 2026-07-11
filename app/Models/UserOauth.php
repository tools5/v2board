<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class UserOauth extends Model
{
    protected $table = 'v2_user_oauth';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * access_token / refresh_token 属于敏感凭据，使用应用密钥加密后落库，
     * 避免数据库泄露时第三方令牌被直接读取。
     */
    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = $this->encryptValue($value);
    }

    public function getAccessTokenAttribute($value)
    {
        return $this->decryptValue($value);
    }

    public function setRefreshTokenAttribute($value): void
    {
        $this->attributes['refresh_token'] = $this->encryptValue($value);
    }

    public function getRefreshTokenAttribute($value)
    {
        return $this->decryptValue($value);
    }

    private function encryptValue($value): ?string
    {
        if ($value === null || $value === '') {
            return $value === null ? null : '';
        }
        return Crypt::encryptString((string)$value);
    }

    private function decryptValue($value)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $exception) {
            // 兼容加密前写入的历史明文数据，解密失败则原样返回
            return $value;
        }
    }
}
