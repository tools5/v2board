<?php

namespace App\Services;

use App\Models\Notice;
use App\Models\User;
use App\Models\WebPushSubscription as WebPushSubscriptionModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    /**
     * Resolved runtime settings.
     * Priority: admin-saved v2board.php values (when present) > .env / config/webpush.php.
     */
    public function getSettings()
    {
        $board = config('v2board', []);
        $envConfig = config('webpush', []);

        $enabled = $this->resolveBool(
            $board,
            'web_push_enabled',
            (bool)($envConfig['enabled'] ?? false)
        );

        $subject = $this->resolveString(
            $board,
            'web_push_vapid_subject',
            (string)($envConfig['vapid']['subject'] ?? '')
        );
        $subject = $this->normalizeVapidSubject($subject);

        $publicKey = $this->resolveString(
            $board,
            'web_push_public_key',
            (string)($envConfig['vapid']['public_key'] ?? '')
        );
        $privateKey = $this->resolveString(
            $board,
            'web_push_private_key',
            (string)($envConfig['vapid']['private_key'] ?? '')
        );

        $ttl = $this->resolveInt(
            $board,
            'web_push_ttl',
            (int)($envConfig['ttl'] ?? 86400),
            0,
            604800
        );
        $urgency = $this->resolveString(
            $board,
            'web_push_urgency',
            (string)($envConfig['urgency'] ?? 'normal')
        );
        if (!in_array($urgency, ['very-low', 'low', 'normal', 'high'], true)) {
            $urgency = 'normal';
        }

        $batchSize = $this->resolveInt(
            $board,
            'web_push_batch_size',
            (int)($envConfig['batch_size'] ?? 500),
            1,
            5000
        );
        $requestTimeout = $this->resolveInt(
            $board,
            'web_push_request_timeout',
            (int)($envConfig['request_timeout'] ?? 30),
            1,
            120
        );

        $proxy = $this->resolveString(
            $board,
            'web_push_proxy',
            (string)($envConfig['proxy'] ?? '')
        );
        $caBundle = $this->resolveString(
            $board,
            'web_push_ca_bundle',
            (string)($envConfig['ca_bundle'] ?? '')
        );

        $remindExpireEnabled = $this->resolveBool(
            $board,
            'web_push_remind_expire',
            (bool)($envConfig['remind']['expire_enabled'] ?? true)
        );
        $remindTrafficEnabled = $this->resolveBool(
            $board,
            'web_push_remind_traffic',
            (bool)($envConfig['remind']['traffic_enabled'] ?? true)
        );

        $expireDaysRaw = $this->resolveString(
            $board,
            'web_push_remind_expire_days',
            is_array($envConfig['remind']['expire_days'] ?? null)
                ? implode(',', $envConfig['remind']['expire_days'])
                : '3,1,0'
        );
        $expireDays = $this->parseExpireDays($expireDaysRaw);

        $trafficPercent = $this->resolveInt(
            $board,
            'web_push_remind_traffic_percent',
            (int)($envConfig['remind']['traffic_percent'] ?? 95),
            1,
            99
        );

        $expireUrl = $this->resolveString(
            $board,
            'web_push_remind_expire_url',
            (string)($envConfig['remind']['expire_url'] ?? '')
        );
        $trafficUrl = $this->resolveString(
            $board,
            'web_push_remind_traffic_url',
            (string)($envConfig['remind']['traffic_url'] ?? '')
        );

        return [
            'enabled' => $enabled,
            'vapid_subject' => $subject,
            'public_key' => $publicKey,
            'private_key' => $privateKey,
            'ttl' => $ttl,
            'urgency' => $urgency,
            'batch_size' => $batchSize,
            'request_timeout' => $requestTimeout,
            'proxy' => $proxy,
            'ca_bundle' => $caBundle,
            'remind_expire' => $remindExpireEnabled,
            'remind_traffic' => $remindTrafficEnabled,
            'remind_expire_days' => implode(',', $expireDays),
            'remind_expire_days_list' => $expireDays,
            'remind_traffic_percent' => $trafficPercent,
            'remind_expire_url' => $expireUrl,
            'remind_traffic_url' => $trafficUrl,
            'source' => $this->detectConfigSource($board),
        ];
    }

    public function isConfigured()
    {
        $settings = $this->getSettings();

        return (bool)$settings['enabled']
            && (string)$settings['vapid_subject'] !== ''
            && (string)$settings['public_key'] !== ''
            && (string)$settings['private_key'] !== '';
    }

    /**
     * Persist Web Push settings into config/v2board.php (same path as system settings).
     */
    public function saveSettings(array $input)
    {
        $current = $this->getSettings();

        $enabled = array_key_exists('enabled', $input)
            ? $this->toBool($input['enabled'])
            : (bool)$current['enabled'];

        $subject = array_key_exists('vapid_subject', $input)
            ? trim((string)$input['vapid_subject'])
            : (string)$current['vapid_subject'];
        $subject = $this->normalizeVapidSubject($subject);

        $publicKey = array_key_exists('public_key', $input)
            ? trim((string)$input['public_key'])
            : (string)$current['public_key'];
        $privateKey = array_key_exists('private_key', $input)
            ? trim((string)$input['private_key'])
            : (string)$current['private_key'];

        $ttl = array_key_exists('ttl', $input)
            ? max(0, min(604800, (int)$input['ttl']))
            : (int)$current['ttl'];
        $urgency = array_key_exists('urgency', $input)
            ? trim((string)$input['urgency'])
            : (string)$current['urgency'];
        if (!in_array($urgency, ['very-low', 'low', 'normal', 'high'], true)) {
            $urgency = 'normal';
        }

        $batchSize = array_key_exists('batch_size', $input)
            ? max(1, min(5000, (int)$input['batch_size']))
            : (int)$current['batch_size'];
        $requestTimeout = array_key_exists('request_timeout', $input)
            ? max(1, min(120, (int)$input['request_timeout']))
            : (int)$current['request_timeout'];

        $proxy = array_key_exists('proxy', $input)
            ? trim((string)$input['proxy'])
            : (string)$current['proxy'];
        $caBundle = array_key_exists('ca_bundle', $input)
            ? trim((string)$input['ca_bundle'])
            : (string)$current['ca_bundle'];

        $remindExpire = array_key_exists('remind_expire', $input)
            ? $this->toBool($input['remind_expire'])
            : (bool)$current['remind_expire'];
        $remindTraffic = array_key_exists('remind_traffic', $input)
            ? $this->toBool($input['remind_traffic'])
            : (bool)$current['remind_traffic'];

        $expireDaysRaw = array_key_exists('remind_expire_days', $input)
            ? (string)$input['remind_expire_days']
            : (string)$current['remind_expire_days'];
        $expireDays = $this->parseExpireDays($expireDaysRaw);

        $trafficPercent = array_key_exists('remind_traffic_percent', $input)
            ? max(1, min(99, (int)$input['remind_traffic_percent']))
            : (int)$current['remind_traffic_percent'];

        $expireUrl = array_key_exists('remind_expire_url', $input)
            ? trim((string)$input['remind_expire_url'])
            : (string)$current['remind_expire_url'];
        $trafficUrl = array_key_exists('remind_traffic_url', $input)
            ? trim((string)$input['remind_traffic_url'])
            : (string)$current['remind_traffic_url'];

        if ($enabled) {
            if ($subject === '') {
                throw new \InvalidArgumentException('VAPID Subject 不能为空（https://域名 或 mailto:邮箱）');
            }
            if ($publicKey === '' || $privateKey === '') {
                throw new \InvalidArgumentException('启用推送前请填写或生成 VAPID 公钥与私钥');
            }
        }

        $config = config('v2board');
        if (!is_array($config)) {
            $config = [];
        }

        $config['web_push_enabled'] = $enabled ? 1 : 0;
        $config['web_push_vapid_subject'] = $subject;
        $config['web_push_public_key'] = $publicKey;
        $config['web_push_private_key'] = $privateKey;
        $config['web_push_ttl'] = $ttl;
        $config['web_push_urgency'] = $urgency;
        $config['web_push_batch_size'] = $batchSize;
        $config['web_push_request_timeout'] = $requestTimeout;
        $config['web_push_proxy'] = $proxy !== '' ? $proxy : null;
        $config['web_push_ca_bundle'] = $caBundle !== '' ? $caBundle : null;
        $config['web_push_remind_expire'] = $remindExpire ? 1 : 0;
        $config['web_push_remind_traffic'] = $remindTraffic ? 1 : 0;
        $config['web_push_remind_expire_days'] = implode(',', $expireDays);
        $config['web_push_remind_traffic_percent'] = $trafficPercent;
        $config['web_push_remind_expire_url'] = $expireUrl !== '' ? $expireUrl : null;
        $config['web_push_remind_traffic_url'] = $trafficUrl !== '' ? $trafficUrl : null;

        $exported = var_export($config, true);
        if (!File::put(base_path() . '/config/v2board.php', "<?php\n return $exported ;", true)) {
            throw new \RuntimeException('写入配置文件失败，请检查 config/v2board.php 权限');
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        try {
            Artisan::call('config:cache');
        } catch (\Throwable $error) {
            // Some environments (missing predis etc.) may fail config:cache; still refresh runtime.
            Artisan::call('config:clear');
            Log::warning('Web Push config:cache failed, fell back to config:clear', [
                'reason' => $error->getMessage(),
            ]);
        }

        // Refresh in-memory config for current request.
        config([
            'v2board' => $config,
        ]);

        if (Cache::has('WEBMANPID')) {
            $pid = Cache::get('WEBMANPID');
            Cache::forget('WEBMANPID');
            if (function_exists('posix_kill') && $pid) {
                @posix_kill((int)$pid, 15);
            }
        }

        return $this->getSettings();
    }

    /**
     * Generate a new VAPID key pair (does not auto-save).
     */
    public function generateVapidKeys()
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $error) {
            throw new \RuntimeException('生成 VAPID 密钥失败：' . $error->getMessage());
        }

        return [
            'public_key' => (string)($keys['publicKey'] ?? ''),
            'private_key' => (string)($keys['privateKey'] ?? ''),
            'vapid_subject' => $this->normalizeVapidSubject(
                (string)config('v2board.app_url', config('app.url', ''))
            ),
        ];
    }

    public function defaultIconUrl()
    {
        $logo = trim((string)config('v2board.logo', ''));
        if ($logo !== '') {
            return $logo;
        }

        $baseUrl = rtrim((string)config('v2board.app_url', config('app.url', '')), '/');
        return $baseUrl . '/theme/blued/images/logo.png';
    }

    public function defaultClickUrl()
    {
        $baseUrl = rtrim((string)config('v2board.app_url', config('app.url', '')), '/');
        return $baseUrl . '/#/dashboard';
    }

    private function resolveString(array $board, $key, $fallback)
    {
        if (array_key_exists($key, $board) && $board[$key] !== null && $board[$key] !== '') {
            return trim((string)$board[$key]);
        }

        return trim((string)$fallback);
    }

    private function resolveBool(array $board, $key, $fallback)
    {
        if (array_key_exists($key, $board) && $board[$key] !== null && $board[$key] !== '') {
            return $this->toBool($board[$key]);
        }

        return (bool)$fallback;
    }

    private function resolveInt(array $board, $key, $fallback, $min, $max)
    {
        $value = $fallback;
        if (array_key_exists($key, $board) && $board[$key] !== null && $board[$key] !== '') {
            $value = (int)$board[$key];
        }

        return max($min, min($max, (int)$value));
    }

    private function toBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    private function normalizeVapidSubject($subject)
    {
        $subject = trim((string)$subject);
        if ($subject === '') {
            $appUrl = trim((string)config('v2board.app_url', config('app.url', '')));
            if (preg_match('/^https:\/\//i', $appUrl)) {
                return $appUrl;
            }
            $mailFrom = trim((string)config('v2board.email_from_address', env('MAIL_FROM_ADDRESS', '')));
            if (filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
                return 'mailto:' . $mailFrom;
            }
            return 'mailto:admin@localhost';
        }

        if (preg_match('/^(https:\/\/|mailto:)/i', $subject)) {
            return $subject;
        }
        if (filter_var($subject, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:' . $subject;
        }
        if (preg_match('/^https?:\/\//i', $subject)) {
            // Force https for VAPID subject when a full URL is given.
            return preg_replace('/^http:\/\//i', 'https://', $subject);
        }

        return $subject;
    }

    private function parseExpireDays($raw)
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = explode(',', (string)$raw);
        }

        $days = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '' || !is_numeric($part)) {
                continue;
            }
            $days[] = max(0, (int)$part);
        }

        $days = array_values(array_unique($days));
        if (empty($days)) {
            $days = [3, 1, 0];
        }
        sort($days);

        return $days;
    }

    private function detectConfigSource(array $board)
    {
        $adminKeys = [
            'web_push_enabled',
            'web_push_public_key',
            'web_push_private_key',
            'web_push_vapid_subject',
        ];
        foreach ($adminKeys as $key) {
            if (array_key_exists($key, $board) && $board[$key] !== null && $board[$key] !== '') {
                return 'admin';
            }
        }

        return 'env';
    }

    /**
     * Normalize a free-form admin/user payload into a browser-safe structure.
     */
    public function normalizePayload(array $input)
    {
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('推送标题不能为空');
        }

        $body = trim((string)($input['body'] ?? ''));
        $icon = trim((string)($input['icon'] ?? ''));
        $image = trim((string)($input['image'] ?? ''));
        $url = trim((string)($input['url'] ?? ''));
        $tag = trim((string)($input['tag'] ?? ''));
        $badge = trim((string)($input['badge'] ?? ''));

        if ($icon === '') {
            $icon = $this->defaultIconUrl();
        }
        if ($badge === '') {
            $badge = $this->defaultIconUrl();
        }
        if ($url === '') {
            $url = $this->defaultClickUrl();
        }
        if ($tag === '') {
            $tag = 'web-push-' . date('YmdHis');
        }

        $actions = [];
        if (!empty($input['actions']) && is_array($input['actions'])) {
            foreach ($input['actions'] as $actionItem) {
                if (!is_array($actionItem)) {
                    continue;
                }
                $action = trim((string)($actionItem['action'] ?? ''));
                $actionTitle = trim((string)($actionItem['title'] ?? ''));
                if ($action === '' || $actionTitle === '') {
                    continue;
                }
                $actions[] = [
                    'action' => mb_substr($action, 0, 64),
                    'title' => mb_substr($actionTitle, 0, 64),
                    'url' => trim((string)($actionItem['url'] ?? $url)),
                ];
                if (count($actions) >= 2) {
                    break;
                }
            }
        } elseif (!empty($input['action_title'])) {
            $actions[] = [
                'action' => 'open',
                'title' => mb_substr(trim((string)$input['action_title']), 0, 64),
                'url' => $url,
            ];
        }

        $settings = $this->getSettings();
        $ttl = isset($input['ttl']) ? (int)$input['ttl'] : (int)$settings['ttl'];
        $ttl = max(0, min(604800, $ttl));

        $urgency = $input['urgency'] ?? $settings['urgency'];
        if (!in_array($urgency, ['very-low', 'low', 'normal', 'high'], true)) {
            $urgency = 'normal';
        }

        return [
            'title' => mb_substr($title, 0, 120),
            'body' => mb_substr($body, 0, 500),
            'icon' => mb_substr($icon, 0, 512),
            'image' => $image !== '' ? mb_substr($image, 0, 512) : null,
            'badge' => mb_substr($badge, 0, 512),
            'url' => mb_substr($url, 0, 512),
            'tag' => mb_substr($tag, 0, 120),
            'actions' => $actions,
            'ttl' => $ttl,
            'urgency' => $urgency,
            'renotify' => !empty($input['renotify']),
            'requireInteraction' => !empty($input['require_interaction']),
        ];
    }

    public function encodePayload(array $payload)
    {
        $json = json_encode([
            'title' => $payload['title'],
            'body' => $payload['body'],
            'icon' => $payload['icon'],
            'image' => $payload['image'],
            'badge' => $payload['badge'],
            'url' => $payload['url'],
            'tag' => $payload['tag'],
            'actions' => $payload['actions'],
            'renotify' => !empty($payload['renotify']),
            'requireInteraction' => !empty($payload['requireInteraction']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('推送内容编码失败');
        }

        return $json;
    }

    public function sendNotice(Notice $notice)
    {
        $plainText = html_entity_decode(strip_tags((string)$notice->content), ENT_QUOTES, 'UTF-8');
        $plainText = trim((string)preg_replace('/\s+/u', ' ', $plainText));
        $iconUrl = trim((string)$notice->img_url);
        if ($iconUrl === '') {
            $iconUrl = $this->defaultIconUrl();
        }

        $payload = $this->normalizePayload([
            'title' => (string)$notice->title,
            'body' => mb_substr($plainText, 0, 180),
            'icon' => $iconUrl,
            'image' => $iconUrl,
            'url' => $this->defaultClickUrl(),
            'tag' => 'notice-' . $notice->id,
            'renotify' => true,
        ]);

        return $this->sendToQuery(WebPushSubscriptionModel::query(), $payload);
    }

    /**
     * @param Builder $query
     * @param array $payload normalized payload
     * @return array{configured:bool,total:int,sent:int,failed:int,expired:int}
     */
    public function sendToQuery(Builder $query, array $payload)
    {
        $stats = [
            'configured' => $this->isConfigured(),
            'total' => 0,
            'sent' => 0,
            'failed' => 0,
            'expired' => 0,
        ];

        if (!$stats['configured']) {
            return $stats;
        }

        $encodedPayload = $this->encodePayload($payload);
        $settings = $this->getSettings();
        $batchSize = max(1, (int)$settings['batch_size']);
        $service = $this;
        $topic = !empty($payload['tag']) ? (string)$payload['tag'] : null;

        $query->orderBy('id')->chunkById($batchSize, function ($subscriptions) use (
            &$stats,
            $encodedPayload,
            $payload,
            $service,
            $batchSize,
            $topic
        ) {
            $webPush = $service->createClient($batchSize, $payload);

            foreach ($subscriptions as $storedSubscription) {
                $stats['total']++;
                try {
                    $subscription = Subscription::create([
                        'endpoint' => $storedSubscription->endpoint,
                        'publicKey' => $storedSubscription->public_key,
                        'authToken' => $storedSubscription->auth_token,
                        'contentEncoding' => $storedSubscription->content_encoding ?: 'aes128gcm',
                    ]);

                    $options = [];
                    if ($topic) {
                        $options['topic'] = mb_substr($topic, 0, 32);
                    }

                    $webPush->queueNotification($subscription, $encodedPayload, $options);
                } catch (\Throwable $error) {
                    $stats['failed']++;
                    $storedSubscription->delete();
                    Log::warning('Invalid web push subscription removed', [
                        'subscription_id' => $storedSubscription->id,
                        'reason' => $error->getMessage(),
                    ]);
                }
            }

            foreach ($webPush->flush() as $report) {
                $endpointHash = hash('sha256', $report->getEndpoint());
                if ($report->isSuccess()) {
                    $stats['sent']++;
                    WebPushSubscriptionModel::where('endpoint_hash', $endpointHash)->update([
                        'last_used_at' => time(),
                    ]);
                    continue;
                }

                $stats['failed']++;
                if ($report->isSubscriptionExpired()) {
                    $stats['expired']++;
                    WebPushSubscriptionModel::where('endpoint_hash', $endpointHash)->delete();
                }

                Log::warning('Web push delivery failed', [
                    'endpoint_hash' => $endpointHash,
                    'expired' => $report->isSubscriptionExpired(),
                    'reason' => $report->getReason(),
                ]);
            }
        });

        return $stats;
    }

    public function sendToUserIds(array $userIds, array $payload)
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (empty($userIds)) {
            return [
                'configured' => $this->isConfigured(),
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
                'expired' => 0,
            ];
        }

        return $this->sendToQuery(
            WebPushSubscriptionModel::query()->whereIn('user_id', $userIds),
            $payload
        );
    }

    public function sendToSubscriptionIds(array $subscriptionIds, array $payload)
    {
        $subscriptionIds = array_values(array_unique(array_filter(array_map('intval', $subscriptionIds))));
        if (empty($subscriptionIds)) {
            return [
                'configured' => $this->isConfigured(),
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
                'expired' => 0,
            ];
        }

        return $this->sendToQuery(
            WebPushSubscriptionModel::query()->whereIn('id', $subscriptionIds),
            $payload
        );
    }

    /**
     * Build subscription query by admin target filters.
     */
    public function buildTargetQuery(array $target)
    {
        $query = WebPushSubscriptionModel::query();
        $targetType = (string)($target['type'] ?? 'all');

        if ($targetType === 'user') {
            $userId = (int)($target['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new \InvalidArgumentException('请指定用户 ID');
            }
            $query->where('user_id', $userId);
            return $query;
        }

        if ($targetType === 'subscription') {
            $subscriptionId = (int)($target['subscription_id'] ?? 0);
            if ($subscriptionId <= 0) {
                throw new \InvalidArgumentException('请指定订阅 ID');
            }
            $query->where('id', $subscriptionId);
            return $query;
        }

        if ($targetType === 'filter') {
            $planId = $target['plan_id'] ?? null;
            $banned = $target['banned'] ?? null;
            $hasPlan = array_key_exists('has_plan', $target) ? (bool)$target['has_plan'] : null;

            $query->whereIn('user_id', function ($subQuery) use ($planId, $banned, $hasPlan) {
                $subQuery->select('id')->from((new User())->getTable());
                if ($planId !== null && $planId !== '') {
                    $subQuery->where('plan_id', (int)$planId);
                }
                if ($banned !== null && $banned !== '') {
                    $subQuery->where('banned', (int)$banned);
                }
                if ($hasPlan === true) {
                    $subQuery->whereNotNull('plan_id')->where('plan_id', '>', 0);
                } elseif ($hasPlan === false) {
                    $subQuery->where(function ($innerQuery) {
                        $innerQuery->whereNull('plan_id')->orWhere('plan_id', 0);
                    });
                }
            });

            return $query;
        }

        // all
        return $query;
    }

    public function createClient($batchSize = 500, array $payload = [])
    {
        $settings = $this->getSettings();
        $clientOptions = [];
        $proxy = trim((string)$settings['proxy']);
        if ($proxy !== '') {
            $clientOptions['proxy'] = $proxy;
        }

        $caBundle = trim((string)$settings['ca_bundle']);
        if ($caBundle !== '') {
            if (!preg_match('/^(?:[a-z]:[\\\\\/]|[\\\\\/]{1,2})/i', $caBundle)) {
                $caBundle = base_path($caBundle);
            }
            if (!is_file($caBundle)) {
                throw new \RuntimeException('Web Push CA bundle 文件不存在');
            }
            $clientOptions['verify'] = $caBundle;
        }

        $ttl = isset($payload['ttl'])
            ? max(0, (int)$payload['ttl'])
            : max(0, (int)$settings['ttl']);
        $urgency = (string)($payload['urgency'] ?? $settings['urgency']);

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string)$settings['vapid_subject'],
                'publicKey' => (string)$settings['public_key'],
                'privateKey' => (string)$settings['private_key'],
            ],
        ], [
            'TTL' => $ttl,
            'urgency' => $urgency,
            'batchSize' => max(1, (int)$batchSize),
        ], max(1, (int)$settings['request_timeout']), $clientOptions);

        $webPush->setReuseVAPIDHeaders(true);
        return $webPush;
    }
}
