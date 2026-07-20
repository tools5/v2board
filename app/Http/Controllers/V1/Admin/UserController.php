<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserFetch;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\Ticket;
use App\Models\Order;
use App\Models\Plan;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\UserOauth;
use App\Services\AuthService;
use App\Support\ConfiguredUrl;
use App\Services\Oauth\OauthProviderRegistry;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    public function resetSecret(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user) abort(500, '用户不存在');
        $user->token = Helper::guid();
        $user->uuid = Helper::guid(true);
        return response([
            'data' => $user->save()
        ]);
    }

    private function filter(Request $request, $builder)
    {
        // OAuth 独立用户不出现在用户管理
        if (\Illuminate\Support\Facades\Schema::hasTable('v2_oauth_user')) {
            $builder->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('v2_oauth_user')
                    ->whereColumn('v2_oauth_user.user_id', 'v2_user.id');
            });
        }

        $filters = $request->input('filter');
        if ($filters) {
            foreach ($filters as $k => $filter) {
                if ($filter['condition'] === '模糊') {
                    $filter['condition'] = 'like';
                    $filter['value'] = "%{$filter['value']}%";
                }
                if ($filter['key'] === 'd' || $filter['key'] === 'transfer_enable') {
                    $filter['value'] = $filter['value'] * 1073741824;
                }
                if ($filter['key'] === 'invite_by_email') {
                    $user = User::where('email', $filter['condition'], $filter['value'])->first();
                    $inviteUserId = isset($user->id) ? $user->id : 0;
                    $builder->where('invite_user_id', $inviteUserId);
                    unset($filters[$k]);
                    continue;
                }
                if ($filter['key'] === 'plan_id' && $filter['value'] == 'null') {
                    $builder->whereNull('plan_id');
                    continue;
                }
                $builder->where($filter['key'], $filter['condition'], $filter['value']);
            }
        }
    }

    public function fetch(UserFetch $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $userModel = User::select(
            DB::raw('*'),
            DB::raw('(u+d) as total_used')
        )
            ->orderBy($sort, $sortType);
        $this->filter($request, $userModel);
        $total = $userModel->count();
        $res = $userModel->forPage($current, $pageSize)
            ->get();
        $plan = Plan::get();
        for ($i = 0; $i < count($res); $i++) {
            for ($k = 0; $k < count($plan); $k++) {
                if ($plan[$k]['id'] == $res[$i]['plan_id']) {
                    $res[$i]['plan_name'] = $plan[$k]['name'];
                }
            }
            //统计在线设备
            $countalive = 0;
            $ips = [];
            $ips_array = Cache::get('ALIVE_IP_USER_'. $res[$i]['id']);
            if ($ips_array) {
                $countalive = $ips_array['alive_ip'];
                foreach($ips_array as $nodetypeid => $data) {
                    if (!is_int($data) && isset($data['aliveips'])) {
                        foreach($data['aliveips'] as $ip_NodeId) {
                            $ip = explode("_", $ip_NodeId)[0];
                            $ips[] = $ip . '_' . $nodetypeid;
                        }
                    }
                }
            }
            $res[$i]['alive_ip'] = $countalive;
            $res[$i]['ips'] = implode(', ', $ips);
            $res[$i]['subscribe_url'] = Helper::getSubscribeUrl($res[$i]['token']);
        }
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function getUserInfoById(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        $user = User::find($request->input('id'));
        if (!$user) {
            abort(500, '用户不存在');
        }
        if ($user->invite_user_id) {
            $user['invite_user'] = User::find($user->invite_user_id);
        }

        $oauthBindings = [];
        if (Schema::hasTable('v2_user_oauth')) {
            $oauthBindings = UserOauth::where('user_id', $user->id)->get()->map(function ($binding) {
                $meta = OauthProviderRegistry::get($binding->provider) ?: [];
                $externalIdLabel = '平台用户ID';
                switch ($binding->provider) {
                    case 'linuxdo':
                        $externalIdLabel = '论坛ID';
                        break;
                    case 'telegram':
                        $externalIdLabel = 'TGID';
                        break;
                    case 'github':
                        $externalIdLabel = 'GitHub ID';
                        break;
                    case 'google':
                        $externalIdLabel = 'Google ID';
                        break;
                    case 'microsoft':
                        $externalIdLabel = 'Microsoft ID';
                        break;
                }
                return [
                    'id' => $binding->id,
                    'provider' => $binding->provider,
                    'provider_name' => $meta['name'] ?? $binding->provider,
                    'provider_user_id' => $binding->provider_user_id,
                    'external_id_label' => $externalIdLabel,
                    'external_id' => $binding->provider_user_id,
                    'provider_username' => $binding->provider_username,
                    'provider_email' => $binding->provider_email,
                    'provider_avatar' => $binding->provider_avatar,
                    'password_never_set' => (int)$binding->password_never_set,
                    'created_at' => $binding->created_at,
                ];
            })->values()->all();
        }
        $user['oauth_bindings'] = $oauthBindings;
        $user['has_oauth_binding'] = count($oauthBindings) > 0;

        return response([
            'data' => $user
        ]);
    }

    public function update(UserUpdate $request)
    {
        $params = $request->validated();
        $revokeSessions = false;

        $user = DB::transaction(function () use ($request, $params, &$revokeSessions) {
            $user = User::lockForUpdate()->find((int)$params['id']);
            if (!$user) {
                abort(404, '用户不存在');
            }
            if (User::where('email', $params['email'])->where('id', '!=', $user->id)->exists()) {
                abort(422, '邮箱已被使用');
            }

            unset($params['id'], $params['invite_user_email']);
            $passwordChanged = isset($params['password']) && $params['password'] !== '';
            if ($passwordChanged) {
                $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
                $params['password_algo'] = null;
            } else {
                unset($params['password']);
            }

            if (isset($params['plan_id'])) {
                $plan = Plan::find($params['plan_id']);
                if (!$plan) {
                    abort(422, '订阅计划不存在');
                }
                $params['group_id'] = $plan->group_id;
            } else {
                $params['group_id'] = null;
            }

            $inviteUserEmail = trim((string)$request->input('invite_user_email', ''));
            if ($inviteUserEmail !== '') {
                $inviteUser = User::where('email', $inviteUserEmail)->lockForUpdate()->first();
                if (!$inviteUser) {
                    abort(404, '邀请人不存在');
                }
                $this->assertInviteRelationshipDoesNotCycle($user->id, $inviteUser->id);
                $params['invite_user_id'] = $inviteUser->id;
            } else {
                $params['invite_user_id'] = null;
            }

            $revokeSessions = $passwordChanged
                || (isset($params['banned']) && (int)$params['banned'] === 1)
                || (isset($params['is_admin']) && (int)$params['is_admin'] !== (int)$user->is_admin)
                || (isset($params['is_staff']) && (int)$params['is_staff'] !== (int)$user->is_staff);

            try {
                $saved = $user->fill($params)->save();
            } catch (\Throwable $e) {
                report($e);
                abort(500, '保存失败');
            }
            if (!$saved) {
                abort(500, '保存失败');
            }

            return $user;
        });

        if ($revokeSessions) {
            (new AuthService($user))->removeAllSession();
        }

        return response([
            'data' => true
        ]);
    }

    public function setInviteUser(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|min:1',
            'invite_user_id' => 'nullable|integer|min:1',
            'invite_user_email' => 'nullable|email:strict|max:255'
        ]);

        $hasInviteUserId = $request->filled('invite_user_id');
        $hasInviteUserEmail = $request->filled('invite_user_email');
        if ($hasInviteUserId && $hasInviteUserEmail) {
            abort(422, '邀请人 ID 和邮箱只能填写一项');
        }

        DB::transaction(function () use ($params, $hasInviteUserId, $hasInviteUserEmail) {
            $user = User::lockForUpdate()->find((int)$params['id']);
            if (!$user) {
                abort(404, '用户不存在');
            }

            $inviteUser = null;
            if ($hasInviteUserId) {
                $inviteUser = User::lockForUpdate()->find((int)$params['invite_user_id']);
            } elseif ($hasInviteUserEmail) {
                $inviteUser = User::where('email', trim($params['invite_user_email']))
                    ->lockForUpdate()
                    ->first();
            }

            if (($hasInviteUserId || $hasInviteUserEmail) && !$inviteUser) {
                abort(404, '邀请人不存在');
            }

            if ($inviteUser) {
                $this->assertInviteRelationshipDoesNotCycle($user->id, $inviteUser->id);
            }

            $user->invite_user_id = $inviteUser ? $inviteUser->id : null;
            if (!$user->save()) {
                abort(500, '保存失败');
            }
        });

        return response([
            'data' => true
        ]);
    }

    private function assertInviteRelationshipDoesNotCycle(int $userId, int $inviteUserId): void
    {
        $visited = [];
        $currentUserId = $inviteUserId;

        while ($currentUserId) {
            if ($currentUserId === $userId) {
                abort(422, '邀请关系不能引用用户自身或形成循环');
            }
            if (isset($visited[$currentUserId])) {
                abort(422, '现有邀请关系中存在循环，无法保存');
            }

            $visited[$currentUserId] = true;
            $currentUser = User::select(['id', 'invite_user_id'])
                ->lockForUpdate()
                ->find($currentUserId);
            $currentUserId = $currentUser && $currentUser->invite_user_id
                ? (int)$currentUser->invite_user_id
                : null;
        }
    }

    public function dumpCSV(Request $request)
    {
        $userModel = User::orderBy('id', 'asc');
        $this->filter($request, $userModel);
        $res = $userModel->get();
        $plan = Plan::get();
        for ($i = 0; $i < count($res); $i++) {
            for ($k = 0; $k < count($plan); $k++) {
                if ($plan[$k]['id'] == $res[$i]['plan_id']) {
                    $res[$i]['plan_name'] = $plan[$k]['name'];
                }
            }
        }

        $data = "邮箱,余额,推广佣金,总流量,设备数限制,剩余流量,套餐到期时间,订阅计划,订阅地址\r\n";
        foreach($res as $user) {
            $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
            $balance = $user['balance'] / 100;
            $commissionBalance = $user['commission_balance'] / 100;
            $transferEnable = $user['transfer_enable'] ? $user['transfer_enable'] / 1073741824 : 0;
            $deviceLimit = $user['devce_limit'] ? $user['devce_limit'] : NULL;
            $notUseFlow = (($user['transfer_enable'] - ($user['u'] + $user['d'])) / 1073741824) ?? 0;
            $planName = $user['plan_name'] ?? '无订阅';
            $subscribeUrl =  Helper::getSubscribeUrl($user['token']);
            $data .= "{$user['email']},{$balance},{$commissionBalance},{$transferEnable}, {$deviceLimit}, {$notUseFlow},{$expireDate},{$planName},{$subscribeUrl}\r\n";

        }
        echo "\xEF\xBB\xBF" . $data;
    }

    public function generate(UserGenerate $request)
    {
        if ($request->input('email_prefix')) {
            if ($request->input('plan_id')) {
                $plan = Plan::find($request->input('plan_id'));
                if (!$plan) {
                    abort(500, '订阅计划不存在');
                }
            }
            $user = [
                'email' => $request->input('email_prefix') . '@' . $request->input('email_suffix'),
                'plan_id' => isset($plan->id) ? $plan->id : NULL,
                'group_id' => isset($plan->group_id) ? $plan->group_id : NULL,
                'transfer_enable' => isset($plan->transfer_enable) ? $plan->transfer_enable * 1073741824 : 0,
                'device_limit' => isset($plan->device_limit) ? $plan->device_limit : NULL,
                'expired_at' => $request->input('expired_at') ?? NULL,
                'uuid' => Helper::guid(true),
                'token' => Helper::guid()
            ];
            if (User::where('email', $user['email'])->first()) {
                abort(500, '邮箱已存在于系统中');
            }
            $user['password'] = password_hash($request->input('password') ?? $user['email'], PASSWORD_DEFAULT);
            if (!User::create($user)) {
                abort(500, '生成失败');
            }
            return response([
                'data' => true
            ]);
        }
        if ($request->input('generate_count')) {
            $this->multiGenerate($request);
        }
    }

    private function multiGenerate(Request $request)
    {
        if ($request->input('plan_id')) {
            $plan = Plan::find($request->input('plan_id'));
            if (!$plan) {
                abort(500, '订阅计划不存在');
            }
        }
        $users = [];
        for ($i = 0;$i < $request->input('generate_count');$i++) {
            $user = [
                'email' => Helper::randomChar(6) . '@' . $request->input('email_suffix'),
                'plan_id' => isset($plan->id) ? $plan->id : NULL,
                'group_id' => isset($plan->group_id) ? $plan->group_id : NULL,
                'transfer_enable' => isset($plan->transfer_enable) ? $plan->transfer_enable * 1073741824 : 0,
                'device_limit' => isset($plan->device_limit) ? $plan->device_limit : NULL,
                'expired_at' => $request->input('expired_at') ?? NULL,
                'uuid' => Helper::guid(true),
                'token' => Helper::guid(),
                'created_at' => time(),
                'updated_at' => time()
            ];
            $user['password'] = password_hash($request->input('password') ?? $user['email'], PASSWORD_DEFAULT);
            array_push($users, $user);
        }
        DB::beginTransaction();
        if (!User::insert($users)) {
            DB::rollBack();
            abort(500, '生成失败');
        }
        DB::commit();
        $data = "账号,密码,过期时间,UUID,创建时间,订阅地址\r\n";
        foreach($users as $user) {
            $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
            $createDate = date('Y-m-d H:i:s', $user['created_at']);
            $password = $request->input('password') ?? $user['email'];
            $subscribeUrl = Helper::getSubscribeUrl($user['token']);
            $data .= "{$user['email']},{$password},{$expireDate},{$user['uuid']},{$createDate},{$subscribeUrl}\r\n";
        }
        echo $data;
    }

    public function sendMail(UserSendMail $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);
        foreach ($builder->cursor() as $user) {
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => $request->input('subject'),
                'template_name' => 'notify',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => ConfiguredUrl::applicationUrl(),
                    'content' => $request->input('content')
                ]
            ], 'send_email_mass');
        }

        return response([
            'data' => true
        ]);
    }

    public function ban(Request $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);
        try {
            $builder->each(function ($user){
                $authService = new AuthService($user);
                $authService->removeAllSession();
            });
            $builder->update([
                'banned' => 1
            ]);
        } catch (\Exception $e) {
            abort(500, '处理失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function allDel(Request $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);

        DB::beginTransaction();
        try {
            $builder->each(function ($user){
                $authService = new AuthService($user);
                $authService->removeAllSession();
                Order::where('user_id', $user->id)->delete();
                InviteCode::where('user_id', $user->id)->delete();
                $tickets = Ticket::where('user_id', $user->id)->get();
                foreach($tickets as $ticket) {
                    TicketMessage::where('ticket_id', $ticket->id)->delete();
                }
                Ticket::where('user_id', $user->id)->delete();
                User::where('invite_user_id', $user->id)->update(['invite_user_id' => null]);
                if (\Illuminate\Support\Facades\Schema::hasTable('v2_user_oauth')) {
                \App\Models\UserOauth::where('user_id', $user->id)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('v2_oauth_user')) {
                \App\Models\OauthUser::where('user_id', $user->id)->delete();
            }
            });
            $builder->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, '批量删除用户信息失败');
        }  

        return response([
            'data' => true
        ]);
    }

    public function delUser(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user) {
            abort(500, '用户不存在');
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('v2_oauth_user')
            && \App\Models\OauthUser::where('user_id', $user->id)->exists()) {
            abort(500, '该用户属于 OAuth 用户，请到「OAuth 管理」中操作');
        }
        DB::beginTransaction();
        try {
            $authService = new AuthService($user);
            $authService->removeAllSession();
            Order::where('user_id', $request->input('id'))->delete();
            User::where('invite_user_id', $request->input('id'))->update(['invite_user_id' => null]);
            InviteCode::where('user_id', $request->input('id'))->delete();
            
            $tickets = Ticket::where('user_id', $request->input('id'))->get();
            foreach($tickets as $ticket) {
                TicketMessage::where('ticket_id', $ticket->id)->delete();
            }
            Ticket::where('user_id', $request->input('id'))->delete();
            if (\Illuminate\Support\Facades\Schema::hasTable('v2_user_oauth')) {
                \App\Models\UserOauth::where('user_id', $request->input('id'))->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('v2_oauth_user')) {
                \App\Models\OauthUser::where('user_id', $request->input('id'))->delete();
            }
    
            $user->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, '删除用户失败');
        }

        return response([
            'data' => true
        ]);
    }
}
