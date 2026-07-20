<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\WebPushEndpointResolutionException;
use App\Http\Controllers\Controller;
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

    public function config()
    {
        $publicKey = (string)$this->webPushService->getSettings()['public_key'];

        return response([
            'data' => [
                'enabled' => $this->webPushService->isConfigured(),
                'public_key' => $publicKey,
                'default_icon' => $this->webPushService->defaultIconUrl(),
                'default_url' => $this->webPushService->defaultClickUrl(),
            ],
        ]);
    }

    public function status(Request $request)
    {
        $endpoint = (string)$request->query('endpoint', '');
        $subscribed = false;
        $subscriptionId = null;

        if ($endpoint !== '') {
            $subscription = WebPushSubscription::where('user_id', $request->user['id'])
                ->where('endpoint_hash', hash('sha256', $endpoint))
                ->first();
            if ($subscription) {
                $subscribed = true;
                $subscriptionId = $subscription->id;
            }
        }

        $deviceCount = WebPushSubscription::where('user_id', $request->user['id'])->count();

        return response([
            'data' => [
                'subscribed' => $subscribed,
                'subscription_id' => $subscriptionId,
                'device_count' => $deviceCount,
            ],
        ]);
    }

    public function devices(Request $request)
    {
        $rows = WebPushSubscription::where('user_id', $request->user['id'])
            ->orderBy('id', 'DESC')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'device_name' => $row->device_name ?: $this->guessDeviceName($row->user_agent),
                    'user_agent' => $row->user_agent,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                    'last_used_at' => $row->last_used_at,
                ];
            });

        return response([
            'data' => $rows,
        ]);
    }

    public function subscribe(Request $request)
    {
        if (!$this->webPushService->isConfigured()) {
            abort(503, '浏览器推送尚未配置');
        }

        $data = $request->validate([
            'endpoint' => 'required|url|max:2048',
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
            'content_encoding' => 'nullable|in:aesgcm,aes128gcm',
            'device_name' => 'nullable|string|max:120',
        ], [
            'endpoint.required' => '浏览器推送地址缺失，请刷新页面后重试',
            'endpoint.url' => '浏览器推送地址格式无效',
            'keys.p256dh.required' => '浏览器推送公钥缺失，请刷新页面后重试',
            'keys.auth.required' => '浏览器推送认证密钥缺失，请刷新页面后重试',
            'content_encoding.in' => '当前浏览器的推送加密格式不受支持',
        ]);

        try {
            $this->webPushService->assertValidSubscription(
                $data['endpoint'],
                $data['keys']['p256dh'],
                $data['keys']['auth']
            );
        } catch (WebPushEndpointResolutionException $error) {
            abort(503, '暂时无法验证浏览器推送服务，请稍后重试');
        } catch (\InvalidArgumentException $error) {
            abort(422, $error->getMessage());
        }

        $endpoint = $data['endpoint'];
        $endpointHash = hash('sha256', $endpoint);
        $userAgent = mb_substr((string)$request->userAgent(), 0, 500);
        $deviceName = trim((string)($data['device_name'] ?? ''));
        if ($deviceName === '') {
            $deviceName = $this->guessDeviceName($userAgent);
        }

        $subscription = WebPushSubscription::updateOrCreate(
            ['endpoint_hash' => $endpointHash],
            [
                'user_id' => $request->user['id'],
                'endpoint' => $endpoint,
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
                'user_agent' => $userAgent,
                'device_name' => mb_substr($deviceName, 0, 120),
                'last_used_at' => time(),
            ]
        );

        return response([
            'data' => [
                'subscribed' => true,
                'subscription_id' => $subscription->id,
                'device_name' => $subscription->device_name,
            ],
        ]);
    }

    public function unsubscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => 'nullable|string|max:2048',
            'id' => 'nullable|integer|min:1',
        ]);

        $query = WebPushSubscription::where('user_id', $request->user['id']);
        if (!empty($data['id'])) {
            $query->where('id', (int)$data['id']);
        } elseif (!empty($data['endpoint'])) {
            $query->where('endpoint_hash', hash('sha256', $data['endpoint']));
        } else {
            abort(422, '请提供 endpoint 或订阅 ID');
        }

        $query->delete();

        return response([
            'data' => [
                'subscribed' => false,
            ],
        ]);
    }

    public function test(Request $request)
    {
        if (!$this->webPushService->isConfigured()) {
            abort(503, '浏览器推送尚未配置');
        }

        $endpoint = (string)$request->input('endpoint', '');
        $title = trim((string)$request->input('title', '测试通知'));
        $body = trim((string)$request->input('body', '浏览器推送已开启，这是一条测试消息。'));

        try {
            $payload = $this->webPushService->normalizePayload([
                'title' => $title !== '' ? $title : '测试通知',
                'body' => $body !== '' ? $body : '浏览器推送已开启，这是一条测试消息。',
                'url' => $request->input('url') ?: $this->webPushService->defaultClickUrl(),
                'icon' => $request->input('icon'),
                'tag' => 'user-test-' . $request->user['id'] . '-' . time(),
                'action_title' => $request->input('action_title', '打开面板'),
                'renotify' => true,
            ]);
        } catch (\InvalidArgumentException $error) {
            abort(422, $error->getMessage());
        }

        if ($endpoint !== '') {
            $subscription = WebPushSubscription::where('user_id', $request->user['id'])
                ->where('endpoint_hash', hash('sha256', $endpoint))
                ->first();
            if (!$subscription) {
                abort(422, '当前设备尚未订阅推送');
            }
            $stats = $this->webPushService->sendToSubscriptionIds([$subscription->id], $payload);
        } else {
            $stats = $this->webPushService->sendToUserIds([$request->user['id']], $payload);
        }

        if ($stats['total'] === 0) {
            abort(422, '没有可推送的设备，请先开启浏览器通知');
        }

        return response([
            'data' => [
                'sent' => $stats['sent'],
                'failed' => $stats['failed'],
                'expired' => $stats['expired'],
                'total' => $stats['total'],
            ],
        ]);
    }

    private function guessDeviceName($userAgent)
    {
        $userAgent = (string)$userAgent;
        if ($userAgent === '') {
            return '未知设备';
        }

        $browser = '浏览器';
        if (stripos($userAgent, 'Edg/') !== false) {
            $browser = 'Edge';
        } elseif (stripos($userAgent, 'Chrome/') !== false) {
            $browser = 'Chrome';
        } elseif (stripos($userAgent, 'Firefox/') !== false) {
            $browser = 'Firefox';
        } elseif (stripos($userAgent, 'Safari/') !== false) {
            $browser = 'Safari';
        }

        $platform = '桌面';
        if (stripos($userAgent, 'Android') !== false) {
            $platform = 'Android';
        } elseif (stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPad') !== false) {
            $platform = 'iOS';
        } elseif (stripos($userAgent, 'Windows') !== false) {
            $platform = 'Windows';
        } elseif (stripos($userAgent, 'Mac OS') !== false || stripos($userAgent, 'Macintosh') !== false) {
            $platform = 'macOS';
        } elseif (stripos($userAgent, 'Linux') !== false) {
            $platform = 'Linux';
        }

        return $browser . ' · ' . $platform;
    }

}
