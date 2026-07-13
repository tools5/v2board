<?php

namespace App\Services;

use App\Models\Notice;
use App\Models\WebPushSubscription as WebPushSubscriptionModel;
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

    public function sendNotice(Notice $notice)
    {
        $stats = [
            'configured' => $this->isConfigured(),
            'sent' => 0,
            'failed' => 0,
            'expired' => 0,
        ];

        if (!$stats['configured']) {
            return $stats;
        }

        $payload = $this->buildNoticePayload($notice);
        $batchSize = max(1, (int)config('webpush.batch_size', 500));
        $service = $this;

        WebPushSubscriptionModel::query()
            ->orderBy('id')
            ->chunkById($batchSize, function ($subscriptions) use (&$stats, $payload, $notice, $service, $batchSize) {
                $webPush = $service->createClient($batchSize);

                foreach ($subscriptions as $storedSubscription) {
                    try {
                        $subscription = Subscription::create([
                            'endpoint' => $storedSubscription->endpoint,
                            'publicKey' => $storedSubscription->public_key,
                            'authToken' => $storedSubscription->auth_token,
                            'contentEncoding' => $storedSubscription->content_encoding ?: 'aes128gcm',
                        ]);

                        $webPush->queueNotification($subscription, $payload, [
                            'topic' => 'notice-' . $notice->id,
                        ]);
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
                    if ($report->isSuccess()) {
                        $stats['sent']++;
                        continue;
                    }

                    $stats['failed']++;
                    if ($report->isSubscriptionExpired()) {
                        $stats['expired']++;
                        WebPushSubscriptionModel::where(
                            'endpoint_hash',
                            hash('sha256', $report->getEndpoint())
                        )->delete();
                    }

                    Log::warning('Web push delivery failed', [
                        'endpoint_hash' => hash('sha256', $report->getEndpoint()),
                        'expired' => $report->isSubscriptionExpired(),
                        'reason' => $report->getReason(),
                    ]);
                }
            });

        return $stats;
    }

    public function createClient($batchSize = 500)
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

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string)config('webpush.vapid.subject'),
                'publicKey' => (string)config('webpush.vapid.public_key'),
                'privateKey' => (string)config('webpush.vapid.private_key'),
            ],
        ], [
            'TTL' => max(0, (int)config('webpush.ttl', 86400)),
            'urgency' => (string)config('webpush.urgency', 'normal'),
            'batchSize' => max(1, (int)$batchSize),
        ], max(1, (int)config('webpush.request_timeout', 30)), $clientOptions);

        $webPush->setReuseVAPIDHeaders(true);
        return $webPush;
    }

    private function buildNoticePayload(Notice $notice)
    {
        $plainText = html_entity_decode(strip_tags((string)$notice->content), ENT_QUOTES, 'UTF-8');
        $plainText = trim((string)preg_replace('/\s+/u', ' ', $plainText));

        $iconUrl = trim((string)$notice->img_url);
        if ($iconUrl === '') {
            $iconUrl = rtrim((string)config('app.url', ''), '/') . '/theme/blued/images/logo.png';
        }

        $payload = json_encode([
            'title' => (string)$notice->title,
            'body' => mb_substr($plainText, 0, 180),
            'url' => '/theme/blued/#/dashboard',
            'icon' => $iconUrl,
            'badge' => rtrim((string)config('app.url', ''), '/') . '/theme/blued/images/logo.png',
            'tag' => 'notice-' . $notice->id,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new \RuntimeException('公告推送内容编码失败');
        }

        return $payload;
    }
}
