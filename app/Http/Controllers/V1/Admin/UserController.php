<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserFetch;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\NodeConnectionLog;
use App\Models\Ticket;
use App\Models\Order;
use App\Models\Plan;
use App\Models\SubscribeRequestLog;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\UserOauth;
use App\Services\AuthService;
use App\Services\IpLocationService;
use App\Services\OnlineDeviceService;
use App\Services\ServerService;
use App\Services\SubscribeAuditRetentionService;
use App\Services\SubscriptionRiskService;
use App\Services\SubscriptionTokenHistoryService;
use App\Support\ConfiguredUrl;
use App\Services\Oauth\OauthProviderRegistry;
use App\Utils\Helper;
use App\Utils\TokenRotationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    public function resetSecret(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user) abort(500, '用户不存在');
        // 包 using() 只为给 token 历史标注原因与操作者；捕获本身由 Eloquent 观察者完成，
        // 漏包只会让 issued_reason 退化成 unknown，不会丢记录。
        return TokenRotationContext::using('admin_reset', function () use ($user) {
            $user->token = Helper::guid();
            $user->uuid = Helper::guid(true);
            return response([
                'data' => $user->save()
            ]);
        });
    }

    private function filter(Request $request, $builder, ?SubscriptionRiskService $riskService = null)
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
        if (!$filters) {
            return;
        }
        if (!is_array($filters)) {
            abort(422, '过滤参数有误');
        }

        // 列名与操作符必须来自白名单，避免用未受控字符串直接进入 where()/orderBy()。
        $allowedColumns = self::allowedUserColumns();
        $allowedConditions = ['>', '<', '=', '>=', '<=', '<>', '!=', 'like', '模糊'];

        foreach ($filters as $filter) {
            if (!is_array($filter) || !isset($filter['key']) || !array_key_exists('value', $filter)) {
                continue;
            }
            $key = (string)$filter['key'];
            $condition = isset($filter['condition']) ? (string)$filter['condition'] : '=';
            $value = $filter['value'];

            // 风险徽标是派生字段，不在列白名单内，必须先于白名单与「模糊」改写处理。
            if ($key === 'risk') {
                $this->applyRiskFilter($builder, $condition, (string)$value, $riskService);
                continue;
            }

            // invite_by_email 为虚拟字段（按邀请人邮箱反查），其余必须是真实且非敏感的列。
            if ($key !== 'invite_by_email' && !in_array($key, $allowedColumns, true)) {
                abort(422, '过滤字段有误');
            }
            if (!in_array($condition, $allowedConditions, true)) {
                abort(422, '过滤条件有误');
            }

            if ($condition === '模糊') {
                $condition = 'like';
                // 转义 LIKE 通配符，避免用户输入的 % / _ 改变匹配语义。
                $value = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$value) . '%';
            }

            if ($key === 'd' || $key === 'transfer_enable') {
                $value = $value * 1073741824;
            }

            if ($key === 'invite_by_email') {
                $builder->whereIn('invite_user_id', User::query()
                    ->select('id')
                    ->where('email', $condition, $value));
                continue;
            }

            if ($key === 'plan_id' && $value == 'null') {
                $builder->whereNull('plan_id');
                continue;
            }

            $builder->where($key, $condition, $value);
        }
    }

    /**
     * v2_user 的真实列（排除口令等敏感列），用于校验过滤/排序字段。
     * 进程内静态缓存，避免每次请求都查 information_schema。
     */
    private static function allowedUserColumns(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $sensitive = ['password', 'password_salt', 'password_algo', 'remember_token'];
        try {
            $columns = Schema::getColumnListing('v2_user');
        } catch (\Throwable $e) {
            $columns = [];
        }
        $cache = array_values(array_filter($columns, function ($column) use ($sensitive) {
            return is_string($column) && !in_array($column, $sensitive, true);
        }));
        return $cache;
    }

    /**
     * 「风险」列过滤。徽标语义（SubscriptionRiskService::summaryForUser）逐条对译成 SQL：
     *   可疑   = 手动判定为 suspicious（或回落口径下最新周期为 suspicious）
     *   待观察 = 非可疑，且（未被评估覆盖 或 首个 30 天周期未走完 或 判定为 pending/no_data）
     *   正常   = 非可疑且以上待观察来源全部不成立
     * 徽标是逐行现算的，这里必须用同一套判据，否则筛出来的行和列上显示的牌子对不上。
     */
    private function applyRiskFilter($builder, string $condition, string $value, ?SubscriptionRiskService $riskService = null): void
    {
        // 风险是派生徽标，只有相等语义；其余 condition 属 API 面误用，拒绝。
        if ($condition !== '=') {
            abort(422, '过滤条件有误');
        }
        // 词汇表外的值筛空集而不是报错：过滤抽屉切换字段名会保留上一字段已填的旧值，
        // 这是管理端可达的正常路径，不能拿错误打断列表。
        if (!in_array($value, ['suspicious', 'pending', 'normal'], true)) {
            $builder->whereRaw('1 = 0');
            return;
        }

        // fetch() 会把自己那份服务实例传进来：schema 探测是实例级 memo，共享实例让
        // 过滤与徽标循环只探一次。其余共用 filter() 的端点各建各的。
        $riskService = $riskService ?: new SubscriptionRiskService();
        // 手动评估结果表就位后，「风险」列由它驱动，过滤必须走同一数据源。
        if ($riskService->manualAvailable()) {
            $this->applyManualRiskFilter($builder, $value);
            return;
        }
        // —— 以下是结果表不可用时的回落口径（旧周期账本），与彼时徽标语义一致 ——
        // 风险表不可用时徽标对全员显示「待观察」，过滤语义保持一致：pending 全命中，其余空集。
        if (!$riskService->available()) {
            if ($value !== 'pending') {
                $builder->whereRaw('1 = 0');
            }
            return;
        }

        // 「用户最新一个已评估周期状态为 X」：同用户不存在 cycle_end 更大的行（单订阅制，
        // 周期序列直接锚在 user_id 上，D1）。
        $latestCycleWithStatus = function (string $status) {
            return function ($query) use ($status) {
                $query->select(DB::raw(1))
                    ->from('v2_subscription_risk_cycle as risk_latest')
                    ->whereColumn('risk_latest.user_id', 'v2_user.id')
                    ->where('risk_latest.status', $status)
                    ->whereNotExists(function ($newer) {
                        $newer->select(DB::raw(1))
                            ->from('v2_subscription_risk_cycle as risk_newer')
                            ->whereColumn('risk_newer.user_id', 'risk_latest.user_id')
                            ->whereColumn('risk_newer.cycle_end', '>', 'risk_latest.cycle_end');
                    });
            };
        };
        $anyCycle = function ($query) {
            $query->select(DB::raw(1))
                ->from('v2_subscription_risk_cycle as risk_any')
                ->whereColumn('risk_any.user_id', 'v2_user.id');
        };
        // 首个 30 天周期未走完 = 账号创建时间落在最近一个周期长度之内（锚点 created_at，D2）。
        $firstCycleThreshold = time() - SubscriptionRiskService::CYCLE_SECONDS;

        if ($value === 'suspicious') {
            $builder->whereExists($latestCycleWithStatus('suspicious'));
            return;
        }
        if ($value === 'pending') {
            $builder->whereNotExists($latestCycleWithStatus('suspicious'))
                ->where(function ($query) use ($anyCycle, $firstCycleThreshold, $latestCycleWithStatus) {
                    $query->whereNotExists($anyCycle)
                        ->orWhere('v2_user.created_at', '>', $firstCycleThreshold)
                        ->orWhereExists($latestCycleWithStatus('pending'));
                });
            return;
        }
        $builder->whereNotExists($latestCycleWithStatus('suspicious'))
            ->whereExists($anyCycle)
            ->where('v2_user.created_at', '<=', $firstCycleThreshold)
            ->whereNotExists($latestCycleWithStatus('pending'));
    }

    /**
     * 手动评估落库口径，与 SubscriptionRiskService::manualSummaryForUser 逐条一致：
     *   可疑   = 手动判定为 suspicious
     *   待观察 = 非可疑，且（未被任何一轮评估覆盖 ∨ 判定既非 suspicious 也非 normal）
     *   正常   = 非可疑、已覆盖且判定为 normal
     * 单订阅制：判定行按 user_id 唯一，一用户一行（D1）。
     */
    private function applyManualRiskFilter($builder, string $value): void
    {
        $manualRow = function (string $mode) {
            return function ($query) use ($mode) {
                $query->select(DB::raw(1))
                    ->from('v2_subscription_risk_manual as risk_mm')
                    ->whereColumn('risk_mm.user_id', 'v2_user.id');
                if ($mode === 'suspicious') {
                    $query->where('risk_mm.status', 'suspicious');
                } elseif ($mode === 'odd') {
                    // 待观察来源之一：已覆盖但既非可疑也非正常（no_data 及任何异常值）。
                    $query->whereNotIn('risk_mm.status', ['suspicious', 'normal']);
                }
                // mode 'any' 不加状态条件：只判断该用户是否被评估覆盖过。
            };
        };

        if ($value === 'suspicious') {
            $builder->whereExists($manualRow('suspicious'));
            return;
        }
        if ($value === 'pending') {
            $builder->whereNotExists($manualRow('suspicious'))
                ->where(function ($query) use ($manualRow) {
                    $query->whereNotExists($manualRow('any'))
                        ->orWhereExists($manualRow('odd'));
                });
            return;
        }
        $builder->whereNotExists($manualRow('suspicious'))
            ->whereExists($manualRow('any'))
            ->whereNotExists($manualRow('odd'));
    }

    public function fetch(UserFetch $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        // 排序列必须是真实且非敏感的列（或已知的计算别名 total_used），否则回退到 created_at。
        $sortableColumns = array_merge(self::allowedUserColumns(), ['total_used']);
        if (!in_array($sort, $sortableColumns, true)) {
            $sort = 'created_at';
        }
        $userModel = User::select(
            DB::raw('*'),
            DB::raw('(u+d) as total_used')
        )
            ->orderBy($sort, $sortType);
        // 提前建好传给 filter()：风险过滤与下方徽标循环共享实例，schema 探测只跑一次。
        $riskService = new SubscriptionRiskService();
        $this->filter($request, $userModel, $riskService);
        $total = $userModel->count();
        $res = $userModel->forPage($current, $pageSize)
            ->get();
        // 套餐用 id 建索引，避免 用户数 × 套餐数 的嵌套匹配。
        $plans = Plan::get()->keyBy('id');
        // 一次性批量取本页用户的在线 IP 缓存（Cache::many），取代循环内逐个 Cache::get。
        $onlineDeviceSummaries = (new OnlineDeviceService())->summariesForUsers($res);
        for ($i = 0; $i < count($res); $i++) {
            if (isset($plans[$res[$i]['plan_id']])) {
                $res[$i]['plan_name'] = $plans[$res[$i]['plan_id']]['name'];
            }
            //统计在线设备
            $onlineDevices = $onlineDeviceSummaries[(int)$res[$i]['id']] ?? ['alive_ip' => 0, 'ips' => ''];
            $res[$i]['alive_ip'] = $onlineDevices['alive_ip'];
            $res[$i]['ips'] = $onlineDevices['ips'];
            $res[$i]['subscribe_url'] = Helper::getSubscribeUrl($res[$i]['token']);
            $res[$i]['risk'] = $riskService->summaryForUser((int)$res[$i]['id']);
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
        // 风险摘要：手动评估结果优先，回落 30 天周期账本。
        $user['risk'] = (new SubscriptionRiskService())->summaryForUser((int)$user->id);

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

    /**
     * 订阅审计抽屉：该用户的订阅拉取记录 + 节点连接记录 + UA/IP 汇总 + 风险摘要。
     */
    public function subscribeRequests(Request $request)
    {
        $userId = (int)$request->input('user_id');
        if (!$userId || !User::where('id', $userId)->exists()) {
            abort(404, '用户不存在');
        }
        if (!Schema::hasTable('v2_subscribe_request_log')) {
            return response(['data' => [], 'total' => 0]);
        }

        $query = SubscribeRequestLog::where('user_id', $userId)->orderByDesc('requested_at');
        if ($request->filled('user_agent')) $query->where('user_agent', 'like', '%' . $request->input('user_agent') . '%');
        if ($request->filled('request_ip')) $query->where('request_ip', 'like', '%' . $request->input('request_ip') . '%');
        if ($request->filled('cycle_start')) $query->where('requested_at', '>=', (int)$request->input('cycle_start'));
        if ($request->filled('cycle_end')) $query->where('requested_at', '<', (int)$request->input('cycle_end'));

        $page = max(1, (int)($request->input('page') ?: $request->input('current') ?: 1));
        $pageSize = min(100, max(10, (int)($request->input('pageSize') ?: 20)));
        $total = $query->count();
        $uaCount = (int)(clone $query)->reorder()->selectRaw('COUNT(DISTINCT ua_hash) AS count')->value('count');
        // 必须在 forPage 之前克隆：forPage 会把 limit/offset 写进共享 builder，
        // 之后再 clone 聚合会把分页编译进 COUNT 查询，第二页起恒为 0
        $distinctIpCount = (int)(clone $query)->reorder()->select('request_ip')->distinct()->count('request_ip');
        $uaSummary = (clone $query)->reorder()
            ->select('ua_hash', 'user_agent')
            ->selectRaw('COUNT(*) AS request_count, MIN(requested_at) AS first_requested_at, MAX(requested_at) AS last_requested_at')
            ->groupBy('ua_hash', 'user_agent')
            ->orderByDesc('request_count')
            ->limit(100)
            ->get();
        $records = $query->forPage($page, $pageSize)->get();
        // 每个 IP 的出现次数：单订阅制按用户直接聚合（D1）。
        $ipCounts = [];
        $ipCountsQuery = SubscribeRequestLog::where('user_id', $userId)
            ->select('request_ip')
            ->selectRaw('COUNT(*) AS request_count')
            ->groupBy('request_ip');
        foreach ($ipCountsQuery->get() as $ipCount) {
            $ipCounts[(string)$ipCount->request_ip] = (int)$ipCount->request_count;
        }
        $locationService = new IpLocationService();
        $records->each(function ($record) use ($ipCounts, $locationService) {
            $record['ip_count'] = $ipCounts[(string)$record->request_ip] ?? 0;
            $record['ip_location'] = $locationService->lookup($record->request_ip);
        });
        $connections = $this->nodeConnections($userId, $locationService);
        return response([
            'data' => $records,
            'total' => $total,
            'connections' => $connections,
            'summary' => [
                'request_count' => $total,
                'user_agent_count' => $uaCount,
                'distinct_ip_count' => $distinctIpCount,
                'connection_ip_count' => $connections->pluck('ip')->unique()->count(),
                'user_agents' => $uaSummary
            ],
            'risk' => (new SubscriptionRiskService())->summaryForUser($userId)
        ]);
    }

    /**
     * 节点上报的实际连接 IP。与订阅拉取 IP 是两条完全不同的来源：拉取 IP 是客户端
     * 下载配置时访问 Web 服务留下的，连接 IP 是节点看到的真实使用者。
     */
    private function nodeConnections(int $userId, IpLocationService $locationService)
    {
        if (!Schema::hasTable('v2_node_connection_log')) {
            return collect();
        }
        $records = NodeConnectionLog::where('user_id', $userId)
            ->orderByDesc('last_seen_at')
            ->limit(200)
            ->get();
        if ($records->isEmpty()) {
            return $records;
        }

        $servers = (new ServerService())->getAllServers();
        $serverNames = [];
        foreach ($servers as $server) {
            $serverNames[$server['type'] . '-' . $server['id']] = $server['name'];
        }

        $records->each(function ($record) use ($serverNames, $locationService) {
            $record['node_name'] = $serverNames[$record->node_type . '-' . $record->node_id] ?? null;
            $record['ip_location'] = $locationService->lookup($record->ip);
        });
        return $records;
    }

    /**
     * 风险抽屉：打开时顺手补算已完成而未评估的周期（不带 force，不改写已冻结判定），
     * 再返回摘要与 30 天周期账本。
     */
    public function subscriptionRisk(Request $request)
    {
        $userId = (int)$request->input('user_id');
        $user = User::find($userId);
        if (!$user) abort(404, '用户不存在');

        $riskService = new SubscriptionRiskService();
        $riskService->evaluateCompletedCycles($user);
        return response([
            'data' => [
                'summary' => $riskService->summaryForUser($userId),
                'cycles' => $riskService->cyclesForUser($userId, $request->input('cycle_start') ? (int)$request->input('cycle_start') : null)
            ]
        ]);
    }

    public function clearSubscribeAudit(Request $request)
    {
        $userId = (int)$request->input('user_id');
        if (!$userId || !User::where('id', $userId)->exists()) {
            abort(404, '用户不存在');
        }

        try {
            $counts = (new SubscribeAuditRetentionService())->purgeUser($userId);
        } catch (\Throwable $e) {
            abort(500, '清空审计记录失败');
        }

        // 这是面板里唯一一个删除滥用证据的端点，而 RequestLog 中间件只记路径，
        // 不记是谁删的、删了什么，所以这里单独补一条。
        info('ADMIN AUDIT CLEAR user_id=' . $userId
            . ' by=' . (is_array($request->user) ? ($request->user['email'] ?? '-') : '-')
            . ' ' . json_encode($counts));

        return response([
            'data' => $counts
        ]);
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
            // 包 using() 给 token 历史标注签发原因；捕获由 User::created 观察者完成。
            $created = TokenRotationContext::using('admin_generate', function () use ($user) {
                return User::create($user);
            });
            if (!$created) {
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
        // User::insert() 绕过全部 Eloquent 事件，是整个项目里唯一需要显式记录 token 历史的
        // 写入点。insert 不回填 id，按 token 反查（该列有唯一索引）拿 id。放在事务内：
        // 批量生成回滚时历史也要跟着回滚。
        $tokens = array_values(array_filter(array_column($users, 'token')));
        if (count($tokens)) {
            $historyRows = [];
            foreach (User::whereIn('token', $tokens)->get(['id', 'token']) as $created) {
                $historyRows[] = ['user_id' => (int)$created->id, 'token' => (string)$created->token];
            }
            (new SubscriptionTokenHistoryService())->recordBulk($historyRows, 'admin_generate_bulk');
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
        // 先固化目标集合：清理动作会删除风控过滤器 whereExists 依赖的行，
        // 若最后用原过滤器 delete()，重算后可能一个都匹配不上（用户留存但数据已删），
        // 且 each() 的分页会因集合缩水跳过未处理用户。
        $userIds = (clone $builder)->pluck('id')->all();

        DB::beginTransaction();
        try {
            User::whereIn('id', $userIds)->orderBy('id')->each(function ($user){
                $authService = new AuthService($user);
                $authService->removeAllSession();
                Order::where('user_id', $user->id)->delete();
                InviteCode::where('user_id', $user->id)->delete();
                $tickets = Ticket::where('user_id', $user->id)->get();
                foreach($tickets as $ticket) {
                    TicketMessage::where('ticket_id', $ticket->id)->delete();
                }
                Ticket::where('user_id', $user->id)->delete();
                // 走同一个服务，「该用户的审计数据」只有一处定义，与清空按钮不会漂移。
                (new SubscribeAuditRetentionService())->purgeUser((int)$user->id);
                // token 历史单独清：它不该被「清空该用户审计记录」按钮带走（那个按钮
                // 的用途是重置误判的风险判定），但账号注销后 user_id 已无法解析，必须清。
                (new SubscriptionTokenHistoryService())->purgeUser((int)$user->id);
                User::where('invite_user_id', $user->id)->update(['invite_user_id' => null]);
                if (\Illuminate\Support\Facades\Schema::hasTable('v2_user_oauth')) {
                \App\Models\UserOauth::where('user_id', $user->id)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('v2_oauth_user')) {
                \App\Models\OauthUser::where('user_id', $user->id)->delete();
            }
            });
            User::whereIn('id', $userIds)->delete();
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
            // 同 allDel：审计数据随账号注销一并清理；token 历史不跟随「清空审计记录」
            // 按钮，但账号注销时必须清。
            (new SubscribeAuditRetentionService())->purgeUser((int)$user->id);
            (new SubscriptionTokenHistoryService())->purgeUser((int)$user->id);

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
