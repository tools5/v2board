<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class TrafficUpdate extends Command
{
    private const UPLOAD_KEY = 'v2board_upload_traffic';
    private const DOWNLOAD_KEY = 'v2board_download_traffic';
    private const BATCH_POINTER_KEY = 'v2board_traffic_batch_current';
    private const UPDATE_LOCK_KEY = 'traffic_update_lock';
    private const RESET_LOCK_KEY = 'traffic_reset_lock';
    private const UPDATE_LOCK_TTL = 7200;
    private const UPDATE_LOCK_WAIT = 900;
    private const RESET_LOCK_TTL = 7200;
    private const IDEMPOTENCY_SCOPE = 'traffic_update';
    private const MAX_USER_ID = 2147483647;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'traffic:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '流量更新任务';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        return $this->runUpdate(false);
    }

    public function flushBeforeReset(string $resetLockToken): void
    {
        $this->runUpdate(true, $resetLockToken);
    }

    private function runUpdate(bool $duringReset, string $resetLockToken = null): int
    {
        ini_set('memory_limit', '-1');

        $lockToken = (string) Str::uuid();
        if (!$this->acquireUpdateLock($lockToken, $duringReset, $resetLockToken)) {
            return 0;
        }

        try {
            if ($duringReset) {
                $this->assertResetLockOwner($resetLockToken);
                $this->refreshOwnedLock(self::RESET_LOCK_KEY, $resetLockToken, self::RESET_LOCK_TTL);
            } elseif (Redis::exists(self::RESET_LOCK_KEY)) {
                return 0;
            }

            $this->refreshOwnedLock(self::UPDATE_LOCK_KEY, $lockToken, self::UPDATE_LOCK_TTL);
            $hadPendingBatch = (bool) Redis::exists(self::BATCH_POINTER_KEY);
            $this->processNextBatch();
            $this->refreshOwnedLock(self::UPDATE_LOCK_KEY, $lockToken, self::UPDATE_LOCK_TTL);

            if ($duringReset && $hadPendingBatch) {
                $this->assertResetLockOwner($resetLockToken);
                $this->refreshOwnedLock(self::RESET_LOCK_KEY, $resetLockToken, self::RESET_LOCK_TTL);
                $this->processNextBatch();
                $this->refreshOwnedLock(self::UPDATE_LOCK_KEY, $lockToken, self::UPDATE_LOCK_TTL);
            }

            DB::table('v2_job_idempotency')
                ->where('scope', self::IDEMPOTENCY_SCOPE)
                ->where('created_at', '<', time() - 604800)
                ->delete();

            return 0;
        } catch (\Throwable $e) {
            // The claimed Redis hashes are intentionally kept for the next run.
            Log::error('流量更新失败', [
                'message' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw $e;
        } finally {
            $this->releaseLock(self::UPDATE_LOCK_KEY, $lockToken);
        }
    }

    private function processNextBatch(): bool
    {
        $batchId = $this->claimBatch();
        if (!$batchId) {
            return false;
        }

        $uploadBatchKey = $this->batchKey($batchId, 'upload');
        $downloadBatchKey = $this->batchKey($batchId, 'download');
        $uploads = Redis::hgetall($uploadBatchKey);
        $downloads = Redis::hgetall($downloadBatchKey);

        $this->applyBatch($batchId, $uploads, $downloads);
        $this->deleteClaimedBatch($batchId, $uploadBatchKey, $downloadBatchKey);

        return true;
    }

    private function claimBatch()
    {
        $newBatchId = (string) Str::uuid();
        $uploadBatchKey = $this->batchKey($newBatchId, 'upload');
        $downloadBatchKey = $this->batchKey($newBatchId, 'download');

        $script = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if current then
    return current
end

local claimed = 0
if redis.call('EXISTS', KEYS[2]) == 1 then
    redis.call('RENAME', KEYS[2], KEYS[4])
    claimed = 1
end
if redis.call('EXISTS', KEYS[3]) == 1 then
    redis.call('RENAME', KEYS[3], KEYS[5])
    claimed = 1
end

if claimed == 0 then
    return false
end

redis.call('SET', KEYS[1], ARGV[1])
return ARGV[1]
LUA;

        $batchId = Redis::eval(
            $script,
            5,
            self::BATCH_POINTER_KEY,
            self::UPLOAD_KEY,
            self::DOWNLOAD_KEY,
            $uploadBatchKey,
            $downloadBatchKey,
            $newBatchId
        );

        if (!$batchId) {
            return null;
        }

        $batchId = (string) $batchId;
        if (!preg_match('/^[a-f0-9-]{36}$/i', $batchId)) {
            throw new \RuntimeException('Redis 流量批次标识无效');
        }

        return $batchId;
    }

    private function applyBatch(string $batchId, array $uploads, array $downloads): void
    {
        $traffic = $this->normalizeTraffic($uploads, $downloads);
        $now = time();

        DB::transaction(function () use ($batchId, $traffic, $now) {
            $inserted = DB::table('v2_job_idempotency')->insertOrIgnore([
                'scope' => self::IDEMPOTENCY_SCOPE,
                'job_id' => $batchId,
                'created_at' => $now
            ]);

            if (!$inserted) {
                return;
            }

            foreach (array_chunk($traffic, 500, true) as $chunk) {
                $uploadCases = [];
                $downloadCases = [];
                $bindings = [];

                foreach ($chunk as $userId => $values) {
                    $uploadCases[] = 'WHEN ? THEN ?';
                    $bindings[] = $userId;
                    $bindings[] = $values['upload'];
                }
                foreach ($chunk as $userId => $values) {
                    $downloadCases[] = 'WHEN ? THEN ?';
                    $bindings[] = $userId;
                    $bindings[] = $values['download'];
                }

                $bindings[] = $now;
                $bindings[] = $now;
                foreach (array_keys($chunk) as $userId) {
                    $bindings[] = $userId;
                }

                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $sql = sprintf(
                    'UPDATE v2_user SET u = u + CASE id %s ELSE 0 END, '
                    . 'd = d + CASE id %s ELSE 0 END, t = ?, updated_at = ? '
                    . 'WHERE id IN (%s)',
                    implode(' ', $uploadCases),
                    implode(' ', $downloadCases),
                    $placeholders
                );

                DB::update($sql, $bindings);
            }
        }, 3);
    }

    private function normalizeTraffic(array $uploads, array $downloads): array
    {
        $traffic = [];
        $userIds = array_unique(array_merge(array_keys($uploads), array_keys($downloads)));

        foreach ($userIds as $userId) {
            $userId = (string) $userId;
            if (
                !ctype_digit($userId)
                || (int) $userId <= 0
                || (float) $userId > self::MAX_USER_ID
            ) {
                Log::warning('忽略无效的流量用户标识', ['user_id' => $userId]);
                continue;
            }

            $upload = $this->normalizeBytes($uploads[$userId] ?? 0);
            $download = $this->normalizeBytes($downloads[$userId] ?? 0);
            if ($upload === 0 && $download === 0) {
                continue;
            }

            $traffic[(int) $userId] = [
                'upload' => $upload,
                'download' => $download
            ];
        }

        return $traffic;
    }

    private function normalizeBytes($value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        $value = (float) $value;
        if (!is_finite($value) || $value <= 0) {
            return 0;
        }

        return (int) min(PHP_INT_MAX, round($value));
    }

    private function deleteClaimedBatch(string $batchId, string $uploadKey, string $downloadKey): void
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    redis.call('DEL', KEYS[1], KEYS[2], KEYS[3])
    return 1
end
return 0
LUA;

        $deleted = Redis::eval(
            $script,
            3,
            self::BATCH_POINTER_KEY,
            $uploadKey,
            $downloadKey,
            $batchId
        );

        if ((int) $deleted !== 1) {
            throw new \RuntimeException('Redis 流量批次清理失败');
        }
    }

    private function batchKey(string $batchId, string $direction): string
    {
        return "v2board_traffic_batch:{$batchId}:{$direction}";
    }

    private function acquireLock(string $key, string $token, int $seconds): bool
    {
        $script = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then
    redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
    return 1
end
return 0
LUA;

        return (int) Redis::eval($script, 1, $key, $token, $seconds) === 1;
    }

    private function acquireUpdateLock(
        string $lockToken,
        bool $duringReset,
        string $resetLockToken = null
    ): bool {
        $deadline = time() + self::UPDATE_LOCK_WAIT;
        do {
            if ($this->acquireLock(self::UPDATE_LOCK_KEY, $lockToken, self::UPDATE_LOCK_TTL)) {
                return true;
            }

            if (!$duringReset) {
                return false;
            }

            $this->assertResetLockOwner($resetLockToken);
            sleep(1);
        } while (time() < $deadline);

        throw new \RuntimeException('等待流量归集任务结束超时');
    }

    private function refreshOwnedLock(string $key, string $token, int $seconds): void
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('EXPIRE', KEYS[1], ARGV[2])
end
return 0
LUA;

        if ((int) Redis::eval($script, 1, $key, $token, $seconds) !== 1) {
            throw new \RuntimeException('流量任务锁已失效：' . $key);
        }
    }

    private function assertResetLockOwner(string $resetLockToken = null): void
    {
        $currentToken = Redis::get(self::RESET_LOCK_KEY);
        if (!$resetLockToken || !is_string($currentToken) || !hash_equals($resetLockToken, $currentToken)) {
            throw new \RuntimeException('流量重置锁已失效');
        }
    }

    private function releaseLock(string $key, string $token): void
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

        Redis::eval($script, 1, $key, $token);
    }
}
