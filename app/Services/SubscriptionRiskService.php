<?php

namespace App\Services;

use App\Models\NodeConnectionLog;
use App\Models\StatUser;
use App\Models\SubscribeRequestLog;
use App\Models\SubscriptionRiskCycle;
use App\Models\SubscriptionRiskManual;
use App\Models\User;
use App\Services\SubscribeAuditRetentionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 订阅风险评估。本面板为单订阅制：评估主体是用户（D1），30 天周期网格以
 * v2_user.created_at 为锚点铺开（D2，取代 kexue 的 subscription.started_at）。
 * 表名与列名沿用 kexue 以保持管理端字段兼容，subscription_id 列保留但恒为 NULL。
 */
class SubscriptionRiskService
{
    public const CYCLE_SECONDS = 30 * 86400;
    private $availability;
    private $manualAvailability;
    private $manualStagingAvailability;
    private $ruleService;
    private $metricsColumn;
    private $nodeLogAvailability;
    private $nodeMetricKeys;
    private $locationService;
    private $locationMemo = [];

    public function available(): bool
    {
        if ($this->availability !== null) {
            return $this->availability;
        }
        try {
            // 依赖表按需自建（D5）：判定账本自己建，原始审计日志由审计服务建；
            // v2_stat_user 是面板固有表，只探不建。
            $this->ensureCycleTable();
            SubscribeAuditService::ensureTable();
            return $this->availability = Schema::hasTable('v2_subscription_risk_cycle')
                && Schema::hasTable('v2_subscribe_request_log')
                && Schema::hasTable('v2_stat_user');
        } catch (\Throwable $e) {
            return $this->availability = false;
        }
    }

    /**
     * 评估该用户所有已完成的 30 天周期。
     */
    public function evaluateCompletedCycles(User $user, ?int $now = null, bool $force = false): array
    {
        if (!$this->available()) {
            return [];
        }

        $now = $now ?: time();
        // 周期锚点：账号创建时间（D2）。
        $anchor = (int)$user->created_at;
        if ($anchor <= 0 || $anchor >= $now) {
            return [];
        }

        // 已完成周期的输入是封闭的：窗口关闭后不会再有新行落进该区间，只会因保留期被删。
        // 所以重算等于用「证据已被清理」的空结果覆盖当初的判定，把 suspicious 改写成
        // normal。已评估过的周期一律跳过，只有 CLI 的 --force 能穿透。
        $evaluated = [];
        if (!$force) {
            foreach (SubscriptionRiskCycle::where('user_id', (int)$user->id)
                ->whereNotNull('evaluated_at')
                ->pluck('cycle_start') as $cycleStart) {
                $evaluated[(int)$cycleStart] = true;
            }
        }

        // IP 定位 memo 按用户重置。节点连接行按设计会重叠该用户的每一个周期，memo 若留在
        // 单个周期内，同一批 IP 会被每个周期各查一遍（12 个周期 × 200 个 IP = 2400 次点查），
        // 单个用户就能冲破重算的 4 秒界限。MMDB reader 留在服务实例上，整轮只打开一次。
        $this->locationMemo = [];

        // 只评估证据仍在审计保留窗口内的周期：更早的周期日志已被（或将被）清理，
        // 评估结果必然是空数据；同时避免首次上线时对老账号做全量历史回填
        // （3 年账号 ×36 周期 × 全站用户会把 0:20 的 cron 拖成小时级洪峰）。
        $retentionDays = (new SubscribeAuditRetentionService())->retentionDays();
        $evidenceFloor = $retentionDays > 0 ? $now - ($retentionDays * 86400) : 0;

        $completedCycles = intdiv($now - $anchor, self::CYCLE_SECONDS);
        $results = [];
        for ($cycle = 0; $cycle < $completedCycles; $cycle++) {
            $cycleStart = $anchor + ($cycle * self::CYCLE_SECONDS);
            if (isset($evaluated[$cycleStart])) {
                continue;
            }
            $cycleEnd = $cycleStart + self::CYCLE_SECONDS;
            if ($cycleEnd < $evidenceFloor) {
                continue;
            }
            $results[] = $this->evaluateCycle($user, $cycleStart, $cycleEnd);
        }
        return $results;
    }

    /**
     * 采集任意时间窗内的原始指标。30 天周期评估与后台手动自定义周期评估共用这一段，
     * 「怎么判、判完写不写库」的语义差异留在各自的调用方里。
     *
     * $dayOverlapTraffic：v2_stat_user 是按天分桶的 UPSERT 表（record_at 固定为当天 0 点），
     * 点判定 record_at ∈ [start, end) 只取「0 点时间戳落在窗口内」的整桶——
     * 不足 24 小时且不跨午夜的窗口一个桶都取不到，跨午夜的窗口丢起点侧整天。手动评估的
     * 短窗口传 true 改按天重叠取桶（桶区间 [record_at, record_at+86400) 与窗口相交即计入，
     * 粒度为整天）。30 天周期路径保持原点判定不动：边缘桶误差约 1/30，且是已冻结判定的
     * 既有口径，改了等于让历史与新周期不可比。
     */
    public function collectWindow(User $user, int $windowStart, int $windowEnd, bool $dayOverlapTraffic = false): array
    {
        $trafficQuery = StatUser::where('user_id', (int)$user->id);
        if ($dayOverlapTraffic) {
            $trafficQuery->where('record_at', '>', $windowStart - 86400)
                ->where('record_at', '<', $windowEnd);
        } else {
            $trafficQuery->where('record_at', '>=', $windowStart)
                ->where('record_at', '<', $windowEnd);
        }
        $traffic = $trafficQuery
            ->selectRaw('COALESCE(SUM(u + d), 0) AS used_traffic, COUNT(*) AS sample_count')
            ->first();

        $usedTraffic = (int)($traffic->used_traffic ?? 0);
        $sampleCount = (int)($traffic->sample_count ?? 0);
        $transferEnable = max(0, (int)$user->transfer_enable);
        $userAgentCount = (int)SubscribeRequestLog::where('user_id', (int)$user->id)
            ->where('requested_at', '>=', $windowStart)
            ->where('requested_at', '<', $windowEnd)
            ->selectRaw('COUNT(DISTINCT ua_hash) AS count')
            ->value('count');

        $ipRows = SubscribeRequestLog::where('user_id', (int)$user->id)
            ->where('requested_at', '>=', $windowStart)
            ->where('requested_at', '<', $windowEnd)
            ->where('request_ip', '<>', '')
            ->select('request_ip')
            ->selectRaw('COUNT(*) AS request_count')
            ->groupBy('request_ip')
            ->orderByDesc('request_count')
            ->get();

        // IpLocationService::lookup() 每次都重新查一遍 v2_ip_location_cache，没有请求内缓存。
        // 拉取 IP 与连接 IP 两组里的重复项会让查询数翻倍，所以两次归约共享 $this->locationMemo。
        $pullGeo = $this->reduceLocations($ipRows->pluck('request_ip')->all());
        $nodeMetrics = $this->nodeMetrics($user, $windowStart, $windowEnd);
        $hasTrafficBasis = $transferEnable > 0 && $sampleCount > 0;

        return [
            'used_traffic' => $usedTraffic,
            'sample_count' => $sampleCount,
            'transfer_enable' => $transferEnable,
            'used_ratio' => $hasTrafficBasis ? round($usedTraffic / $transferEnable, 8) : null,
            'user_agent_count' => $userAgentCount,
            'distinct_ip_count' => $ipRows->count(),
            'city_count' => $pullGeo['city_count'],
            'region_count' => $pullGeo['region_count'],
            'country_count' => $pullGeo['country_count'],
            'ip_rows' => $ipRows,
            'node_metrics' => $nodeMetrics,
            'has_traffic_basis' => $hasTrafficBasis,
            'has_log_basis' => ($userAgentCount + $ipRows->count()) > 0,
            'has_node_basis' => $nodeMetrics !== null
        ];
    }

    public function evaluateCycle(User $user, int $cycleStart, int $cycleEnd): SubscriptionRiskCycle
    {
        $window = $this->collectWindow($user, $cycleStart, $cycleEnd);
        $usedTraffic = $window['used_traffic'];
        $transferEnable = $window['transfer_enable'];
        $userAgentCount = $window['user_agent_count'];
        $ipRows = $window['ip_rows'];
        $distinctIpCount = $window['distinct_ip_count'];
        $cityCount = $window['city_count'];
        $regionCount = $window['region_count'];
        $countryCount = $window['country_count'];
        $nodeMetrics = $window['node_metrics'];

        $hasTrafficBasis = $window['has_traffic_basis'];
        $hasLogBasis = $window['has_log_basis'];
        $hasNodeBasis = $window['has_node_basis'];
        // 有节点证据但没有拉取证据的周期也可以判定：节点上报的是实际使用者，
        // 「拉一次订阅分发给多地使用」这种场景只在这一侧留下痕迹。
        $hasAnyEvidence = $hasLogBasis || $hasNodeBasis;
        $ratio = $window['used_ratio'];

        // 唯一键重锚为 (user_id, cycle_start)（D1），subscription_id 恒为 NULL。
        $record = SubscriptionRiskCycle::firstOrNew([
            'user_id' => (int)$user->id,
            'cycle_start' => $cycleStart
        ]);
        $record->cycle_end = $cycleEnd;

        // 只在本轮确实拿到依据时才覆盖对应字段组，否则沿用已存值。源数据被保留期清掉
        // 之后再跑（尤其 --force）不会把历史判定抹成零。首次创建时无旧值可留，全部写入。
        if ($hasTrafficBasis || !$record->exists) {
            $record->transfer_enable = $transferEnable;
            $record->used_traffic = $usedTraffic;
            $record->used_ratio = $ratio;
        }
        if ($hasLogBasis || !$record->exists) {
            $record->user_agent_count = $userAgentCount;
            $record->distinct_ip_count = $distinctIpCount;
            $record->city_count = $cityCount;
            $record->region_count = $regionCount;
            $record->country_count = $countryCount;
        }

        // 规则引擎的输入用合并后的值构建，而不是本轮的局部变量。这是部分清理场景下不破坏
        // 历史判定的关键：拉取派生的键取（可能被保留下来的）记录列值，节点派生的键只在本轮
        // 确实有依据时才取新值，否则沿用已存 metrics —— 绝不用清理后算出的 0 覆盖历史。
        $storedMetrics = $this->storedMetrics($record);
        $metrics = [
            'user_agent_count' => (int)$record->user_agent_count,
            'distinct_ip_count' => (int)$record->distinct_ip_count,
            'city_count' => (int)$record->city_count,
            'region_count' => (int)$record->region_count,
            'country_count' => (int)$record->country_count,
            'used_ratio' => $record->used_ratio === null ? null : (float)$record->used_ratio
        ];
        foreach ($this->nodeMetricKeys() as $key) {
            if ($hasNodeBasis) {
                $metrics[$key] = $nodeMetrics[$key];
            } elseif (array_key_exists($key, $storedMetrics)) {
                $metrics[$key] = $storedMetrics[$key];
            }
            // 两处都没有 ⇒ 该键在 $metrics 里缺失。缺失不等于 0，规则不会命中。
        }

        // status 与 risk_reasons 只在本轮有证据时重算。没有证据时原样保留，避免出现一条
        // 既没有理由、又把 suspicious 降级掉的记录。
        if ($hasAnyEvidence || !$record->exists) {
            $reasons = [];
            $mergedRatio = $metrics['used_ratio'];
            if ($mergedRatio !== null) {
                if ($mergedRatio < 0.4) {
                    $reasons[] = '低流量使用率：' . round($mergedRatio * 100, 2) . '%';
                }
            } elseif ((int)$record->transfer_enable <= 0) {
                $reasons[] = '套餐总流量无效';
            } else {
                $reasons[] = '历史流量统计数据不足';
            }

            // 判定完全由规则引擎决定，引擎会指名是哪一条规则命中，管理员能直接对上要改的那一行。
            $ruleResult = $this->ruleService()->evaluate($metrics);
            $reasons = array_merge($reasons, $ruleResult['reasons']);

            // 重复 IP 属于证据标注而非判定输入，只能从本轮的 $ipRows 复原。本轮没有拉取依据
            // 时（拉取日志已被保留期清掉，但节点行仍与该周期重叠）把已存的这几行原样搬过来：
            // 门控放宽到 $hasAnyEvidence 之后，这条路径会重写 risk_reasons，不搬就等于静默
            // 删掉一段证据 —— 判定不受影响，但管理员会看到一条缺了证据的判定。
            if ($hasLogBasis) {
                foreach ($ipRows->filter(function ($row) {
                    return (int)$row->request_count > 1;
                })->take(10) as $ipRow) {
                    $reasons[] = '重复 IP：' . $ipRow->request_ip . ' 出现 ' . $ipRow->request_count . ' 次';
                }
            } else {
                foreach ($this->storedReasons($record) as $stored) {
                    if (strpos((string)$stored, '重复 IP：') === 0) {
                        $reasons[] = (string)$stored;
                    }
                }
            }

            $record->status = $ruleResult['has_risk']
                ? 'suspicious'
                : ($mergedRatio !== null ? 'normal' : 'pending');
            $record->risk_reasons = json_encode(array_values(array_unique($reasons)), JSON_UNESCAPED_UNICODE);
            if ($this->metricsColumnAvailable()) {
                // 手工 encode 而不是靠 Eloquent 的 array cast：cast 走的是不带
                // JSON_UNESCAPED_UNICODE 的 json_encode，规则名会存成 \uXXXX 转义，而相邻的
                // risk_reasons 是可读中文 —— 部署验证要在 phpMyAdmin 里看这一列。
                $record->metrics = json_encode([
                    'v' => 1,
                    'metrics' => $metrics,
                    'fired_rules' => $ruleResult['fired']
                ], JSON_UNESCAPED_UNICODE);
            }
        }

        $record->evaluated_at = time();
        $record->save();
        return $record;
    }

    /**
     * 后台手动自定义周期评估：与周期评估用同一套指标采集和规则引擎。方法本身纯计算
     * ——不读不写任何判定表；调用方（RiskRuleController::manualEvaluate）把返回的三态
     * 落进 v2_subscription_risk_manual 驱动「风险」列。30 天账本 v2_subscription_risk_cycle
     * 是冻结判定，只服务审计抽屉的历史周期视图，本方法与它互不沾染。
     */
    public function assessWindow(User $user, int $windowStart, int $windowEnd): array
    {
        // 与 evaluateCompletedCycles 同理按用户重置 memo：手动评估在一个服务实例上
        // 连续扫几百个用户，memo 不重置会随批次无界增长。
        $this->locationMemo = [];
        // 流量按天重叠取桶（粒度整天），原因见 collectWindow 注释。
        $window = $this->collectWindow($user, $windowStart, $windowEnd, true);

        $metrics = [
            'user_agent_count' => $window['user_agent_count'],
            'distinct_ip_count' => $window['distinct_ip_count'],
            'city_count' => $window['city_count'],
            'region_count' => $window['region_count'],
            'country_count' => $window['country_count'],
            'used_ratio' => $window['used_ratio']
        ];
        // 节点无依据 ⇒ 节点键缺失 ⇒ 规则不命中。与周期评估同一约定：缺失不等于 0。
        if ($window['has_node_basis']) {
            foreach ($this->nodeMetricKeys() as $key) {
                $metrics[$key] = $window['node_metrics'][$key];
            }
        }

        // 窗口内三路证据全空时不进规则引擎：本方法不读历史判定行，无旧值可沿用，
        // 「没有数据」必须与「判定为正常」区分开，否则短窗口会给全站发一遍正常牌。
        if (!$window['has_log_basis'] && !$window['has_node_basis'] && !$window['has_traffic_basis']) {
            return ['status' => 'no_data', 'metrics' => $metrics, 'reasons' => [], 'fired' => []];
        }

        $ruleResult = $this->ruleService()->evaluate($metrics);
        $reasons = $ruleResult['reasons'];
        // 沿用周期评估的重复 IP 证据模板，另拼定位后缀（归属地/运营商/IDC，词汇与订阅
        // 审计抽屉三列一致）。理由串属中文数据，各语种原样展示（漏翻不坏的既有边界）。
        foreach ($window['ip_rows']->filter(function ($row) {
            return (int)$row->request_count > 1;
        })->take(10) as $ipRow) {
            $reasons[] = '重复 IP：' . $ipRow->request_ip . ' 出现 ' . $ipRow->request_count . ' 次'
                . $this->describeIpLocation((string)$ipRow->request_ip);
        }

        return [
            'status' => $ruleResult['has_risk'] ? 'suspicious' : 'normal',
            'metrics' => $metrics,
            'reasons' => array_values(array_unique($reasons)),
            'fired' => $ruleResult['fired']
        ];
    }

    /**
     * 重复 IP 证据行的定位后缀：（归属地，运营商，IDC：…）。三段词汇与订阅审计抽屉的
     * 归属地/运营商/IDC 三列完全一致（含 is_idc 三态：厂商名或「是」/「否」/「未知」）。
     * 复用本轮 collectWindow 已填充的 locationMemo，正常路径零新增点查。
     */
    private function describeIpLocation(string $ip): string
    {
        // 审计层对取不到合法客户端地址的请求存的是字面量 "unknown"（SubscribeAuditService），
        // 给它拼「（未知，IDC：未知）」纯属噪音——非法 IP 不加后缀，保留裸理由行。
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }
        if (!array_key_exists($ip, $this->locationMemo)) {
            // 拉取归约只处理非空 IP，重复 IP 理应已入 memo；兜底补查一次。
            $this->locationMemo[$ip] = $this->locationService()->lookup($ip);
        }
        $location = $this->locationMemo[$ip];
        $place = implode(' / ', array_filter([
            (string)($location['country_name'] ?? '') !== '' ? $location['country_name'] : (string)($location['country_code'] ?? ''),
            (string)($location['province'] ?? '') !== '' ? $location['province'] : (string)($location['region'] ?? ''),
            (string)($location['city'] ?? ''),
            (string)($location['district'] ?? '')
        ], function ($part) {
            return (string)$part !== '';
        }));
        if ($place === '') {
            $place = '未知';
        }
        if (($location['is_idc'] ?? null) === true) {
            $idc = 'IDC：' . ((string)($location['idc_vendor'] ?? '') !== '' ? $location['idc_vendor'] : '是');
        } elseif (($location['is_idc'] ?? null) === false) {
            $idc = 'IDC：否';
        } else {
            $idc = 'IDC：未知';
        }
        $parts = array_filter([$place, (string)($location['isp'] ?? ''), $idc], function ($part) {
            return (string)$part !== '';
        });
        return '（' . implode('，', $parts) . '）';
    }

    /**
     * 用外部快照顶替规则表读取：手动评估整轮跨几十个 step 请求，每请求都现读规则表会让
     * 「评估中途有人改规则」把同一轮结果切成两套判定标准。restart 时把 enabledRules()
     * 快照冻进游标状态，后续每批注入同一份。
     */
    public function useRuleSnapshot(array $rules): void
    {
        $this->ruleService()->useRules($rules);
    }

    private function ruleService(): RiskRuleService
    {
        if ($this->ruleService === null) {
            // 规则表只读一次：EvaluateSubscriptionRisk 只构造一个 SubscriptionRiskService
            // 然后 chunkById 遍历全部用户，RiskRuleService 的实例级 memo 就够。
            $this->ruleService = new RiskRuleService();
        }
        return $this->ruleService;
    }

    /**
     * 刻意与 available() 分开：available() 是硬闸门，往里加一个新列的条件就会在异常的
     * 库上静默关掉全部风控评估。这里只决定「要不要写 metrics」，取不到就跳过写入。
     */
    private function metricsColumnAvailable(): bool
    {
        if ($this->metricsColumn !== null) {
            return $this->metricsColumn;
        }
        try {
            return $this->metricsColumn = Schema::hasColumn('v2_subscription_risk_cycle', 'metrics');
        } catch (\Throwable $e) {
            return $this->metricsColumn = false;
        }
    }

    private function nodeLogAvailable(): bool
    {
        if ($this->nodeLogAvailability !== null) {
            return $this->nodeLogAvailability;
        }
        try {
            return $this->nodeLogAvailability = Schema::hasTable('v2_node_connection_log');
        } catch (\Throwable $e) {
            return $this->nodeLogAvailability = false;
        }
    }

    private function nodeMetricKeys(): array
    {
        if ($this->nodeMetricKeys !== null) {
            return $this->nodeMetricKeys;
        }
        $keys = [];
        foreach (RiskRuleService::DIMENSIONS as $key => $meta) {
            if (($meta['source'] ?? '') === 'node_log') {
                $keys[] = $key;
            }
        }
        return $this->nodeMetricKeys = $keys;
    }

    private function storedMetrics(SubscriptionRiskCycle $record): array
    {
        if (!$record->exists || !$this->metricsColumnAvailable()) {
            return [];
        }
        $stored = json_decode((string)$record->metrics, true);
        if (!is_array($stored) || !isset($stored['metrics']) || !is_array($stored['metrics'])) {
            return [];
        }
        return $stored['metrics'];
    }

    private function storedReasons(SubscriptionRiskCycle $record): array
    {
        if (!$record->exists) {
            return [];
        }
        $decoded = json_decode((string)$record->risk_reasons, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function locationService(): IpLocationService
    {
        if ($this->locationService === null) {
            // 留在服务实例上：IpLocationService 持有 MMDB reader，按周期新建会把 reader
            // 反复打开一遍。
            $this->locationService = new IpLocationService();
        }
        return $this->locationService;
    }

    /**
     * 把地理归约提出来给拉取 IP 和节点连接 IP 两组共用。
     * 两组共享 $this->locationMemo，重复的 IP 只查一次。
     */
    private function reduceLocations(array $ips): array
    {
        $locationService = $this->locationService();
        $locations = [];
        $countries = [];
        foreach ($ips as $ip) {
            $ip = (string)$ip;
            if ($ip === '') {
                continue;
            }
            if (!array_key_exists($ip, $this->locationMemo)) {
                $this->locationMemo[$ip] = $locationService->lookup($ip);
            }
            $location = $this->locationMemo[$ip];
            if (($location['status'] ?? '') !== 'resolved' || !$location['location_key']) {
                continue;
            }
            $locations[$location['location_key']] = $location;
            if ($location['country_code']) {
                $countries[$location['country_code']] = true;
            }
        }

        $cityKeys = [];
        $regionKeys = [];
        foreach ($locations as $location) {
            if ($location['city']) {
                $cityKeys[$location['country_code'] . '|' . $location['region'] . '|' . $location['city']] = true;
            }
            if ($location['region']) {
                $regionKeys[$location['country_code'] . '|' . $location['region']] = true;
            }
        }

        return [
            'city_count' => count($cityKeys),
            'region_count' => count($regionKeys),
            'country_count' => count($countries)
        ];
    }

    /**
     * 节点连接维度。返回 null 表示本周期没有节点依据（表缺失、查询失败或没有重叠行），
     * 调用方据此保留已存指标而不是写 0。
     */
    private function nodeMetrics(User $user, int $cycleStart, int $cycleEnd): ?array
    {
        if (!$this->nodeLogAvailable()) {
            return null;
        }

        try {
            // v2_node_connection_log 是 UPSERT 表：每个「节点用户 + 节点 + IP」一行，
            // first_seen_at 固定、last_seen_at 每次上报刷新，没有按周期的行。所以窗口判定
            // 必须是区间重叠，不能拿单个时间戳去夹。本面板所有行都按 user_id 落库
            // （NodeConnectionAuditService::resolveOwners），直接按 user_id 取。
            $query = NodeConnectionLog::where('user_id', (int)$user->id)
                ->where('first_seen_at', '<', $cycleEnd)
                ->where('last_seen_at', '>=', $cycleStart);

            // first_seen_at 是插入时固定的，所以 new_ip_count 是窗口精确的，不受重叠影响。
            // 刻意不派生任何 report_count 的指标：那是自 first_seen_at 以来的累计计数器，
            // 对重叠行求 SUM 不是窗口范围内的量，暴露它会是正确性 bug 而不是功能。
            $aggregate = (clone $query)->selectRaw(
                "COUNT(*) AS row_count,
                 COUNT(DISTINCT `ip`) AS ip_count,
                 COUNT(DISTINCT CONCAT(`node_type`, '-', `node_id`)) AS node_count,
                 COUNT(DISTINCT CASE WHEN `first_seen_at` >= ? THEN `ip` END) AS new_ip_count",
                [$cycleStart]
            )->first();

            if ((int)($aggregate->row_count ?? 0) <= 0) {
                return null;
            }

            // 与 UserController::nodeConnections 的上限一致：地理归约只看前 200 个去重 IP。
            $ips = (clone $query)->distinct()->limit(200)->pluck('ip')->all();
            $geo = $this->reduceLocations($ips);

            return [
                'node_ip_count' => (int)($aggregate->ip_count ?? 0),
                'node_new_ip_count' => (int)($aggregate->new_ip_count ?? 0),
                'node_count' => (int)($aggregate->node_count ?? 0),
                'node_country_count' => $geo['country_count'],
                'node_region_count' => $geo['region_count'],
                'node_city_count' => $geo['city_count']
            ];
        } catch (\Throwable $e) {
            // 节点指标是增量能力，取不到就退回「无依据」，不能让它中断整轮评估。
            // 必须记日志：静默失败与「本周期没有重叠行」在结果上完全一样，无法区分。
            Log::warning('节点连接指标读取失败，本周期按无节点依据处理', [
                'user_id' => (int)$user->id,
                'cycle_start' => $cycleStart,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 「风险」列徽标是否已切到手动评估数据源。表建不出来（数据库异常）时回落旧的
     * 周期账本口径——与 available() 同理不并进硬闸门，缺表只降级不熄火。
     */
    public function manualAvailable(): bool
    {
        if ($this->manualAvailability !== null) {
            return $this->manualAvailability;
        }
        try {
            $this->ensureManualTable();
            return $this->manualAvailability = Schema::hasTable('v2_subscription_risk_manual');
        } catch (\Throwable $e) {
            return $this->manualAvailability = false;
        }
    }

    public function manualStagingAvailable(): bool
    {
        if ($this->manualStagingAvailability !== null) {
            return $this->manualStagingAvailability;
        }
        try {
            $this->ensureManualStageTable();
            return $this->manualStagingAvailability = Schema::hasTable('v2_subscription_risk_manual_stage');
        } catch (\Throwable $e) {
            return $this->manualStagingAvailability = false;
        }
    }

    /**
     * 用户徽标：手动评估落库数据优先（产品语义：列表「风险」列由手动评估驱动）。
     *   可疑   = 手动判定为 suspicious
     *   正常   = 手动判定为 normal
     *   待观察 = 其余（未被任何一轮评估覆盖 / 窗口内无数据）
     * 30 天账本退居审计抽屉的历史周期视图，不再驱动徽标。
     */
    public function summaryForUser(int $userId): array
    {
        if ($this->manualAvailable()) {
            return $this->manualSummaryForUser($userId);
        }
        return $this->cycleSummaryForUser($userId);
    }

    private function manualSummaryForUser(int $userId): array
    {
        $row = null;
        try {
            // 单订阅制：判定行直接锚在 user_id 上（唯一键），一用户一行。
            $row = SubscriptionRiskManual::where('user_id', $userId)
                ->first(['status', 'risk_reasons', 'metrics']);
        } catch (\Throwable $e) {
            $row = null;
        }

        $reasons = [];
        $suspiciousCount = 0;
        $distinctIpCount = 0;
        $cityCount = 0;
        $regionCount = 0;
        $countryCount = 0;
        // 没赶上任何一轮手动评估 ⇒ 待观察。
        $hasPending = !$row;
        if ($row) {
            if ($row->status === 'suspicious') {
                $suspiciousCount++;
                $rowReasons = json_decode((string)$row->risk_reasons, true);
                $reasons = is_array($rowReasons) ? $rowReasons : [];
            } elseif ($row->status !== 'normal') {
                // no_data 及任何异常值都算待观察。
                $hasPending = true;
            }
            $decoded = json_decode((string)$row->metrics, true);
            $metrics = is_array($decoded) && isset($decoded['metrics']) && is_array($decoded['metrics'])
                ? $decoded['metrics'] : [];
            $distinctIpCount = (int)($metrics['distinct_ip_count'] ?? 0);
            $cityCount = (int)($metrics['city_count'] ?? 0);
            $regionCount = (int)($metrics['region_count'] ?? 0);
            $countryCount = (int)($metrics['country_count'] ?? 0);
        }

        return [
            'status' => $suspiciousCount > 0 ? 'suspicious' : ($hasPending ? 'pending' : 'normal'),
            'suspicious_count' => $suspiciousCount,
            'reasons' => array_values(array_unique($reasons)),
            'distinct_ip_count' => $distinctIpCount,
            'city_count' => $cityCount,
            'region_count' => $regionCount,
            'country_count' => $countryCount
        ];
    }

    private function cycleSummaryForUser(int $userId): array
    {
        if (!$this->available()) {
            return [
                'status' => 'pending', 'suspicious_count' => 0, 'reasons' => [],
                'distinct_ip_count' => 0, 'city_count' => 0, 'region_count' => 0, 'country_count' => 0
            ];
        }

        // 显式列清单：这个方法在 UserController@fetch 里按用户行调用（N+1），SELECT * 会让
        // 每一行都把 metrics 这个 TEXT blob 一起拉出来，而这里从头到尾没有解码它。
        // 单订阅制下每用户一条周期序列，最新一条即当前口径。
        $latest = SubscriptionRiskCycle::where('user_id', $userId)
            ->orderByDesc('cycle_end')
            ->first([
                'cycle_end', 'status', 'risk_reasons',
                'distinct_ip_count', 'city_count', 'region_count', 'country_count'
            ]);

        $reasons = [];
        $suspiciousCount = 0;
        $distinctIpCount = 0;
        $cityCount = 0;
        $regionCount = 0;
        $countryCount = 0;
        $hasPending = $latest === null;
        // 第一个 30 天周期还没走完的账号确实算「待观察」（锚点为 created_at，D2）。
        $createdAt = (int)User::where('id', $userId)->value('created_at');
        if ($createdAt > 0 && $createdAt + self::CYCLE_SECONDS > time()) {
            $hasPending = true;
        }
        if ($latest) {
            $distinctIpCount = (int)$latest->distinct_ip_count;
            $cityCount = (int)$latest->city_count;
            $regionCount = (int)$latest->region_count;
            $countryCount = (int)$latest->country_count;
            if ($latest->status === 'suspicious') {
                $suspiciousCount++;
                $recordReasons = json_decode((string)$latest->risk_reasons, true);
                $reasons = is_array($recordReasons) ? $recordReasons : [];
            } elseif ($latest->status === 'pending') {
                $hasPending = true;
            }
        }

        return [
            'status' => $suspiciousCount > 0 ? 'suspicious' : ($hasPending ? 'pending' : 'normal'),
            'suspicious_count' => $suspiciousCount,
            'reasons' => array_values(array_unique($reasons)),
            'distinct_ip_count' => $distinctIpCount,
            'city_count' => $cityCount,
            'region_count' => $regionCount,
            'country_count' => $countryCount
        ];
    }

    public function cyclesForUser(int $userId, ?int $cycleStart = null)
    {
        if (!$this->available()) {
            return collect();
        }
        $query = SubscriptionRiskCycle::where('user_id', $userId)->orderByDesc('cycle_end');
        if ($cycleStart) $query->where('cycle_start', $cycleStart);
        return $query->get();
    }

    private function ensureCycleTable(): void
    {
        if (Schema::hasTable('v2_subscription_risk_cycle')) {
            return;
        }
        try {
            Schema::create('v2_subscription_risk_cycle', function ($table) {
                $table->bigIncrements('id');
                $table->integer('user_id');
                // 单订阅制：列保留但恒 NULL，唯一键重锚 (user_id, cycle_start)（D1）。
                $table->bigInteger('subscription_id')->nullable();
                $table->bigInteger('cycle_start');
                $table->bigInteger('cycle_end');
                $table->bigInteger('transfer_enable')->default(0);
                $table->bigInteger('used_traffic')->default(0);
                $table->decimal('used_ratio', 12, 8)->nullable();
                $table->integer('user_agent_count')->default(0);
                $table->integer('distinct_ip_count')->default(0);
                $table->integer('city_count')->default(0);
                $table->integer('region_count')->default(0);
                $table->integer('country_count')->default(0);
                $table->string('status', 16)->default('pending');
                $table->text('risk_reasons')->nullable();
                $table->text('metrics')->nullable();
                $table->bigInteger('evaluated_at')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->unique(['user_id', 'cycle_start'], 'user_cycle_start');
                $table->index(['user_id', 'cycle_end'], 'user_cycle_end');
                $table->index('status', 'status');
            });
        } catch (\Throwable $e) {
            // 并发建表或权限不足时忽略，可用性由上层的 hasTable 决定。
        }
    }

    private function ensureManualTable(): void
    {
        if (Schema::hasTable('v2_subscription_risk_manual')) {
            return;
        }
        try {
            Schema::create('v2_subscription_risk_manual', function ($table) {
                $table->bigIncrements('id');
                $table->string('run_id', 32);
                $table->integer('user_id');
                // 单订阅制：列保留但恒 NULL，唯一键重锚 user_id（D1）。
                $table->bigInteger('subscription_id')->nullable();
                $table->string('status', 16)->default('no_data');
                $table->bigInteger('window_start')->default(0);
                $table->bigInteger('window_end')->default(0);
                $table->text('risk_reasons')->nullable();
                $table->text('metrics')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->unique('user_id', 'user_id');
                $table->index('run_id', 'run_id');
            });
        } catch (\Throwable $e) {
            // 并发建表或权限不足时忽略。
        }
    }

    private function ensureManualStageTable(): void
    {
        if (Schema::hasTable('v2_subscription_risk_manual_stage')) {
            return;
        }
        try {
            Schema::create('v2_subscription_risk_manual_stage', function ($table) {
                $table->bigIncrements('id');
                $table->string('run_id', 32);
                $table->integer('user_id');
                // 单订阅制：列保留但恒 NULL，唯一键重锚 (run_id, user_id)（D1）。
                $table->bigInteger('subscription_id')->nullable();
                $table->string('status', 16)->default('no_data');
                $table->bigInteger('window_start')->default(0);
                $table->bigInteger('window_end')->default(0);
                $table->text('risk_reasons')->nullable();
                $table->text('metrics')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->unique(['run_id', 'user_id'], 'run_user');
                $table->index('run_id', 'run_id');
                $table->index('updated_at', 'updated_at');
            });
        } catch (\Throwable $e) {
            // 并发建表或权限不足时忽略。
        }
    }
}
