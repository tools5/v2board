<?php

namespace App\Services;

use App\Models\SubscribeRequestLog;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * 订阅拉取审计：每次订阅拉取写一行 v2_subscribe_request_log。
 * 本类在用户面高频路径上被调用，所有失败一律吞掉，绝不影响订阅下发。
 */
class SubscribeAuditService
{
    private const MAX_USER_AGENT_LENGTH = 1000;

    // 订阅拉取是热路径：static 短路让每个 worker 只做一次表探测（模式 B，
    // 同 AdmobRewardService::ensureTableExists）。
    private static $tableChecked = false;
    private static $tableAvailable = false;

    public static function ensureTable(): void
    {
        if (self::$tableChecked) {
            return;
        }
        self::$tableChecked = true;
        try {
            if (!Schema::hasTable('v2_subscribe_request_log')) {
                Schema::create('v2_subscribe_request_log', function ($table) {
                    $table->bigIncrements('id');
                    $table->integer('user_id');
                    // 本面板为单订阅制：列保留但恒写 NULL，避免与上游 DDL/索引名分叉（D1）。
                    $table->bigInteger('subscription_id')->nullable();
                    $table->string('user_agent', 1000);
                    $table->char('ua_hash', 64);
                    $table->string('request_ip', 45);
                    $table->bigInteger('requested_at');
                    $table->integer('created_at');
                    $table->integer('updated_at');
                    $table->index(['user_id', 'subscription_id', 'requested_at'], 'user_subscription_requested_at');
                    $table->index(['subscription_id', 'requested_at'], 'subscription_requested_at');
                    $table->index(['subscription_id', 'ua_hash', 'requested_at'], 'subscription_ua_requested_at');
                    $table->index(['user_id', 'requested_at'], 'user_requested_at');
                    $table->index('requested_at', 'requested_at');
                    // 刻意不给 request_ip 建索引：这是全站写入量最高的表，共享 IP 分析读的是
                    // 离线聚合出的 v2_ip_account_link，不需要在写路径上多维护一个索引。
                });
            }
            self::$tableAvailable = true;
        } catch (\Throwable $e) {
            // 并发建表或权限不足：再探一次，别人建成了也算可用。
            try {
                self::$tableAvailable = Schema::hasTable('v2_subscribe_request_log');
            } catch (\Throwable $inner) {
                self::$tableAvailable = false;
            }
        }
    }

    public static function available(): bool
    {
        self::ensureTable();
        return self::$tableAvailable;
    }

    public function record(Request $request, $user): ?SubscribeRequestLog
    {
        if (!$user || !self::available()) {
            return null;
        }

        $userAgent = trim((string)$request->header('User-Agent', ''));
        if ($userAgent === '') {
            $userAgent = '(empty)';
        }
        $userAgent = function_exists('mb_substr')
            ? mb_substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH)
            : substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH);

        try {
            return SubscribeRequestLog::create([
                'user_id' => (int)$user->id,
                // 单订阅制恒写 NULL（D1）。
                'subscription_id' => null,
                'user_agent' => $userAgent,
                'ua_hash' => hash('sha256', strtolower($userAgent)),
                'request_ip' => $this->resolveIp($request),
                'requested_at' => time()
            ]);
        } catch (\Throwable $e) {
            // 审计失败绝不能让本来可用的订阅拉取挂掉。
            return null;
        }
    }

    public function resolveIp(Request $request): string
    {
        // 站点经反向代理接入时 REMOTE_ADDR 恒为回环地址。Helper::getRealClientIp 只在
        // 对端属于可信代理（v2board.trusted_proxies / 回环 / 内网）时才解析转发头，
        // 与 v2_order.created_ip 的口径一致，客户端自行伪造的转发头进不了审计记录（D4）。
        $address = Helper::getRealClientIp($request);
        if ($address === '0.0.0.0' || !filter_var($address, FILTER_VALIDATE_IP)) {
            return 'unknown';
        }
        return $address;
    }
}
