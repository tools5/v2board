<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * XBClient 客户端的 AdMob 激励广告：配置下发、SSV 服务端验证、奖励发放与记录。
 *
 * 流程：客户端从 /admob/user/config 拿广告单元与 ssv_custom_data（本服务签发的
 * HMAC 凭据，绑定用户+场景）→ 用户看完广告后 Google 调 /admob/guest/ssv（ECDSA
 * 签名验证）→ 本服务按场景发放奖励并落表 → 客户端用 /reward-pending、/reward-history
 * 查询结果。奖励内容按 v2board 配置：plan 场景延长套餐/加流量，points 场景加余额。
 */
class AdmobRewardService
{
    public const SCENE_PLAN = 'plan';
    public const SCENE_POINTS = 'points';

    private const VERIFIER_KEYS_URL = 'https://www.gstatic.com/admob/reward/verifier-keys.json';
    private const VERIFIER_KEYS_CACHE_KEY = 'ADMOB_SSV_VERIFIER_KEYS';
    private const VERIFIER_KEYS_CACHE_TTL = 86400;
    // SSV 先于/晚于 reward-pending 到达都可能：pending 行在此窗口内可被 SSV 升级
    private const PENDING_MATCH_WINDOW = 3600;
    // reward-pending 认为“刚看完的广告”的追溯窗口
    private const CREDITED_NOTIFY_WINDOW = 900;

    private static $tableChecked = false;

    public static function ensureTableExists(): void
    {
        if (self::$tableChecked) {
            return;
        }
        self::$tableChecked = true;
        if (Schema::hasTable('v2_admob_reward')) {
            return;
        }
        try {
            Schema::create('v2_admob_reward', function ($table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->string('scene', 16);
                $table->string('transaction_id', 128)->default('');
                $table->string('status', 16)->default('pending');
                $table->string('error', 255)->default('');
                $table->string('reward_content', 255)->default('');
                $table->text('rewards')->nullable();
                $table->integer('used_at')->default(0);
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->index(['user_id', 'scene', 'created_at'], 'idx_admob_user_scene_time');
                $table->index('transaction_id', 'idx_admob_transaction');
            });
        } catch (\Throwable $exception) {
            // 并发建表时忽略，后续查询失败会由业务层报错
        }
    }

    /**
     * 场景是否可用：开关开启 + 广告单元已填 + 奖励定义非空。
     */
    public static function sceneEnabled(string $scene): bool
    {
        if ($scene === self::SCENE_PLAN) {
            return (int)config('v2board.admob_plan_reward_ad_enabled', 0)
                && trim((string)config('v2board.admob_plan_rewarded_ad_unit_id', '')) !== ''
                && ((int)config('v2board.admob_plan_reward_expire_days', 0) > 0
                    || (int)config('v2board.admob_plan_reward_transfer_gb', 0) > 0);
        }
        if ($scene === self::SCENE_POINTS) {
            return (int)config('v2board.admob_points_reward_ad_enabled', 0)
                && trim((string)config('v2board.admob_points_rewarded_ad_unit_id', '')) !== ''
                && (int)config('v2board.admob_points_reward_balance', 0) > 0;
        }
        return false;
    }

    /**
     * 签发绑定 用户+场景 的 SSV custom_data（AdMob 会原样回传）。
     */
    public static function customData(int $userId, string $scene): string
    {
        $issuedAt = time();
        $payload = "v1.{$scene}.{$userId}.{$issuedAt}";
        return $payload . '.' . self::sign($payload);
    }

    /**
     * 解析并校验 custom_data；无效返回 null。
     */
    public static function parseCustomData($value): ?array
    {
        if (!is_string($value)) {
            return null;
        }
        $parts = explode('.', trim($value));
        if (count($parts) !== 5 || $parts[0] !== 'v1') {
            return null;
        }
        [$version, $scene, $userId, $issuedAt, $signature] = $parts;
        if (!in_array($scene, [self::SCENE_PLAN, self::SCENE_POINTS], true)
            || !ctype_digit($userId) || !ctype_digit($issuedAt)) {
            return null;
        }
        $payload = "{$version}.{$scene}.{$userId}.{$issuedAt}";
        if (!hash_equals(self::sign($payload), $signature)) {
            return null;
        }
        return ['scene' => $scene, 'user_id' => (int)$userId];
    }

    /**
     * 客户端上报“已看完广告”：若 SSV 已到，返回已发放内容；否则登记 pending 行。
     */
    public function pendingResult(int $userId, string $scene): array
    {
        self::ensureTableExists();
        $since = time() - self::CREDITED_NOTIFY_WINDOW;
        $credited = DB::table('v2_admob_reward')
            ->where('user_id', $userId)
            ->where('scene', $scene)
            ->where('status', 'credited')
            ->where('used_at', '>=', $since)
            ->orderByDesc('id')
            ->first();
        if ($credited) {
            return [
                'credited' => true,
                'reward_content' => (string)$credited->reward_content,
                'rewards' => $credited->rewards ? json_decode($credited->rewards, true) : null,
            ];
        }
        $hasPending = DB::table('v2_admob_reward')
            ->where('user_id', $userId)
            ->where('scene', $scene)
            ->where('status', 'pending')
            ->where('created_at', '>=', time() - self::PENDING_MATCH_WINDOW)
            ->exists();
        if (!$hasPending) {
            $now = time();
            DB::table('v2_admob_reward')->insert([
                'user_id' => $userId,
                'scene' => $scene,
                'transaction_id' => '',
                'status' => 'pending',
                'error' => '',
                'reward_content' => '等待 Google 验证',
                'rewards' => null,
                'used_at' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return ['credited' => false];
    }

    /**
     * 用户奖励记录（新→旧，最多 50 条），字段与客户端 parseRewardLogs 对齐。
     */
    public function historyRows(int $userId): array
    {
        self::ensureTableExists();
        return DB::table('v2_admob_reward')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int)$row->id,
                    'scene' => (string)$row->scene,
                    'transaction_id' => (string)$row->transaction_id,
                    'status' => (string)$row->status,
                    'error' => (string)$row->error,
                    'reward_content' => (string)$row->reward_content,
                    'rewards' => $row->rewards ? json_decode($row->rewards, true) : null,
                    'used_at' => (int)$row->used_at,
                    'created_at' => (int)$row->created_at,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 处理 Google SSV 回调。签名验证使用原始 query string（&signature= 之前的部分）。
     * 参见 https://developers.google.com/admob/android/ssv
     */
    public function handleSsvCallback(string $rawQueryString, array $params): void
    {
        self::ensureTableExists();
        $this->verifySsvSignature($rawQueryString, $params);

        $custom = self::parseCustomData($params['custom_data'] ?? null);
        if (!$custom) {
            abort(400, 'custom_data 无效');
        }
        $transactionId = trim((string)($params['transaction_id'] ?? ''));
        if ($transactionId === '') {
            abort(400, 'transaction_id 缺失');
        }
        // Google 会重试回调：同一交易只发放一次
        $exists = DB::table('v2_admob_reward')
            ->where('transaction_id', $transactionId)
            ->where('status', 'credited')
            ->exists();
        if ($exists) {
            return;
        }

        $user = User::find($custom['user_id']);
        if (!$user) {
            abort(400, '用户不存在');
        }
        $scene = $custom['scene'];
        if (!self::sceneEnabled($scene)) {
            $this->recordFailure($user->id, $scene, $transactionId, '该奖励场景已关闭');
            return;
        }
        $dailyLimit = (int)config(
            $scene === self::SCENE_PLAN
                ? 'v2board.admob_plan_reward_daily_limit'
                : 'v2board.admob_points_reward_daily_limit',
            0
        );
        if ($dailyLimit > 0) {
            $todayStart = strtotime('today');
            $creditedToday = DB::table('v2_admob_reward')
                ->where('user_id', $user->id)
                ->where('scene', $scene)
                ->where('status', 'credited')
                ->where('used_at', '>=', $todayStart)
                ->count();
            if ($creditedToday >= $dailyLimit) {
                $this->recordFailure($user->id, $scene, $transactionId, '今日奖励次数已达上限');
                return;
            }
        }

        DB::transaction(function () use ($user, $scene, $transactionId) {
            $locked = User::lockForUpdate()->find($user->id);
            if (!$locked) {
                abort(500, '用户不存在');
            }
            // 加锁后复查：Google 超时重发的并发回调只发放一次
            $duplicated = DB::table('v2_admob_reward')
                ->where('transaction_id', $transactionId)
                ->where('status', 'credited')
                ->exists();
            if ($duplicated) {
                return;
            }
            [$rewards, $content] = $this->creditRewards($locked, $scene);
            if (!$rewards) {
                // 无可发放内容（如奖励为延长套餐但用户没有可延长的套餐）：
                // 记失败行并对 Google 返回 200，避免无意义的重试
                $this->finishRewardRow($locked->id, $scene, $transactionId, [
                    'status' => 'failed',
                    'error' => $content,
                    'reward_content' => '未发放',
                    'rewards' => null,
                ]);
                return;
            }
            if (!$locked->save()) {
                abort(500, '奖励发放失败');
            }
            $this->finishRewardRow($locked->id, $scene, $transactionId, [
                'status' => 'credited',
                'error' => '',
                'reward_content' => $content,
                'rewards' => json_encode($rewards, JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    /**
     * 按场景把奖励累加到已加锁的用户模型（不落库），返回 [rewards 数组, 摘要或失败原因]。
     */
    private function creditRewards(User $user, string $scene): array
    {
        $rewards = [];
        $parts = [];
        if ($scene === self::SCENE_POINTS) {
            $balance = (int)config('v2board.admob_points_reward_balance', 0);
            if ($balance > 0) {
                $user->balance = (int)$user->balance + $balance;
                $rewards['balance'] = $balance;
                $parts[] = '余额 +' . rtrim(rtrim(number_format($balance / 100, 2, '.', ''), '0'), '.');
            }
        } else {
            $expireDays = (int)config('v2board.admob_plan_reward_expire_days', 0);
            $transferGb = (int)config('v2board.admob_plan_reward_transfer_gb', 0);
            // 一次性/长期套餐（expired_at 为 NULL）或无套餐用户跳过延期，只发流量
            if ($expireDays > 0 && $user->plan_id && $user->expired_at !== null) {
                $base = max(time(), (int)$user->expired_at);
                $user->expired_at = $base + $expireDays * 86400;
                $rewards['expire_days'] = $expireDays;
                $parts[] = "套餐 +{$expireDays} 天";
            }
            if ($transferGb > 0 && $user->plan_id) {
                $bytes = $transferGb * 1073741824;
                $user->transfer_enable = (int)$user->transfer_enable + $bytes;
                $rewards['transfer_enable'] = $bytes;
                $parts[] = "流量 +{$transferGb} GB";
            }
        }
        if (!$rewards) {
            return [[], '当前账号没有可发放的奖励（请先购买套餐）'];
        }
        return [$rewards, implode(' · ', $parts)];
    }

    /**
     * 把结果写入奖励记录：优先升级窗口内的 pending 行，否则插入新行。
     */
    private function finishRewardRow(int $userId, string $scene, string $transactionId, array $result): void
    {
        $now = time();
        $fields = array_merge($result, [
            'transaction_id' => $transactionId,
            'used_at' => $now,
            'updated_at' => $now,
        ]);
        $pending = DB::table('v2_admob_reward')
            ->where('user_id', $userId)
            ->where('scene', $scene)
            ->where('status', 'pending')
            ->where('created_at', '>=', $now - self::PENDING_MATCH_WINDOW)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
        if ($pending) {
            DB::table('v2_admob_reward')->where('id', $pending->id)->update($fields);
            return;
        }
        DB::table('v2_admob_reward')->insert(array_merge($fields, [
            'user_id' => $userId,
            'scene' => $scene,
            'created_at' => $now,
        ]));
    }

    private function recordFailure(int $userId, string $scene, string $transactionId, string $error): void
    {
        $this->finishRewardRow($userId, $scene, $transactionId, [
            'status' => 'failed',
            'error' => $error,
            'reward_content' => '未发放',
            'rewards' => null,
        ]);
    }

    /**
     * 验证 Google ECDSA(SHA256) 签名。消息是回调原始 query string 中
     * `&signature=` 之前的全部内容（保持原始编码，不能重排/重编码）。
     */
    private function verifySsvSignature(string $rawQueryString, array $params): void
    {
        $signature = (string)($params['signature'] ?? '');
        $keyId = (string)($params['key_id'] ?? '');
        if ($signature === '' || $keyId === '') {
            abort(400, '缺少签名参数');
        }
        $signaturePos = strpos($rawQueryString, '&signature=');
        if ($signaturePos === false) {
            abort(400, '回调格式无效');
        }
        $message = substr($rawQueryString, 0, $signaturePos);
        $pem = $this->verifierKeyPem($keyId);
        $der = base64_decode(strtr($signature, '-_', '+/'));
        if ($der === false || $der === '') {
            abort(400, '签名编码无效');
        }
        if (openssl_verify($message, $der, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            abort(403, 'SSV 签名验证失败');
        }
    }

    private function verifierKeyPem(string $keyId): string
    {
        $keys = Cache::get(self::VERIFIER_KEYS_CACHE_KEY);
        if (!is_array($keys) || !isset($keys[$keyId])) {
            $response = Http::timeout(10)->get(self::VERIFIER_KEYS_URL);
            if (!$response->ok()) {
                abort(500, '获取 Google 验证公钥失败');
            }
            $list = $response->json('keys');
            if (!is_array($list)) {
                abort(500, 'Google 验证公钥格式无效');
            }
            $keys = [];
            foreach ($list as $key) {
                if (isset($key['keyId'], $key['pem'])) {
                    $keys[(string)$key['keyId']] = (string)$key['pem'];
                }
            }
            Cache::put(self::VERIFIER_KEYS_CACHE_KEY, $keys, self::VERIFIER_KEYS_CACHE_TTL);
        }
        if (!isset($keys[$keyId])) {
            abort(403, '未知的 SSV 签名 key_id');
        }
        return $keys[$keyId];
    }

    private static function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, self::ssvSecret()), 0, 32);
    }

    /**
     * custom_data 的 HMAC 密钥：从 APP_KEY 派生。
     * webman 多 worker 各持一份内存配置，运行期生成再落盘会导致各 worker 密钥不一致
     * （签发与验证落在不同 worker 时必然失败），派生密钥天然全局一致且无需写文件。
     */
    private static function ssvSecret(): string
    {
        $appKey = (string)config('app.key');
        if ($appKey === '') {
            abort(500, 'APP_KEY 未配置');
        }
        return hash_hmac('sha256', 'admob_ssv_custom_data', $appKey);
    }
}
