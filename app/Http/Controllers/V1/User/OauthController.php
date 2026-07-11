<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\Oauth\OauthProviderRegistry;
use App\Services\Oauth\OauthService;
use Illuminate\Http\Request;

class OauthController extends Controller
{
    public function providers()
    {
        return response([
            'data' => OauthProviderRegistry::enabledPublicList(),
        ]);
    }

    public function bindings(Request $request)
    {
        $oauthService = new OauthService();
        return response([
            'data' => $oauthService->listBindings((int)$request->user['id']),
        ]);
    }

    public function bind(Request $request)
    {
        $provider = (string)$request->input('provider', '');
        if ($provider === '') {
            abort(500, '请指定要绑定的平台');
        }
        if (OauthProviderRegistry::isTelegramWidget($provider)) {
            abort(500, 'Telegram 绑定请使用 Login Widget 接口');
        }

        $oauthService = new OauthService();
        $url = $oauthService->buildAuthorizeUrl($provider, 'bind', (int)$request->user['id']);
        return response([
            'data' => $url,
        ]);
    }

    /**
     * Telegram Login Widget 绑定（已登录用户）。
     */
    public function telegramBind(Request $request)
    {
        $payload = [];
        foreach (['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date', 'hash'] as $key) {
            if ($request->has($key) && $request->input($key) !== null && $request->input($key) !== '') {
                $payload[$key] = $request->input($key);
            }
        }

        $oauthService = new OauthService();
        try {
            $oauthService->handleTelegramWidget($payload, 'bind', (int)$request->user['id'], $request);
        } catch (\Throwable $exception) {
            $status = method_exists($exception, 'getStatusCode') ? (int)$exception->getStatusCode() : 500;
            if ($status < 400 || $status >= 600) {
                $status = 500;
            }
            return response([
                'message' => $exception->getMessage() ?: 'Telegram 绑定失败',
            ], $status);
        }

        return response([
            'data' => true,
        ]);
    }

    public function unbind(Request $request)
    {
        $provider = (string)$request->input('provider', '');
        if ($provider === '') {
            abort(500, '请指定要解绑的平台');
        }

        $oauthService = new OauthService();
        $oauthService->unbind((int)$request->user['id'], $provider);
        return response([
            'data' => true,
        ]);
    }
}
