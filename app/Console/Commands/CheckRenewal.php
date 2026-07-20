<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckRenewal extends Command
{
    private const MAX_MONEY = 2147483647;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:renewal';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '自动续费';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        ini_set('memory_limit', '-1');
        $now = time();
        $failures = [];

        User::where('auto_renewal', 1)
            ->whereNotNull('plan_id')
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', $now)
            ->where('expired_at', '<', $now + 172800)
            ->select('id')
            ->chunkById(200, function ($users) use (&$failures) {
                foreach ($users as $candidate) {
                    try {
                        $reason = $this->renewUser($candidate->id);
                        if ($reason) {
                            Log::notice('用户自动续费已关闭', [
                                'user_id' => $candidate->id,
                                'reason' => $reason
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $failures[] = $candidate->id;
                        Log::error('用户自动续费失败', [
                            'user_id' => $candidate->id,
                            'message' => $e->getMessage(),
                            'exception' => get_class($e)
                        ]);
                    }
                }
            });

        if ($failures) {
            throw new \RuntimeException('部分用户自动续费失败，用户 ID：' . implode(',', $failures));
        }

        return 0;
    }

    private function renewUser(int $userId)
    {
        return DB::transaction(function () use ($userId) {
            $user = User::where('id', $userId)
                ->lockForUpdate()
                ->first();
            if (!$user || !$this->isEligible($user)) {
                return null;
            }

            try {
                $latestOrder = Order::where('user_id', $user->id)
                    ->whereNotIn('period', ['reset_price', 'onetime_price', 'deposit'])
                    ->where('status', 3)
                    ->orderBy('created_at', 'desc')
                    ->first();
                if (!$latestOrder) {
                    throw new DomainException('没有可用于续费的历史订单');
                }

                $period = (string) $latestOrder->period;
                if (!in_array($period, [
                    'month_price',
                    'quarter_price',
                    'half_year_price',
                    'year_price',
                    'two_year_price',
                    'three_year_price'
                ], true)) {
                    throw new DomainException('历史订单周期无效');
                }

                $plan = Plan::find($user->plan_id);
                if (!$plan) {
                    throw new DomainException('订阅不存在');
                }
                if (!$plan->renew) {
                    throw new DomainException('订阅不允许续费');
                }

                $price = $this->normalizeMoney($plan->{$period}, '订阅续费价格');
                $balance = $this->normalizeMoney($user->balance, '用户余额');
                if ($balance < $price) {
                    throw new DomainException('余额不足');
                }
            } catch (DomainException $e) {
                $user->auto_renewal = 0;
                $user->saveOrFail();
                return $e->getMessage();
            }

            $newExpiredAt = $this->getTime($period, (int) $user->expired_at);
            if (!$newExpiredAt) {
                throw new \RuntimeException('无法计算自动续费到期时间');
            }

            $order = new Order();
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $order->period = $period;
            $order->trade_no = Helper::generateOrderNo();
            $order->balance_amount = $price;
            $order->total_amount = 0;
            $order->type = 2;
            $order->status = 3;

            $user->balance = $balance - $price;
            $user->expired_at = $newExpiredAt;
            $user->saveOrFail();
            $order->saveOrFail();

            return null;
        }, 3);
    }

    private function isEligible(User $user): bool
    {
        $now = time();
        return (bool) $user->auto_renewal
            && $user->plan_id !== null
            && $user->expired_at !== null
            && (int) $user->expired_at > $now
            && (int) $user->expired_at - $now < 172800;
    }

    protected function normalizeMoney($value, string $field): int
    {
        if (is_int($value)) {
            if ($value < 0 || $value > self::MAX_MONEY) {
                throw new DomainException($field . '无效');
            }

            return $value;
        }

        if (!is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            throw new DomainException($field . '无效');
        }

        $digits = ltrim($value, '0');
        if ($digits === '') {
            return 0;
        }

        $maximum = (string) self::MAX_MONEY;
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new DomainException($field . '无效');
        }

        return (int) $digits;
    }

    private function getTime(string $period, int $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }

        switch ($period) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }

        return null;
    }
}
