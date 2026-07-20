<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfigSave;
use App\Jobs\SendEmailJob;
use App\Services\Oauth\OauthProviderRegistry;
use App\Services\TelegramService;
use App\Support\AtomicConfigWriter;
use App\Support\ConfiguredUrl;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class ConfigController extends Controller
{
    public function getEmailTemplate()
    {
        $path = resource_path('views/mail/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return response([
            'data' => $files
        ]);
    }

    public function getThemeTemplate()
    {
        $path = public_path('theme/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return response([
            'data' => $files
        ]);
    }

    public function testSendMail(Request $request)
    {
        $obj = new SendEmailJob([
            'email' => $request->user['email'],
            'subject' => 'This is v2board test email',
            'template_name' => 'notify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'content' => 'This is v2board test email',
                'url' => ConfiguredUrl::applicationUrl()
            ]
        ]);
        return response([
            'data' => true,
            'log' => $obj->handle()
        ]);
    }

    public function setTelegramWebhook(Request $request)
    {
        $request->validate([
            'telegram_bot_token' => 'nullable|string|max:255',
        ]);

        $submittedToken = trim((string)$request->input('telegram_bot_token', ''));
        $botToken = $submittedToken !== ''
            ? $submittedToken
            : trim((string)config('v2board.telegram_bot_token', ''));
        if ($botToken === '') {
            abort(422, '请先配置 Telegram Bot Token');
        }

        // A webhook must use a configured HTTPS origin, never the request Host header.
        $applicationUrl = ConfiguredUrl::applicationUrl();
        if ($applicationUrl === '' || stripos($applicationUrl, 'https://') !== 0) {
            abort(422, '请先配置有效的 HTTPS 站点 URL');
        }

        $telegramService = new TelegramService($botToken);
        $telegramService->getMe();

        $webhookSecret = $this->ensureTelegramWebhookSecret();
        $hookUrl = rtrim($applicationUrl, '/') . '/api/v1/guest/telegram/webhook';
        $telegramService->setWebhook($hookUrl, $webhookSecret);

        return response([
            'data' => true
        ]);
    }

    public function fetch(Request $request)
    {
        $key = $request->input('key');
        $data = [
            'ticket' => [
                'ticket_status' => config('v2board.ticket_status', 0)
            ],
            'deposit' => [
                'deposit_bounus' => config('v2board.deposit_bounus', [])
            ],
            'invite' => [
                'invite_force' => (int)config('v2board.invite_force', 0),
                'invite_commission' => config('v2board.invite_commission', 10),
                'invite_gen_limit' => config('v2board.invite_gen_limit', 5),
                'invite_never_expire' => config('v2board.invite_never_expire', 0),
                'commission_first_time_enable' => config('v2board.commission_first_time_enable', 1),
                'commission_auto_check_enable' => config('v2board.commission_auto_check_enable', 1),
                'commission_withdraw_limit' => config('v2board.commission_withdraw_limit', 100),
                'commission_withdraw_method' => config('v2board.commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT),
                'withdraw_close_enable' => config('v2board.withdraw_close_enable', 0),
                'commission_distribution_enable' => config('v2board.commission_distribution_enable', 0),
                'commission_distribution_l1' => config('v2board.commission_distribution_l1'),
                'commission_distribution_l2' => config('v2board.commission_distribution_l2'),
                'commission_distribution_l3' => config('v2board.commission_distribution_l3')
            ],
            'site' => [
                'logo' => ConfiguredUrl::normalizeExternalHttpUrl(config('v2board.logo')),
                'force_https' => (int)config('v2board.force_https', 0),
                'stop_register' => (int)config('v2board.stop_register', 0),
                'app_name' => config('v2board.app_name', 'V2Board'),
                'app_description' => config('v2board.app_description', 'V2Board is best!'),
                'app_url' => ConfiguredUrl::applicationUrl(),
                'subscribe_url' => config('v2board.subscribe_url'),
                'subscribe_path' => config('v2board.subscribe_path'),
                'try_out_plan_id' => (int)config('v2board.try_out_plan_id', 0),
                'try_out_hour' => (int)config('v2board.try_out_hour', 1),
                'tos_url' => ConfiguredUrl::normalizeExternalHttpUrl(config('v2board.tos_url')),
                'currency' => config('v2board.currency', 'CNY'),
                'currency_symbol' => config('v2board.currency_symbol', '¥'),
            ],
            'subscribe' => [
                'plan_change_enable' => (int)config('v2board.plan_change_enable', 1),
                'reset_traffic_method' => (int)config('v2board.reset_traffic_method', 0),
                'surplus_enable' => (int)config('v2board.surplus_enable', 1),
                'allow_new_period' => (int)config('v2board.allow_new_period', 0),
                'new_order_event_id' => (int)config('v2board.new_order_event_id', 0),
                'renew_order_event_id' => (int)config('v2board.renew_order_event_id', 0),
                'change_order_event_id' => (int)config('v2board.change_order_event_id', 0),
                'show_info_to_server_enable' => (int)config('v2board.show_info_to_server_enable', 0),
                'show_subscribe_method' => (int)config('v2board.show_subscribe_method', 0),
                'show_subscribe_expire' => (int)config('v2board.show_subscribe_expire', 5),
            ],
            'frontend' => [
                'frontend_theme' => config('v2board.frontend_theme', 'v2board'),
                'frontend_theme_sidebar' => config('v2board.frontend_theme_sidebar', 'light'),
                'frontend_theme_header' => config('v2board.frontend_theme_header', 'dark'),
                'frontend_theme_color' => config('v2board.frontend_theme_color', 'default'),
                'frontend_background_url' => ConfiguredUrl::normalizeExternalHttpUrl(config('v2board.frontend_background_url')),
            ],
            'server' => [
                'server_api_url' => ConfiguredUrl::normalizeExternalHttpUrl(config('v2board.server_api_url')),
                'server_token' => '',
                'server_token_configured' => $this->isSecretConfigured('server_token'),
                'server_token_allow_legacy' => (int)config('v2board.server_token_allow_legacy', 1),
                'server_pull_interval' => config('v2board.server_pull_interval', 60),
                'server_push_interval' => config('v2board.server_push_interval', 60),
                'server_node_report_min_traffic' => config('v2board.server_node_report_min_traffic', 0),
                'server_device_online_min_traffic' => config('v2board.server_device_online_min_traffic', 0),
                'device_limit_mode' => config('v2board.device_limit_mode', 0)
            ],
            'email' => [
                'email_template' => config('v2board.email_template', 'default'),
                'email_host' => config('v2board.email_host'),
                'email_port' => config('v2board.email_port'),
                'email_username' => config('v2board.email_username'),
                'email_password' => '',
                'email_password_configured' => $this->isSecretConfigured('email_password'),
                'email_encryption' => config('v2board.email_encryption'),
                'email_from_address' => config('v2board.email_from_address'),
                // 与安全页「邮箱验证」配合：开启验证后，注册使用验证码或邮件链接
                'register_email_mode' => config('v2board.register_email_mode', 'code') === 'link' ? 'link' : 'code',
            ],
            'telegram' => [
                'telegram_bot_enable' => config('v2board.telegram_bot_enable', 0),
                'telegram_bot_token' => '',
                'telegram_bot_token_configured' => $this->isSecretConfigured('telegram_bot_token'),
                'telegram_discuss_link' => ConfiguredUrl::normalizeExternalHttpUrl(config('v2board.telegram_discuss_link'))
            ],
            'app' => [
                'windows_version' => config('v2board.windows_version'),
                'windows_download_url' => ConfiguredUrl::normalizeExternalHttpUrl(config('v2board.windows_download_url')),
                'macos_version' => config('v2board.macos_version'),
                'macos_download_url' => ConfiguredUrl::normalizeExternalHttpUrl(config('v2board.macos_download_url')),
                'android_version' => config('v2board.android_version'),
                'android_download_url' => ConfiguredUrl::normalizeExternalHttpUrl(config('v2board.android_download_url'))
            ],
            'safe' => [
                'email_verify' => (int)config('v2board.email_verify', 0),
                'safe_mode_enable' => (int)config('v2board.safe_mode_enable', 0),
                'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))),
                'email_whitelist_enable' => (int)config('v2board.email_whitelist_enable', 0),
                'email_whitelist_suffix' => config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT),
                'email_gmail_limit_enable' => config('v2board.email_gmail_limit_enable', 0),
                'recaptcha_enable' => (int)config('v2board.recaptcha_enable', 0),
                'recaptcha_key' => '',
                'recaptcha_key_configured' => $this->isSecretConfigured('recaptcha_key'),
                'recaptcha_site_key' => config('v2board.recaptcha_site_key'),
                'register_limit_by_ip_enable' => (int)config('v2board.register_limit_by_ip_enable', 0),
                'register_limit_count' => config('v2board.register_limit_count', 3),
                'register_limit_expire' => config('v2board.register_limit_expire', 60),
                'password_limit_enable' => (int)config('v2board.password_limit_enable', 1),
                'password_limit_count' => config('v2board.password_limit_count', 5),
                'password_limit_expire' => config('v2board.password_limit_expire', 60)
            ],
            // 登录设置：统一管理第三方登录（可继续扩展 GitHub / Google 等）
            'login' => [
                'providers' => OauthProviderRegistry::adminProviderList(),
            ]
        ];
        if ($key && isset($data[$key])) {
            return response([
                'data' => [
                    $key => $data[$key]
                ]
            ]);
        };
        // TODO: default should be in Dict
        return response([
            'data' => $data
        ]);
    }

    public function save(ConfigSave $request)
    {
        $data = $request->validated();
        $changes = [];
        foreach (ConfigSave::allRules() as $k => $v) {
            if (array_key_exists($k, $data)) {
                $changes[$k] = $data[$k];
            }
        }
        $changes = $this->preserveExistingSecrets($changes);

        try {
            $config = AtomicConfigWriter::updateArray(
                base_path('config/v2board.php'),
                $changes,
                (array)config('v2board', [])
            );
        } catch (\Throwable $error) {
            report($error);
            abort(500, '修改失败');
        }

        Config::set('v2board', $config);
        if (Cache::has('WEBMANPID')) {
            $pid = Cache::pull('WEBMANPID');
            if (function_exists('posix_kill') && is_numeric($pid) && (int)$pid > 1) {
            return response([
                    'data' => posix_kill((int)$pid, 15)
            ]);
            }
        }

        return response([
            'data' => true
        ]);
    }

    private function ensureTelegramWebhookSecret(): string
    {
        $secret = trim((string)config('v2board.telegram_webhook_secret', ''));
        if (preg_match('/\A[A-Za-z0-9_-]{1,256}\z/', $secret)) {
            return $secret;
        }

        $secret = bin2hex(random_bytes(32));
        try {
            $config = AtomicConfigWriter::updateArray(
                base_path('config/v2board.php'),
                ['telegram_webhook_secret' => $secret],
                (array)config('v2board', [])
            );
        } catch (\Throwable $error) {
            report($error);
            abort(500, '无法保存 Telegram Webhook 密钥');
        }

        Config::set('v2board', $config);
        return $secret;
    }

    private function preserveExistingSecrets(array $changes): array
    {
        foreach ($this->secretConfigKeys() as $key) {
            if (!array_key_exists($key, $changes)) {
                continue;
            }

            $submitted = $changes[$key];
            if ($submitted === null || (is_string($submitted) && trim($submitted) === '')) {
                $changes[$key] = config('v2board.' . $key);
            }
        }

        return $changes;
    }

    private function secretConfigKeys(): array
    {
        $keys = [
            'server_token',
            'email_password',
            'telegram_bot_token',
            'recaptcha_key',
        ];

        foreach (OauthProviderRegistry::all() as $meta) {
            foreach (['client_secret_key', 'bot_token_key'] as $metaKey) {
                if (!empty($meta[$metaKey])) {
                    $keys[] = $meta[$metaKey];
                }
            }
        }

        return array_values(array_unique($keys));
    }

    private function isSecretConfigured(string $key): bool
    {
        return trim((string)config('v2board.' . $key, '')) !== '';
    }
}
