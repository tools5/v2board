<?php

namespace App\Services;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class AuthService
{
    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(Request $request)
    {
        $guid = Helper::guid();
        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
        ], config('app.key'), 'HS256');
        self::addSession($this->user->id, $guid, [
            'ip' => Helper::getRealClientIp($request),
            'login_at' => time(),
            'ua' => $request->userAgent(),
            'auth_data' => $authData
        ]);
        return [
            'token' => $this->user->token,
            'is_admin' => $this->user->is_admin,
            'is_staff' => $this->user->is_staff,
            'auth_data' => $authData
        ];
    }

    public static function decryptAuthData($jwt)
    {
        try {
            if (!Cache::has($jwt)) {
                $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
                if (!self::checkSession($data['id'], $data['session'])) return false;
                $user = User::select([
                    'id',
                    'email',
                    'is_admin',
                    'is_staff'
                ])
                    ->find($data['id']);
                if (!$user) return false;
                Cache::put($jwt, $user->toArray(), 3600);
            }
            return Cache::get($jwt);
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function checkSession($userId, $session)
    {
        $sessions = (array)Cache::get(CacheKey::get("USER_SESSIONS", $userId)) ?? [];
        if (!in_array($session, array_keys($sessions))) return false;
        return true;
    }

    private static function addSession($userId, $guid, $meta)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        $sessions = (array)Cache::get($cacheKey, []);
        $sessions[$guid] = $meta;
        if (!Cache::put(
            $cacheKey,
            $sessions
        )) return false;
        return true;
    }

    public function getSessions()
    {
        return (array)Cache::get(CacheKey::get("USER_SESSIONS", $this->user->id), []);
    }

    /**
     * 读取会话列表时，把当前 JWT 对应会话的 IP/UA 刷新为本次请求的真实值。
     * 解决：旧会话仍是 127.0.0.1，以及「记住登录/继续」未重新 login 导致 IP 不更新。
     */
    public function getSessionsAndRefreshCurrent(Request $request)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        if (empty($sessions)) {
            return $sessions;
        }

        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) {
            return $sessions;
        }

        $currentSessionId = null;
        foreach ($sessions as $sessionId => $sessionMeta) {
            if (!is_array($sessionMeta)) {
                continue;
            }
            if (!empty($sessionMeta['auth_data']) && $sessionMeta['auth_data'] === $authorization) {
                $currentSessionId = $sessionId;
                break;
            }
        }

        // 兼容：缓存里 meta 没有 auth_data 时，从 JWT payload 解析 session
        if ($currentSessionId === null) {
            try {
                $payload = (array)JWT::decode($authorization, new Key(config('app.key'), 'HS256'));
                if (!empty($payload['session']) && isset($sessions[$payload['session']])) {
                    $currentSessionId = $payload['session'];
                }
            } catch (\Exception $exception) {
                // ignore
            }
        }

        if ($currentSessionId === null || !isset($sessions[$currentSessionId]) || !is_array($sessions[$currentSessionId])) {
            return $sessions;
        }

        $realClientIp = Helper::getRealClientIp($request);
        $userAgent = $request->userAgent();
        $sessionMeta = $sessions[$currentSessionId];
        $shouldPersist = false;

        if ($realClientIp && (!isset($sessionMeta['ip']) || $sessionMeta['ip'] !== $realClientIp)) {
            $sessionMeta['ip'] = $realClientIp;
            $shouldPersist = true;
        }
        if ($userAgent && empty($sessionMeta['ua'])) {
            $sessionMeta['ua'] = $userAgent;
            $shouldPersist = true;
        }
        if (empty($sessionMeta['auth_data'])) {
            $sessionMeta['auth_data'] = $authorization;
            $shouldPersist = true;
        }

        if ($shouldPersist) {
            $sessions[$currentSessionId] = $sessionMeta;
            Cache::put($cacheKey, $sessions);
        }

        return $sessions;
    }

    public function removeSession($sessionId)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        unset($sessions[$sessionId]);
        if (!Cache::put(
            $cacheKey,
            $sessions
        )) return false;
        return true;
    }

    public function removeAllSession()
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        foreach ($sessions as $guid => $meta) {
            if (isset($meta['auth_data'])) {
                Cache::forget($meta['auth_data']);
            }
        }
        return Cache::forget($cacheKey);
    }
}
