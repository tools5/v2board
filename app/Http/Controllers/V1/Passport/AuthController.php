<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Http\Requests\Passport\AuthRegisterWithLink;
use App\Http\Requests\Passport\AuthResetPasswordWithLink;
use App\Http\Requests\Passport\AuthSendPasswordResetLink;
use App\Http\Requests\Passport\AuthSendRegisterLink;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserOauth;
use App\Services\AuthService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use ReCaptcha\ReCaptcha;

class AuthController extends Controller
{
    public function register(AuthRegister $request)
    {
        if ($this->isRegisterLinkModeEnabled()) {
            abort(500, '当前注册需通过邮箱完成，请先收取注册邮件并打开链接完成注册');
        }

        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int)$registerCountByIP >= (int)config('v2board.register_limit_count', 3)) {
                abort(500, __('Register frequently, please try again after :minute minute', [
                    'minute' => config('v2board.register_limit_expire', 60)
                ]));
            }
        }
        if ((int)config('v2board.recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
            if (!$recaptchaResp->isSuccess()) {
                abort(500, __('Invalid code is incorrect'));
            }
        }
        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, __('Email suffix is not in the Whitelist'));
            }
        }
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }
        }
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, __('Registration has closed'));
        }
        if ((int)config('v2board.invite_force', 0)) {
            if (empty($request->input('invite_code'))) {
                abort(500, __('You must use the invitation code to register'));
            }
        }
        $email = $request->input('email');
        $cacheKeyEmail = is_string($email) ? strtolower(trim($email)) : '';
        if ((int)config('v2board.email_verify', 0)) {
            $inputCode = $request->input('email_code');
            if (!is_string($inputCode) || !preg_match('/^\d{6}$/', $inputCode)) {
                abort(500, __('Incorrect email verification code'));
            }
            $cachedCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
            if ($cachedCode === null || $cachedCode === '' || !hash_equals((string)$cachedCode, $inputCode)) {
                abort(500, __('Incorrect email verification code'));
            }
        }
        $password = $request->input('password');
        $exist = User::where('email', $email)->first();
        if ($exist) {
            abort(500, __('Email already exists'));
        }

        $user = $this->createRegisteredUser(
            $email,
            $password,
            is_string($request->input('invite_code')) ? $request->input('invite_code') : ''
        );

        if ((int)config('v2board.email_verify', 0)) {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
        }

        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            Cache::put(
                CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()),
                (int)$registerCountByIP + 1,
                (int)config('v2board.register_limit_expire', 60) * 60
            );
        }

        $authService = new AuthService($user);

        return response()->json([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    /**
     * 发送注册邮件链接（仅 email_verify + register_email_mode=link 时可用）
     */
    public function sendRegisterLink(AuthSendRegisterLink $request)
    {
        if (!$this->isRegisterLinkModeEnabled()) {
            abort(500, '当前未开启该注册方式');
        }
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, __('Registration has closed'));
        }

        $ip = $request->ip();
        if (RateLimiter::tooManyAttempts('register_link:' . $ip, 3)) {
            abort(429, __('Too many requests, please try again later.'));
        }
        RateLimiter::hit('register_link:' . $ip, 60);

        if ((int)config('v2board.recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
            if (!$recaptchaResp->isSuccess()) {
                abort(500, __('Invalid code is incorrect'));
            }
        }

        $email = (string)$request->input('email');
        $cacheKeyEmail = strtolower(trim($email));
        $inviteCode = trim((string)$request->input('invite_code', ''));

        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $email,
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, __('Email suffix is not in the Whitelist'));
            }
        }
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $email)[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }
        }
        if ((int)config('v2board.invite_force', 0) && $inviteCode === '') {
            abort(500, __('You must use the invitation code to register'));
        }
        if ($inviteCode !== '') {
            $invite = InviteCode::where('code', $inviteCode)->where('status', 0)->first();
            if (!$invite && (int)config('v2board.invite_force', 0)) {
                abort(500, __('Invalid invitation code'));
            }
            if (!$invite) {
                $inviteCode = '';
            }
        }
        if (User::where('email', $email)->exists()) {
            abort(500, __('This email is registered'));
        }
        if (Cache::get(CacheKey::get('LAST_SEND_REGISTER_LINK_TIMESTAMP', $cacheKeyEmail))) {
            abort(500, __('Email verification code has been sent, please request again later'));
        }

        $token = Helper::guid();
        $expireSeconds = 1800;
        Cache::put(CacheKey::get('REGISTER_LINK_TOKEN', $token), [
            'email' => $email,
            'invite_code' => $inviteCode,
            'created_at' => time(),
        ], $expireSeconds);
        Cache::put(CacheKey::get('LAST_SEND_REGISTER_LINK_TIMESTAMP', $cacheKeyEmail), time(), 60);

        $baseUrl = rtrim((string)config('v2board.app_url') ?: url('/'), '/');
        $link = $this->buildFrontendAuthLink('register', [
            'register_token' => $token,
        ]);

        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => '[' . config('v2board.app_name', 'V2Board') . '] 验证您的电子邮箱地址',
            'template_name' => 'registerLink',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'link' => $link,
                'expire_minutes' => (int)($expireSeconds / 60),
                'url' => $baseUrl,
            ],
        ]);

        return response([
            'data' => true,
        ]);
    }

    /**
     * 通过注册邮件链接完成注册（设置密码）
     */
    public function registerWithLink(AuthRegisterWithLink $request)
    {
        if (!$this->isRegisterLinkModeEnabled()) {
            abort(500, '当前未开启该注册方式');
        }
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, __('Registration has closed'));
        }

        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int)$registerCountByIP >= (int)config('v2board.register_limit_count', 3)) {
                abort(500, __('Register frequently, please try again after :minute minute', [
                    'minute' => config('v2board.register_limit_expire', 60)
                ]));
            }
        }

        $token = (string)$request->input('token');
        $cacheKey = CacheKey::get('REGISTER_LINK_TOKEN', $token);
        $payload = Cache::get($cacheKey);
        if (!is_array($payload) || empty($payload['email'])) {
            abort(500, '链接无效或已过期，请重新获取');
        }

        $email = (string)$payload['email'];
        $inviteFromToken = isset($payload['invite_code']) ? trim((string)$payload['invite_code']) : '';
        $inviteFromRequest = trim((string)$request->input('invite_code', ''));
        $inviteCode = $inviteFromRequest !== '' ? $inviteFromRequest : $inviteFromToken;

        if ((int)config('v2board.invite_force', 0) && $inviteCode === '') {
            abort(500, __('You must use the invitation code to register'));
        }

        if (User::where('email', $email)->exists()) {
            Cache::forget($cacheKey);
            abort(500, __('Email already exists'));
        }

        // 先创建用户，成功后再销毁 token，避免校验失败浪费链接
        $user = $this->createRegisteredUser($email, (string)$request->input('password'), $inviteCode);
        Cache::forget($cacheKey);

        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            Cache::put(
                CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()),
                (int)$registerCountByIP + 1,
                (int)config('v2board.register_limit_expire', 60) * 60
            );
        }

        $authService = new AuthService($user);
        return response()->json([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    /**
     * 校验注册邮件链接是否仍有效，并返回预填邮箱（不消费 token）
     */
    public function checkRegisterLink(Request $request)
    {
        $token = (string)$request->input('token', '');
        if ($token === '' || strlen($token) < 16) {
            abort(400, '链接无效');
        }
        $payload = Cache::get(CacheKey::get('REGISTER_LINK_TOKEN', $token));
        if (!is_array($payload) || empty($payload['email'])) {
            abort(500, '链接无效或已过期，请重新获取');
        }
        return response([
            'data' => [
                'email' => $payload['email'],
                'invite_code' => $payload['invite_code'] ?? '',
            ],
        ]);
    }

    /**
     * 是否处于「邮箱验证 + 邮件链接注册」模式
     */
    private function isRegisterLinkModeEnabled(): bool
    {
        return (int)config('v2board.email_verify', 0) === 1
            && $this->isEmailLinkModeEnabled();
    }

    /**
     * 是否开启「邮件链接」邮箱验证方式（注册/找回密码共用配置 register_email_mode）
     */
    private function isEmailLinkModeEnabled(): bool
    {
        return config('v2board.register_email_mode', 'code') === 'link';
    }

    /**
     * 生成前台认证相关链接（兼容 hash 路由主题）
     */
    private function buildFrontendAuthLink(string $page, array $query = []): string
    {
        $baseUrl = rtrim((string)config('v2board.app_url') ?: url('/'), '/');
        $theme = (string)config('v2board.frontend_theme', 'default');
        $queryString = http_build_query($query);

        // default / v2board 使用 forgetpassword；blued 使用 forgot-password
        if ($page === 'forgot-password' || $page === 'forgetpassword') {
            $hashPage = in_array($theme, ['default', 'v2board'], true) ? 'forgetpassword' : 'forgot-password';
        } else {
            $hashPage = $page === 'register' ? 'register' : $page;
        }

        // 当前内置主题均走 hash 路由
        return $baseUrl . '/#/' . $hashPage . ($queryString !== '' ? '?' . $queryString : '');
    }

    /**
     * 创建注册用户（邮箱注册 / 链接注册共用）
     */
    private function createRegisteredUser(string $email, string $password, string $inviteCode = ''): User
    {
        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();

        if ($inviteCode !== '') {
            $invite = InviteCode::where('code', $inviteCode)
                ->where('status', 0)
                ->first();
            if (!$invite) {
                if ((int)config('v2board.invite_force', 0)) {
                    abort(500, __('Invalid invitation code'));
                }
            } else {
                $user->invite_user_id = $invite->user_id ? $invite->user_id : null;
                if (!(int)config('v2board.invite_never_expire', 0)) {
                    $invite->status = 1;
                    $invite->save();
                }
            }
        }

        if ((int)config('v2board.try_out_plan_id', 0)) {
            $plan = Plan::find(config('v2board.try_out_plan_id'));
            if ($plan) {
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->device_limit = $plan->device_limit;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->expired_at = time() + ((int)config('v2board.try_out_hour', 1) * 3600);
                $user->speed_limit = $plan->speed_limit;
            }
        }

        if (!$user->save()) {
            abort(500, __('Register failed'));
        }

        $user->last_login_at = time();
        $user->save();

        return $user;
    }

    public function login(AuthLogin $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if ((int)config('v2board.password_limit_enable', 1)) {
            $passwordErrorCount = (int)Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int)config('v2board.password_limit_count', 5)) {
                abort(500, __('There are too many password errors, please try again after :minute minutes.', [
                    'minute' => config('v2board.password_limit_expire', 60)
                ]));
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, __('Incorrect email or password'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $password,
            $user->password)
        ) {
            if ((int)config('v2board.password_limit_enable')) {
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    (int)$passwordErrorCount + 1,
                    60 * (int)config('v2board.password_limit_expire', 60)
                );
            }
            abort(500, __('Incorrect email or password'));
        }

        if ($user->banned) {
            abort(500, __('Your account has been suspended'));
        }

        $authService = new AuthService($user);
        return response([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function token2Login(Request $request)
    {
        if ($request->input('token')) {
            $redirect = '/#/login?verify=' . $request->input('token') . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
            if (config('v2board.app_url')) {
                $location = config('v2board.app_url') . $redirect;
            } else {
                $location = url($redirect);
            }
            return redirect()->to($location)->send();
        }

        if ($request->input('verify')) {
            $key =  CacheKey::get('TEMP_TOKEN', $request->input('verify'));
            $userId = Cache::get($key);
            if (!$userId) {
                abort(500, __('Token error'));
            }
            $user = User::find($userId);
            if (!$user) {
                abort(500, __('The user does not '));
            }
            if ($user->banned) {
                abort(500, __('Your account has been suspended'));
            }
            Cache::forget($key);
            $authService = new AuthService($user);
            return response([
                'data' => $authService->generateAuthData($request)
            ]);
        }
    }

    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) abort(403, '未登录或登陆已过期');

        $user = AuthService::decryptAuthData($authorization);
        if (!$user) abort(403, '未登录或登陆已过期');

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user['id'], 60);
        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $url = config('v2board.app_url') . $redirect;
        } else {
            $url = url($redirect);
        }
        return response([
            'data' => $url
        ]);
    }

    public function forget(AuthForget $request)
    {
        if ($this->isEmailLinkModeEnabled()) {
            abort(500, '当前找回密码需通过邮箱完成，请使用页面上的「发送重置邮件」');
        }

        $email     = $request->input('email');
        $inputCode = $request->input('email_code');
        $password  = $request->input('password');

        if (!is_string($email) || !is_string($inputCode) || !is_string($password)) {
            abort(500, __('Incorrect email verification code'));
        }
        if (!preg_match('/^\d{6}$/', $inputCode)) {
            abort(500, __('Incorrect email verification code'));
        }

        $cacheKeyEmail         = strtolower(trim($email));
        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $cacheKeyEmail);
        $forgetRequestLimit    = (int)Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) {
            abort(500, __('Reset failed, Please try again later'));
        }

        $cachedCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
        if ($cachedCode === null || $cachedCode === '' || !hash_equals((string)$cachedCode, $inputCode)) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit + 1, 300);
            abort(500, __('Incorrect email verification code'));
        }
        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, __('This email is not registered in the system'));
        }
        $this->applyNewPassword($user, (string)$password);
        Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
        (new AuthService($user))->removeAllSession();
        return response([
            'data' => true
        ]);
    }

    /**
     * 发送找回密码邮件链接（register_email_mode=link）
     */
    public function sendPasswordResetLink(AuthSendPasswordResetLink $request)
    {
        if (!$this->isEmailLinkModeEnabled()) {
            abort(500, '当前未开启该找回密码方式');
        }

        $ip = $request->ip();
        if (RateLimiter::tooManyAttempts('password_reset_link:' . $ip, 3)) {
            abort(429, __('Too many requests, please try again later.'));
        }
        RateLimiter::hit('password_reset_link:' . $ip, 60);

        if ((int)config('v2board.recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
            if (!$recaptchaResp->isSuccess()) {
                abort(500, __('Invalid code is incorrect'));
            }
        }

        $email = (string)$request->input('email');
        $cacheKeyEmail = strtolower(trim($email));

        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $email,
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, __('Email suffix is not in the Whitelist'));
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, __('This email is not registered in the system'));
        }
        if ($user->banned) {
            abort(500, __('Your account has been suspended'));
        }
        if (Cache::get(CacheKey::get('LAST_SEND_PASSWORD_RESET_LINK_TIMESTAMP', $cacheKeyEmail))) {
            abort(500, __('Email verification code has been sent, please request again later'));
        }

        $token = Helper::guid();
        $expireSeconds = 1800;
        Cache::put(CacheKey::get('PASSWORD_RESET_LINK_TOKEN', $token), [
            'email' => $email,
            'user_id' => $user->id,
            'created_at' => time(),
        ], $expireSeconds);
        Cache::put(CacheKey::get('LAST_SEND_PASSWORD_RESET_LINK_TIMESTAMP', $cacheKeyEmail), time(), 60);

        $baseUrl = rtrim((string)config('v2board.app_url') ?: url('/'), '/');
        $link = $this->buildFrontendAuthLink('forgot-password', [
            'reset_token' => $token,
        ]);

        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => '[' . config('v2board.app_name', 'V2Board') . '] 重置密码链接',
            'template_name' => 'passwordResetLink',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'link' => $link,
                'expire_minutes' => (int)($expireSeconds / 60),
                'url' => $baseUrl,
            ],
        ]);

        return response([
            'data' => true,
        ]);
    }

    /**
     * 通过重置密码邮件链接设置新密码
     */
    public function resetPasswordWithLink(AuthResetPasswordWithLink $request)
    {
        if (!$this->isEmailLinkModeEnabled()) {
            abort(500, '当前未开启该找回密码方式');
        }

        $token = (string)$request->input('token');
        $cacheKey = CacheKey::get('PASSWORD_RESET_LINK_TOKEN', $token);
        $payload = Cache::get($cacheKey);
        if (!is_array($payload) || empty($payload['email'])) {
            abort(500, '链接无效或已过期，请重新获取');
        }

        $email = (string)$payload['email'];
        $user = User::where('email', $email)->first();
        if (!$user) {
            Cache::forget($cacheKey);
            abort(500, __('This email is not registered in the system'));
        }
        if ($user->banned) {
            abort(500, __('Your account has been suspended'));
        }

        $this->applyNewPassword($user, (string)$request->input('password'));
        Cache::forget($cacheKey);
        (new AuthService($user))->removeAllSession();

        return response([
            'data' => true,
        ]);
    }

    /**
     * 校验重置密码邮件链接是否有效（不消费 token）
     */
    public function checkPasswordResetLink(Request $request)
    {
        $token = (string)$request->input('token', '');
        if ($token === '' || strlen($token) < 16) {
            abort(400, '链接无效');
        }
        $payload = Cache::get(CacheKey::get('PASSWORD_RESET_LINK_TOKEN', $token));
        if (!is_array($payload) || empty($payload['email'])) {
            abort(500, '链接无效或已过期，请重新获取');
        }
        return response([
            'data' => [
                'email' => $payload['email'],
            ],
        ]);
    }

    /**
     * 更新用户密码，并清除 OAuth「从未设置密码」标记
     */
    private function applyNewPassword(User $user, string $password): void
    {
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->password_algo = null;
        $user->password_salt = null;
        if (!$user->save()) {
            abort(500, __('Reset failed'));
        }

        if (Schema::hasTable('v2_user_oauth')) {
            UserOauth::where('user_id', $user->id)
                ->update(['password_never_set' => 0]);
        }
        if (class_exists(\App\Services\Oauth\OauthService::class)) {
            \App\Services\Oauth\OauthService::markPasswordSet((int)$user->id);
        }
    }
}
