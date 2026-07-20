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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
                'private_key_configured' => (string)$settings['private_key'] !== '',
                'subscription_count' => WebPushSubscription::count(),
                'user_count' => WebPushSubscription::distinct('user_id')->count('user_id'),
                'message_count' => WebPushMessage::count(),
                'default_icon' => $this->webPushService->defaultIconUrl(),
                'default_url' => $this->webPushService->defaultClickUrl(),
                'plans' => Plan::orderBy('sort', 'ASC')->get(['id', 'name']),
                'settings' => $this->publicSettings($settings),
            ],
        ]);
    }

    public function saveSettings(Request $request)
    {
        try {
            $settings = $this->webPushService->saveSettings($request->all());
        } catch (\InvalidArgumentException $error) {
            abort(422, $error->getMessage());
        } catch (\Throwable $error) {
            Log::error('Web Push settings save failed', [
                'exception' => $error,
            ]);
            abort(500, 'Web Push 配置保存失败，请检查 storage 写入权限和服务器日志');
        }

        return response([
            'data' => [
                'configured' => $this->webPushService->isConfigured(),
                'private_key_configured' => (string)$settings['private_key'] !== '',
                'settings' => $this->publicSettings($settings),
            ],
        ]);
    }

    public function generateKeys()
    {
        try {
            $keys = $this->webPushService->generateVapidKeys();
        } catch (\Throwable $error) {
            Log::error('Web Push VAPID key generation failed', ['exception' => $error]);
            abort(500, 'VAPID 密钥生成失败，请检查 OpenSSL 和服务器日志');
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
            abort(422, '参数错误');
        }
        $subscription = WebPushSubscription::find($id);
        if (!$subscription) {
            abort(404, '订阅不存在');
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

    public function clearMessages()
    {
        if (!Schema::hasTable('v2_web_push_message')) {
            abort(503, 'Web Push 数据表尚未安装，请先执行数据库迁移');
        }

        $deleted = WebPushMessage::query()->delete();

        return response([
            'data' => true,
            'deleted' => (int)$deleted,
        ]);
    }

    public function send(Request $request)
    {
        if (!$this->webPushService->isConfigured()) {
            abort(503, '浏览器推送尚未配置，请先在后台 Web Push 页面完成配置并保存');
        }

        if (!Schema::hasTable('v2_web_push_message')) {
            abort(503, 'Web Push 消息表尚未安装，请先执行数据库迁移');
        }

        if (!Schema::hasTable('v2_web_push_subscription')) {
            abort(503, 'Web Push 订阅表尚未安装，请先执行数据库迁移');
        }

        $title = trim((string)$request->input('title', ''));
        $body = trim((string)$request->input('body', ''));
        if ($title === '') {
            abort(422, '推送标题不能为空');
        }

        $targetType = (string)$request->input('target_type', 'all');
        if (!in_array($targetType, ['all', 'user', 'filter', 'subscription'], true)) {
            abort(422, '推送目标类型无效');
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
            abort(422, '请填写目标用户 ID');
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
            abort(422, $error->getMessage());
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
            Log::error('Web Push message create failed', ['exception' => $error]);
            abort(500, '写入推送记录失败，请检查服务器日志');
        }

        // Always prefer queue for HTTP requests.
        // Sync send can take minutes (FCM/network) and will crash Webman → Cloudflare 502.
        $forceSync = (bool)$request->input('sync', false);

        if ($forceSync) {
            try {
                // Bound work: only for small tests. Prefer queue in production.
                set_time_limit(60);
                SendCustomWebPushJob::dispatchNow($message->id);
                $message = $message->fresh() ?: $message;
                return response([
                    'data' => $message,
                    'meta' => ['mode' => 'sync'],
                ]);
            } catch (\Throwable $syncError) {
                $message->status = 'failed';
                $message->error_message = '同步推送任务执行失败';
                $message->save();
                Log::error('Synchronous Web Push failed', [
                    'message_id' => $message->id,
                    'exception' => $syncError,
                ]);
                abort(500, '同步推送失败，请检查服务器日志');
            }
        }

        try {
            SendCustomWebPushJob::dispatch($message->id);
        } catch (\Throwable $queueError) {
            Log::error('Web Push queue dispatch failed', [
                'message_id' => $message->id,
                'exception' => $queueError,
            ]);
            $message->status = 'failed';
            $message->error_message = '推送任务入队失败';
            $message->save();
            abort(500, '推送入队失败，请检查 Redis、队列进程和服务器日志');
        }

        return response([
            'data' => $message,
            'meta' => [
                'mode' => 'queue',
                'hint' => '已入队 send_web_push，请确保 Horizon/队列进程在运行',
            ],
        ]);
    }

    public function test(Request $request)
    {
        if (!$this->webPushService->isConfigured()) {
            abort(503, '浏览器推送尚未配置');
        }

        $userId = (int)$request->input('user_id', 0);
        if ($userId <= 0) {
            abort(422, '请填写测试用户 ID');
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
            abort(422, $error->getMessage());
        }

        try {
            $stats = $this->webPushService->sendToUserIds([$userId], $payload);
        } catch (\Throwable $error) {
            Log::error('Web Push test delivery failed', [
                'user_id' => $userId,
                'exception' => $error,
            ]);
            abort(500, '测试推送失败，请检查服务器日志');
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
            Log::error('Web Push test history write failed', [
                'user_id' => $userId,
                'exception' => $error,
            ]);
            return response([
                'data' => [
                    'message' => null,
                    'stats' => $stats,
                    'warning' => '推送已尝试，但发送记录写入失败，请检查服务器日志',
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

    private function publicSettings(array $settings)
    {
        return [
            'enabled' => (bool)$settings['enabled'],
            'vapid_subject' => (string)$settings['vapid_subject'],
            'public_key' => (string)$settings['public_key'],
            'private_key' => '',
            'private_key_configured' => (string)$settings['private_key'] !== '',
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
        ];
    }
}
