<?php

namespace App\Services;

use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AuthService
{
    private const DEFAULT_TOKEN_TTL = 604800;
    private const USER_CACHE_PREFIX = 'AUTH_USER_';

    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(Request $request)
    {
        $now = time();
        $expiresAt = $now + self::tokenTtl();
        $guid = Helper::guid();
        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
            'jti' => $guid,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expiresAt,
        ], config('app.key'), 'HS256');

        self::addSession($this->user->id, $guid, [
            'ip' => Helper::getRealClientIp($request),
            'login_at' => $now,
            'ua' => $request->userAgent(),
            'expires_at' => $expiresAt,
            'token_hash' => self::tokenHash($authData),
        ]);

        return [
            'token' => $this->user->token,
            'is_admin' => $this->user->is_admin,
            'is_staff' => $this->user->is_staff,
            'auth_data' => $authData,
        ];
    }

    /**
     * Read an authentication token exclusively from the Authorization header.
     * Both a raw JWT and the standard Bearer form are supported.
     */
    public static function extractAuthData(Request $request)
    {
        $authorization = trim((string)$request->header('Authorization', ''));
        if ($authorization === '') {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            $authorization = trim($matches[1]);
        }

        return $authorization !== '' ? $authorization : null;
    }

    /**
     * Resolve the session guid of the request's own token so endpoints can
     * tell the caller which entry in the session list is theirs.
     */
    public static function currentSessionId(Request $request)
    {
        $jwt = self::extractAuthData($request);
        if (!$jwt) {
            return null;
        }

        try {
            $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
        } catch (\Throwable $e) {
            return null;
        }

        return isset($data['session']) && is_string($data['session']) && $data['session'] !== ''
            ? $data['session']
            : null;
    }

    public static function decryptAuthData($jwt)
    {
        if (!is_string($jwt) || trim($jwt) === '') {
            return false;
        }

        $jwt = trim($jwt);
        $tokenHash = self::tokenHash($jwt);
        $userCacheKey = self::userCacheKey($tokenHash);

        try {
            $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
            if (!self::hasValidClaims($data)) {
                Cache::forget($userCacheKey);
                return false;
            }

            $userId = (int)$data['id'];
            $sessionId = (string)$data['session'];
            if (!self::checkSession($userId, $sessionId, $tokenHash)) {
                Cache::forget($userCacheKey);
                return false;
            }

            $user = Cache::get($userCacheKey);
            if (!is_array($user)) {
                $model = User::select([
                    'id',
                    'email',
                    'is_admin',
                    'is_staff',
                    'banned',
                ])->find($userId);
                if (!$model || $model->banned) {
                    Cache::forget($userCacheKey);
                    return false;
                }

                $user = $model->toArray();
                $remainingTtl = max(1, (int)$data['exp'] - time());
                Cache::put($userCacheKey, $user, min(300, $remainingTtl));
            }

            if (!empty($user['banned'])) {
                Cache::forget($userCacheKey);
                return false;
            }

            return $user;
        } catch (\Throwable $e) {
            Cache::forget($userCacheKey);
            return false;
        }
    }

    private static function hasValidClaims(array $data)
    {
        foreach (['id', 'session', 'jti', 'iat', 'nbf', 'exp'] as $claim) {
            if (!array_key_exists($claim, $data)) {
                return false;
            }
        }

        if ((int)$data['id'] <= 0 || !is_string($data['session']) || $data['session'] === '') {
            return false;
        }
        if (!is_string($data['jti']) || !hash_equals($data['session'], $data['jti'])) {
            return false;
        }

        $now = time();
        $issuedAt = (int)$data['iat'];
        $notBefore = (int)$data['nbf'];
        $expiresAt = (int)$data['exp'];

        return $issuedAt > 0
            && $issuedAt <= $now + 60
            && $notBefore <= $now + 60
            && $expiresAt > $now
            && $expiresAt > $issuedAt;
    }

    private static function checkSession($userId, $sessionId, $tokenHash)
    {
        $sessions = (array)Cache::get(CacheKey::get('USER_SESSIONS', $userId), []);
        if (!isset($sessions[$sessionId]) || !is_array($sessions[$sessionId])) {
            return false;
        }

        $meta = $sessions[$sessionId];
        if (isset($meta['expires_at']) && (int)$meta['expires_at'] <= time()) {
            return false;
        }

        if (isset($meta['token_hash'])) {
            return is_string($meta['token_hash']) && hash_equals($meta['token_hash'], $tokenHash);
        }

        // Legacy session records are only accepted when they still point to this token.
        return isset($meta['auth_data'])
            && is_string($meta['auth_data'])
            && hash_equals(self::tokenHash($meta['auth_data']), $tokenHash);
    }

    private static function addSession($userId, $guid, array $meta)
    {
        $cacheKey = CacheKey::get('USER_SESSIONS', $userId);
        $sessions = self::activeSessions((array)Cache::get($cacheKey, []));
        $sessions[$guid] = $meta;

        return Cache::put($cacheKey, $sessions, self::sessionsTtl($sessions));
    }

    public function getSessions()
    {
        $cacheKey = CacheKey::get('USER_SESSIONS', $this->user->id);
        $sessions = self::activeSessions((array)Cache::get($cacheKey, []));
        $publicSessions = [];

        foreach ($sessions as $sessionId => $meta) {
            unset($meta['auth_data'], $meta['token_hash']);
            $publicSessions[$sessionId] = $meta;
        }

        self::storeSessions($cacheKey, $sessions);
        return $publicSessions;
    }

    public function removeSession($sessionId)
    {
        $cacheKey = CacheKey::get('USER_SESSIONS', $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        $meta = isset($sessions[$sessionId]) && is_array($sessions[$sessionId])
            ? $sessions[$sessionId]
            : [];

        self::forgetSessionUserCache($meta);
        unset($sessions[$sessionId]);

        return self::storeSessions($cacheKey, self::activeSessions($sessions));
    }

    public function removeAllSession()
    {
        $cacheKey = CacheKey::get('USER_SESSIONS', $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        foreach ($sessions as $meta) {
            if (is_array($meta)) {
                self::forgetSessionUserCache($meta);
            }
        }

        return Cache::forget($cacheKey);
    }

    private static function forgetSessionUserCache(array $meta)
    {
        if (isset($meta['token_hash']) && is_string($meta['token_hash'])) {
            Cache::forget(self::userCacheKey($meta['token_hash']));
        }
        if (isset($meta['auth_data']) && is_string($meta['auth_data'])) {
            Cache::forget($meta['auth_data']);
            Cache::forget(self::userCacheKey(self::tokenHash($meta['auth_data'])));
        }
    }

    private static function activeSessions(array $sessions)
    {
        $now = time();
        foreach ($sessions as $sessionId => $meta) {
            if (!is_array($meta) || (isset($meta['expires_at']) && (int)$meta['expires_at'] <= $now)) {
                if (is_array($meta)) {
                    self::forgetSessionUserCache($meta);
                }
                unset($sessions[$sessionId]);
            }
        }

        return $sessions;
    }

    private static function storeSessions($cacheKey, array $sessions)
    {
        if (!$sessions) {
            Cache::forget($cacheKey);
            return true;
        }

        return Cache::put($cacheKey, $sessions, self::sessionsTtl($sessions));
    }

    private static function sessionsTtl(array $sessions)
    {
        $latestExpiry = 0;
        foreach ($sessions as $meta) {
            if (is_array($meta) && isset($meta['expires_at'])) {
                $latestExpiry = max($latestExpiry, (int)$meta['expires_at']);
            }
        }

        return $latestExpiry > time()
            ? max(1, $latestExpiry - time())
            : self::tokenTtl();
    }

    private static function tokenTtl()
    {
        $configured = (int)config('v2board.auth_token_expire', self::DEFAULT_TOKEN_TTL);
        return $configured > 0 ? $configured : self::DEFAULT_TOKEN_TTL;
    }

    private static function tokenHash($jwt)
    {
        return hash('sha256', (string)$jwt);
    }

    private static function userCacheKey($tokenHash)
    {
        return self::USER_CACHE_PREFIX . $tokenHash;
    }
}
