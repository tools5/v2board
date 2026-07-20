<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\User;
use App\Support\ConfiguredUrl;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use ReCaptcha\ReCaptcha;

class CommController extends Controller
{
    public function sendEmailVerify(CommSendEmailVerify $request)
    {
        $ip = $request->ip();
        $email = (string)$request->input('email');
        $cacheKeyEmail = strtolower(trim($email));
        $ipRateKey = 'email_verify:ip:' . $ip;
        $emailRateKey = 'email_verify:email:' . hash('sha256', $cacheKeyEmail);
        if (RateLimiter::tooManyAttempts($ipRateKey, 3)
            || RateLimiter::tooManyAttempts($emailRateKey, 3)) {
            abort(429, __('Too many requests, please try again later.'));
        }
        RateLimiter::hit($ipRateKey, 60);
        RateLimiter::hit($emailRateKey, 60);

        if ((int)config('v2board.recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
            if (!$recaptchaResp->isSuccess()) {
                abort(500, __('Invalid code is incorrect'));
            }
        }
        $isforget = $request->input('isforget');
        $email_exists = User::where('email', $email)->exists();
        //检查是否在白名单内
        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, __('Email suffix is not in the Whitelist'));
            }
        }
        // 检查是否是gmail别名邮箱
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }
        }
        if (isset($isforget)) {
            if ($isforget == 0 && $email_exists) {
                abort(500, __('This email is registered'));
            }
            if ($isforget == 1 && !$email_exists) {
                abort(500, __('This email is not registered in the system'));
            }
            // 注册走邮件链接时，禁止再发验证码
            if ((int)$isforget === 0
                && (int)config('v2board.email_verify', 0) === 1
                && config('v2board.register_email_mode', 'code') === 'link'
            ) {
                abort(500, '当前注册需通过邮箱完成，请使用页面上的「发送注册邮件」');
            }
            // 找回密码走邮件链接时，禁止再发验证码
            if ((int)$isforget === 1
                && config('v2board.register_email_mode', 'code') === 'link'
            ) {
                abort(500, '当前找回密码需通过邮箱完成，请使用页面上的「发送重置邮件」');
            }
        }
        if (Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $cacheKeyEmail))) {
            abort(500, __('Email verification code has been sent, please request again later'));
        }
        $code = (string)random_int(100000, 999999);
        $subject = config('v2board.app_name', 'V2Board') . __('Email verification code');

        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => $subject,
            'template_name' => 'verify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'code' => $code,
                'url' => ConfiguredUrl::applicationUrl()
            ]
        ]);

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail), $code, 300);
        Cache::put(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $cacheKeyEmail), time(), 60);
        return response([
            'data' => true
        ]);
    }

    public function pv(Request $request)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return response([
            'data' => true
        ]);
    }
}
