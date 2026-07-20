<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class ResetTraffic extends Command
{
    private const LOCK_KEY = 'traffic_reset_lock';
    private const LOCK_TTL = 7200;
    private const USER_CHUNK_SIZE = 500;

    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:traffic';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '流量清空';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->builder = User::where('expired_at', '!=', NULL)
            ->where('expired_at', '>', time());
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', '-1');
        $lockToken = (string) Str::uuid();
        if (!$this->acquireLock(self::LOCK_KEY, $lockToken, self::LOCK_TTL)) {
            $this->warn('已有流量重置任务正在运行，本次跳过。');
            return 0;
        }

        try {
            app(TrafficUpdate::class)->flushBeforeReset($lockToken);
            $this->refreshLock(self::LOCK_KEY, $lockToken, self::LOCK_TTL);

            foreach ($this->groupPlanIdsByResetMethod() as $method => $planIds) {
                $this->refreshLock(self::LOCK_KEY, $lockToken, self::LOCK_TTL);
                $builder = with(clone($this->builder))->whereIn('plan_id', $planIds);

                switch ($method) {
                    case 0:
                        $this->resetByMonthFirstDay($builder, $lockToken);
                        break;
                    case 1:
                        $this->resetByExpireDay($builder, $lockToken);
                        break;
                    case 2:
                        break;
                    case 3:
                        $this->resetByYearFirstDay($builder, $lockToken);
                        break;
                    case 4:
                        $this->resetByExpireYear($builder, $lockToken);
                        break;
                }
            }

            return 0;
        } finally {
            $this->releaseLock(self::LOCK_KEY, $lockToken);
        }
    }

    private function groupPlanIdsByResetMethod(): array
    {
        $defaultMethod = $this->normalizeResetMethod(
            config('v2board.reset_traffic_method', 0),
            '默认流量重置方式'
        );
        $groups = [];

        foreach (Plan::query()->select(['id', 'reset_traffic_method'])->get() as $plan) {
            $method = $plan->reset_traffic_method === null
                ? $defaultMethod
                : $this->normalizeResetMethod(
                    $plan->reset_traffic_method,
                    '套餐 ' . $plan->id . ' 的流量重置方式'
                );
            $groups[$method][] = (int) $plan->id;
        }

        return $groups;
    }

    private function normalizeResetMethod($value, string $field): int
    {
        if (is_string($value) && preg_match('/\A[0-4]\z/D', $value) === 1) {
            return (int) $value;
        }
        if (is_int($value) && $value >= 0 && $value <= 4) {
            return $value;
        }

        throw new \UnexpectedValueException($field . '无效，应为 0 到 4');
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

    private function refreshLock(string $key, string $token, int $seconds): void
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('EXPIRE', KEYS[1], ARGV[2])
end
return 0
LUA;

        if ((int) Redis::eval($script, 1, $key, $token, $seconds) !== 1) {
            throw new \RuntimeException('流量重置锁已失效');
        }
    }

    private function resetByExpireYear($builder, string $lockToken): void
    {
        $today = date('m-d');
        $this->resetInChunks($builder, $lockToken, function ($item) use ($today) {
            return date('m-d', (int) $item->expired_at) === $today;
        });
    }

    private function resetByYearFirstDay($builder, string $lockToken): void
    {
        if ((string)date('md') === '0101') {
            $this->resetInChunks($builder, $lockToken);
        }
    }

    private function resetByMonthFirstDay($builder, string $lockToken): void
    {
        if ((string)date('d') === '01') {
            $this->resetInChunks($builder, $lockToken);
        }
    }

    private function resetByExpireDay($builder, string $lockToken): void
    {
        $lastDay = date('t');
        $today = date('d');
        $now = time();
        $this->resetInChunks($builder, $lockToken, function ($item) use ($lastDay, $today, $now) {
            $expireDay = date('d', (int) $item->expired_at);
            $matchesResetDay = $expireDay === $today
                || ($today === $lastDay && $expireDay >= $lastDay);

            return $matchesResetDay && $now < (int) $item->expired_at - 2160000;
        });
    }

    private function resetInChunks($builder, string $lockToken, callable $predicate = null): void
    {
        $builder->select(['id', 'expired_at'])
            ->chunkById(self::USER_CHUNK_SIZE, function ($users) use ($lockToken, $predicate) {
                $this->refreshLock(self::LOCK_KEY, $lockToken, self::LOCK_TTL);
                $userIds = [];

                foreach ($users as $user) {
                    if ($predicate === null || $predicate($user)) {
                        $userIds[] = (int) $user->id;
                    }
                }

                if ($userIds === []) {
                    return;
                }

                $this->retryTransaction(function () use ($userIds) {
                    User::whereIn('id', $userIds)->update([
                        'u' => 0,
                        'd' => 0
                    ]);
                });
            });
    }

    private function retryTransaction(callable $callback): void
    {
        $attempts = 0;
        $maxAttempts = 3;
        while ($attempts < $maxAttempts) {
            try {
                DB::transaction($callback);
                return;
            } catch (Throwable $e) {
                $attempts++;
                if ($attempts >= $maxAttempts || !$this->isRetryableDatabaseError($e)) {
                    $this->notifyResetFailure($e);
                    throw new \RuntimeException('用户流量重置失败：' . $e->getMessage(), 0, $e);
                }
                sleep(5);
            }
        }
    }

    private function isRetryableDatabaseError(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $code = (string) $exception->getCode();

        return $code === '40001'
            || $code === '1213'
            || strpos($message, '40001') !== false
            || strpos($message, 'deadlock') !== false;
    }

    private function notifyResetFailure(Throwable $exception): void
    {
        try {
            (new TelegramService())->sendMessageWithAdmin(
                date('Y/m/d H:i:s') . '用户流量重置失败：' . $exception->getMessage()
            );
        } catch (Throwable $notificationException) {
            Log::error('用户流量重置失败，且管理员通知发送失败', [
                'message' => $exception->getMessage(),
                'notification_error' => $notificationException->getMessage()
            ]);
        }
    }
}
