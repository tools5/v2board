<?php

namespace App\Console\Commands;

use App\Models\CommissionLog;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckCommission extends Command
{
    private const MAX_MONEY = 2147483647;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:commission';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '返佣服务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->autoCheck();
        $this->autoPayCommission();
    }

    public function autoCheck()
    {
        if ((int)config('v2board.commission_auto_check_enable', 1)) {
            Order::where('commission_status', 0)
                ->where('invite_user_id', '!=', NULL)
                ->whereIn('status', [3, 4])
                ->where('updated_at', '<=', strtotime('-3 day', time()))
                ->update([
                    'commission_status' => 1
                ]);
        }
    }

    public function autoPayCommission()
    {
        $failures = [];
        $commissionShareLevels = $this->resolveCommissionShareLevels();
        Order::where('commission_status', 1)
            ->where('invite_user_id', '!=', NULL)
            ->select('id')
            ->chunkById(100, function ($orders) use (&$failures, $commissionShareLevels) {
                foreach ($orders as $candidate) {
                    try {
                        DB::transaction(function () use ($candidate, $commissionShareLevels) {
                            $order = Order::where('id', $candidate->id)
                                ->lockForUpdate()
                                ->first();

                            if (!$order || (int) $order->commission_status !== 1 || !$order->invite_user_id) {
                                return;
                            }

                            $this->payHandle($order->invite_user_id, $order, $commissionShareLevels);
                            $order->commission_status = 2;
                            $order->saveOrFail();
                        }, 3);
                    } catch (\Throwable $e) {
                        $failures[] = $candidate->id;
                        Log::error('订单返佣失败', [
                            'order_id' => $candidate->id,
                            'message' => $e->getMessage(),
                            'exception' => get_class($e)
                        ]);
                    }
                }
            });

        if ($failures) {
            throw new \RuntimeException('部分订单返佣失败，订单 ID：' . implode(',', $failures));
        }
    }

    public function payHandle($inviteUserId, Order $order, array $commissionShareLevels = null)
    {
        $level = 3;
        if ($commissionShareLevels === null) {
            $commissionShareLevels = $this->resolveCommissionShareLevels();
        }

        $commissionBase = $this->normalizeMoney($order->commission_balance, '订单佣金金额');

        $visitedUserIds = [];
        $orderUserId = (int) $order->user_id;
        if ($orderUserId > 0) {
            $visitedUserIds[$orderUserId] = true;
        }

        for ($l = 0; $l < $level && $inviteUserId; $l++) {
            $inviteUserId = (int) $inviteUserId;
            if ($inviteUserId <= 0) {
                break;
            }
            if (isset($visitedUserIds[$inviteUserId])) {
                throw new \RuntimeException('邀请关系存在循环，用户 ID：' . $inviteUserId);
            }
            $visitedUserIds[$inviteUserId] = true;

            $inviter = User::where('id', $inviteUserId)
                ->lockForUpdate()
                ->first();
            if (!$inviter) {
                break;
            }

            if (isset($commissionShareLevels[$l])) {
                $commissionBalance = $this->calculateCommissionShare(
                    $commissionBase,
                    $commissionShareLevels[$l]
                );

                if ($commissionBalance > 0) {
                    if ((int)config('v2board.withdraw_close_enable', 0)) {
                        $inviter->balance = $this->addMoney(
                            $inviter->balance,
                            $commissionBalance,
                            '用户余额'
                        );
                    } else {
                        $inviter->commission_balance = $this->addMoney(
                            $inviter->commission_balance,
                            $commissionBalance,
                            '用户佣金余额'
                        );
                    }
                    $inviter->saveOrFail();

                    CommissionLog::create([
                        'invite_user_id' => $inviteUserId,
                        'user_id' => $order->user_id,
                        'trade_no' => $order->trade_no,
                        'order_amount' => $order->total_amount,
                        'get_amount' => $commissionBalance
                    ]);

                    $order->actual_commission_balance = $this->addMoney(
                        $order->actual_commission_balance,
                        $commissionBalance,
                        '订单实际佣金'
                    );
                }
            }

            $inviteUserId = $inviter->invite_user_id;
        }

        return true;
    }

    protected function resolveCommissionShareLevels(): array
    {
        if (!(int) config('v2board.commission_distribution_enable', 0)) {
            return [100];
        }

        $levels = [];
        foreach (['l1', 'l2', 'l3'] as $index => $level) {
            $value = config('v2board.commission_distribution_' . $level, 0);
            if ($value === null || $value === '') {
                $value = 0;
            }
            if (!is_numeric($value)) {
                throw new \InvalidArgumentException('返佣比例必须是 0 到 100 的整数');
            }

            $numericValue = (float) $value;
            if (
                !is_finite($numericValue)
                || $numericValue < 0
                || $numericValue > 100
                || floor($numericValue) !== $numericValue
            ) {
                throw new \InvalidArgumentException('返佣比例必须是 0 到 100 的整数');
            }

            $levels[$index] = (int) $numericValue;
        }

        if (array_sum($levels) > 100) {
            throw new \InvalidArgumentException('三级返佣比例总和不能超过 100');
        }

        return $levels;
    }

    protected function calculateCommissionShare(int $commissionBase, int $percentage): int
    {
        return intdiv($commissionBase, 100) * $percentage
            + intdiv(($commissionBase % 100) * $percentage, 100);
    }

    private function addMoney($current, int $amount, string $field): int
    {
        $current = $this->normalizeMoney($current, $field, true);
        if ($amount < 0 || $current > self::MAX_MONEY - $amount) {
            throw new \OverflowException($field . '超出可处理范围');
        }

        return $current + $amount;
    }

    protected function normalizeMoney($value, string $field, bool $emptyAsZero = false): int
    {
        if ($emptyAsZero && ($value === null || $value === '')) {
            return 0;
        }

        if (is_int($value)) {
            if ($value < 0 || $value > self::MAX_MONEY) {
                throw new \UnexpectedValueException($field . '无效');
            }

            return $value;
        }

        if (!is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            throw new \UnexpectedValueException($field . '无效');
        }

        $digits = ltrim($value, '0');
        if ($digits === '') {
            return 0;
        }

        $maximum = (string) self::MAX_MONEY;
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new \UnexpectedValueException($field . '无效');
        }

        return (int) $digits;
    }
}
