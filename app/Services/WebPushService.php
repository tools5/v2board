<?php

namespace App\Services;

use App\Models\Notice;
use App\Models\User;
use App\Models\WebPushSubscription as WebPushSubscriptionModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function isConfigured()
    {
        return (bool)config('webpush.enabled')
            && (string)config('webpush.vapid.subject') !== ''
            && (string)config('webpush.vapid.public_key') !== ''
            && (string)config('webpush.vapid.private_key') !== '';
    }

    public function defaultIconUrl()
    {
        $logo = trim((string)config('v2board.logo', ''));
        if ($logo !== '') {
            return $logo;
        }

        return rtrim((string)config('app.url', ''), '/') . '/theme/blued/images/logo.png';
    }

    public function defaultClickUrl()
    {
        return rtrim((string)config('app.url', ''), '/') . '/#/dashboard';
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

        $ttl = isset($input['ttl']) ? (int)$input['ttl'] : (int)config('webpush.ttl', 86400);
        $ttl = max(0, min(604800, $ttl));

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
            'urgency' => in_array(($input['urgency'] ?? 'normal'), ['very-low', 'low', 'normal', 'high'], true)
                ? $input['urgency']
                : 'normal',
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
        $batchSize = max(1, (int)config('webpush.batch_size', 500));
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
        $clientOptions = [];
        $proxy = trim((string)config('webpush.proxy', ''));
        if ($proxy !== '') {
            $clientOptions['proxy'] = $proxy;
        }

        $caBundle = trim((string)config('webpush.ca_bundle', ''));
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
            : max(0, (int)config('webpush.ttl', 86400));
        $urgency = (string)($payload['urgency'] ?? config('webpush.urgency', 'normal'));

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string)config('webpush.vapid.subject'),
                'publicKey' => (string)config('webpush.vapid.public_key'),
                'privateKey' => (string)config('webpush.vapid.private_key'),
            ],
        ], [
            'TTL' => $ttl,
            'urgency' => $urgency,
            'batchSize' => max(1, (int)$batchSize),
        ], max(1, (int)config('webpush.request_timeout', 30)), $clientOptions);

        $webPush->setReuseVAPIDHeaders(true);
        return $webPush;
    }
}
