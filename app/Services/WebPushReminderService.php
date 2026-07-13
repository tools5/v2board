<?php

namespace App\Services;

use App\Models\User;
use App\Models\WebPushSubscription;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebPushReminderService
{
    private $webPushService;

    public function __construct(WebPushService $webPushService)
    {
        $this->webPushService = $webPushService;
    }

    /**
     * @param bool $forceIgnoreCache
     * @return array{configured:bool,expire_checked:int,expire_sent_users:int,traffic_checked:int,traffic_sent_users:int}
     */
    public function processAll($forceIgnoreCache = false)
    {
        $stats = [
            'configured' => $this->webPushService->isConfigured(),
            'expire_checked' => 0,
            'expire_sent_users' => 0,
            'traffic_checked' => 0,
            'traffic_sent_users' => 0,
        ];

        if (!$stats['configured']) {
            return $stats;
        }

        $settings = $this->webPushService->getSettings();

        if (!empty($settings['remind_expire'])) {
            $expireStats = $this->processExpireReminders($forceIgnoreCache, $settings);
            $stats['expire_checked'] = $expireStats['checked'];
            $stats['expire_sent_users'] = $expireStats['sent_users'];
        }

        if (!empty($settings['remind_traffic'])) {
            $trafficStats = $this->processTrafficReminders($forceIgnoreCache, $settings);
            $stats['traffic_checked'] = $trafficStats['checked'];
            $stats['traffic_sent_users'] = $trafficStats['sent_users'];
        }

        return $stats;
    }

    public function processExpireReminders($forceIgnoreCache = false, array $settings = null)
    {
        $stats = ['checked' => 0, 'sent_users' => 0];
        if ($settings === null) {
            $settings = $this->webPushService->getSettings();
        }
        $expireDays = $settings['remind_expire_days_list'] ?? [3, 1, 0];
        if (!is_array($expireDays) || empty($expireDays)) {
            $expireDays = [3, 1, 0];
        }
        $expireDays = array_values(array_unique(array_map('intval', $expireDays)));
        sort($expireDays);

        $now = time();
        $maxDay = max($expireDays);
        $windowEnd = $now + (($maxDay + 1) * 86400);

        $userIds = $this->subscribedUserIds();
        if (empty($userIds)) {
            return $stats;
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->where('banned', 0)
            ->where('remind_expire', 1)
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', $now)
            ->where('expired_at', '<=', $windowEnd)
            ->get();

        foreach ($users as $user) {
            $stats['checked']++;
            $remainingSeconds = (int)$user->expired_at - $now;
            $remainingDays = (int)floor($remainingSeconds / 86400);
            if (!in_array($remainingDays, $expireDays, true)) {
                continue;
            }

            $cacheKey = CacheKey::get('LAST_SEND_WEBPUSH_REMIND_EXPIRE', $user->id . '_' . $remainingDays);
            if (!$forceIgnoreCache && Cache::get($cacheKey)) {
                continue;
            }

            $payload = $this->buildExpirePayload($user, $remainingDays, $settings);
            $sendStats = $this->webPushService->sendToUserIds([(int)$user->id], $payload);
            if (($sendStats['sent'] ?? 0) > 0) {
                // 同一剩余天数只提醒一次，缓存略大于 1 天避免跨日重复
                Cache::put($cacheKey, 1, 30 * 3600);
                $stats['sent_users']++;
            } elseif (($sendStats['total'] ?? 0) === 0) {
                // 无设备，短缓存避免空扫
                Cache::put($cacheKey, 1, 6 * 3600);
            } else {
                Log::warning('Web push expire remind delivery failed', [
                    'user_id' => $user->id,
                    'remaining_days' => $remainingDays,
                    'failed' => $sendStats['failed'] ?? 0,
                ]);
            }
        }

        return $stats;
    }

    public function processTrafficReminders($forceIgnoreCache = false, array $settings = null)
    {
        $stats = ['checked' => 0, 'sent_users' => 0];
        if ($settings === null) {
            $settings = $this->webPushService->getSettings();
        }
        $userIds = $this->subscribedUserIds();
        if (empty($userIds)) {
            return $stats;
        }

        $threshold = (float)($settings['remind_traffic_percent'] ?? 95);
        $now = time();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->where('banned', 0)
            ->where('remind_traffic', 1)
            ->where(function ($query) use ($now) {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>', $now);
            })
            ->where('transfer_enable', '>', 0)
            ->get();

        foreach ($users as $user) {
            $stats['checked']++;
            if (!$this->isTrafficWarnValue($user, $threshold)) {
                continue;
            }

            $cacheKey = CacheKey::get('LAST_SEND_WEBPUSH_REMIND_TRAFFIC', $user->id);
            if (!$forceIgnoreCache && Cache::get($cacheKey)) {
                continue;
            }

            $payload = $this->buildTrafficPayload($user, $threshold, $settings);
            $sendStats = $this->webPushService->sendToUserIds([(int)$user->id], $payload);
            if (($sendStats['sent'] ?? 0) > 0) {
                Cache::put($cacheKey, 1, 24 * 3600);
                $stats['sent_users']++;
            } elseif (($sendStats['total'] ?? 0) === 0) {
                Cache::put($cacheKey, 1, 6 * 3600);
            } else {
                Log::warning('Web push traffic remind delivery failed', [
                    'user_id' => $user->id,
                    'failed' => $sendStats['failed'] ?? 0,
                ]);
            }
        }

        return $stats;
    }

    private function subscribedUserIds()
    {
        return WebPushSubscription::query()
            ->select('user_id')
            ->distinct()
            ->pluck('user_id')
            ->map(function ($userId) {
                return (int)$userId;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function isTrafficWarnValue(User $user, $thresholdPercent)
    {
        $used = (float)$user->u + (float)$user->d;
        $enable = (float)$user->transfer_enable;
        if ($used <= 0 || $enable <= 0) {
            return false;
        }

        $percentage = ($used / $enable) * 100;
        return $percentage >= $thresholdPercent && $percentage < 100;
    }

    private function buildExpirePayload(User $user, $remainingDays, array $settings = [])
    {
        $appName = (string)config('v2board.app_name', 'V2Board');
        $expireText = date('Y-m-d H:i', (int)$user->expired_at);
        $url = trim((string)($settings['remind_expire_url'] ?? ''));
        if ($url === '') {
            $url = rtrim((string)config('v2board.app_url', config('app.url', '')), '/') . '/#/plan';
        }

        if ((int)$remainingDays <= 0) {
            $title = $appName . ' 套餐今日到期';
            $body = '您的套餐将于 ' . $expireText . ' 到期，请尽快续费以免服务中断。';
        } else {
            $title = $appName . ' 套餐到期提醒';
            $body = '您的套餐将在 ' . (int)$remainingDays . ' 天后（' . $expireText . '）到期，请及时续费。';
        }

        return $this->webPushService->normalizePayload([
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'tag' => 'plan-expire-' . $user->id . '-' . (int)$remainingDays,
            'action_title' => '立即续费',
            'renotify' => true,
            'require_interaction' => true,
            'urgency' => 'high',
            'ttl' => 86400,
        ]);
    }

    private function buildTrafficPayload(User $user, $thresholdPercent, array $settings = [])
    {
        $appName = (string)config('v2board.app_name', 'V2Board');
        $used = (float)$user->u + (float)$user->d;
        $enable = max(1, (float)$user->transfer_enable);
        $percent = min(99, (int)floor(($used / $enable) * 100));
        $url = trim((string)($settings['remind_traffic_url'] ?? ''));
        if ($url === '') {
            $url = rtrim((string)config('v2board.app_url', config('app.url', '')), '/') . '/#/plan';
        }

        return $this->webPushService->normalizePayload([
            'title' => $appName . ' 流量不足提醒',
            'body' => '您的流量已使用约 ' . $percent . '%（阈值 ' . (int)$thresholdPercent . '%），请及时重置流量或续费。',
            'url' => $url,
            'tag' => 'traffic-warn-' . $user->id,
            'action_title' => '查看套餐',
            'renotify' => true,
            'require_interaction' => false,
            'urgency' => 'high',
            'ttl' => 86400,
        ]);
    }
}
