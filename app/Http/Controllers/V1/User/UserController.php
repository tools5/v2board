<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserRedeemGiftCard;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Giftcard;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserOauth;
use App\Services\AuthService;
use App\Services\Oauth\OauthService;
use App\Services\OrderService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    public function getActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->getSessionsAndRefreshCurrent($request)
        ]);
    }

    public function removeActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->removeSession($request->input('session_id'))
        ]);
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user['id'] ? true : false
        ];
        if ($request->user['is_admin']) {
            $data['is_admin'] = true;
        }
        if (!empty($request->user['is_staff'])) {
            $data['is_staff'] = true;
        }
        return response([
            'data' => $data
        ]);
    }

    public function changePassword(UserChangePassword $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        // 判断该用户是否为「从未设置过真实密码」的 OAuth 自动注册用户：
        // 若任一第三方绑定标记了 password_never_set，则允许免旧密码「设置密码」。
        $bindingsNeverSet = false;
        if (Schema::hasTable('v2_user_oauth')) {
            $bindingsNeverSet = UserOauth::where('user_id', $user->id)
                ->where('password_never_set', 1)
                ->exists();
        }

        if (!$bindingsNeverSet) {
            // 常规用户（或已设过密码的 OAuth 用户）：必须校验旧密码。
            if (!Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $request->input('old_password'),
                $user->password
            )) {
                abort(500, __('The old password is wrong'));
            }
        }

        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            abort(500, __('Save failed'));
        }

        // 设置成功后清除「从未设置密码」标记，之后修改密码需校验旧密码。
        if ($bindingsNeverSet) {
            \App\Services\Oauth\OauthService::markPasswordSet((int)$user->id);
        }

        $authService = new AuthService($user);
        $authService->removeAllSession();
        return response([
            'data' => true
        ]);
    }

    public function newPeriod(Request $request) 
    {
        if (!config('v2board.allow_new_period', 0)) {
            abort(500, __('Renewal is not allowed'));
        }
        DB::beginTransaction();
        try {
            $user = User::find($request->user['id']);
            if (!$user) {
                abort(500, __('The user does not exist'));
            }
            if ($user->transfer_enable > $user->u + $user->d) {
                abort(500, __('You have not used up your traffic, you cannot renew your subscription'));
            }
            $userService = new UserService();
            $reset_day = $userService->getResetDay($user);
            if ($reset_day === null) {
                abort(500, __('You do not allow to renew the subscription'));
            }
            unset($user->plan);
            $reset_period = $userService->getResetPeriod($user);
            if ($reset_period === null) {
                abort(500, __('You do not allow to renew the subscription'));
            }
            switch ($reset_period) {
                case 1:
                    $reset_day = 30;
                    $reset_period = 30;
                    break;
                case 30:
                    break;
                case 12:
                    $reset_day = 365;
                    $reset_period = 365;
                    break;
                case 365:
                    break;
                default:
                    abort(500, __('Invalid reset period'));
            }
            if ($reset_day <= 0) {
                $reset_day = $reset_period;
            }
            if ($user->expired_at !== null && ($reset_period + 1) * 86400 < $user->expired_at - time()) {
                if (!$user->update(
                    [
                        'expired_at' => $user->expired_at - $reset_day * 86400,
                        'u' => 0,
                        'd' => 0
                    ]
                )) {
                    throw new \Exception(__('Save failed'));
                }
            } else {
                abort(500, __('You do not have enough time to renew your subscription'));
            }

            DB::commit();
            return response([
                'data' => true
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
    }

    public function redeemgiftcard(UserRedeemGiftCard $request)
    {
        DB::beginTransaction();

        try {
            $user = User::find($request->user['id']);
            if (!$user) {
                abort(500, __('The user does not exist'));
            }
            $giftcard_input = $request->giftcard;
            $giftcard = Giftcard::where('code', $giftcard_input)->first();

            if (!$giftcard) {
                abort(500, __('The gift card does not exist'));
            }

            $currentTime = time();
            if ($giftcard->started_at && $currentTime < $giftcard->started_at) {
                abort(500, __('The gift card is not yet valid'));
            }

            if ($giftcard->ended_at && $currentTime > $giftcard->ended_at) {
                abort(500, __('The gift card has expired'));
            }

            if ($giftcard->limit_use !== null) {
                if (!is_numeric($giftcard->limit_use) || $giftcard->limit_use <= 0) {
                    abort(500, __('The gift card usage limit has been reached'));
                }
            }

            $usedUserIds = $giftcard->used_user_ids ? json_decode($giftcard->used_user_ids, true) : [];
            if (!is_array($usedUserIds)) {
                $usedUserIds = [];
            }

            if (in_array($user->id, $usedUserIds)) {
                abort(500, __('The gift card has already been used by this user'));
            }

            $usedUserIds[] = $user->id;
            $giftcard->used_user_ids = json_encode($usedUserIds);

            switch ($giftcard->type) {
                case 1:
                    $user->balance += $giftcard->value;
                    break;
                case 2:
                    if ($user->expired_at !== null) {
                        if ($user->expired_at <= $currentTime) {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        } else {
                            $user->expired_at += $giftcard->value * 86400;
                        }
                    } else {
                        abort(500, __('Not suitable gift card type'));
                    }
                    break;
                case 3:
                    $user->transfer_enable += $giftcard->value * 1073741824;
                    break;
                case 4:
                    $user->u = 0;
                    $user->d = 0;
                    break;
                case 5:
                    if ($user->plan_id == null || ($user->expired_at !== null && $user->expired_at < $currentTime)) {
                        $plan = Plan::where('id', $giftcard->plan_id)->first();
                        $user->plan_id = $plan->id;
                        $user->group_id = $plan->group_id;
                        $user->transfer_enable = $plan->transfer_enable * 1073741824;
                        $user->device_limit = $plan->device_limit;
                        $user->u = 0;
                        $user->d = 0;
                        if($giftcard->value == 0) {
                            $user->expired_at = null;
                        } else {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        }
                    } else {
                        abort(500, __('Not suitable gift card type'));
                    }
                    break;
                default:
                    abort(500, __('Unknown gift card type'));
            }

            if ($giftcard->limit_use !== null) {
                $giftcard->limit_use -= 1;
            }

            if (!$user->save() || !$giftcard->save()) {
                throw new \Exception(__('Save failed'));
            }

            DB::commit();

            return response([
                'data' => true,
                'type' => $giftcard->type,
                'value' => $giftcard->value
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
    }

    public function info(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'email',
                'transfer_enable',
                'device_limit',
                'last_login_at',
                'created_at',
                'banned',
                'auto_renewal',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid',
                'is_admin',
                'is_staff'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        // 第三方登录绑定信息：用于前端判断是否为 OAuth 用户、展示第三方用户名/头像。
        $oauthBindings = collect();
        if (Schema::hasTable('v2_user_oauth')) {
            $oauthBindings = UserOauth::where('user_id', $request->user['id'])->get();
        }
        $primaryBinding = $oauthBindings->first();

        // 是否为「占位邮箱」（OAuth 自动注册但第三方未提供真实邮箱），
        // 用于前端隐藏假邮箱、引导绑定真实邮箱。
        $isPlaceholderEmail = (bool)preg_match('/@oauth\.local$/i', (string)$user->email);

        // 头像优先级：绑定的第三方头像 > 基于邮箱的 Cravatar。
        $providerAvatar = null;
        foreach ($oauthBindings as $binding) {
            if (!empty($binding->provider_avatar)) {
                $providerAvatar = $binding->provider_avatar;
                break;
            }
        }
        $user['avatar_url'] = $providerAvatar
            ?: 'https://cravatar.cn/avatar/' . md5((string)$user->email) . '?s=64&d=identicon';

        // OAuth 相关标记（供前端做邮箱替换显示、设置密码、绑定引导等）
        $user['is_oauth_user'] = $oauthBindings->isNotEmpty();
        $user['is_placeholder_email'] = $isPlaceholderEmail;
        // 是否已设置过真实密码：OAuth 自动注册用户初始为随机密码，标记为未设置。
        // 只要任一绑定标记了 password_never_set，即视为尚未设置真实密码。
        $user['has_password'] = !$oauthBindings->contains(function ($binding) {
            return (bool)$binding->password_never_set;
        });
        $user['oauth_bindings'] = $oauthBindings->map(function ($binding) {
            $meta = \App\Services\Oauth\OauthProviderRegistry::get($binding->provider);
            return [
                'provider' => $binding->provider,
                'provider_name' => $meta['name'] ?? $binding->provider,
                'provider_username' => $binding->provider_username,
                'provider_email' => $binding->provider_email,
            ];
        })->values();
        // 主展示名：优先第三方用户名，形如「GitHub: octocat」
        if ($primaryBinding) {
            $meta = \App\Services\Oauth\OauthProviderRegistry::get($primaryBinding->provider);
            $providerName = $meta['name'] ?? $primaryBinding->provider;
            $user['oauth_display_name'] = $primaryBinding->provider_username
                ? $providerName . ': ' . $primaryBinding->provider_username
                : $providerName;
        } else {
            $user['oauth_display_name'] = null;
        }

        // 角色标记：供前端总览徽章等展示（管理员优先于员工）
        $user['is_admin'] = (bool)$user->is_admin;
        $user['is_staff'] = (bool)$user->is_staff;

        return response([
            'data' => $user
        ]);
    }

    public function setupOauthInfo(Request $request)
    {
        // 供 OAuth 首次注册「完善信息」引导页使用：可设置真实邮箱和/或密码，
        // 两者都是可选的（前端可「跳过」），但至少要提交一项。
        $newEmail = $request->input('email');
        $newPassword = $request->input('password');

        $hasEmail = is_string($newEmail) && trim($newEmail) !== '';
        $hasPassword = is_string($newPassword) && $newPassword !== '';
        if (!$hasEmail && !$hasPassword) {
            abort(400, '请至少填写邮箱或密码');
        }

        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        // 仅允许 OAuth 绑定用户使用该接口，避免普通用户绕过校验直接改邮箱。
        $bindings = UserOauth::where('user_id', $user->id)->get();
        if ($bindings->isEmpty()) {
            abort(403, '该功能仅对第三方登录用户开放');
        }

        $isPlaceholderEmail = (bool)preg_match('/@oauth\.local$/i', (string)$user->email);

        DB::beginTransaction();
        try {
            if ($hasEmail) {
                // 仅允许把占位邮箱替换为真实邮箱，禁止已绑定真实邮箱的用户在此接口改邮箱
                if (!$isPlaceholderEmail) {
                    abort(400, '当前账号已绑定真实邮箱，如需修改请联系管理员');
                }
                $newEmail = trim($newEmail);
                if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    abort(400, __('Email format is incorrect'));
                }
                // 禁止再次写成占位域
                if (preg_match('/@oauth\.local$/i', $newEmail)) {
                    abort(400, '请填写真实邮箱地址');
                }
                // 邮箱后缀白名单校验（复用注册配置）
                if ((int)config('v2board.email_whitelist_enable', 0)) {
                    if (!Helper::emailSuffixVerify(
                        $newEmail,
                        config('v2board.email_whitelist_suffix', \App\Utils\Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT)
                    )) {
                        abort(400, __('Email suffix is not in the Whitelist'));
                    }
                }
                // Gmail 别名（含 +）拦截，与注册逻辑保持一致
                if ((int)config('v2board.email_gmail_limit_enable', 0)) {
                    $prefix = explode('@', $newEmail)[0];
                    if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                        abort(400, __('Gmail alias is not supported'));
                    }
                }
                // 邮箱唯一性校验
                $existUser = User::where('email', $newEmail)->where('id', '!=', $user->id)->first();
                if ($existUser) {
                    abort(400, __('Email already exists'));
                }
                $user->email = $newEmail;
            }

            if ($hasPassword) {
                if (strlen($newPassword) < 8) {
                    abort(400, __('Password must be greater than 8 digits'));
                }
                $user->password = password_hash($newPassword, PASSWORD_DEFAULT);
                $user->password_algo = null;
                $user->password_salt = null;
            }

            if (!$user->save()) {
                throw new \Exception(__('Save failed'));
            }

            // 设置了真实密码则清除「从未设置密码」标记。
            if ($hasPassword) {
                \App\Services\Oauth\OauthService::markPasswordSet((int)$user->id);
            }

            // 占位邮箱换成真实邮箱时，同步独立 OAuth 用户表
            if ($hasEmail) {
                \App\Services\Oauth\OauthService::syncOauthUserEmail((int)$user->id, (string)$user->email);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return response([
            'data' => true
        ]);
    }

    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            User::where('invite_user_id', $request->user['id'])
                ->count()
        ];
        return response([
            'data' => $stat
        ]);
    }

    public function getSubscribe(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'device_limit',
                'email',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                abort(500, __('Subscription plan does not exist'));
            }
        }

        //统计在线设备
        $countalive = 0;
        $ips_array = Cache::get('ALIVE_IP_USER_' . $request->user['id']);
        if ($ips_array) {
            $countalive = $ips_array['alive_ip'];
        }
        $user['alive_ip'] = $countalive;

        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);

        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        $user['allow_new_period'] = config('v2board.allow_new_period', 0);
        return response([
            'data' => $user
        ]);
    }

    public function unbindTelegram(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        // 若已有 OAuth Telegram 绑定，走统一解绑（含 password_never_set 保护，并同步清空 telegram_id）
        if (Schema::hasTable('v2_user_oauth')) {
            $hasOauthBinding = UserOauth::where('user_id', $user->id)
                ->where('provider', 'telegram')
                ->exists();
            if ($hasOauthBinding) {
                (new OauthService())->unbind((int)$user->id, 'telegram');
                return response([
                    'data' => true
                ]);
            }
        }

        if (!$user->update(['telegram_id' => null])) {
            abort(500, __('Unbind telegram failed'));
        }
        return response([
            'data' => true
        ]);
    }

    public function resetSecurity(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            abort(500, __('Reset failed'));
        }
        return response([
            'data' => Helper::getSubscribeUrl($user['token'])
        ]);
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'auto_renewal',
            'remind_expire',
            'remind_traffic'
        ]);

        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        try {
            $user->update($updateData);
        } catch (\Exception $e) {
            abort(500, __('Save failed'));
        }

        return response([
            'data' => true
        ]);
    }

    public function transfer(UserTransfer $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($request->input('transfer_amount') > $user->commission_balance) {
            abort(500, __('Insufficient commission balance'));
        }
        DB::beginTransaction();
        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $request->user['id'];
        $order->plan_id = 0;
        $order->period = 'deposit';
        $order->trade_no = Helper::generateOrderNo();
        $order->total_amount = $request->input('transfer_amount');

        $orderService->setOrderType($user);
        $orderService->setInvite($user);

        $user->commission_balance = $user->commission_balance - $request->input('transfer_amount');
        $user->balance = $user->balance + $request->input('transfer_amount');
        $order->status = 3;
        $order->total_amount = 0;
        $order->surplus_amount = $request->input('transfer_amount');
        $order->callback_no = '佣金划转 Commission transfer';
        if (!$order->save()||!$user->save()) {
            DB::rollback();
            abort(500, __('Transfer failed'));
        }

        DB::commit();

        return response([
            'data' => true
        ]);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 60);
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
}
