<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\Oauth\OauthProviderRegistry;
use App\Services\Oauth\OauthService;
use App\Support\ConfiguredUrl;
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
        $isPopup = (bool)$request->input('popup', false);
        $url = $oauthService->buildAuthorizeUrl(
            $provider,
            'login',
            null,
            is_string($inviteCode) ? $inviteCode : null,
            $isPopup
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
            return $this->popupOrRedirectOnError($message);
        }

        $isPopup = !empty($result['popup']);

        if (($result['mode'] ?? '') === 'bind') {
            if ($isPopup) {
                return $this->popupPostMessage(['type' => 'bind', 'success' => true]);
            }
            return $this->redirectWithMessage('绑定成功', false, '/#/profile');
        }

        $user = $result['user'];
        $authData = $result['auth'] ?? (new AuthService($user))->generateAuthData($request);

        if ($isPopup) {
            // 弹窗登录需回传完整凭证，主题侧用 token + auth_data 完成会话
            return $this->popupPostMessage([
                'type' => 'login',
                'auth_data' => $authData['auth_data'] ?? null,
                'token' => $authData['token'] ?? null,
                'is_admin' => $authData['is_admin'] ?? 0,
                'is_staff' => $authData['is_staff'] ?? 0,
                'is_new' => !empty($result['is_new']),
            ]);
        }

        $extraQuery = !empty($result['is_new']) ? ['oauth_setup' => 1] : [];
        $loginUrl = $oauthService->createLoginRedirect($user, 'dashboard', $extraQuery);
        return $this->redirectToFrontendUrl($loginUrl);
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
        return $this->redirectToFrontendPath($path);
    }

    /**
     * 异常时自动判断：如果当前窗口是弹窗（有 opener），用 postMessage 传递错误；否则常规重定向。
     * 因为异常发生时 state 可能已被消耗，无法从缓存得知是否为 popup 模式，
     * 所以返回一段 HTML 在客户端自动检测。
     */
    private function popupOrRedirectOnError(string $message)
    {
        $escapedMessage = $this->jsonForScript($message);
        $fallbackUrl = $this->buildErrorRedirectUrl($message);
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>登录失败</title></head>
<body>
<script>
(function() {
    var msg = {$escapedMessage};
    if (window.opener) {
        var data = { error: msg, _source: 'v2board_oauth_popup' };
        window.opener.postMessage(data, location.origin);
        window.close();
    } else {
        location.href = {$this->jsonForScript($fallbackUrl)};
    }
})();
</script>
<noscript><p>登录失败：{$this->escapeHtml($message)}</p></noscript>
</body>
</html>
HTML;
        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * 返回一段极简 HTML，通过 window.opener.postMessage 将结果传回主窗口并关闭弹窗。
     * 使用 targetOrigin = location.origin 确保只向同源父窗口发送数据。
     */
    private function popupPostMessage(array $payload)
    {
        $jsonPayload = $this->jsonForScript($payload);
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>登录中...</title></head>
<body>
<script>
(function() {
    var data = {$jsonPayload};
    data._source = 'v2board_oauth_popup';
    if (window.opener) {
        window.opener.postMessage(data, location.origin);
    }
    window.close();
    setTimeout(function() {
        document.body.innerHTML = '<p style="text-align:center;margin-top:40px;">登录完成，请关闭此窗口。</p>';
    }, 500);
})();
</script>
</body>
</html>
HTML;
        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function buildErrorRedirectUrl(string $message): string
    {
        $query = http_build_query([
            'oauth_msg' => $message,
            'oauth_error' => 1,
        ]);
        $path = '/#/login?' . $query;
        return ConfiguredUrl::applicationPathUrl($path);
    }

    private function redirectToFrontendPath(string $path)
    {
        return $this->redirectToFrontendUrl(ConfiguredUrl::applicationPathUrl($path));
    }

    private function redirectToFrontendUrl(string $url)
    {
        if (strpos($url, '/#/') === 0) {
            return response('', 302)->header('Location', $url);
        }

        return redirect()->away($url);
    }

    private function jsonForScript($value): string
    {
        $encoded = json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode OAuth popup payload');
        }

        return $encoded;
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
