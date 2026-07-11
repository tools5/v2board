<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\OauthUser;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\UserOauth;
use App\Services\AuthService;
use App\Services\Oauth\OauthProviderRegistry;
use App\Services\Oauth\OauthService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 后台 OAuth 绑定管理：
 * - 列表主数据来自 v2_user_oauth（含「邮箱用户后绑定」与「OAuth 自动注册」）
 * - OAuth 自动注册用户另有 v2_oauth_user 标记，不在「用户管理」列表中
 * - 套餐/流量/封禁等运行数据读写关联的 v2_user
 */
class OauthController extends Controller
{
    public function fetch(Request $request)
    {
        $this->assertBindingTable();

        $current = max(1, (int)$request->input('current', 1));
        $pageSize = (int)$request->input('pageSize', 10);
        if ($pageSize < 10) {
            $pageSize = 10;
        }
        if ($pageSize > 100) {
            $pageSize = 100;
        }

        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC'], true)
            ? $request->input('sort_type')
            : 'DESC';
        $sort = $request->input('sort') ?: 'created_at';
        $allowedSort = [
            'id', 'user_id', 'provider', 'provider_user_id', 'primary_provider',
            'primary_provider_user_id', 'email', 'created_at', 'updated_at', 'banned', 'expired_at',
        ];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'created_at';
        }
        if ($sort === 'primary_provider') {
            $sort = 'provider';
        }
        if ($sort === 'primary_provider_user_id') {
            $sort = 'provider_user_id';
        }

        // 列表按「本站用户」聚合：同一 user_id 的多个第三方绑定合并为一行。
        $userIdQuery = UserOauth::query()
            ->from('v2_user_oauth as ub')
            ->leftJoin('v2_user as u', 'u.id', '=', 'ub.user_id');
        if (Schema::hasTable('v2_oauth_user')) {
            $userIdQuery->leftJoin('v2_oauth_user as ou', 'ou.user_id', '=', 'ub.user_id');
        }
        $this->applyFilters($request, $userIdQuery);

        if (in_array($sort, ['banned', 'expired_at'], true)) {
            $userIdQuery->orderBy('u.' . $sort, $sortType);
        } elseif ($sort === 'email') {
            $userIdQuery->orderBy('u.email', $sortType);
        } elseif (in_array($sort, ['created_at', 'updated_at', 'id', 'user_id'], true)) {
            $userIdQuery->orderByRaw('MAX(ub.' . $sort . ') ' . $sortType);
        } elseif (in_array($sort, ['provider', 'provider_user_id'], true)) {
            $userIdQuery->orderByRaw('MAX(ub.' . $sort . ') ' . $sortType);
        } else {
            $userIdQuery->orderByRaw('MAX(ub.created_at) ' . $sortType);
        }

        $userIdQuery->groupBy('ub.user_id');
        // 排序字段若引用 u.*，部分数据库要求 group by 一并包含
        if (in_array($sort, ['banned', 'expired_at', 'email'], true)) {
            $userIdQuery->groupBy('u.' . ($sort === 'email' ? 'email' : $sort));
        }

        $distinctUserQuery = (clone $userIdQuery)->select('ub.user_id');
        $total = (int)DB::query()
            ->fromSub($distinctUserQuery, 'oauth_user_groups')
            ->count();

        $pageUserIds = (clone $userIdQuery)
            ->select('ub.user_id')
            ->forPage($current, $pageSize)
            ->pluck('ub.user_id')
            ->filter()
            ->map(function ($userId) {
                return (int)$userId;
            })
            ->values()
            ->all();

        $data = [];
        if (!empty($pageUserIds)) {
            $bindingQuery = $this->buildBindingListQuery()
                ->whereIn('ub.user_id', $pageUserIds)
                ->orderBy('ub.user_id', 'asc')
                ->orderBy('ub.id', 'asc');
            $bindingRows = $bindingQuery->get();
            $plans = Plan::get()->keyBy('id');

            $bindingsByUserId = [];
            foreach ($bindingRows as $bindingRow) {
                $userId = (int)$bindingRow->user_id;
                if (!isset($bindingsByUserId[$userId])) {
                    $bindingsByUserId[$userId] = [];
                }
                $bindingsByUserId[$userId][] = $bindingRow;
            }

            // 保持分页查询得到的用户顺序
            foreach ($pageUserIds as $userId) {
                if (empty($bindingsByUserId[$userId])) {
                    continue;
                }
                $data[] = $this->formatUserGroupedListRow($bindingsByUserId[$userId], $plans);
            }
        }

        return response([
            'data' => $data,
            'total' => $total,
            'providers' => $this->providerOptions(),
        ]);
    }

    public function getInfoById(Request $request)
    {
        $this->assertBindingTable();
        $id = (int)$request->input('id');
        $userId = (int)$request->input('user_id', 0);
        if ($id <= 0 && $userId <= 0) {
            abort(500, '参数错误');
        }

        $binding = null;
        $oauthUser = null;
        $user = null;

        // 聚合列表优先按 user_id 打开用户详情
        if ($userId > 0) {
            $user = User::find($userId);
        }

        if (!$user && $id > 0) {
            $binding = UserOauth::find($id);
            if ($binding) {
                $user = User::find($binding->user_id);
            } elseif (Schema::hasTable('v2_oauth_user')) {
                $oauthUser = OauthUser::find($id);
                if ($oauthUser) {
                    $user = User::find($oauthUser->user_id);
                }
            }
        }

        if (!$user) {
            abort(500, '用户或绑定不存在');
        }

        if ($user->invite_user_id) {
            $user['invite_user'] = User::find($user->invite_user_id);
        }

        if (!$oauthUser && Schema::hasTable('v2_oauth_user')) {
            $oauthUser = OauthUser::where('user_id', $user->id)->first();
        }

        $onlineStats = $this->resolveUserOnlineStats((int)$user->id);
        $user['alive_ip'] = $onlineStats['alive_ip'];
        $user['ips'] = $onlineStats['ips'];
        $user['is_online'] = $onlineStats['is_online'];
        $user['subscribe_url'] = $user->token ? Helper::getSubscribeUrl($user->token) : null;

        $allBindings = UserOauth::where('user_id', $user->id)->get()->map(function ($item) {
            return $this->formatBindingSummary($item);
        })->values();

        $primaryBinding = $binding;
        if (!$primaryBinding) {
            $primaryBinding = UserOauth::where('user_id', $user->id)->orderBy('id', 'asc')->first();
        }

        $primaryProvider = $primaryBinding
            ? $primaryBinding->provider
            : ($oauthUser ? $oauthUser->primary_provider : null);
        $primaryProviderUserId = $primaryBinding
            ? $primaryBinding->provider_user_id
            : ($oauthUser ? $oauthUser->primary_provider_user_id : null);

        return response([
            'data' => [
                'oauth_user' => $oauthUser ? [
                    'id' => $oauthUser->id,
                    'user_id' => $oauthUser->user_id,
                    'email' => $oauthUser->email,
                    'primary_provider' => $oauthUser->primary_provider,
                    'primary_provider_user_id' => $oauthUser->primary_provider_user_id,
                    'external_id_label' => $this->externalIdLabel($oauthUser->primary_provider),
                    'password_never_set' => (int)$oauthUser->password_never_set,
                    'remarks' => $oauthUser->remarks,
                ] : null,
                'binding' => $primaryBinding ? $this->formatBindingSummary($primaryBinding) : (
                    $primaryProvider ? [
                        'id' => null,
                        'user_id' => $user->id,
                        'provider' => $primaryProvider,
                        'provider_name' => (OauthProviderRegistry::get($primaryProvider)['name'] ?? $primaryProvider),
                        'provider_user_id' => $primaryProviderUserId,
                        'external_id_label' => $this->externalIdLabel((string)$primaryProvider),
                        'external_id' => $primaryProviderUserId,
                    ] : null
                ),
                'user' => $user,
                'bindings' => $allBindings,
                'is_oauth_managed' => (bool)$oauthUser,
                'alive_ip' => $onlineStats['alive_ip'],
                'ips' => $onlineStats['ips'],
                'is_online' => $onlineStats['is_online'],
            ],
        ]);
    }

    public function update(UserUpdate $request)
    {
        $params = $request->validated();
        $user = User::find($request->input('id'));
        if (!$user) {
            abort(500, '用户不存在');
        }

        $hasBinding = Schema::hasTable('v2_user_oauth')
            && UserOauth::where('user_id', $user->id)->exists();
        $oauthUser = Schema::hasTable('v2_oauth_user')
            ? OauthUser::where('user_id', $user->id)->first()
            : null;

        if (!$hasBinding && !$oauthUser) {
            abort(500, '该用户没有第三方绑定记录');
        }

        if (User::where('email', $params['email'])->first() && $user->email !== $params['email']) {
            abort(500, '邮箱已被使用');
        }
        if (isset($params['password'])) {
            $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            $params['password_algo'] = null;
            $params['password_salt'] = null;
            OauthService::markPasswordSet((int)$user->id);
        } else {
            unset($params['password']);
        }
        if (isset($params['plan_id'])) {
            $plan = Plan::find($params['plan_id']);
            if (!$plan) {
                abort(500, '订阅计划不存在');
            }
            $params['group_id'] = $plan->group_id;
        } else {
            $params['group_id'] = null;
        }
        if ($request->input('invite_user_email')) {
            $inviteUser = User::where('email', $request->input('invite_user_email'))->first();
            if ($inviteUser) {
                $params['invite_user_id'] = $inviteUser->id;
            }
        } else {
            $params['invite_user_id'] = null;
        }

        if (isset($params['banned']) && (int)$params['banned'] === 1) {
            (new AuthService($user))->removeAllSession();
        }

        try {
            $user->update($params);
            if ($oauthUser) {
                $oauthUser->email = $params['email'];
                if (array_key_exists('remarks', $params)) {
                    $oauthUser->remarks = $params['remarks'];
                }
                $oauthUser->save();
            }
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true,
        ]);
    }

    public function unbind(Request $request)
    {
        $this->assertBindingTable();
        $bindingId = (int)$request->input('binding_id', 0);
        $id = (int)$request->input('id');
        $force = (int)$request->input('force', 0) === 1;

        $binding = null;
        if ($bindingId > 0) {
            $binding = UserOauth::find($bindingId);
        } elseif ($id > 0) {
            // 列表行 id 即为 v2_user_oauth.id；兼容旧前端传 oauth_user 行 id
            $binding = UserOauth::find($id);
            if (!$binding && Schema::hasTable('v2_oauth_user')) {
                $oauthUser = OauthUser::find($id);
                if ($oauthUser) {
                    $binding = UserOauth::where('user_id', $oauthUser->user_id)
                        ->where('provider', $oauthUser->primary_provider)
                        ->first();
                }
            }
        }

        if (!$binding) {
            abort(500, '绑定记录不存在');
        }

        $userId = (int)$binding->user_id;
        $provider = (string)$binding->provider;
        $isOauthManaged = Schema::hasTable('v2_oauth_user')
            && OauthUser::where('user_id', $userId)->exists();

        try {
            if ($force) {
                $this->forceUnbind($userId, $provider, $binding);
            } else {
                (new OauthService())->unbind($userId, $provider);
            }

            // OAuth 自动注册用户：主平台解绑后若无其它绑定，同步清理独立用户表（避免幽灵记录）
            if ($isOauthManaged && Schema::hasTable('v2_oauth_user')) {
                $remaining = UserOauth::where('user_id', $userId)->count();
                if ($remaining === 0) {
                    OauthUser::where('user_id', $userId)->delete();
                } else {
                    $oauthUser = OauthUser::where('user_id', $userId)->first();
                    if ($oauthUser && $oauthUser->primary_provider === $provider) {
                        $replacement = UserOauth::where('user_id', $userId)->orderBy('id', 'asc')->first();
                        if ($replacement) {
                            $oauthUser->primary_provider = $replacement->provider;
                            $oauthUser->primary_provider_user_id = $replacement->provider_user_id;
                            $oauthUser->primary_provider_username = $replacement->provider_username;
                            $oauthUser->primary_provider_email = $replacement->provider_email;
                            $oauthUser->primary_provider_avatar = $replacement->provider_avatar;
                            $oauthUser->password_never_set = (int)$replacement->password_never_set;
                            $oauthUser->save();
                        }
                    }
                }
            }
        } catch (\Throwable $exception) {
            $message = $exception->getMessage() ?: '解绑失败';
            abort(500, $message);
        }

        return response([
            'data' => true,
        ]);
    }

    public function resetSecret(Request $request)
    {
        $user = $this->findBoundSystemUser($request);
        $user->token = Helper::guid();
        $user->uuid = Helper::guid(true);
        return response([
            'data' => $user->save(),
        ]);
    }

    public function ban(Request $request)
    {
        $query = $this->buildBindingUserQuery($request);
        $userIds = $query->pluck('user_id')->unique()->filter()->values();
        if ($userIds->isEmpty()) {
            return response(['data' => true]);
        }

        try {
            User::whereIn('id', $userIds)->each(function ($user) {
                (new AuthService($user))->removeAllSession();
            });
            User::whereIn('id', $userIds)->update(['banned' => 1]);
        } catch (\Exception $e) {
            abort(500, '处理失败');
        }

        return response([
            'data' => true,
        ]);
    }

    public function delUser(Request $request)
    {
        $user = $this->findBoundSystemUser($request);
        $isOauthManaged = Schema::hasTable('v2_oauth_user')
            && OauthUser::where('user_id', $user->id)->exists();

        // 邮箱用户仅解绑，不在此处删除账号（请到用户管理操作）
        if (!$isOauthManaged) {
            abort(500, '该账号为邮箱用户，仅可在此解绑第三方；删除账号请到「用户管理」');
        }

        $this->deleteUserCascade($user);
        return response([
            'data' => true,
        ]);
    }

    public function allDel(Request $request)
    {
        $query = $this->buildBindingUserQuery($request);
        $userIds = $query->pluck('user_id')->unique()->filter()->values();
        if ($userIds->isEmpty()) {
            return response(['data' => true]);
        }

        // 批量删除仅针对 OAuth 独立用户
        if (Schema::hasTable('v2_oauth_user')) {
            $userIds = OauthUser::whereIn('user_id', $userIds)->pluck('user_id')->unique()->values();
        } else {
            $userIds = collect();
        }

        if ($userIds->isEmpty()) {
            abort(500, '筛选结果中没有可删除的 OAuth 独立用户（邮箱用户绑定请单独解绑）');
        }

        DB::beginTransaction();
        try {
            foreach (User::whereIn('id', $userIds)->cursor() as $user) {
                $this->deleteUserCascade($user, false);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, '批量删除失败');
        }

        return response([
            'data' => true,
        ]);
    }

    public function sendMail(UserSendMail $request)
    {
        $query = $this->buildBindingUserQuery($request);
        $emails = $query->pluck('email')->unique()->filter()->values();
        foreach ($emails as $email) {
            SendEmailJob::dispatch([
                'email' => $email,
                'subject' => $request->input('subject'),
                'template_name' => 'notify',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'content' => $request->input('content'),
                ],
            ], 'send_email_mass');
        }

        return response([
            'data' => true,
        ]);
    }

    public function dumpCSV(Request $request)
    {
        $this->assertBindingTable();

        $query = $this->buildBindingListQuery()->orderBy('ub.id', 'asc');
        $this->applyFilters($request, $query);
        $rows = $query->get();
        $plans = Plan::get()->keyBy('id');

        $data = "用户ID,邮箱,账号类型,平台,平台名称,外部ID标签,外部ID,第三方用户名,第三方邮箱,TGID,是否未设密码,套餐,总流量GB,已用流量GB,到期时间,余额,是否在线,在线设备数,设备数限制,在线IP,是否封禁,订阅地址,绑定时间\r\n";
        foreach ($rows as $row) {
            $provider = $row->provider;
            $meta = OauthProviderRegistry::get($provider) ?: [];
            $providerName = $meta['name'] ?? $provider;
            $idLabel = $this->externalIdLabel((string)$provider);
            $planName = ($row->plan_id && isset($plans[$row->plan_id])) ? $plans[$row->plan_id]->name : '无订阅';
            $transfer = $row->transfer_enable ? round($row->transfer_enable / 1073741824, 2) : 0;
            $used = round(((int)$row->u + (int)$row->d) / 1073741824, 2);
            $expire = $row->expired_at === null ? '长期有效' : date('Y-m-d H:i:s', $row->expired_at);
            $balance = ((int)$row->balance) / 100;
            $banned = (int)$row->banned === 1 ? '是' : '否';
            $neverSet = (int)$row->password_never_set === 1 ? '是' : '否';
            $subscribe = $row->token ? Helper::getSubscribeUrl($row->token) : '';
            $created = $row->created_at ? date('Y-m-d H:i:s', is_numeric($row->created_at) ? $row->created_at : strtotime((string)$row->created_at)) : '';
            $email = $row->email ?: $row->oauth_email;
            $accountType = $row->oauth_user_id ? 'OAuth注册' : '邮箱用户绑定';
            $onlineStats = $this->resolveUserOnlineStats((int)$row->user_id);
            $isOnlineLabel = $onlineStats['is_online'] ? '是' : '否';
            $deviceLimit = $row->device_limit === null || $row->device_limit === '' ? '∞' : $row->device_limit;
            $data .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\r\n",
                $row->user_id,
                $this->csvCell($email),
                $this->csvCell($accountType),
                $this->csvCell($provider),
                $this->csvCell($providerName),
                $this->csvCell($idLabel),
                $this->csvCell($row->provider_user_id),
                $this->csvCell($row->provider_username),
                $this->csvCell($row->provider_email),
                $this->csvCell($row->telegram_id),
                $neverSet,
                $this->csvCell($planName),
                $transfer,
                $used,
                $expire,
                $balance,
                $isOnlineLabel,
                $onlineStats['alive_ip'],
                $this->csvCell($deviceLimit),
                $this->csvCell($onlineStats['ips']),
                $banned,
                $this->csvCell($subscribe),
                $created
            );
        }

        echo "\xEF\xBB\xBF" . $data;
    }

    private function assertBindingTable(): void
    {
        OauthService::ensureTableExists();
        if (!Schema::hasTable('v2_user_oauth')) {
            abort(500, 'OAuth 绑定表不存在，请先执行 php artisan migrate');
        }
    }

    private function buildBindingListQuery()
    {
        $this->assertBindingTable();

        $query = UserOauth::query()
            ->from('v2_user_oauth as ub')
            ->leftJoin('v2_user as u', 'u.id', '=', 'ub.user_id')
            ->select([
                'ub.id',
                'ub.user_id',
                'ub.provider',
                'ub.provider_user_id',
                'ub.provider_username',
                'ub.provider_email',
                'ub.provider_avatar',
                'ub.password_never_set',
                'ub.created_at',
                'ub.updated_at',
                'u.email',
                'u.banned',
                'u.plan_id',
                'u.transfer_enable',
                'u.u',
                'u.d',
                'u.device_limit',
                'u.expired_at',
                'u.balance',
                'u.commission_balance',
                'u.telegram_id',
                'u.token',
                'u.uuid',
                'u.remarks',
                'u.is_admin',
                'u.is_staff',
                'u.invite_user_id',
                'u.created_at as user_created_at',
                'u.last_login_at',
            ]);

        if (Schema::hasTable('v2_oauth_user')) {
            $query->leftJoin('v2_oauth_user as ou', 'ou.user_id', '=', 'ub.user_id')
                ->addSelect([
                    'ou.id as oauth_user_id',
                    'ou.email as oauth_email',
                    'ou.primary_provider',
                    'ou.remarks as oauth_remarks',
                ]);
        } else {
            $query->selectRaw('NULL as oauth_user_id')
                ->selectRaw('NULL as oauth_email')
                ->selectRaw('NULL as primary_provider')
                ->selectRaw('NULL as oauth_remarks');
        }

        return $query;
    }

    private function applyFilters(Request $request, $query): void
    {
        $filters = $request->input('filter');
        if (!$filters || !is_array($filters)) {
            if ($request->filled('provider')) {
                $query->where('ub.provider', (string)$request->input('provider'));
            }
            if ($request->filled('email')) {
                $keyword = '%' . $request->input('email') . '%';
                $query->where(function ($builder) use ($keyword) {
                    $builder->where('u.email', 'like', $keyword);
                    if (Schema::hasTable('v2_oauth_user')) {
                        $builder->orWhere('ou.email', 'like', $keyword);
                    }
                });
            }
            if ($request->filled('provider_user_id')) {
                $query->where('ub.provider_user_id', 'like', '%' . $request->input('provider_user_id') . '%');
            }
            if ($request->filled('banned') && $request->input('banned') !== '') {
                $query->where('u.banned', (int)$request->input('banned'));
            }
            return;
        }

        foreach ($filters as $filter) {
            if (!is_array($filter) || empty($filter['key'])) {
                continue;
            }
            $key = (string)$filter['key'];
            $condition = $filter['condition'] ?? '=';
            $value = $filter['value'] ?? null;
            if ($condition === '模糊') {
                $condition = 'like';
                $value = '%' . $value . '%';
            }

            switch ($key) {
                case 'email':
                    $query->where(function ($builder) use ($condition, $value) {
                        $builder->where('u.email', $condition, $value);
                        if (Schema::hasTable('v2_oauth_user')) {
                            $builder->orWhere('ou.email', $condition, $value);
                        }
                    });
                    break;
                case 'banned':
                    $query->where('u.banned', $condition, $value);
                    break;
                case 'plan_id':
                    if ($value === 'null' || $value === null || $value === '') {
                        $query->whereNull('u.plan_id');
                    } else {
                        $query->where('u.plan_id', $condition, $value);
                    }
                    break;
                case 'provider':
                    $query->where('ub.provider', $condition, $value);
                    break;
                case 'provider_user_id':
                case 'external_id':
                    $query->where('ub.provider_user_id', $condition, $value);
                    break;
                case 'provider_username':
                    $query->where('ub.provider_username', $condition, $value);
                    break;
                case 'provider_email':
                    $query->where('ub.provider_email', $condition, $value);
                    break;
                case 'user_id':
                    $query->where('ub.user_id', $condition, $value);
                    break;
                case 'telegram_id':
                    $query->where('u.telegram_id', $condition, $value);
                    break;
                case 'remarks':
                    $query->where(function ($builder) use ($condition, $value) {
                        $builder->where('u.remarks', $condition, $value);
                        if (Schema::hasTable('v2_oauth_user')) {
                            $builder->orWhere('ou.remarks', $condition, $value);
                        }
                    });
                    break;
                case 'password_never_set':
                    $query->where('ub.password_never_set', $condition, $value);
                    break;
                case 'is_oauth_managed':
                    if (!Schema::hasTable('v2_oauth_user')) {
                        break;
                    }
                    if ((int)$value === 1 || $value === true || $value === '1') {
                        $query->whereNotNull('ou.id');
                    } else {
                        $query->whereNull('ou.id');
                    }
                    break;
                default:
                    break;
            }
        }
    }

    private function buildBindingUserQuery(Request $request)
    {
        $this->assertBindingTable();
        $query = UserOauth::query()
            ->from('v2_user_oauth as ub')
            ->leftJoin('v2_user as u', 'u.id', '=', 'ub.user_id');
        if (Schema::hasTable('v2_oauth_user')) {
            $query->leftJoin('v2_oauth_user as ou', 'ou.user_id', '=', 'ub.user_id');
        }
        $query->select([
            'ub.user_id',
            'u.email',
        ]);
        $this->applyFilters($request, $query);
        return $query;
    }

    /**
     * 根据 user_id / 绑定 id / 旧 oauth_user id 解析已绑定第三方的系统用户。
     */
    private function findBoundSystemUser(Request $request): User
    {
        $userId = (int)$request->input('user_id', 0);
        if ($userId <= 0 && $request->input('id')) {
            $id = (int)$request->input('id');
            $binding = UserOauth::find($id);
            if ($binding) {
                $userId = (int)$binding->user_id;
            } elseif (Schema::hasTable('v2_oauth_user')) {
                $oauthUser = OauthUser::find($id);
                if ($oauthUser) {
                    $userId = (int)$oauthUser->user_id;
                } else {
                    $userId = $id;
                }
            } else {
                $userId = $id;
            }
        }

        $user = User::find($userId);
        if (!$user) {
            abort(500, '用户不存在');
        }

        $hasBinding = Schema::hasTable('v2_user_oauth')
            && UserOauth::where('user_id', $user->id)->exists();
        $isOauthManaged = Schema::hasTable('v2_oauth_user')
            && OauthUser::where('user_id', $user->id)->exists();

        if (!$hasBinding && !$isOauthManaged) {
            abort(500, '该用户没有第三方绑定');
        }

        return $user;
    }

    private function deleteUserCascade(User $user, bool $useTransaction = true): void
    {
        $runner = function () use ($user) {
            (new AuthService($user))->removeAllSession();
            Order::where('user_id', $user->id)->delete();
            User::where('invite_user_id', $user->id)->update(['invite_user_id' => null]);
            InviteCode::where('user_id', $user->id)->delete();
            $tickets = Ticket::where('user_id', $user->id)->get();
            foreach ($tickets as $ticket) {
                TicketMessage::where('ticket_id', $ticket->id)->delete();
            }
            Ticket::where('user_id', $user->id)->delete();
            if (Schema::hasTable('v2_user_oauth')) {
                UserOauth::where('user_id', $user->id)->delete();
            }
            if (Schema::hasTable('v2_oauth_user')) {
                OauthUser::where('user_id', $user->id)->delete();
            }
            $user->delete();
        };

        if (!$useTransaction) {
            $runner();
            return;
        }

        DB::beginTransaction();
        try {
            $runner();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, '删除用户失败');
        }
    }

    private function forceUnbind(int $userId, string $provider, UserOauth $binding): void
    {
        DB::transaction(function () use ($userId, $provider, $binding) {
            $binding->delete();
            if ($provider === 'telegram') {
                User::where('id', $userId)->update(['telegram_id' => null]);
            }
        });
    }

    private function formatBindingListRow($row, $plans): array
    {
        $provider = (string)$row->provider;
        $meta = OauthProviderRegistry::get($provider) ?: [];
        $providerName = $meta['name'] ?? $provider;
        $planName = null;
        if ($row->plan_id && isset($plans[$row->plan_id])) {
            $planName = $plans[$row->plan_id]->name;
        }

        $onlineStats = $this->resolveUserOnlineStats((int)$row->user_id);

        $email = $row->email ?: $row->oauth_email;
        $isOauthManaged = !empty($row->oauth_user_id);

        return [
            // 列表行 id = v2_user_oauth.id，便于直接解绑
            'id' => $row->id,
            'binding_id' => $row->id,
            'oauth_user_id' => $row->oauth_user_id,
            'user_id' => $row->user_id,
            'email' => $email,
            'provider' => $provider,
            'provider_name' => $providerName,
            'provider_user_id' => $row->provider_user_id,
            'external_id_label' => $this->externalIdLabel($provider),
            'external_id' => $row->provider_user_id,
            'provider_username' => $row->provider_username,
            'provider_email' => $row->provider_email,
            'provider_avatar' => $row->provider_avatar,
            'password_never_set' => (int)$row->password_never_set,
            'telegram_id' => $row->telegram_id,
            'banned' => (int)$row->banned,
            'plan_id' => $row->plan_id,
            'plan_name' => $planName,
            'transfer_enable' => $row->transfer_enable,
            'u' => $row->u,
            'd' => $row->d,
            'total_used' => (int)$row->u + (int)$row->d,
            'device_limit' => $row->device_limit,
            'expired_at' => $row->expired_at,
            'balance' => $row->balance,
            'commission_balance' => $row->commission_balance,
            'remarks' => $row->oauth_remarks ?: $row->remarks,
            'is_admin' => (int)$row->is_admin,
            'is_staff' => (int)$row->is_staff,
            'invite_user_id' => $row->invite_user_id,
            'token' => $row->token,
            'uuid' => $row->uuid,
            'subscribe_url' => $row->token ? Helper::getSubscribeUrl($row->token) : null,
            // 与用户管理一致：在线设备数 / IP 明细 / 是否在线
            'alive_ip' => $onlineStats['alive_ip'],
            'ips' => $onlineStats['ips'],
            'is_online' => $onlineStats['is_online'],
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'user_created_at' => $row->user_created_at,
            'last_login_at' => $row->last_login_at,
            'is_placeholder_email' => (bool)preg_match('/@oauth\.local$/i', (string)$email),
            // true = OAuth 自动注册（用户管理不可见）；false = 邮箱用户后绑定
            'is_oauth_managed' => $isOauthManaged,
            'account_type' => $isOauthManaged ? 'oauth_registered' : 'email_bound',
            'account_type_label' => $isOauthManaged ? 'OAuth注册' : '邮箱用户绑定',
        ];
    }

    /**
     * 读取节点上报的在线设备缓存，逻辑与 Admin\UserController 保持一致。
     *
     * @return array{alive_ip:int,ips:string,is_online:bool}
     */
    private function resolveUserOnlineStats(int $userId): array
    {
        $aliveIpCount = 0;
        $ipList = [];
        if ($userId <= 0) {
            return [
                'alive_ip' => 0,
                'ips' => '',
                'is_online' => false,
            ];
        }

        $ipsArray = Cache::get('ALIVE_IP_USER_' . $userId);
        if (is_array($ipsArray)) {
            $aliveIpCount = (int)($ipsArray['alive_ip'] ?? 0);
            foreach ($ipsArray as $nodeTypeId => $nodeData) {
                if (is_int($nodeData) || !isset($nodeData['aliveips']) || !is_array($nodeData['aliveips'])) {
                    continue;
                }
                foreach ($nodeData['aliveips'] as $ipNodeId) {
                    $ip = explode('_', (string)$ipNodeId)[0];
                    if ($ip !== '') {
                        $ipList[] = $ip . '_' . $nodeTypeId;
                    }
                }
            }
        }

        return [
            'alive_ip' => $aliveIpCount,
            'ips' => implode(', ', $ipList),
            'is_online' => $aliveIpCount > 0,
        ];
    }

    /**
     * 将同一本站用户的多条第三方绑定聚合成列表一行。
     *
     * @param array<int, object> $bindingRows
     */
    private function formatUserGroupedListRow(array $bindingRows, $plans): array
    {
        $primaryRow = $bindingRows[0];
        $formatted = $this->formatBindingListRow($primaryRow, $plans);

        $bindings = [];
        $providerNames = [];
        $passwordNeverSet = 0;
        $latestCreatedAt = $primaryRow->created_at;
        $latestUpdatedAt = $primaryRow->updated_at;

        foreach ($bindingRows as $bindingRow) {
            $item = $this->formatBindingSummaryFromListRow($bindingRow);
            $bindings[] = $item;
            $providerNames[] = $item['provider_name'];
            if ((int)$bindingRow->password_never_set === 1) {
                $passwordNeverSet = 1;
            }
            if ($bindingRow->created_at > $latestCreatedAt) {
                $latestCreatedAt = $bindingRow->created_at;
            }
            if ($bindingRow->updated_at > $latestUpdatedAt) {
                $latestUpdatedAt = $bindingRow->updated_at;
            }
        }

        $formatted['bindings'] = $bindings;
        $formatted['binding_count'] = count($bindings);
        $formatted['providers'] = array_values(array_unique(array_map(function ($binding) {
            return $binding['provider'];
        }, $bindings)));
        $formatted['provider_names'] = array_values(array_unique($providerNames));
        $formatted['provider_name'] = implode(' / ', $formatted['provider_names']);
        $formatted['password_never_set'] = $passwordNeverSet;
        $formatted['created_at'] = $latestCreatedAt;
        $formatted['updated_at'] = $latestUpdatedAt;
        // 聚合行用 user_id 作为稳定主键；单绑定 id 仍保留 primary binding_id 便于兼容旧前端
        $formatted['row_key'] = 'user_' . $formatted['user_id'];

        return $formatted;
    }

    private function formatBindingSummaryFromListRow($row): array
    {
        $provider = (string)$row->provider;
        $meta = OauthProviderRegistry::get($provider) ?: [];
        return [
            'id' => $row->id,
            'binding_id' => $row->id,
            'user_id' => $row->user_id,
            'provider' => $provider,
            'provider_name' => $meta['name'] ?? $provider,
            'provider_user_id' => $row->provider_user_id,
            'external_id_label' => $this->externalIdLabel($provider),
            'external_id' => $row->provider_user_id,
            'provider_username' => $row->provider_username,
            'provider_email' => $row->provider_email,
            'provider_avatar' => $row->provider_avatar,
            'password_never_set' => (int)$row->password_never_set,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function formatBindingSummary(UserOauth $binding): array
    {
        $meta = OauthProviderRegistry::get($binding->provider) ?: [];
        return [
            'id' => $binding->id,
            'user_id' => $binding->user_id,
            'provider' => $binding->provider,
            'provider_name' => $meta['name'] ?? $binding->provider,
            'provider_user_id' => $binding->provider_user_id,
            'external_id_label' => $this->externalIdLabel($binding->provider),
            'external_id' => $binding->provider_user_id,
            'provider_username' => $binding->provider_username,
            'provider_email' => $binding->provider_email,
            'provider_avatar' => $binding->provider_avatar,
            'password_never_set' => (int)$binding->password_never_set,
            'created_at' => $binding->created_at,
            'updated_at' => $binding->updated_at,
        ];
    }

    private function externalIdLabel(string $provider): string
    {
        switch ($provider) {
            case 'linuxdo':
                return '论坛ID';
            case 'telegram':
                return 'TGID';
            case 'github':
                return 'GitHub ID';
            case 'google':
                return 'Google ID';
            case 'microsoft':
                return 'Microsoft ID';
            default:
                return '平台用户ID';
        }
    }

    private function providerOptions(): array
    {
        $options = [];
        foreach (OauthProviderRegistry::all() as $key => $meta) {
            $options[] = [
                'value' => $key,
                'label' => $meta['name'] ?? $key,
                'id_label' => $this->externalIdLabel($key),
            ];
        }
        return $options;
    }

    private function csvCell($value): string
    {
        $text = str_replace(['"', "\r", "\n"], ['""', ' ', ' '], (string)$value);
        return '"' . $text . '"';
    }
}
