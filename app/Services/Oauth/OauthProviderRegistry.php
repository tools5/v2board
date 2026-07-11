<?php

namespace App\Services\Oauth;

/**
 * 第三方登录平台注册表
 * 后续新增 GitHub / Google 等，只需在此增加配置项即可
 */
class OauthProviderRegistry
{
    /**
     * @return array<string, array>
     */
    public static function all(): array
    {
        return [
            'linuxdo' => [
                'key' => 'linuxdo',
                'name' => 'LinuxDo Connect',
                'description' => '通过 Linux.do Connect（OpenID Connect）登录',
                'docs_url' => 'https://wiki.linux.do/Community/LinuxDoConnect',
                'authorize_url' => 'https://connect.linux.do/oauth2/authorize',
                'token_url' => 'https://connect.linux.do/oauth2/token',
                'userinfo_url' => 'https://connect.linux.do/api/user',
                'scopes' => 'openid profile email',
                'enable_key' => 'login_linuxdo_enable',
                'client_id_key' => 'login_linuxdo_client_id',
                'client_secret_key' => 'login_linuxdo_client_secret',
                'auto_register_key' => 'login_linuxdo_auto_register',
                'min_trust_level_key' => 'login_linuxdo_min_trust_level',
                'callback_url_key' => 'login_linuxdo_callback_url',
                'button_text' => '使用 Linux.do 登录',
                'button_color' => '#222222',
                // Linux.do Connect 侧账号必须完成邮箱验证才能授权，
                // 但其 userinfo 不一定回传 email_verified 字段，
                // 因此在缺失该字段时可信任其邮箱用于自动关联已有账号。
                'email_trusted_when_unknown' => true,
            ],
            'github' => [
                'key' => 'github',
                'name' => 'GitHub',
                'description' => '通过 GitHub OAuth 登录',
                'docs_url' => 'https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps',
                'authorize_url' => 'https://github.com/login/oauth/authorize',
                'token_url' => 'https://github.com/login/oauth/access_token',
                'userinfo_url' => 'https://api.github.com/user',
                'scopes' => 'read:user user:email',
                'enable_key' => 'login_github_enable',
                'client_id_key' => 'login_github_client_id',
                'client_secret_key' => 'login_github_client_secret',
                'auto_register_key' => 'login_github_auto_register',
                // GitHub 无信任等级概念
                'min_trust_level_key' => null,
                'callback_url_key' => 'login_github_callback_url',
                'button_text' => '使用 GitHub 登录',
                'button_color' => '#24292f',
                // GitHub 的 /user 接口不一定回传邮箱，需额外调用 /user/emails，
                // 我们只信任其中 verified=true 的主邮箱，因此这里不做无条件信任。
                'email_trusted_when_unknown' => false,
                // GitHub API 强制要求携带 User-Agent，否则会返回 403
                'userinfo_headers' => [
                    'Accept' => 'application/vnd.github+json',
                ],
            ],
            'google' => [
                'key' => 'google',
                'name' => 'Google',
                'description' => '通过 Google OAuth（OpenID Connect）登录',
                'docs_url' => 'https://developers.google.com/identity/protocols/oauth2',
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
                'scopes' => 'openid profile email',
                'enable_key' => 'login_google_enable',
                'client_id_key' => 'login_google_client_id',
                'client_secret_key' => 'login_google_client_secret',
                'auto_register_key' => 'login_google_auto_register',
                'min_trust_level_key' => null,
                'callback_url_key' => 'login_google_callback_url',
                'button_text' => '使用 Google 登录',
                'button_color' => '#ea4335',
                // Google 的 userinfo 会返回 email_verified 字段，按该字段判断即可，
                // 不做无条件信任。
                'email_trusted_when_unknown' => false,
                // Google 需要额外授权参数确保拿到 refresh_token / 每次都显示账号选择。
                'extra_authorize_params' => [
                    'access_type' => 'online',
                    'prompt' => 'select_account',
                ],
            ],
            'microsoft' => [
                'key' => 'microsoft',
                'name' => 'Microsoft',
                'description' => '通过 Microsoft 账号（Azure AD / OpenID Connect）登录',
                'docs_url' => 'https://learn.microsoft.com/azure/active-directory/develop/v2-oauth2-auth-code-flow',
                // 使用 common 端点，个人 Microsoft 账号与组织账号均可登录
                'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                'userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo',
                'scopes' => 'openid profile email',
                'enable_key' => 'login_microsoft_enable',
                'client_id_key' => 'login_microsoft_client_id',
                'client_secret_key' => 'login_microsoft_client_secret',
                'auto_register_key' => 'login_microsoft_auto_register',
                'min_trust_level_key' => null,
                'callback_url_key' => 'login_microsoft_callback_url',
                'button_text' => '使用 Microsoft 登录',
                'button_color' => '#2f2f2f',
                // preferred_username / UPN 不是稳定的已验证邮箱声明，
                // 缺少 email_verified 时禁止用它自动关联已有本站账号。
                'email_trusted_when_unknown' => false,
                // 要求返回 id_token + code，并强制选择账号
                'extra_authorize_params' => [
                    'response_mode' => 'query',
                    'prompt' => 'select_account',
                ],
            ],
            // Telegram Login Widget：非 OAuth2，走 auth_type=telegram_login_widget
            'telegram' => [
                'key' => 'telegram',
                'name' => 'Telegram',
                'description' => '通过 Telegram Login Widget 登录（非 OAuth2 授权码流程）',
                'docs_url' => 'https://core.telegram.org/widgets/login',
                'auth_type' => 'telegram_login_widget',
                // Widget 模式不使用 authorize/token/userinfo
                'authorize_url' => null,
                'token_url' => null,
                'userinfo_url' => null,
                'scopes' => null,
                'enable_key' => 'login_telegram_enable',
                // 登录专用 Bot Token；为空时可回退系统 telegram_bot_token
                'bot_token_key' => 'login_telegram_bot_token',
                'bot_token_fallback_key' => 'telegram_bot_token',
                'bot_username_key' => 'login_telegram_bot_username',
                'auto_register_key' => 'login_telegram_auto_register',
                'min_trust_level_key' => null,
                'callback_url_key' => null,
                'client_id_key' => null,
                'client_secret_key' => null,
                'button_text' => '使用 Telegram 登录',
                'button_color' => '#0088cc',
                // Telegram 不提供邮箱
                'email_trusted_when_unknown' => false,
                // Widget 数据默认最长接受 1 天，防止重放
                'auth_max_age_seconds' => 86400,
            ],
        ];
    }

    public static function get(string $provider): ?array
    {
        $all = self::all();
        return $all[$provider] ?? null;
    }

    /**
     * 按当前站点生成默认回调地址。
     */
    public static function defaultCallbackUrl(string $provider): string
    {
        $path = '/api/v1/passport/auth/oauth/callback?provider=' . urlencode($provider);
        $appUrl = rtrim((string)config('v2board.app_url', ''), '/');

        return $appUrl !== '' ? $appUrl . $path : url($path);
    }

    /**
     * 实际使用的回调地址：优先用管理员自定义值，为空则回退到默认生成值。
     */
    public static function effectiveCallbackUrl(string $provider): string
    {
        $meta = self::get($provider);
        if ($meta && isset($meta['callback_url_key'])) {
            $custom = trim((string)config('v2board.' . $meta['callback_url_key'], ''));
            if ($custom !== '') {
                return $custom;
            }
        }
        return self::defaultCallbackUrl($provider);
    }

    /**
     * 协议类型：oauth2（默认）或 telegram_login_widget
     */
    public static function authType(string $provider): string
    {
        $meta = self::get($provider);
        if (!$meta) {
            return 'oauth2';
        }
        return (string)($meta['auth_type'] ?? 'oauth2');
    }

    public static function isTelegramWidget(string $provider): bool
    {
        return self::authType($provider) === 'telegram_login_widget';
    }

    /**
     * 解析 Telegram 登录用的 Bot Token：
     * 优先 login_telegram_bot_token，为空再回退系统 telegram_bot_token。
     */
    public static function resolveBotToken(string $provider): string
    {
        $meta = self::get($provider);
        if (!$meta) {
            return '';
        }
        $primaryKey = $meta['bot_token_key'] ?? null;
        if ($primaryKey) {
            $primary = trim((string)config('v2board.' . $primaryKey, ''));
            if ($primary !== '') {
                return $primary;
            }
        }
        $fallbackKey = $meta['bot_token_fallback_key'] ?? null;
        if ($fallbackKey) {
            return trim((string)config('v2board.' . $fallbackKey, ''));
        }
        return '';
    }

    /**
     * 从请求输入 + 现有配置中解析 Bot Token（保存校验用）。
     * 请求里显式提交空字符串表示“清空登录专用 token，依赖回退”。
     */
    public static function resolveBotTokenFromInput(string $provider, array $input): string
    {
        $meta = self::get($provider);
        if (!$meta) {
            return '';
        }
        $primaryKey = $meta['bot_token_key'] ?? null;
        if ($primaryKey && array_key_exists($primaryKey, $input)) {
            $submitted = trim((string)$input[$primaryKey]);
            if ($submitted !== '') {
                return $submitted;
            }
            // 显式清空登录专用 token：看回退
            $fallbackKey = $meta['bot_token_fallback_key'] ?? null;
            if ($fallbackKey) {
                return trim((string)config('v2board.' . $fallbackKey, ''));
            }
            return '';
        }
        return self::resolveBotToken($provider);
    }

    public static function isEnabled(string $provider): bool
    {
        $meta = self::get($provider);
        if (!$meta) {
            return false;
        }
        if (!(int)config('v2board.' . $meta['enable_key'], 0)) {
            return false;
        }

        if (self::isTelegramWidget($provider)) {
            $usernameKey = $meta['bot_username_key'] ?? null;
            $username = $usernameKey
                ? trim((string)config('v2board.' . $usernameKey, ''))
                : '';
            return $username !== '' && self::resolveBotToken($provider) !== '';
        }

        $clientIdKey = $meta['client_id_key'] ?? null;
        $clientSecretKey = $meta['client_secret_key'] ?? null;
        if (!$clientIdKey || !$clientSecretKey) {
            return false;
        }
        $clientId = (string)config('v2board.' . $clientIdKey, '');
        $clientSecret = (string)config('v2board.' . $clientSecretKey, '');
        return $clientId !== '' && $clientSecret !== '';
    }

    /**
     * 前端可展示的已启用平台列表（不含密钥）
     *
     * @return array<int, array>
     */
    public static function enabledPublicList(): array
    {
        $list = [];
        foreach (self::all() as $key => $meta) {
            if (!self::isEnabled($key)) {
                continue;
            }
            $authType = self::authType($key);
            $item = [
                'provider' => $key,
                'name' => $meta['name'],
                'button_text' => $meta['button_text'],
                'button_color' => $meta['button_color'],
                'auth_type' => $authType,
            ];
            if ($authType === 'telegram_login_widget') {
                $usernameKey = $meta['bot_username_key'] ?? null;
                $username = $usernameKey
                    ? ltrim(trim((string)config('v2board.' . $usernameKey, '')), '@')
                    : '';
                $item['bot_username'] = $username;
                $item['redirect_url'] = null;
            } else {
                $item['redirect_url'] = url('/api/v1/passport/auth/oauth/redirect?provider=' . urlencode($key));
            }
            $list[] = $item;
        }
        return $list;
    }

    /**
     * 后台登录设置页展示用的完整平台元数据（不含密钥明文需求，密钥单独读配置）
     *
     * @return array<int, array>
     */
    public static function adminProviderList(): array
    {
        $list = [];
        foreach (self::all() as $key => $meta) {
            $authType = self::authType($key);
            $clientSecretKey = $meta['client_secret_key'] ?? null;
            $clientSecret = $clientSecretKey
                ? (string)config('v2board.' . $clientSecretKey, '')
                : '';
            $botTokenKey = $meta['bot_token_key'] ?? null;
            $botTokenConfigured = $botTokenKey
                ? trim((string)config('v2board.' . $botTokenKey, '')) !== ''
                : false;
            $botTokenFallbackConfigured = !empty($meta['bot_token_fallback_key'])
                && trim((string)config('v2board.' . $meta['bot_token_fallback_key'], '')) !== '';
            $minTrustLevelKey = $meta['min_trust_level_key'] ?? null;
            $botUsernameKey = $meta['bot_username_key'] ?? null;

            $list[] = [
                'provider' => $key,
                'name' => $meta['name'],
                'description' => $meta['description'],
                'docs_url' => $meta['docs_url'] ?? '',
                'auth_type' => $authType,
                'enable' => (int)config('v2board.' . $meta['enable_key'], 0),
                'client_id' => !empty($meta['client_id_key'])
                    ? (string)config('v2board.' . $meta['client_id_key'], '')
                    : '',
                // 密钥不回显到浏览器；空值表示保持现有密钥。
                'client_secret' => '',
                'client_secret_configured' => trim($clientSecret) !== '',
                'bot_username' => $botUsernameKey
                    ? ltrim(trim((string)config('v2board.' . $botUsernameKey, '')), '@')
                    : '',
                'bot_token' => '',
                'bot_token_configured' => $botTokenConfigured,
                'bot_token_fallback_configured' => $botTokenFallbackConfigured,
                'auto_register' => (int)config('v2board.' . $meta['auto_register_key'], 1),
                'min_trust_level' => $minTrustLevelKey
                    ? (int)config('v2board.' . $minTrustLevelKey, 0)
                    : null,
                'callback_url' => isset($meta['callback_url_key']) && $meta['callback_url_key']
                    ? (string)config('v2board.' . $meta['callback_url_key'], '')
                    : '',
                'default_callback_url' => $authType === 'oauth2'
                    ? self::defaultCallbackUrl($key)
                    : '',
                'fields' => [
                    'enable_key' => $meta['enable_key'],
                    'client_id_key' => $meta['client_id_key'] ?? null,
                    'client_secret_key' => $meta['client_secret_key'] ?? null,
                    'bot_token_key' => $botTokenKey,
                    'bot_username_key' => $botUsernameKey,
                    'auto_register_key' => $meta['auto_register_key'],
                    'min_trust_level_key' => $meta['min_trust_level_key'] ?? null,
                    'callback_url_key' => $meta['callback_url_key'] ?? null,
                ],
                'visible_when' => [
                    'field' => 'enable_key',
                    'operator' => 'equals',
                    'value' => 1,
                ],
            ];
        }
        return $list;
    }

    /**
     * 第三方登录配置的保存校验规则。
     * 新增提供商时，保存白名单会自动跟随注册表。
     *
     * @return array<string, mixed>
     */
    public static function configRules(): array
    {
        $rules = [];
        foreach (self::all() as $provider => $meta) {
            $rules[$meta['enable_key']] = 'sometimes|in:0,1';
            $rules[$meta['auto_register_key']] = 'sometimes|in:0,1';

            if (!empty($meta['client_id_key'])) {
                $rules[$meta['client_id_key']] = 'nullable|string';
            }
            if (!empty($meta['client_secret_key'])) {
                $rules[$meta['client_secret_key']] = 'nullable|string';
            }
            if (!empty($meta['bot_username_key'])) {
                $rules[$meta['bot_username_key']] = 'nullable|string|max:64|regex:/^@?[A-Za-z0-9_]{5,32}$/';
            }
            if (!empty($meta['bot_token_key'])) {
                $rules[$meta['bot_token_key']] = 'nullable|string|max:255';
            }

            if (!empty($meta['min_trust_level_key'])) {
                $rules[$meta['min_trust_level_key']] = 'nullable|integer|min:0|max:4';
            }

            if (!empty($meta['callback_url_key'])) {
                $rules[$meta['callback_url_key']] = [
                    'nullable',
                    'url',
                    function ($attribute, $value, $fail) use ($provider) {
                        if (!self::isValidCallbackUrl($provider, (string)$value)) {
                            $fail('OAuth 回调地址必须使用 http(s)，指向本站回调路径并携带正确的 provider 参数。');
                        }
                    },
                ];
            }
        }

        return $rules;
    }

    /**
     * 返回当前输入中缺少的必填凭据键。
     * 未出现在请求中的密钥表示保持已有配置。
     *
     * @return array<int, string>
     */
    public static function missingCredentialKeys(string $provider, array $input): array
    {
        $meta = self::get($provider);
        if (!$meta) {
            return [];
        }

        $missing = [];

        if (self::isTelegramWidget($provider)) {
            $usernameKey = $meta['bot_username_key'] ?? null;
            if ($usernameKey) {
                $username = array_key_exists($usernameKey, $input)
                    ? $input[$usernameKey]
                    : config('v2board.' . $usernameKey, '');
                $username = ltrim(trim((string)$username), '@');
                if ($username === '') {
                    $missing[] = $usernameKey;
                }
            }
            if (self::resolveBotTokenFromInput($provider, $input) === '') {
                // 优先报登录专用 token 字段，方便后台提示
                $missing[] = $meta['bot_token_key'] ?? 'login_telegram_bot_token';
            }
            return $missing;
        }

        foreach (['client_id_key', 'client_secret_key'] as $metaKey) {
            $configKey = $meta[$metaKey] ?? null;
            if (!$configKey) {
                continue;
            }
            $value = array_key_exists($configKey, $input)
                ? $input[$configKey]
                : config('v2board.' . $configKey, '');
            if (trim((string)$value) === '') {
                $missing[] = $configKey;
            }
        }

        return $missing;
    }

    public static function isValidCallbackUrl(string $provider, string $url): bool
    {
        if ($url === '') {
            return true;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return false;
        }

        $expectedPath = '/api/v1/passport/auth/oauth/callback';
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        if (substr($path, -strlen($expectedPath)) !== $expectedPath) {
            return false;
        }

        $query = [];
        parse_str((string)($parts['query'] ?? ''), $query);

        return isset($query['provider']) && hash_equals($provider, (string)$query['provider']);
    }
}
