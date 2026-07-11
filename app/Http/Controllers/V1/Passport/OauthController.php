<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\Oauth\OauthProviderRegistry;
use App\Services\Oauth\OauthService;
use Illuminate\Http\Request;

class OauthController extends Controller
{
    public function providers()
    {
        return response([
            'data' => OauthProviderRegistry::enabledPublicList(),
            // 与 guest/comm/config 的 is_invite_force 一致，供登录页 OAuth 在强制邀请时收集邀请码
            'invite_force' => (int)config('v2board.invite_force', 0) ? 1 : 0,
        ]);
    }

    public function redirect(Request $request)
    {
        $provider = (string)$request->input('provider', '');
        if ($provider === '') {
            abort(500, '请指定登录平台');
        }
        if (OauthProviderRegistry::isTelegramWidget($provider)) {
            abort(500, 'Telegram 登录请使用 Login Widget');
        }

        $oauthService = new OauthService();
        $inviteCode = $request->input('invite_code');
        $url = $oauthService->buildAuthorizeUrl(
            $provider,
            'login',
            null,
            is_string($inviteCode) ? $inviteCode : null
        );
        return redirect()->away($url);
    }

    public function callback(Request $request)
    {
        $provider = (string)$request->input('provider', '');
        if ($provider === '') {
            abort(500, '请指定登录平台');
        }
        if (OauthProviderRegistry::isTelegramWidget($provider)) {
            abort(500, 'Telegram 登录请使用 Login Widget 回调接口');
        }

        $oauthService = new OauthService();

        try {
            $result = $oauthService->handleCallback($provider, $request);
        } catch (\Throwable $exception) {
            $message = $exception->getMessage() ?: '第三方登录失败';
            return $this->redirectWithMessage($message, true);
        }

        if (($result['mode'] ?? '') === 'bind') {
            return $this->redirectWithMessage('绑定成功', false, '/#/profile');
        }

        $user = $result['user'];
        $extraQuery = !empty($result['is_new']) ? ['oauth_setup' => 1] : [];
        $loginUrl = $oauthService->createLoginRedirect($user, 'dashboard', $extraQuery);
        return redirect()->away($loginUrl);
    }

    /**
     * Telegram Login Widget 登录。
     * 前端在 Widget 回调后 POST Widget 字段。
     */
    public function telegram(Request $request)
    {
        $payload = $this->extractTelegramPayload($request);
        $oauthService = new OauthService();
        $inviteCode = $request->input('invite_code');

        try {
            $result = $oauthService->handleTelegramWidget(
                $payload,
                'login',
                null,
                $request,
                is_string($inviteCode) ? $inviteCode : null
            );
        } catch (\Throwable $exception) {
            $status = method_exists($exception, 'getStatusCode') ? (int)$exception->getStatusCode() : 500;
            if ($status < 400 || $status >= 600) {
                $status = 500;
            }
            return response([
                'message' => $exception->getMessage() ?: 'Telegram 登录失败',
            ], $status);
        }

        $user = $result['user'];
        $auth = $result['auth'] ?? (new AuthService($user))->generateAuthData($request);

        return response([
            'data' => array_merge($auth, [
                'is_new' => !empty($result['is_new']),
            ]),
        ]);
    }

    private function extractTelegramPayload(Request $request): array
    {
        $keys = ['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date', 'hash'];
        $payload = [];
        foreach ($keys as $key) {
            if ($request->has($key) && $request->input($key) !== null && $request->input($key) !== '') {
                $payload[$key] = $request->input($key);
            }
        }
        return $payload;
    }

    private function redirectWithMessage(string $message, bool $isError = false, string $fallbackPath = '/#/login')
    {
        $query = http_build_query([
            'oauth_msg' => $message,
            'oauth_error' => $isError ? 1 : 0,
        ]);
        $path = $fallbackPath . (strpos($fallbackPath, '?') === false ? '?' : '&') . $query;
        if (config('v2board.app_url')) {
            return redirect()->away(rtrim(config('v2board.app_url'), '/') . $path);
        }
        return redirect()->away(url($path));
    }
}
