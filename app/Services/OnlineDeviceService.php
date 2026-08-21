<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * 后台用户列表的在线设备摘要：批量读取 UniProxyController::alive() 写入的
 * ALIVE_IP_USER_* 缓存（120 秒 TTL），一页用户一次 Cache::many，不逐行取。
 * 只读缓存，不碰数据库。
 */
class OnlineDeviceService
{
    private const CACHE_KEY_PREFIX = 'ALIVE_IP_USER_';

    /**
     * @param Collection $users 用户模型/数组集合，需含 id
     * @return array<int, array{alive_ip:int, ips:string}>
     */
    public function summariesForUsers(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $cacheKeys = [];
        foreach ($users as $user) {
            $cacheKeys[(int)$user['id']] = self::CACHE_KEY_PREFIX . (int)$user['id'];
        }

        $cacheData = Cache::many(array_values($cacheKeys));
        $summaries = [];
        foreach ($cacheKeys as $userId => $key) {
            $summaries[$userId] = $this->summarize($cacheData[$key] ?? null);
        }
        return $summaries;
    }

    /**
     * alive_ip 直接采用缓存里由 alive() 按 device_limit_mode 算好的计数，
     * 与本面板既有口径完全一致；这里只负责把连接明细拼成展示串。
     */
    private function summarize($ipsArray): array
    {
        $count = 0;
        $connections = [];
        if (is_array($ipsArray)) {
            $count = (int)($ipsArray['alive_ip'] ?? 0);
            foreach ($ipsArray as $nodeTypeId => $nodeData) {
                if ($nodeTypeId === 'alive_ip' || !is_array($nodeData)
                    || !isset($nodeData['aliveips']) || !is_array($nodeData['aliveips'])) {
                    continue;
                }
                foreach ($nodeData['aliveips'] as $ipNodeId) {
                    if (!is_scalar($ipNodeId)) {
                        continue;
                    }
                    $ip = trim(explode('_', (string)$ipNodeId, 2)[0]);
                    if ($ip === '') {
                        continue;
                    }
                    $connections[] = $ip . '_' . $nodeTypeId;
                }
            }
        }

        return [
            'alive_ip' => $count,
            'ips' => implode(', ', $connections)
        ];
    }
}
