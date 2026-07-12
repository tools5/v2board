<?php

namespace App\Services\Oauth;

use App\Models\InviteCode;
use App\Models\OauthUser;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserOauth;
use App\Services\AuthService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OauthService
{
    /**
     * 单次请求内的建表检查缓存，避免每次调用都查询一次 information_schema。
     */
    private static $tableChecked = false;

    /**
     * 记录本次回调是否新建了用户（用于引导「完善信息」页）。
     */
    private $lastUserWasCreated = false;

    /**
     * 确保绑定表存在。正式部署应通过 `php artisan migrate` 建表，
     * 这里仅作为未执行迁移时的兜底，且每个请求最多检查一次。
     */
    public static function ensureTableExists(): void
    {
        if (self::$tableChecked) {
            return;
        }
        if (Schema::hasTable('v2_user_oauth')) {
            self::$tableChecked = true;
            self::ensureOauthUserTableExists();
            return;
        }

        abort(500, '第三方登录数据表缺失，请执行 php artisan migrate 或导入 database/update_oauth.sql');
    }

    public function buildAuthorizeUrl(string $provider, string $mode = 'login', ?int $userId = null, ?string $inviteCode = null, bool $isPopup = false): string
    {
        self::ensureTableExists();
        $meta = OauthProviderRegistry::get($provider);
        if (!$meta) {
            abort(500, '不支持的登录平台');
        }
        if (OauthProviderRegistry::isTelegramWidget($provider)) {
            abort(500, 'Telegram 登录请使用 Login Widget，不支持授权码跳转');
        }
        if (!OauthProviderRegistry::isEnabled($provider)) {
            abort(500, $meta['name'] . ' 登录未启用或未配置完整');
        }

        $normalizedInviteCode = $this->normalizeInviteCodeInput($inviteCode);

        $state = Helper::guid();
        Cache::put(CacheKey::get('OAUTH_STATE', $state), [
            'provider' => $provider,
            'mode' => $mode,
            'user_id' => $userId,
            'invite_code' => $normalizedInviteCode,
            'popup' => $isPopup,
            'created_at' => time(),
        ], 600);

        $clientId = (string)config('v2board.' . $meta['client_id_key']);
        $redirectUri = $this->getCallbackUrl($provider);

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $meta['scopes'],
            'state' => $state,
        ];
        // 部分平台（如 Google）需要附加授权参数（access_type / prompt 等）
        if (!empty($meta['extra_authorize_params']) && is_array($meta['extra_authorize_params'])) {
            $params = array_merge($params, $meta['extra_authorize_params']);
        }

        return $meta['authorize_url'] . '?' . http_build_query($params);
    }

    public function getCallbackUrl(string $provider): string
    {
        // 优先使用管理员在后台自定义的回调地址，为空时回退到按当前站点生成的默认值。
        // 注意：此地址必须与第三方平台应用里配置的回调地址完全一致。
        return OauthProviderRegistry::effectiveCallbackUrl($provider);
    }

    public function handleCallback(string $provider, Request $request): array
    {
        self::ensureTableExists();
        $meta = OauthProviderRegistry::get($provider);
        if (!$meta) {
            abort(500, '不支持的登录平台');
        }
        if (OauthProviderRegistry::isTelegramWidget($provider)) {
            abort(500, 'Telegram 登录请使用 Login Widget 回调接口');
        }
        if (!OauthProviderRegistry::isEnabled($provider)) {
            abort(500, $meta['name'] . ' 登录未启用或未配置完整');
        }

        $error = $request->input('error');
        if ($error) {
            $description = $request->input('error_description', $error);
            abort(400, '授权失败：' . $description);
        }

        $code = $request->input('code');
        $state = $request->input('state');
        if (!$code || !$state) {
            abort(400, '授权回调参数不完整');
        }

        $stateData = Cache::pull(CacheKey::get('OAUTH_STATE', $state));
        if (!$stateData || ($stateData['provider'] ?? null) !== $provider) {
            abort(400, '登录状态已失效，请重新发起登录');
        }

        $tokenData = $this->exchangeCodeForToken($meta, (string)$code, $provider);
        $accessToken = $tokenData['access_token'] ?? null;
        if (!$accessToken) {
            abort(500, '获取访问令牌失败');
        }

        $profile = $this->fetchUserProfile($meta, (string)$accessToken);
        $normalized = $this->normalizeProfile($provider, $profile, $meta, (string)$accessToken);
        if (empty($normalized['provider_user_id'])) {
            abort(500, '无法获取第三方用户标识');
        }

        $this->assertTrustLevel($meta, $profile);

        $mode = $stateData['mode'] ?? 'login';
        if ($mode === 'bind') {
            $userId = (int)($stateData['user_id'] ?? 0);
            if ($userId <= 0) {
                abort(400, '绑定状态无效，请重新绑定');
            }
            $user = User::find($userId);
            if (!$user) {
                abort(404, '要绑定的用户不存在');
            }
            $this->bindUser($user, $provider, $normalized, $tokenData, $profile);
            return [
                'mode' => 'bind',
                'user' => $user,
            ];
        }

        $this->lastUserWasCreated = false;
        $inviteCode = $this->normalizeInviteCodeInput($stateData['invite_code'] ?? null);
        $user = $this->findOrCreateUser($provider, $meta, $normalized, $tokenData, $profile, $inviteCode);
        return [
            'mode' => 'login',
            'user' => $user,
            'is_new' => $this->lastUserWasCreated,
            'auth' => (new AuthService($user))->generateAuthData($request),
            'popup' => !empty($stateData['popup']),
        ];
    }

    /**
     * Telegram Login Widget 登录 / 绑定。
     * $payload 为 Widget 回传字段：id, first_name, last_name, username, photo_url, auth_date, hash
     * $inviteCode 仅在自动注册新用户时生效（与邮箱注册 invite_force 对齐）
     */
    public function handleTelegramWidget(
        array $payload,
        string $mode = 'login',
        ?int $userId = null,
        ?Request $request = null,
        ?string $inviteCode = null
    ): array {
        self::ensureTableExists();
        $provider = 'telegram';
        $meta = OauthProviderRegistry::get($provider);
        if (!$meta || !OauthProviderRegistry::isTelegramWidget($provider)) {
            abort(500, '不支持的登录平台');
        }
        if (!OauthProviderRegistry::isEnabled($provider)) {
            abort(500, $meta['name'] . ' 登录未启用或未配置完整');
        }

        $botToken = OauthProviderRegistry::resolveBotToken($provider);
        if ($botToken === '') {
            abort(500, 'Telegram Bot Token 未配置');
        }

        $this->assertTelegramWidgetPayload($payload, $botToken, (int)($meta['auth_max_age_seconds'] ?? 86400));

        $normalized = $this->normalizeTelegramProfile($payload);
        if ($normalized['provider_user_id'] === '') {
            abort(500, '无法获取 Telegram 用户标识');
        }

        // 无 access/refresh token；raw 存 Widget 字段
        $tokenData = [];
        $profile = $payload;

        if ($mode === 'bind') {
            if (!$userId || $userId <= 0) {
                abort(400, '绑定状态无效，请重新绑定');
            }
            $user = User::find($userId);
            if (!$user) {
                abort(404, '要绑定的用户不存在');
            }
            $this->bindUser($user, $provider, $normalized, $tokenData, $profile);
            $this->syncTelegramIdColumn($user, $normalized['provider_user_id']);
            return [
                'mode' => 'bind',
                'user' => $user,
            ];
        }

        $this->lastUserWasCreated = false;
        $normalizedInviteCode = $this->normalizeInviteCodeInput($inviteCode);
        $user = $this->findOrCreateUser($provider, $meta, $normalized, $tokenData, $profile, $normalizedInviteCode);
        $this->syncTelegramIdColumn($user, $normalized['provider_user_id']);

        $result = [
            'mode' => 'login',
            'user' => $user,
            'is_new' => $this->lastUserWasCreated,
        ];
        if ($request) {
            $result['auth'] = (new AuthService($user))->generateAuthData($request);
        }
        return $result;
    }

    /**
     * 校验 Telegram Login Widget 签名与时效。
     * 见 https://core.telegram.org/widgets/login#checking-authorization
     */
    public function assertTelegramWidgetPayload(array $payload, string $botToken, int $maxAgeSeconds = 86400): void
    {
        $hash = isset($payload['hash']) ? (string)$payload['hash'] : '';
        if ($hash === '' || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            abort(400, 'Telegram 授权数据无效');
        }

        $authDate = isset($payload['auth_date']) ? (int)$payload['auth_date'] : 0;
        if ($authDate <= 0) {
            abort(400, 'Telegram 授权时间无效');
        }
        if ($maxAgeSeconds > 0 && (time() - $authDate) > $maxAgeSeconds) {
            abort(400, 'Telegram 授权已过期，请重新登录');
        }
        // 允许最多 60 秒时钟偏差
        if ($authDate > (time() + 60)) {
            abort(400, 'Telegram 授权时间无效');
        }

        $checkData = [];
        foreach ($payload as $key => $value) {
            if ($key === 'hash' || $value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $checkData[(string)$key] = (string)$value;
        }
        ksort($checkData);
        $lines = [];
        foreach ($checkData as $key => $value) {
            $lines[] = $key . '=' . $value;
        }
        $dataCheckString = implode("\n", $lines);
        $secretKey = hash('sha256', $botToken, true);
        $calculated = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals(strtolower($calculated), strtolower($hash))) {
            abort(400, 'Telegram 授权校验失败');
        }
    }

    private function normalizeTelegramProfile(array $payload): array
    {
        $id = $payload['id'] ?? null;
        $username = $payload['username'] ?? null;
        if ($username === null || $username === '') {
            $parts = array_filter([
                isset($payload['first_name']) ? (string)$payload['first_name'] : '',
                isset($payload['last_name']) ? (string)$payload['last_name'] : '',
            ]);
            $username = $parts ? implode(' ', $parts) : null;
        }
        $avatar = isset($payload['photo_url']) && is_string($payload['photo_url'])
            ? $payload['photo_url']
            : null;

        return [
            'provider_user_id' => $id !== null && $id !== '' ? (string)$id : '',
            'provider_username' => $username !== null ? (string)$username : null,
            'provider_email' => null,
            'provider_email_verified' => null,
            'provider_avatar' => $avatar,
        ];
    }

    /**
     * 与历史 Bot 通知字段 v2_user.telegram_id 保持一致。
     * 若该 telegram_id 已被其他用户占用，先释放对方占用，避免通知串号。
     */
    private function syncTelegramIdColumn(User $user, string $telegramId): void
    {
        $telegramId = trim($telegramId);
        if ($telegramId === '') {
            return;
        }
        // telegram_id 为 bigint，仅同步纯数字 ID
        if (!preg_match('/^\d+$/', $telegramId)) {
            return;
        }
        if ((string)$user->telegram_id === $telegramId) {
            return;
        }

        DB::transaction(function () use ($user, $telegramId) {
            User::where('telegram_id', $telegramId)
                ->where('id', '!=', $user->id)
                ->update(['telegram_id' => null]);

            // 事务内重新读取，避免并发下覆盖最新状态
            $fresh = User::query()->where('id', $user->id)->lockForUpdate()->first();
            if (!$fresh) {
                return;
            }
            if ((string)$fresh->telegram_id === $telegramId) {
                $user->telegram_id = $telegramId;
                return;
            }
            $fresh->telegram_id = $telegramId;
            $fresh->save();
            $user->telegram_id = $telegramId;
        });
    }

    private function exchangeCodeForToken(array $meta, string $code, string $provider): array
    {
        $clientId = (string)config('v2board.' . $meta['client_id_key']);
        $clientSecret = (string)config('v2board.' . $meta['client_secret_key']);
        $redirectUri = $this->getCallbackUrl($provider);

        $response = Http::asForm()
            ->timeout(15)
            ->acceptJson()
            ->post($meta['token_url'], [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]);

        if (!$response->successful()) {
            abort(500, '换取令牌失败：HTTP ' . $response->status());
        }

        $data = $response->json();
        if (!is_array($data)) {
            abort(500, '换取令牌失败：响应格式错误');
        }
        if (!empty($data['error'])) {
            abort(500, '换取令牌失败：' . ($data['error_description'] ?? $data['error']));
        }
        return $data;
    }

    private function fetchUserProfile(array $meta, string $accessToken): array
    {
        // 统一带上 User-Agent（GitHub 等平台强制要求，否则返回 403），
        // 并合并 provider 自定义的 userinfo 请求头。
        $headers = array_merge(
            ['User-Agent' => config('v2board.app_name', 'V2Board')],
            (!empty($meta['userinfo_headers']) && is_array($meta['userinfo_headers'])) ? $meta['userinfo_headers'] : []
        );

        $response = Http::withToken($accessToken)
            ->withHeaders($headers)
            ->timeout(15)
            ->acceptJson()
            ->get($meta['userinfo_url']);

        if (!$response->successful()) {
            abort(500, '获取用户信息失败：HTTP ' . $response->status());
        }

        $data = $response->json();
        if (!is_array($data)) {
            abort(500, '获取用户信息失败：响应格式错误');
        }
        return $data;
    }

    private function normalizeProfile(string $provider, array $profile, array $meta = [], string $accessToken = ''): array
    {
        if ($provider === 'linuxdo') {
            $id = $profile['id'] ?? $profile['sub'] ?? $profile['external_id'] ?? null;
            $username = $profile['username'] ?? $profile['login'] ?? $profile['name'] ?? null;
            $email = $profile['email'] ?? null;
            $avatar = $profile['avatar_url'] ?? $profile['avatar_template'] ?? null;
            return [
                'provider_user_id' => $id !== null ? (string)$id : '',
                'provider_username' => $username !== null ? (string)$username : null,
                'provider_email' => is_string($email) ? $email : null,
                'provider_email_verified' => $this->extractEmailVerified($profile),
                'provider_avatar' => is_string($avatar) ? $avatar : null,
            ];
        }

        if ($provider === 'github') {
            $id = $profile['id'] ?? $profile['node_id'] ?? null;
            $username = $profile['login'] ?? $profile['name'] ?? null;
            $avatar = $profile['avatar_url'] ?? null;
            // GitHub 的 /user 接口在用户把邮箱设为私密时不会返回 email，
            // 需要额外调用 /user/emails 取「已验证的主邮箱」。
            $email = is_string($profile['email'] ?? null) ? $profile['email'] : null;
            $emailVerified = null;
            $primaryEmail = $this->fetchGithubPrimaryEmail($accessToken);
            if ($primaryEmail) {
                $email = $primaryEmail['email'];
                $emailVerified = $primaryEmail['verified'];
            }
            return [
                'provider_user_id' => $id !== null ? (string)$id : '',
                'provider_username' => $username !== null ? (string)$username : null,
                'provider_email' => $email,
                'provider_email_verified' => $emailVerified,
                'provider_avatar' => is_string($avatar) ? $avatar : null,
            ];
        }

        if ($provider === 'google') {
            // Google userinfo（OIDC）返回 sub / name / email / email_verified / picture
            $id = $profile['sub'] ?? $profile['id'] ?? null;
            $username = $profile['name'] ?? $profile['given_name'] ?? null;
            $email = is_string($profile['email'] ?? null) ? $profile['email'] : null;
            $avatar = $profile['picture'] ?? null;
            return [
                'provider_user_id' => $id !== null ? (string)$id : '',
                'provider_username' => $username !== null ? (string)$username : null,
                'provider_email' => $email,
                'provider_email_verified' => $this->extractEmailVerified($profile),
                'provider_avatar' => is_string($avatar) ? $avatar : null,
            ];
        }

        if ($provider === 'microsoft') {
            // Microsoft Graph OIDC userinfo 返回 sub / name / email（或 preferred_username）/ picture
            $id = $profile['sub'] ?? $profile['oid'] ?? $profile['id'] ?? null;
            $username = $profile['name'] ?? $profile['given_name'] ?? null;
            $email = null;
            foreach (['email', 'preferred_username', 'upn'] as $emailKey) {
                if (!empty($profile[$emailKey]) && is_string($profile[$emailKey]) && strpos($profile[$emailKey], '@') !== false) {
                    $email = $profile[$emailKey];
                    break;
                }
            }
            $avatar = $profile['picture'] ?? null;
            return [
                'provider_user_id' => $id !== null ? (string)$id : '',
                'provider_username' => $username !== null ? (string)$username : null,
                'provider_email' => $email,
                'provider_email_verified' => $this->extractEmailVerified($profile),
                'provider_avatar' => is_string($avatar) ? $avatar : null,
            ];
        }

        $id = $profile['id'] ?? $profile['sub'] ?? null;
        return [
            'provider_user_id' => $id !== null ? (string)$id : '',
            'provider_username' => isset($profile['name']) ? (string)$profile['name'] : null,
            'provider_email' => isset($profile['email']) && is_string($profile['email']) ? $profile['email'] : null,
            'provider_email_verified' => $this->extractEmailVerified($profile),
            'provider_avatar' => isset($profile['avatar_url']) && is_string($profile['avatar_url']) ? $profile['avatar_url'] : null,
        ];
    }

    /**
     * 拉取 GitHub 账号已验证的主邮箱。
     * 返回 ['email' => string, 'verified' => bool]，取不到返回 null。
     */
    private function fetchGithubPrimaryEmail(string $accessToken): ?array
    {
        if ($accessToken === '') {
            return null;
        }
        $response = Http::withToken($accessToken)
            ->withHeaders([
                // GitHub API 要求携带 User-Agent，否则会拒绝请求
                'User-Agent' => config('v2board.app_name', 'V2Board'),
                'Accept' => 'application/vnd.github+json',
            ])
            ->timeout(15)
            ->get('https://api.github.com/user/emails');

        if (!$response->successful()) {
            return null;
        }
        $emails = $response->json();
        if (!is_array($emails)) {
            return null;
        }

        $primary = null;
        $firstVerified = null;
        foreach ($emails as $item) {
            if (!is_array($item) || empty($item['email'])) {
                continue;
            }
            $verified = !empty($item['verified']);
            if (!empty($item['primary'])) {
                $primary = ['email' => (string)$item['email'], 'verified' => $verified];
            }
            if ($verified && $firstVerified === null) {
                $firstVerified = ['email' => (string)$item['email'], 'verified' => true];
            }
        }
        // 优先返回主邮箱，其次返回任一已验证邮箱
        if ($primary !== null) {
            return $primary;
        }
        return $firstVerified;
    }

    /**
     * 从第三方 profile 中提取邮箱验证状态。
     * 返回 true / false 表示明确已验证 / 未验证，返回 null 表示无法判断。
     */
    private function extractEmailVerified(array $profile): ?bool
    {
        foreach (['email_verified', 'verified_email', 'email_confirmed'] as $key) {
            if (!array_key_exists($key, $profile)) {
                continue;
            }
            $value = $profile[$key];
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value)) {
                return $value === 1;
            }
            if (is_string($value)) {
                return in_array(strtolower($value), ['1', 'true', 'yes'], true);
            }
        }
        return null;
    }

    /**
     * 判断该第三方邮箱是否可信到能用于自动关联已有本站账号。
     * 只有在 profile 明确标记已验证，或平台声明「缺省即可信」时才返回 true，
     * 避免攻击者用未验证/可控邮箱接管已有账号。
     */
    private function isProviderEmailTrusted(array $meta, array $normalized): bool
    {
        $verified = $normalized['provider_email_verified'] ?? null;
        if ($verified === true) {
            return true;
        }
        if ($verified === false) {
            return false;
        }
        // profile 未回传验证字段：仅当平台被显式标记为可信时才放行
        return !empty($meta['email_trusted_when_unknown']);
    }

    private function assertTrustLevel(array $meta, array $profile): void
    {
        // 账号状态与最低信任等级无关，即使等级限制为 0 也必须检查。
        if (array_key_exists('silenced', $profile) && $this->isTruthyFlag($profile['silenced'])) {
            abort(403, '该第三方账号已被禁言，无法登录');
        }
        if (array_key_exists('active', $profile) && $this->isFalseFlag($profile['active'])) {
            abort(403, '该第三方账号未激活，无法登录');
        }

        $minKey = $meta['min_trust_level_key'] ?? null;
        if (!$minKey) {
            return;
        }
        $minLevel = (int)config('v2board.' . $minKey, 0);
        if ($minLevel <= 0) {
            return;
        }
        $trustLevel = (int)($profile['trust_level'] ?? 0);
        if ($trustLevel < $minLevel) {
            abort(403, '信任等级不足，需要达到 Lv' . $minLevel . ' 才能登录');
        }
    }

    private function isTruthyFlag($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return !empty($value);
    }

    private function isFalseFlag($value): bool
    {
        if (is_bool($value)) {
            return !$value;
        }
        if (is_int($value)) {
            return $value === 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
        }

        return $value === null;
    }

    private function findOrCreateUser(
        string $provider,
        array $meta,
        array $normalized,
        array $tokenData,
        array $profile,
        string $inviteCode = ''
    ): User {
        $binding = UserOauth::where('provider', $provider)
            ->where('provider_user_id', $normalized['provider_user_id'])
            ->first();

        if ($binding) {
            $user = User::find($binding->user_id);
            if (!$user) {
                $binding->delete();
            } else {
                if ($user->banned) {
                    abort(403, '您的账号已被停用');
                }
                $this->updateBinding($binding, $normalized, $tokenData, $profile);
                $user->last_login_at = time();
                $user->save();
                return $user;
            }
        }

        // 同一真实邮箱只对应一个本站账号：可信第三方邮箱命中后直接绑定，禁止再新建用户。
        // 匹配顺序：本站邮箱 → 其他平台绑定上的 provider_email。
        $trustedEmail = $this->resolveTrustedProviderEmail($meta, $normalized);
        if ($trustedEmail !== null) {
            $existUser = $this->findUserByTrustedEmail($trustedEmail);
            if ($existUser) {
                if ($existUser->banned) {
                    abort(403, '您的账号已被停用');
                }
                $this->bindUser($existUser, $provider, $normalized, $tokenData, $profile);
                $this->maybeUpgradePlaceholderEmail($existUser, $trustedEmail);
                $existUser->last_login_at = time();
                $existUser->save();
                return $existUser;
            }
        }

        // 兼容历史：仅有 v2_user.telegram_id、尚无 oauth 绑定的老用户
        if ($provider === 'telegram' && preg_match('/^\d+$/', (string)$normalized['provider_user_id'])) {
            $legacyUser = User::where('telegram_id', $normalized['provider_user_id'])->first();
            if ($legacyUser) {
                if ($legacyUser->banned) {
                    abort(403, '您的账号已被停用');
                }
                $this->bindUser($legacyUser, $provider, $normalized, $tokenData, $profile);
                $legacyUser->last_login_at = time();
                $legacyUser->save();
                return $legacyUser;
            }
        }

        $autoRegister = (int)config('v2board.' . $meta['auto_register_key'], 1);
        if (!$autoRegister) {
            abort(500, '该第三方账号尚未绑定本站账号，请先注册并在个人中心绑定');
        }
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, '当前已关闭注册');
        }

        return $this->createUserFromOauth($provider, $meta, $normalized, $tokenData, $profile, $inviteCode);
    }

    /**
     * 提取可用于「自动关联已有账号」的规范化邮箱；不可信时返回 null。
     */
    private function resolveTrustedProviderEmail(array $meta, array $normalized): ?string
    {
        if (empty($normalized['provider_email']) || !$this->isProviderEmailTrusted($meta, $normalized)) {
            return null;
        }

        $candidateEmail = strtolower(trim((string)$normalized['provider_email']));
        if ($candidateEmail === '' || !filter_var($candidateEmail, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        // 占位域不可作为关联依据
        if (preg_match('/@oauth\.local$/i', $candidateEmail)) {
            return null;
        }

        return $candidateEmail;
    }

    /**
     * 按可信邮箱查找本站用户：先匹配 v2_user.email，再匹配任意平台绑定的 provider_email。
     * 这样 GitHub / LinuxDo 等不同平台只要邮箱一致，都会落到同一账号。
     */
    private function findUserByTrustedEmail(string $trustedEmail): ?User
    {
        $existUser = User::whereRaw('LOWER(email) = ?', [$trustedEmail])->first();
        if ($existUser) {
            return $existUser;
        }

        $bindingWithEmail = UserOauth::whereRaw('LOWER(provider_email) = ?', [$trustedEmail])
            ->orderBy('id', 'asc')
            ->first();
        if (!$bindingWithEmail) {
            return null;
        }

        $user = User::find($bindingWithEmail->user_id);
        return $user ?: null;
    }

    /**
     * OAuth 自动注册用户仍是 @oauth.local 占位邮箱时，若后续用可信邮箱登录/绑定，
     * 自动升级为本站真实邮箱，便于后续各平台继续按邮箱合并。
     */
    private function maybeUpgradePlaceholderEmail(User $user, string $trustedEmail): void
    {
        if (!preg_match('/@oauth\.local$/i', (string)$user->email)) {
            return;
        }
        if (strlen($trustedEmail) > 64) {
            return;
        }
        if (User::whereRaw('LOWER(email) = ?', [$trustedEmail])->where('id', '!=', $user->id)->exists()) {
            return;
        }

        $user->email = $trustedEmail;
        self::syncOauthUserEmail((int)$user->id, $trustedEmail);
    }

    private function createUserFromOauth(
        string $provider,
        array $meta,
        array $normalized,
        array $tokenData,
        array $profile,
        string $inviteCode = ''
    ): User {
        // 与邮箱注册对齐：开启强制邀请码时，OAuth 自动注册必须提供有效邀请码
        $inviteUserId = $this->resolveInviteUserIdForRegistration($inviteCode);

        // 仅可信（已验证）第三方邮箱可写入本站账号；不可信则使用占位邮箱，引导用户后续绑定
        // v2_user.email / v2_oauth_user.email 均为 varchar(64)，必须严格控制长度
        $email = $this->resolveTrustedProviderEmail($meta, $normalized);
        if ($email !== null && strlen($email) > 64) {
            $email = null;
        }
        if ($email === null) {
            $email = $this->buildOauthPlaceholderEmail(
                $provider,
                (string)($normalized['provider_user_id'] ?? '')
            );
        }

        // 创建前再做一次邮箱合并兜底（并发下 findOrCreateUser 可能都未命中）
        if (!preg_match('/@oauth\.local$/i', $email)) {
            $existUser = $this->findUserByTrustedEmail(strtolower($email));
            if ($existUser) {
                if ($existUser->banned) {
                    abort(403, '您的账号已被停用');
                }
                $this->bindUser($existUser, $provider, $normalized, $tokenData, $profile);
                $this->maybeUpgradePlaceholderEmail($existUser, strtolower($email));
                $existUser->last_login_at = time();
                $existUser->save();
                return $existUser;
            }
        }

        if (User::whereRaw('LOWER(email) = ?', [strtolower($email)])->exists()) {
            $email = $this->buildOauthPlaceholderEmail(
                $provider,
                Helper::randomChar(16) . '_' . substr(md5((string)microtime(true)), 0, 8)
            );
            // 极端碰撞时再生成一次短哈希邮箱
            if (User::whereRaw('LOWER(email) = ?', [strtolower($email)])->exists()) {
                $email = substr(md5($provider . '|' . ($normalized['provider_user_id'] ?? '') . '|' . microtime(true)), 0, 32) . '@oauth.local';
            }
        }

        $user = new User();
        $user->email = $email;
        $user->password = password_hash(Str::random(32), PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->last_login_at = time();
        if ($inviteUserId !== null) {
            $user->invite_user_id = $inviteUserId;
        }

        if ((int)config('v2board.try_out_plan_id', 0)) {
            $plan = Plan::find(config('v2board.try_out_plan_id'));
            if ($plan) {
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->device_limit = $plan->device_limit;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->expired_at = time() + ((int)config('v2board.try_out_hour', 1) * 3600);
                $user->speed_limit = $plan->speed_limit;
            }
        }

        if (!$user->save()) {
            abort(500, '创建用户失败');
        }

        $this->bindUser($user, $provider, $normalized, $tokenData, $profile);

        // OAuth 自动注册的用户初始为随机密码，标记为「从未设置真实密码」，
        // 供前端展示「设置密码」（而非「修改密码」），并允许免旧密码设置。
        UserOauth::where('user_id', $user->id)->update(['password_never_set' => 1]);

        // 写入独立 OAuth 用户表：后台「用户管理」不再展示，改由「OAuth 管理」维护
        $this->createOauthUserRecord($user, $provider, $normalized);

        // 标记本次回调为新注册，供 handleCallback 返回 is_new，引导「完善信息」页。
        $this->lastUserWasCreated = true;

        return $user;
    }

    /**
     * 生成不超过 64 字符的 OAuth 占位邮箱（对齐 v2_user.email varchar(64)）。
     * 格式：{provider}_{id_or_hash}@oauth.local
     */
    private function buildOauthPlaceholderEmail(string $provider, string $providerUserId): string
    {
        $domainSuffix = '@oauth.local';
        $maxEmailLength = 64;
        $maxLocalPartLength = $maxEmailLength - strlen($domainSuffix);

        $providerPart = preg_replace('/[^a-zA-Z0-9_\-]/', '', $provider);
        if ($providerPart === null || $providerPart === '') {
            $providerPart = 'oauth';
        }
        // 平台名过长时压缩，保证后面还能放下 id/hash
        if (strlen($providerPart) > 16) {
            $providerPart = substr($providerPart, 0, 16);
        }

        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $providerUserId);
        if ($safeId === null || $safeId === '') {
            $safeId = substr(md5($provider . '|' . $providerUserId), 0, 16);
        }

        $localPrefix = $providerPart . '_';
        $idBudget = $maxLocalPartLength - strlen($localPrefix);
        if ($idBudget < 8) {
            // 极端情况：整段 local 用短哈希
            return substr(md5($provider . '|' . $providerUserId), 0, $maxLocalPartLength) . $domainSuffix;
        }

        if (strlen($safeId) > $idBudget) {
            // 超长第三方 ID（如 Microsoft OID）改为稳定短哈希，仍可与平台前缀区分
            $safeId = substr(hash('sha256', $provider . '|' . $providerUserId), 0, min(32, $idBudget));
        }

        $email = $localPrefix . $safeId . $domainSuffix;
        if (strlen($email) > $maxEmailLength) {
            $email = substr(md5($provider . '|' . $providerUserId), 0, 32) . $domainSuffix;
        }

        return $email;
    }

    /**
     * 将 OAuth 自动注册用户写入独立表 v2_oauth_user。
     * 邮箱用户后续绑定第三方不会走此逻辑，仍只出现在用户管理。
     */
    private function createOauthUserRecord(User $user, string $provider, array $normalized): void
    {
        self::ensureOauthUserTableExists();
        if (!Schema::hasTable('v2_oauth_user')) {
            return;
        }
        if (OauthUser::where('user_id', $user->id)->exists()) {
            return;
        }

        $oauthUser = new OauthUser();
        $oauthUser->user_id = $user->id;
        $oauthUser->email = $user->email;
        $oauthUser->primary_provider = $provider;
        $oauthUser->primary_provider_user_id = (string)($normalized['provider_user_id'] ?? '');
        $oauthUser->primary_provider_username = $normalized['provider_username'] ?? null;
        $oauthUser->primary_provider_email = $normalized['provider_email'] ?? null;
        $oauthUser->primary_provider_avatar = $this->sanitizeAvatarUrl($normalized['provider_avatar'] ?? null);
        $oauthUser->password_never_set = 1;
        $oauthUser->save();
    }

    /**
     * 兜底建表：正式环境应 migrate，本地/遗漏迁移时避免写表失败。
     */
    public static function ensureOauthUserTableExists(): void
    {
        if (Schema::hasTable('v2_oauth_user')) {
            return;
        }
        try {
            Schema::create('v2_oauth_user', function ($table) {
                $table->increments('id');
                $table->integer('user_id')->unique();
                $table->string('email', 64);
                $table->string('primary_provider', 32);
                $table->string('primary_provider_user_id', 128);
                $table->string('primary_provider_username', 128)->nullable();
                $table->string('primary_provider_email', 128)->nullable();
                $table->text('primary_provider_avatar')->nullable();
                $table->tinyInteger('password_never_set')->default(1);
                $table->text('remarks')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->unique(['primary_provider', 'primary_provider_user_id'], 'uniq_oauth_user_provider');
                $table->index('email');
                $table->index('primary_provider');
            });
        } catch (\Throwable $exception) {
            // 并发建表或权限不足时忽略，业务层再报错
        }
    }

    /**
     * 是否为 OAuth 独立用户表中的用户（不应出现在用户管理）。
     */
    public static function isOauthManagedUser(int $userId): bool
    {
        if ($userId <= 0 || !Schema::hasTable('v2_oauth_user')) {
            return false;
        }
        return OauthUser::where('user_id', $userId)->exists();
    }

    /**
     * 密码已设置时同步清除两处标记。
     */
    public static function markPasswordSet(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        if (Schema::hasTable('v2_user_oauth')) {
            UserOauth::where('user_id', $userId)->update(['password_never_set' => 0]);
        }
        if (Schema::hasTable('v2_oauth_user')) {
            OauthUser::where('user_id', $userId)->update(['password_never_set' => 0]);
        }
    }

    /**
     * 邮箱变更时同步独立表。
     */
    public static function syncOauthUserEmail(int $userId, string $email): void
    {
        if ($userId <= 0 || !Schema::hasTable('v2_oauth_user')) {
            return;
        }
        OauthUser::where('user_id', $userId)->update([
            'email' => $email,
            'updated_at' => time(),
        ]);
    }

    /**
     * 规范化邀请码输入（去空白）。
     */
    private function normalizeInviteCodeInput($inviteCode): string
    {
        if ($inviteCode === null) {
            return '';
        }
        return trim((string)$inviteCode);
    }

    /**
     * 解析 OAuth 自动注册使用的邀请关系，逻辑与 AuthController::register 对齐：
     * - invite_force=1：必须提供有效未使用邀请码
     * - invite_force=0：邀请码可选；无效则忽略
     * - invite_never_expire=0：使用后将邀请码 status 置为 1
     *
     * @return int|null 邀请人 user_id；无邀请关系时返回 null
     */
    private function resolveInviteUserIdForRegistration(string $inviteCode): ?int
    {
        $inviteForce = (int)config('v2board.invite_force', 0) === 1;

        if ($inviteCode === '') {
            if ($inviteForce) {
                abort(500, __('You must use the invitation code to register'));
            }
            return null;
        }

        $invite = InviteCode::where('code', $inviteCode)
            ->where('status', 0)
            ->first();

        if (!$invite) {
            if ($inviteForce) {
                abort(500, __('Invalid invitation code'));
            }
            return null;
        }

        if (!(int)config('v2board.invite_never_expire', 0)) {
            $invite->status = 1;
            $invite->save();
        }

        $inviteUserId = $invite->user_id ? (int)$invite->user_id : null;
        return $inviteUserId > 0 ? $inviteUserId : null;
    }

    private function bindUser(User $user, string $provider, array $normalized, array $tokenData, array $profile): void
    {
        $existOther = UserOauth::where('provider', $provider)
            ->where('provider_user_id', $normalized['provider_user_id'])
            ->where('user_id', '!=', $user->id)
            ->first();
        if ($existOther) {
            abort(500, '该第三方账号已绑定其他用户');
        }

        $binding = UserOauth::where('provider', $provider)
            ->where('user_id', $user->id)
            ->first();

        if (!$binding) {
            $binding = new UserOauth();
            $binding->user_id = $user->id;
            $binding->provider = $provider;
        }

        $this->updateBinding($binding, $normalized, $tokenData, $profile);
    }

    private function updateBinding(UserOauth $binding, array $normalized, array $tokenData, array $profile): void
    {
        $binding->provider_user_id = $normalized['provider_user_id'];
        $binding->provider_username = $normalized['provider_username'];
        $binding->provider_email = $normalized['provider_email'];
        // 头像 URL（尤其 Google）可能超长；列已放宽为 TEXT，但对未迁移的旧库仍做安全兜底截断，避免整体绑定失败。
        $binding->provider_avatar = $this->sanitizeAvatarUrl($normalized['provider_avatar']);
        $binding->access_token = $tokenData['access_token'] ?? null;
        $binding->refresh_token = $tokenData['refresh_token'] ?? null;
        $binding->raw = json_encode($profile, JSON_UNESCAPED_UNICODE);
        if (!$binding->save()) {
            abort(500, '保存第三方绑定失败');
        }

        // 若该用户属于 OAuth 独立用户，同步主平台资料（仅当平台与注册平台一致时更新外部 ID）
        if (Schema::hasTable('v2_oauth_user')) {
            $oauthUser = OauthUser::where('user_id', $binding->user_id)->first();
            if ($oauthUser && $oauthUser->primary_provider === $binding->provider) {
                $oauthUser->primary_provider_user_id = $normalized['provider_user_id'];
                $oauthUser->primary_provider_username = $normalized['provider_username'];
                $oauthUser->primary_provider_email = $normalized['provider_email'];
                $oauthUser->primary_provider_avatar = $this->sanitizeAvatarUrl($normalized['provider_avatar']);
                $oauthUser->save();
            }
        }
    }

    /**
     * 头像 URL 安全处理：
     * 迁移后头像列为 TEXT，正常无需截断；但对未执行迁移的旧库（varchar(512)），
     * 超长的 Google 头像 URL 会触发 SQLSTATE[22001] 导致整体绑定失败，
     * 因此这里做兜底截断，宁可头像略短也不要让绑定挂掉。
     */
    private function sanitizeAvatarUrl($avatar): ?string
    {
        if (!is_string($avatar) || $avatar === '') {
            return null;
        }
        // 上限给到 2048（TEXT 足够容纳），仅拦截异常超长值。
        if (mb_strlen($avatar) > 2048) {
            return mb_substr($avatar, 0, 2048);
        }
        return $avatar;
    }

    public function unbind(int $userId, string $provider): void
    {
        self::ensureTableExists();
        $meta = OauthProviderRegistry::get($provider);
        if (!$meta) {
            abort(500, '不支持的登录平台');
        }
        $bindings = UserOauth::where('user_id', $userId)->get();
        $binding = $bindings->first(function ($item) use ($provider) {
            return $item->provider === $provider;
        });
        if (!$binding) {
            abort(500, '当前账号未绑定该平台');
        }

        $passwordNeverSet = $bindings->contains(function ($item) {
            return (bool)$item->password_never_set;
        });
        if ($passwordNeverSet && $bindings->count() === 1) {
            abort(422, '请先设置登录密码，再解绑最后一个第三方账号');
        }

        DB::transaction(function () use ($bindings, $binding, $passwordNeverSet, $userId, $provider) {
            if ($passwordNeverSet && $binding->password_never_set) {
                $replacement = $bindings->first(function ($item) use ($binding) {
                    return $item->id !== $binding->id;
                });
                if ($replacement) {
                    $replacement->password_never_set = 1;
                    $replacement->save();
                }
            }
            $binding->delete();

            // 解绑 Telegram 时同步清空历史 telegram_id，避免两套状态不一致
            if ($provider === 'telegram') {
                User::where('id', $userId)->update(['telegram_id' => null]);
            }
        });
    }

    public function listBindings(int $userId): array
    {
        self::ensureTableExists();
        $rows = UserOauth::where('user_id', $userId)->get();
        $result = [];
        foreach ($rows as $row) {
            $meta = OauthProviderRegistry::get($row->provider);
            $result[] = [
                'provider' => $row->provider,
                'name' => $meta['name'] ?? $row->provider,
                'provider_user_id' => $row->provider_user_id,
                'provider_username' => $row->provider_username,
                'provider_email' => $row->provider_email,
                'provider_avatar' => $row->provider_avatar,
                'created_at' => $row->created_at,
            ];
        }
        return $result;
    }

    public function createLoginRedirect(User $user, ?string $redirect = 'dashboard', array $extraQuery = []): string
    {
        $code = Helper::guid();
        Cache::put(CacheKey::get('TEMP_TOKEN', $code), $user->id, 120);
        $path = '/#/login?verify=' . $code . '&redirect=' . ($redirect ?: 'dashboard');
        // 追加额外查询参数（如 oauth_setup=1，用于引导「完善信息」页）
        if (!empty($extraQuery)) {
            $path .= '&' . http_build_query($extraQuery);
        }
        if (config('v2board.app_url')) {
            return rtrim(config('v2board.app_url'), '/') . $path;
        }
        return url($path);
    }
}
