<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!is_array($verify)) {
                throw new \UnexpectedValueException('Payment gateway returned an invalid callback result');
            }

            $customResult = 'success';
            if (array_key_exists('custom_result', $verify)) {
                $customResult = $this->callbackString($verify['custom_result'], false);
                if ($customResult === null) {
                    abort(400, 'Payment callback response is invalid');
                }
            }

            $contentType = 'text/plain; charset=UTF-8';
            if (array_key_exists('custom_content_type', $verify)) {
                $contentType = $this->callbackString($verify['custom_content_type']);
                if (!$this->isValidCallbackContentType($contentType)) {
                    abort(400, 'Payment callback content type is invalid');
                }
            }

            $acknowledgeOnly = $verify['acknowledge_only'] ?? false;
            if (array_key_exists('acknowledge_only', $verify) && !is_bool($acknowledgeOnly)) {
                abort(400, 'Payment callback acknowledgement is invalid');
            }
            if ($acknowledgeOnly) {
                return response($customResult, 200)->header('Content-Type', $contentType);
            }

            $tradeNo = $this->callbackString($verify['trade_no'] ?? null);
            $callbackNo = $this->callbackString($verify['callback_no'] ?? null);
            if ($tradeNo === null || $tradeNo === '' || strlen($tradeNo) > 255
                || $callbackNo === null || $callbackNo === '' || strlen($callbackNo) > 255) {
                abort(400, 'Payment callback is missing an order or transaction number');
            }
            if (array_key_exists('expected_currency', $verify)) {
                $currency = $this->callbackString($verify['currency'] ?? null);
                $expectedCurrency = $this->callbackString($verify['expected_currency']);
                if ($currency === null || $currency === ''
                    || $expectedCurrency === null || $expectedCurrency === '') {
                    abort(400, 'Payment currency does not match');
                }
                $currency = strtoupper($currency);
                $expectedCurrency = strtoupper($expectedCurrency);
                if (!hash_equals($expectedCurrency, $currency)) {
                    abort(400, 'Payment currency does not match');
                }
            }

            if (!array_key_exists('amount', $verify)) {
                abort(400, 'Payment callback amount is missing');
            }
            $paidAmount = $this->callbackAmount($verify['amount']);
            if ($paidAmount === null || $paidAmount <= 0) {
                abort(400, 'Payment amount is invalid');
            }

            if (!$this->handle(
                $tradeNo,
                $callbackNo,
                $paymentService->getPaymentId(),
                $paidAmount
            )) {
                throw new \RuntimeException('Payment callback could not be applied to the order');
            }

            return response($customResult, 200)->header('Content-Type', $contentType);
        } catch (HttpExceptionInterface $e) {
            Log::warning('Payment callback rejected', [
                'method' => (string) $method,
                'status' => $e->getStatusCode(),
                'exception' => get_class($e)
            ]);
            return response('fail', $e->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('Payment callback failed', [
                'method' => (string) $method,
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return response('fail', 500);
        }
    }

    private function handle(string $tradeNo, string $callbackNo, ?int $paymentId, int $paidAmount): bool
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return false;
        }

        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo, $paymentId, $paidAmount)) {
            return false;
        }

        if ($orderService->wasPaymentRecorded()) {
            try {
                $this->sendPaymentReceiptNotification($orderService->order);
            } catch (\Throwable $e) {
                Log::warning('Payment received notification failed', [
                    'trade_no' => $tradeNo,
                    'exception' => get_class($e)
                ]);
            }
        }

        return true;
    }

    private function sendPaymentReceiptNotification(Order $order): void
    {
        $user = User::find($order->user_id);
        $payment = $order->payment_id ? Payment::find($order->payment_id) : null;
        $plan = $order->plan_id ? Plan::find($order->plan_id) : null;
        $coupon = $order->coupon_id ? Coupon::find($order->coupon_id) : null;
        $inviter = $order->invite_user_id ? User::find($order->invite_user_id) : null;
        $todayIncome = Order::whereNotNull('paid_at')
            ->where('paid_at', '>=', strtotime('today'))
            ->sum('total_amount');
        $siteUrl = (string) config('v2board.app_url', '');
        $siteHost = parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl;

        // 默认按纯文本发送（parseMode 非 markdown），值不做 markdown 转义
        $message = sprintf(
            "💰 成功收款 %s 元\n———————————————\n🌐 支付接口：%s\n🏦 支付渠道：%s\n📧 用户邮箱：%s\n📦 购买套餐：%s\n📅 套餐周期：%s\n🎫 优  惠  券：%s\n👥 邀  请  人：%s\n🆔 订  单  号：%s\n🌐 来源网址：%s\n📅 注册日期：%s\n📍 下单 IP：%s\n———————————————\n💵 今日总收入：%s 元",
            number_format($order->total_amount / 100, 2, '.', ''),
            $payment->name ?? '未知',
            $payment->payment ?? '未知',
            $user->email ?? '未知',
            $plan->name ?? ($order->plan_id ? '套餐已删除' : '余额充值'),
            $this->periodLabel((string) $order->period),
            $coupon->code ?? '无',
            $inviter->email ?? '无',
            $order->trade_no,
            $siteHost ?: '未配置',
            $user ? date('Y-m-d H:i:s', (int) $user->created_at) : '未知',
            $order->created_ip ?: '暂无记录',
            number_format($todayIncome / 100, 2, '.', '')
        );

        (new TelegramService())->sendMessageWithAdmin($message);
    }

    private function periodLabel(string $period): string
    {
        $labels = [
            'month_price' => '月付',
            'quarter_price' => '季付',
            'half_year_price' => '半年付',
            'year_price' => '年付',
            'two_year_price' => '两年付',
            'three_year_price' => '三年付',
            'onetime_price' => '一次性',
            'reset_price' => '流量重置包',
            'deposit' => '余额充值',
        ];

        return $labels[$period] ?? $period;
    }

    private function isValidCallbackContentType(?string $contentType): bool
    {
        if ($contentType === null || $contentType === '' || strlen($contentType) > 200) {
            return false;
        }

        return preg_match(
            '/\A[A-Za-z0-9!#$%&\'*+^_.+-]+\/[A-Za-z0-9!#$%&\'*+^_.+-]+(?:\s*;\s*[A-Za-z0-9!#$%&\'*+^_.+-]+\s*=\s*(?:\"[^\"\r\n]*\"|[A-Za-z0-9!#$%&\'*+^_.+-]+))*\z/',
            $contentType
        ) === 1;
    }

    private function callbackString($value, bool $trim = true): ?string
    {
        if (!is_string($value) && !is_int($value)
            && !(is_float($value) && is_finite($value))) {
            return null;
        }

        $value = (string) $value;
        return $trim ? trim($value) : $value;
    }

    private function callbackAmount($value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            return null;
        }

        return (int) $normalized;
    }
}
