<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendCustomWebPushJob;
use App\Models\Plan;
use App\Models\User;
use App\Models\WebPushMessage;
use App\Models\WebPushSubscription;
use App\Services\WebPushService;
use Illuminate\Http\Request;

class WebPushController extends Controller
{
    private $webPushService;

    public function __construct(WebPushService $webPushService)
    {
        $this->webPushService = $webPushService;
    }

    public function overview()
    {
        $settings = $this->webPushService->getSettings();

        return response([
            'data' => [
                'configured' => $this->webPushService->isConfigured(),
                'enabled' => (bool)$settings['enabled'],
                'public_key' => (string)$settings['public_key'],
                'subscription_count' => WebPushSubscription::count(),
                'user_count' => WebPushSubscription::distinct('user_id')->count('user_id'),
                'message_count' => WebPushMessage::count(),
                'default_icon' => $this->webPushService->defaultIconUrl(),
                'default_url' => $this->webPushService->defaultClickUrl(),
                'plans' => Plan::orderBy('sort', 'ASC')->get(['id', 'name']),
                'settings' => [
                    'enabled' => (bool)$settings['enabled'],
                    'vapid_subject' => (string)$settings['vapid_subject'],
                    'public_key' => (string)$settings['public_key'],
                    // Never expose private key fully in list views; still needed for edit form.
                    'private_key' => (string)$settings['private_key'],
                    'ttl' => (int)$settings['ttl'],
                    'urgency' => (string)$settings['urgency'],
                    'batch_size' => (int)$settings['batch_size'],
                    'request_timeout' => (int)$settings['request_timeout'],
                    'proxy' => (string)$settings['proxy'],
                    'ca_bundle' => (string)$settings['ca_bundle'],
                    'remind_expire' => (bool)$settings['remind_expire'],
                    'remind_traffic' => (bool)$settings['remind_traffic'],
                    'remind_expire_days' => (string)$settings['remind_expire_days'],
                    'remind_traffic_percent' => (int)$settings['remind_traffic_percent'],
                    'remind_expire_url' => (string)$settings['remind_expire_url'],
                    'remind_traffic_url' => (string)$settings['remind_traffic_url'],
                    'source' => (string)$settings['source'],
                ],
            ],
        ]);
    }

    public function saveSettings(Request $request)
    {
        try {
            $settings = $this->webPushService->saveSettings($request->all());
        } catch (\InvalidArgumentException $error) {
            abort(500, $error->getMessage());
        } catch (\RuntimeException $error) {
            abort(500, $error->getMessage());
        } catch (\Throwable $error) {
            \Log::error('Web Push saveSettings failed', [
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
            ]);
            abort(500, '保存失败：' . $error->getMessage());
        }

        return response([
            'data' => [
                'configured' => $this->webPushService->isConfigured(),
                'settings' => [
                    'enabled' => (bool)$settings['enabled'],
                    'vapid_subject' => (string)$settings['vapid_subject'],
                    'public_key' => (string)$settings['public_key'],
                    'private_key' => (string)$settings['private_key'],
                    'ttl' => (int)$settings['ttl'],
                    'urgency' => (string)$settings['urgency'],
                    'batch_size' => (int)$settings['batch_size'],
                    'request_timeout' => (int)$settings['request_timeout'],
                    'proxy' => (string)$settings['proxy'],
                    'ca_bundle' => (string)$settings['ca_bundle'],
                    'remind_expire' => (bool)$settings['remind_expire'],
                    'remind_traffic' => (bool)$settings['remind_traffic'],
                    'remind_expire_days' => (string)$settings['remind_expire_days'],
                    'remind_traffic_percent' => (int)$settings['remind_traffic_percent'],
                    'remind_expire_url' => (string)$settings['remind_expire_url'],
                    'remind_traffic_url' => (string)$settings['remind_traffic_url'],
                    'source' => (string)$settings['source'],
                ],
            ],
        ]);
    }

    public function generateKeys()
    {
        try {
            $keys = $this->webPushService->generateVapidKeys();
        } catch (\RuntimeException $error) {
            abort(500, $error->getMessage());
        }

        return response([
            'data' => $keys,
        ]);
    }

    public function subscriptions(Request $request)
    {
        $current = max(1, (int)$request->input('current', 1));
        $pageSize = min(100, max(1, (int)$request->input('pageSize', 20)));
        $userId = $request->input('user_id');
        $email = trim((string)$request->input('email', ''));

        $query = WebPushSubscription::query()->orderBy('id', 'DESC');
        if ($userId !== null && $userId !== '') {
            $query->where('user_id', (int)$userId);
        }
        if ($email !== '') {
            $matchedUserIds = User::where('email', 'like', '%' . $email . '%')->pluck('id');
            $query->whereIn('user_id', $matchedUserIds);
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($current, $pageSize)->get()->map(function ($row) {
            $user = User::find($row->user_id);
            return [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'email' => $user ? $user->email : null,
                'device_name' => $row->device_name,
                'user_agent' => $row->user_agent,
                'content_encoding' => $row->content_encoding,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'last_used_at' => $row->last_used_at,
            ];
        });

        return response([
            'data' => $rows,
            'total' => $total,
        ]);
    }

    public function dropSubscription(Request $request)
    {
        $id = (int)$request->input('id');
        if ($id <= 0) {
            abort(500, '参数错误');
        }
        $subscription = WebPushSubscription::find($id);
        if (!$subscription) {
            abort(500, '订阅不存在');
        }
        $subscription->delete();
        return response(['data' => true]);
    }

    public function messages(Request $request)
    {
        $current = max(1, (int)$request->input('current', 1));
        $pageSize = min(100, max(1, (int)$request->input('pageSize', 20)));
        $query = WebPushMessage::query()->orderBy('id', 'DESC');
        $total = (clone $query)->count();
        $rows = $query->forPage($current, $pageSize)->get();

        return response([
            'data' => $rows,
            'total' => $total,
        ]);
    }

    public function send(Request $request)
    {
        if (!$this->webPushService->isConfigured()) {
            abort(500, '浏览器推送尚未配置，请先在后台 Web Push 页面完成配置并保存');
        }

        if (!\Schema::hasTable('v2_web_push_message')) {
            abort(500, '缺少数据表 v2_web_push_message，请执行：php artisan migrate --force');
        }

        if (!\Schema::hasTable('v2_web_push_subscription')) {
            abort(500, '缺少数据表 v2_web_push_subscription，请执行：php artisan migrate --force');
        }

        $title = trim((string)$request->input('title', ''));
        $body = trim((string)$request->input('body', ''));
        if ($title === '') {
            abort(500, '推送标题不能为空');
        }

        $targetType = (string)$request->input('target_type', 'all');
        if (!in_array($targetType, ['all', 'user', 'filter', 'subscription'], true)) {
            abort(500, '推送目标类型无效');
        }

        $targetUserId = $request->input('target_user_id');
        $hasPlanInput = $request->input('has_plan');
        $hasPlan = null;
        if ($hasPlanInput === true || $hasPlanInput === 1 || $hasPlanInput === '1') {
            $hasPlan = true;
        } elseif ($hasPlanInput === false || $hasPlanInput === 0 || $hasPlanInput === '0') {
            $hasPlan = false;
        }

        $targetFilter = [
            'type' => $targetType,
            'user_id' => $targetUserId,
            'subscription_id' => $request->input('subscription_id'),
            'plan_id' => $request->input('plan_id'),
            'banned' => $request->input('banned'),
            'has_plan' => $hasPlan,
        ];

        if ($targetType === 'user' && (int)$targetUserId <= 0) {
            abort(500, '请填写目标用户 ID');
        }

        try {
            $payload = $this->webPushService->normalizePayload([
                'title' => $title,
                'body' => $body,
                'icon' => $request->input('icon'),
                'image' => $request->input('image'),
                'url' => $request->input('url'),
                'tag' => $request->input('tag'),
                'action_title' => $request->input('action_title'),
                'actions' => $request->input('actions'),
                'ttl' => $request->input('ttl'),
                'urgency' => $request->input('urgency'),
                'renotify' => $request->input('renotify', true),
                'require_interaction' => $request->input('require_interaction', false),
            ]);
        } catch (\InvalidArgumentException $error) {
            abort(500, $error->getMessage());
        }

        try {
            $message = WebPushMessage::create([
                'title' => $payload['title'],
                'body' => $payload['body'],
                'icon' => $payload['icon'],
                'image' => $payload['image'],
                'url' => $payload['url'],
                'tag' => $payload['tag'],
                'actions' => $payload['actions'],
                'target_type' => $targetType,
                'target_user_id' => $targetType === 'user' ? (int)$targetUserId : null,
                'target_filter' => $targetFilter,
                'admin_id' => $request->user['id'] ?? null,
                'status' => 'queued',
            ]);
        } catch (\Throwable $error) {
            \Log::error('Web Push message create failed', [
                'message' => $error->getMessage(),
            ]);
            abort(500, '写入推送记录失败：' . $error->getMessage());
        }

        // Default to queue; fall back to sync when Redis/Horizon is unavailable.
        $forceSync = (bool)$request->input('sync', false);
        $usedSync = $forceSync;

        try {
            if ($forceSync) {
                SendCustomWebPushJob::dispatchNow($message->id);
            } else {
                SendCustomWebPushJob::dispatch($message->id);
            }
        } catch (\Throwable $queueError) {
            \Log::warning('Web Push queue dispatch failed, falling back to sync', [
                'message_id' => $message->id,
                'reason' => $queueError->getMessage(),
            ]);
            try {
                SendCustomWebPushJob::dispatchNow($message->id);
                $usedSync = true;
            } catch (\Throwable $syncError) {
                $message->status = 'failed';
                $message->error_message = $syncError->getMessage();
                $message->save();
                abort(500, '推送发送失败：' . $syncError->getMessage() .
                    '（若提示 Class Minishlink 不存在，请执行 composer require minishlink/web-push:^7.0）');
            }
        }

        $message = $message->fresh() ?: $message;

        return response([
            'data' => $message,
            'meta' => [
                'mode' => $usedSync ? 'sync' : 'queue',
            ],
        ]);
    }

    public function test(Request $request)
    {
        if (!$this->webPushService->isConfigured()) {
            abort(500, '浏览器推送尚未配置');
        }

        $userId = (int)$request->input('user_id', 0);
        if ($userId <= 0) {
            abort(500, '请填写测试用户 ID');
        }

        try {
            $payload = $this->webPushService->normalizePayload([
                'title' => $request->input('title', '测试推送'),
                'body' => $request->input('body', '这是一条管理员测试通知'),
                'icon' => $request->input('icon'),
                'image' => $request->input('image'),
                'url' => $request->input('url'),
                'tag' => $request->input('tag', 'admin-test-' . time()),
                'action_title' => $request->input('action_title', '立即查看'),
                'renotify' => true,
            ]);
        } catch (\InvalidArgumentException $error) {
            abort(500, $error->getMessage());
        }

        try {
            $stats = $this->webPushService->sendToUserIds([$userId], $payload);
        } catch (\Throwable $error) {
            abort(500, '测试推送失败：' . $error->getMessage() .
                '（若提示 Class Minishlink 不存在，请执行 composer require minishlink/web-push:^7.0）');
        }

        try {
            $message = WebPushMessage::create([
                'title' => $payload['title'],
                'body' => $payload['body'],
                'icon' => $payload['icon'],
                'image' => $payload['image'],
                'url' => $payload['url'],
                'tag' => $payload['tag'],
                'actions' => $payload['actions'],
                'target_type' => 'user',
                'target_user_id' => $userId,
                'target_filter' => ['type' => 'user', 'user_id' => $userId],
                'admin_id' => $request->user['id'] ?? null,
                'status' => ($stats['sent'] > 0 || $stats['total'] === 0) ? 'sent' : 'failed',
                'total' => $stats['total'],
                'sent' => $stats['sent'],
                'failed' => $stats['failed'],
                'expired' => $stats['expired'],
                'error_message' => $stats['total'] === 0 ? '该用户没有有效订阅设备' : null,
            ]);
        } catch (\Throwable $error) {
            // Sending already happened; do not fail the whole request on history write.
            return response([
                'data' => [
                    'message' => null,
                    'stats' => $stats,
                    'warning' => '推送已尝试，但写入记录失败：' . $error->getMessage(),
                ],
            ]);
        }

        return response([
            'data' => [
                'message' => $message,
                'stats' => $stats,
            ],
        ]);
    }
}
