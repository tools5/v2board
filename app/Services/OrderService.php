<?php

namespace App\Services;

use App\Jobs\OrderHandleJob;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    const STATUS_PENDING = 0;
    const STATUS_PROCESSING = 1;
    const STATUS_CANCELLED = 2;
    const STATUS_COMPLETED = 3;
    const STATUS_SURPLUS = 4;

    CONST STR_TO_TIME = [
        'month_price' => 1,
        'quarter_price' => 3,
        'half_year_price' => 6,
        'year_price' => 12,
        'two_year_price' => 24,
        'three_year_price' => 36
    ];
    public $order;
    public $user;
    private $paymentRecorded = false;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function open(): bool
    {
        return DB::transaction(function () {
            $order = Order::where('id', $this->order->id)->lockForUpdate()->first();
            if (!$order) {
                return false;
            }

            $this->order = $order;
            if ((int) $order->status === self::STATUS_COMPLETED || (int) $order->status === self::STATUS_SURPLUS) {
                return true;
            }
            if ((int) $order->status !== self::STATUS_PROCESSING) {
                return false;
            }

            $this->user = User::where('id', $order->user_id)->lockForUpdate()->first();
            if (!$this->user) {
                throw new \RuntimeException('Order user does not exist');
            }

            if ((int) $order->type === 9) {
                $this->user->balance += $order->total_amount + $this->getbounus($order->total_amount);
                if (!$this->user->save()) {
                    throw new \RuntimeException('Failed to add deposit balance');
                }
                $order->status = self::STATUS_COMPLETED;
                if (!$order->save()) {
                    throw new \RuntimeException('Failed to complete deposit order');
                }
                return true;
            }

            $plan = Plan::find($order->plan_id);
            if (!$plan) {
                throw new \RuntimeException('Order plan does not exist');
            }

            if ($order->refund_amount) {
                $this->user->balance += $order->refund_amount;
            }

            if ($order->surplus_order_ids) {
                Order::whereIn('id', $order->surplus_order_ids)
                    ->where('status', self::STATUS_COMPLETED)
                    ->lockForUpdate()
                    ->get();
                Order::whereIn('id', $order->surplus_order_ids)
                    ->where('status', self::STATUS_COMPLETED)
                    ->update(['status' => self::STATUS_SURPLUS]);
            }

            switch ((string) $order->period) {
                case 'onetime_price':
                    $this->buyByOneTime($order, $plan);
                    break;
                case 'reset_price':
                    $this->buyByResetTraffic();
                    break;
                default:
                    $this->buyByPeriod($order, $plan);
            }

            switch ((int) $order->type) {
                case 1:
                    $this->openEvent(config('v2board.new_order_event_id', 0));
                    break;
                case 2:
                    $this->openEvent(config('v2board.renew_order_event_id', 0));
                    break;
                case 3:
                    $this->openEvent(config('v2board.change_order_event_id', 0));
                    break;
            }

            $this->setSpeedLimit($plan->speed_limit);
            if (!$this->user->save()) {
                throw new \RuntimeException('Failed to update order user');
            }

            $order->status = self::STATUS_COMPLETED;
            if (!$order->save()) {
                throw new \RuntimeException('Failed to complete order');
            }

            return true;
        }, 3);
    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === 'deposit'){
            $order->type = 9;
        } else if ($order->period === 'reset_price') {
            $order->type = 4;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int)config('v2board.plan_change_enable', 1)) abort(500, '目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = 3;
            if ((int)config('v2board.surplus_enable', 1)) $this->getSurplusValue($user, $order);
            if ($order->surplus_amount >= $order->total_amount) {
                $order->refund_amount = $order->surplus_amount - $order->total_amount;
                $order->total_amount = 0;
            } else {
                $order->total_amount = $order->total_amount - $order->surplus_amount;
            }
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) { // 用户订阅未过期且购买订阅与当前订阅相同 === 续费
            $order->type = 2;
        } else { // 新购
            $order->type = 1;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        if ($user->discount) {
            $order->discount_amount = (int) round($order->discount_amount + ($order->total_amount * ($user->discount / 100)));
        }
        $order->total_amount = $order->total_amount - $order->discount_amount;
    }

    public function setInvite(User $user):void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0)) return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) return;
        $isCommission = false;
        switch ((int)$inviter->commission_type) {
            case 0:
                $commissionFirstTime = (int)config('v2board.commission_first_time_enable', 1);
                $isCommission = (!$commissionFirstTime || ($commissionFirstTime && !$this->haveValidOrder($user)));
                break;
            case 1:
                $isCommission = true;
                break;
            case 2:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission) return;
        if ($inviter && $inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (config('v2board.invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user)
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [0, 2])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $this->getSurplusValueByOneTime($user, $order);
        } else {
            $this->getSurplusValueByPeriod($user, $order);
        }
    }


    private function getSurplusValueByOneTime(User $user, Order $order)
    {
        $lastOneTimeOrder = Order::where('user_id', $user->id)
            ->where('period', 'onetime_price')
            ->where('status', 3)
            ->orderBy('id', 'DESC')
            ->first();
        if (!$lastOneTimeOrder) return;
        $nowUserTraffic = $user->transfer_enable / 1073741824;
        if ($nowUserTraffic == 0) return;
        $paidTotalAmount = ($lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount);
        if ($paidTotalAmount == 0) return;
        $notUsedTraffic = $nowUserTraffic - (($user->u + $user->d) / 1073741824);
        $remainingTrafficRatio = $notUsedTraffic / $nowUserTraffic;
        $result = $remainingTrafficRatio * $paidTotalAmount;
        $order->surplus_amount = max($result, 0);
        $orderModel = Order::where('user_id', $user->id)->where('period', '!=', 'reset_price')->where('status', 3);
        $order->surplus_order_ids = array_column($orderModel->get()->toArray(), 'id');
    }

    private function getSurplusValueByPeriod(User $user, Order $order)
    {
        $orders = Order::where('user_id', $user->id)
            ->where('period', '!=', 'reset_price')
            ->where('period', '!=', 'onetime_price')
            ->where('period', '!=', 'deposit')
            ->where('status', 3)
            ->get()
            ->toArray();
        if (!$orders) return;
        $orderAmountSum = 0;
        $orderMonthSum = 0;
        $lastValidateAt = null;
        foreach ($orders as $item) {
            $period = self::STR_TO_TIME[$item['period']];
            $orderEndTime = strtotime("+{$period} month", $item['created_at']);
            if ($orderEndTime < time()) continue;
            $lastValidateAt = $item['created_at'] > $lastValidateAt ? $item['created_at'] : $lastValidateAt;
            $orderMonthSum += $period;
            $orderAmountSum += $item['total_amount'] + $item['balance_amount'] + $item['surplus_amount'] - $item['refund_amount'];
        }
        if ($lastValidateAt === null) return;
    
        $expiredAtByOrder = strtotime("+{$orderMonthSum} month", $lastValidateAt);
        $expiredAtByUser = $user->expired_at;
        if ($expiredAtByOrder < time() || $expiredAtByUser < time()) return;
        $orderSurplusSecond = $expiredAtByUser - time();
        $orderRangeSecond = $expiredAtByOrder - $lastValidateAt;
    
        $totalTraffic = $user->transfer_enable;
        $usedTraffic = ($user->u + $user->d);
        if ($totalTraffic == 0) return;
    
        $remainingTrafficRatio = ($totalTraffic - $usedTraffic) / $totalTraffic;
    
        $avgPricePerSecond = $orderAmountSum / $orderRangeSecond;
        if ($orderRangeSecond <= 31 * 86400) {
            $remainingExpiredTimeRatio = $orderSurplusSecond / $orderRangeSecond;
            $surplusRatio = min($remainingExpiredTimeRatio, $remainingTrafficRatio);
            $orderSurplusAmount = $avgPricePerSecond * $orderSurplusSecond * $surplusRatio;
        } else {
            $monthSeconds = 30 * 86400;
            $firstMonthRemainSeconds = $orderSurplusSecond % $monthSeconds;
            $surplusRatio = min($firstMonthRemainSeconds / $monthSeconds, $remainingTrafficRatio);
            $laterMonthsSeconds = $orderSurplusSecond - $firstMonthRemainSeconds;
            $orderSurplusAmount = $avgPricePerSecond * $monthSeconds * $surplusRatio +
                                  $avgPricePerSecond * $laterMonthsSeconds;
        }
    
        $order->surplus_amount = max($orderSurplusAmount, 0);
        $order->surplus_order_ids = array_column($orders, 'id');
    }

    public function paid(string $callbackNo, ?int $paymentId = null, ?int $paidAmount = null): bool
    {
        if ($callbackNo === '' || strlen($callbackNo) > 255) {
            return false;
        }

        $shouldDispatch = false;
        $this->paymentRecorded = false;
        $updated = DB::transaction(function () use ($callbackNo, $paymentId, $paidAmount, &$shouldDispatch) {
            $order = Order::where('id', $this->order->id)->lockForUpdate()->first();
            if (!$order) {
                return false;
            }

            $this->order = $order;
            if ((int) $order->status === self::STATUS_CANCELLED || (int) $order->status === self::STATUS_SURPLUS) {
                return false;
            }

            if ($paymentId !== null && (int) $order->payment_id !== $paymentId) {
                return false;
            }

            if ($paidAmount !== null) {
                $expectedAmount = (int) $order->total_amount + (int) $order->handling_amount;
                if ($paidAmount !== $expectedAmount) {
                    return false;
                }
            }

            if (in_array((int) $order->status, [self::STATUS_PROCESSING, self::STATUS_COMPLETED], true)
                && $order->callback_no
                && !hash_equals((string) $order->callback_no, $callbackNo)) {
                return false;
            }

            if ((int) $order->status === self::STATUS_COMPLETED) {
                return true;
            }

            if ((int) $order->status === self::STATUS_PROCESSING) {
                $shouldDispatch = true;
                return true;
            }
            if ((int) $order->status !== self::STATUS_PENDING) {
                return false;
            }

            $order->status = self::STATUS_PROCESSING;
            $order->paid_at = time();
            $order->callback_no = $callbackNo;
            if (!$order->save()) {
                return false;
            }

            $this->paymentRecorded = true;
            $shouldDispatch = true;
            return true;
        }, 3);

        if (!$updated || !$shouldDispatch) {
            return $updated;
        }

        try {
            OrderHandleJob::dispatch($this->order->trade_no);
        } catch (\Throwable $e) {
            return false;
        }
        return true;
    }

    public function wasPaymentRecorded(): bool
    {
        return $this->paymentRecorded;
    }

    public function cancel():bool
    {
        return DB::transaction(function () {
            $order = Order::where('id', $this->order->id)->lockForUpdate()->first();
            if (!$order) {
                return false;
            }

            $this->order = $order;
            if ((int) $order->status === self::STATUS_CANCELLED) {
                return true;
            }
            if ((int) $order->status !== self::STATUS_PENDING || $order->payment_id !== null) {
                return false;
            }

            if ($order->balance_amount) {
                $user = User::where('id', $order->user_id)->lockForUpdate()->first();
                if (!$user) {
                    return false;
                }
                $user->balance += $order->balance_amount;
                if (!$user->save()) {
                    throw new \RuntimeException('Failed to refund order balance');
                }
            }

            if ($order->coupon_id) {
                $coupon = Coupon::where('id', $order->coupon_id)->lockForUpdate()->first();
                if ($coupon && $coupon->limit_use !== null) {
                    $coupon->limit_use += 1;
                    if (!$coupon->save()) {
                        throw new \RuntimeException('Failed to restore coupon usage');
                    }
                }
            }

            $order->status = self::STATUS_CANCELLED;
            if (!$order->save()) {
                throw new \RuntimeException('Failed to cancel order');
            }

            return true;
        }, 3);
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function buyByResetTraffic()
    {
        $this->user->u = 0;
        $this->user->d = 0;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int)$order->type === 3) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        // 从一次性转换到循环
        if ($this->user->expired_at === NULL) $this->buyByResetTraffic();
        // 新购
        if ($order->type === 1) $this->buyByResetTraffic();

        // 到期当天续费刷新流量
        $expireDay = date('d', $this->user->expired_at);
        $expireMonth = date('m', $this->user->expired_at);
        $today = date('d');
        $currentMonth = date('m');
        if ($order->type === 2 && $expireMonth == $currentMonth && $expireDay === $today ) {
            $this->buyByResetTraffic();
        }

        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    private function buyByOneTime(Order $order, Plan $plan)
    {
        $transfer_enable = $plan->transfer_enable;
        if (!$order->surplus_order_ids) {
            $notUsedTraffic = ($this->user->transfer_enable - ($this->user->u + $this->user->d)) / 1073741824;
            if ($notUsedTraffic > 0 && $this->user->expired_at == NULL) {
                $transfer_enable += $notUsedTraffic;
            }
        }
        $this->buyByResetTraffic();
        $this->user->transfer_enable = $transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
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
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                $this->buyByResetTraffic();
                break;
        }
    }

    private function getbounus($total_amount) {
        $deposit_bounus = config('v2board.deposit_bounus', []);
        if (empty($deposit_bounus) || $deposit_bounus[0] === null) {
            return 0;
        }
        $add = 0;
        foreach ($deposit_bounus as $tier) {
            list($amount, $bounus) = explode(':', $tier);
            $amount = (float)$amount * 100;
            $bounus = (float)$bounus * 100;
            $amount = (int)$amount;
            $bounus = (int)$bounus;
            if ($total_amount >= $amount) {
                $add = max($add, $bounus);
            }
        }
        return $add;
    }
}
