<?php

namespace App\Http\Controllers\V1\User;

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
        $publicKey = (string)config('webpush.vapid.public_key', '');

        return response([
            'data' => [
                'enabled' => $this->webPushService->isConfigured(),
                'public_key' => $publicKey,
            ],
        ]);
    }

    public function status(Request $request)
    {
        $endpoint = (string)$request->query('endpoint', '');
        $subscribed = false;

        if ($endpoint !== '') {
            $subscribed = WebPushSubscription::where('user_id', $request->user['id'])
                ->where('endpoint_hash', hash('sha256', $endpoint))
                ->exists();
        }

        return response([
            'data' => [
                'subscribed' => $subscribed,
            ],
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
        ], [
            'endpoint.required' => '浏览器推送地址缺失，请刷新页面后重试',
            'endpoint.url' => '浏览器推送地址格式无效',
            'keys.p256dh.required' => '浏览器推送公钥缺失，请刷新页面后重试',
            'keys.auth.required' => '浏览器推送认证密钥缺失，请刷新页面后重试',
            'content_encoding.in' => '当前浏览器的推送加密格式不受支持',
        ]);

        if (!$this->isAllowedEndpoint($data['endpoint'])) {
            abort(422, '推送订阅地址无效');
        }

        $endpoint = $data['endpoint'];
        $endpointHash = hash('sha256', $endpoint);

        WebPushSubscription::updateOrCreate(
            ['endpoint_hash' => $endpointHash],
            [
                'user_id' => $request->user['id'],
                'endpoint' => $endpoint,
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
                'user_agent' => mb_substr((string)$request->userAgent(), 0, 500),
            ]
        );

        return response([
            'data' => [
                'subscribed' => true,
            ],
        ]);
    }

    public function unsubscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => 'required|string|max:2048',
        ]);

        WebPushSubscription::where('user_id', $request->user['id'])
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return response([
            'data' => [
                'subscribed' => false,
            ],
        ]);
    }

    private function isAllowedEndpoint($endpoint)
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
        ) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === 'localhost') {
            return false;
        }

        foreach (['.localhost', '.local', '.internal', '.lan', '.home'] as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        return (bool)preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
            $host
        );
    }
}
