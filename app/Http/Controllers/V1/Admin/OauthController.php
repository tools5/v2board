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
 * 后台 OAuth 用户管理：数据主表为 v2_oauth_user（与用户管理 v2_user 列表分离）。
 * 套餐/流量/封禁等运行数据仍读写关联的系统账号（v2_user），以支持订阅与节点。
 */
class OauthController extends Controller
{
    public function fetch(Request $request)
    {
        $this->assertOauthTable();

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
        // 兼容前端旧 sort 字段
        if ($sort === 'provider') {
            $sort = 'primary_provider';
        }
        if ($sort === 'provider_user_id') {
            $sort = 'primary_provider_user_id';
        }

        $query = OauthUser::query()
            ->from('v2_oauth_user as ou')
            ->leftJoin('v2_user as u', 'u.id', '=', 'ou.user_id')
            ->select([
                'ou.id',
                'ou.user_id',
                'ou.email as oauth_email',
                'ou.primary_provider',
                'ou.primary_provider_user_id',
                'ou.primary_provider_username',
                'ou.primary_provider_email',
                'ou.primary_provider_avatar',
                'ou.password_never_set',
                'ou.remarks as oauth_remarks',
                'ou.created_at',
                'ou.updated_at',
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

        $this->applyFilters($request, $query);

        if (in_array($sort, ['banned', 'expired_at'], true)) {
            $query->orderBy('u.' . $sort, $sortType);
        } elseif ($sort === 'email') {
            $query->orderBy('ou.email', $sortType);
        } else {
            $query->orderBy('ou.' . $sort, $sortType);
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($current, $pageSize)->get();

        $plans = Plan::get()->keyBy('id');
        $data = [];
        foreach ($rows as $row) {
            $data[] = $this->formatOauthUserRow($row, $plans);
        }

        return response([
            'data' => $data,
            'total' => $total,
            'providers' => $this->providerOptions(),
        ]);
    }

    public function getInfoById(Request $request)
    {
        $this->assertOauthTable();
        $id = (int)$request->input('id');
        if ($id <= 0) {
            abort(500, '参数错误');
        }

        $oauthUser = OauthUser::find($id);
        if (!$oauthUser) {
            abort(500, 'OAuth 用户不存在');
        }

        $user = User::find($oauthUser->user_id);
        if (!$user) {
            abort(500, '关联系统账号不存在');
        }

        if ($user->invite_user_id) {
            $user['invite_user'] = User::find($user->invite_user_id);
        }

        $allBindings = UserOauth::where('user_id', $user->id)->get()->map(function ($item) {
            return $this->formatBindingSummary($item);
        })->values();

        return response([
            'data' => [
                'oauth_user' => [
                    'id' => $oauthUser->id,
                    'user_id' => $oauthUser->user_id,
                    'email' => $oauthUser->email,
                    'primary_provider' => $oauthUser->primary_provider,
                    'primary_provider_user_id' => $oauthUser->primary_provider_user_id,
                    'external_id_label' => $this->externalIdLabel($oauthUser->primary_provider),
                    'password_never_set' => (int)$oauthUser->password_never_set,
                    'remarks' => $oauthUser->remarks,
                ],
                'binding' => [
                    'id' => $oauthUser->id,
                    'user_id' => $oauthUser->user_id,
                    'provider' => $oauthUser->primary_provider,
                    'provider_name' => (OauthProviderRegistry::get($oauthUser->primary_provider)['name'] ?? $oauthUser->primary_provider),
                    'provider_user_id' => $oauthUser->primary_provider_user_id,
                    'external_id_label' => $this->externalIdLabel($oauthUser->primary_provider),
                    'external_id' => $oauthUser->primary_provider_user_id,
                ],
                'user' => $user,
                'bindings' => $allBindings,
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

        $oauthUser = OauthUser::where('user_id', $user->id)->first();
        if (!$oauthUser) {
            abort(500, '该用户不是 OAuth 独立用户');
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
            $oauthUser->email = $params['email'];
            if (array_key_exists('remarks', $params)) {
                $oauthUser->remarks = $params['remarks'];
            }
            $oauthUser->save();
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true,
        ]);
    }

    public function unbind(Request $request)
    {
        $this->assertOauthTable();
        // 支持：binding_id（v2_user_oauth.id）或 oauth_user 行 id + provider
        $bindingId = (int)$request->input('binding_id', 0);
        $id = (int)$request->input('id');
        $force = (int)$request->input('force', 0) === 1;

        $binding = null;
        if ($bindingId > 0) {
            $binding = UserOauth::find($bindingId);
        } elseif ($id > 0) {
            // 兼容前端：先按绑定表 id 查，再按 oauth_user 主平台解绑
            $binding = UserOauth::find($id);
            if (!$binding) {
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

        // 仅允许操作独立 OAuth 用户
        if (!OauthUser::where('user_id', $binding->user_id)->exists()) {
            abort(500, '该绑定不属于 OAuth 独立用户');
        }

        $userId = (int)$binding->user_id;
        $provider = (string)$binding->provider;

        try {
            if ($force) {
                $this->forceUnbind($userId, $provider, $binding);
            } else {
                (new OauthService())->unbind($userId, $provider);
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
        $user = $this->findOauthManagedSystemUser($request);
        $user->token = Helper::guid();
        $user->uuid = Helper::guid(true);
        return response([
            'data' => $user->save(),
        ]);
    }

    public function ban(Request $request)
    {
        $query = $this->buildOauthUserQuery($request);
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
        $user = $this->findOauthManagedSystemUser($request);
        $this->deleteUserCascade($user);
        return response([
            'data' => true,
        ]);
    }

    public function allDel(Request $request)
    {
        $query = $this->buildOauthUserQuery($request);
        $userIds = $query->pluck('user_id')->unique()->filter()->values();
        if ($userIds->isEmpty()) {
            return response(['data' => true]);
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
        $query = $this->buildOauthUserQuery($request);
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
        $query = OauthUser::query()
            ->from('v2_oauth_user as ou')
            ->leftJoin('v2_user as u', 'u.id', '=', 'ou.user_id')
            ->select([
                'ou.primary_provider',
                'ou.primary_provider_user_id',
                'ou.primary_provider_username',
                'ou.primary_provider_email',
                'ou.password_never_set',
                'ou.created_at',
                'ou.email as oauth_email',
                'u.id as user_id',
                'u.email',
                'u.banned',
                'u.plan_id',
                'u.transfer_enable',
                'u.u',
                'u.d',
                'u.expired_at',
                'u.balance',
                'u.telegram_id',
                'u.token',
            ])
            ->orderBy('ou.id', 'asc');

        $this->applyFilters($request, $query);
        $rows = $query->get();
        $plans = Plan::get()->keyBy('id');

        $data = "用户ID,邮箱,平台,平台名称,外部ID标签,外部ID,第三方用户名,第三方邮箱,TGID,是否未设密码,套餐,总流量GB,已用流量GB,到期时间,余额,是否封禁,订阅地址,绑定时间\r\n";
        foreach ($rows as $row) {
            $provider = $row->primary_provider;
            $meta = OauthProviderRegistry::get($provider) ?: [];
            $providerName = $meta['name'] ?? $provider;
            $idLabel = $this->externalIdLabel($provider);
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
            $data .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\r\n",
                $row->user_id,
                $this->csvCell($email),
                $this->csvCell($provider),
                $this->csvCell($providerName),
                $this->csvCell($idLabel),
                $this->csvCell($row->primary_provider_user_id),
                $this->csvCell($row->primary_provider_username),
                $this->csvCell($row->primary_provider_email),
                $this->csvCell($row->telegram_id),
                $neverSet,
                $this->csvCell($planName),
                $transfer,
                $used,
                $expire,
                $balance,
                $banned,
                $this->csvCell($subscribe),
                $created
            );
        }

        echo "\xEF\xBB\xBF" . $data;
    }

    private function assertOauthTable(): void
    {
        if (!Schema::hasTable('v2_oauth_user')) {
            abort(500, 'OAuth 用户表不存在，请先执行 php artisan migrate');
        }
    }

    private function applyFilters(Request $request, $query): void
    {
        $filters = $request->input('filter');
        if (!$filters || !is_array($filters)) {
            if ($request->filled('provider')) {
                $query->where('ou.primary_provider', (string)$request->input('provider'));
            }
            if ($request->filled('email')) {
                $query->where(function ($builder) use ($request) {
                    $keyword = '%' . $request->input('email') . '%';
                    $builder->where('ou.email', 'like', $keyword)
                        ->orWhere('u.email', 'like', $keyword);
                });
            }
            if ($request->filled('provider_user_id')) {
                $query->where('ou.primary_provider_user_id', 'like', '%' . $request->input('provider_user_id') . '%');
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
                        $builder->where('ou.email', $condition, $value)
                            ->orWhere('u.email', $condition, $value);
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
                    $query->where('ou.primary_provider', $condition, $value);
                    break;
                case 'provider_user_id':
                case 'external_id':
                    $query->where('ou.primary_provider_user_id', $condition, $value);
                    break;
                case 'provider_username':
                    $query->where('ou.primary_provider_username', $condition, $value);
                    break;
                case 'provider_email':
                    $query->where('ou.primary_provider_email', $condition, $value);
                    break;
                case 'user_id':
                    $query->where('ou.user_id', $condition, $value);
                    break;
                case 'telegram_id':
                    $query->where('u.telegram_id', $condition, $value);
                    break;
                case 'remarks':
                    $query->where(function ($builder) use ($condition, $value) {
                        $builder->where('ou.remarks', $condition, $value)
                            ->orWhere('u.remarks', $condition, $value);
                    });
                    break;
                case 'password_never_set':
                    $query->where('ou.password_never_set', $condition, $value);
                    break;
                default:
                    break;
            }
        }
    }

    private function buildOauthUserQuery(Request $request)
    {
        $this->assertOauthTable();
        $query = OauthUser::query()
            ->from('v2_oauth_user as ou')
            ->leftJoin('v2_user as u', 'u.id', '=', 'ou.user_id')
            ->select([
                'ou.user_id as user_id',
                'ou.email as email',
            ]);
        $this->applyFilters($request, $query);
        return $query;
    }

    private function findOauthManagedSystemUser(Request $request): User
    {
        $userId = (int)$request->input('user_id', 0);
        if ($userId <= 0 && $request->input('id')) {
            $oauthUser = OauthUser::find((int)$request->input('id'));
            if ($oauthUser) {
                $userId = (int)$oauthUser->user_id;
            } else {
                $userId = (int)$request->input('id');
            }
        }
        $user = User::find($userId);
        if (!$user) {
            abort(500, '用户不存在');
        }
        if (!OauthUser::where('user_id', $user->id)->exists()) {
            abort(500, '该用户不是 OAuth 独立用户');
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

    private function formatOauthUserRow($row, $plans): array
    {
        $provider = $row->primary_provider;
        $meta = OauthProviderRegistry::get($provider) ?: [];
        $providerName = $meta['name'] ?? $provider;
        $planName = null;
        if ($row->plan_id && isset($plans[$row->plan_id])) {
            $planName = $plans[$row->plan_id]->name;
        }

        $countAlive = 0;
        $ips = [];
        $ipsArray = Cache::get('ALIVE_IP_USER_' . $row->user_id);
        if ($ipsArray) {
            $countAlive = $ipsArray['alive_ip'] ?? 0;
            foreach ($ipsArray as $nodeTypeId => $data) {
                if (!is_int($data) && isset($data['aliveips'])) {
                    foreach ($data['aliveips'] as $ipNodeId) {
                        $ip = explode('_', $ipNodeId)[0];
                        $ips[] = $ip . '_' . $nodeTypeId;
                    }
                }
            }
        }

        $email = $row->email ?: $row->oauth_email;

        // 绑定表 id：用于解绑主平台（若存在）
        $primaryBindingId = null;
        if (Schema::hasTable('v2_user_oauth')) {
            $primaryBindingId = UserOauth::where('user_id', $row->user_id)
                ->where('provider', $provider)
                ->value('id');
        }

        return [
            'id' => $row->id,
            'binding_id' => $primaryBindingId,
            'user_id' => $row->user_id,
            'email' => $email,
            'provider' => $provider,
            'provider_name' => $providerName,
            'provider_user_id' => $row->primary_provider_user_id,
            'external_id_label' => $this->externalIdLabel($provider),
            'external_id' => $row->primary_provider_user_id,
            'provider_username' => $row->primary_provider_username,
            'provider_email' => $row->primary_provider_email,
            'provider_avatar' => $row->primary_provider_avatar,
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
            'alive_ip' => $countAlive,
            'ips' => implode(', ', $ips),
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'user_created_at' => $row->user_created_at,
            'last_login_at' => $row->last_login_at,
            'is_placeholder_email' => (bool)preg_match('/@oauth\.local$/i', (string)$email),
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
