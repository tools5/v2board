<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
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
                $telegramService = new TelegramService();
                $message = sprintf(
                    "💰成功收款%s元\n———————————————\n订单号：%s",
                    $orderService->order->total_amount / 100,
                    $orderService->order->trade_no
                );
                $telegramService->sendMessageWithAdmin($message);
            } catch (\Throwable $e) {
                Log::warning('Payment received notification failed', [
                    'trade_no' => $tradeNo,
                    'exception' => get_class($e)
                ]);
            }
        }

        return true;
    }

    private function isValidCallbackContentType(?string $contentType): bool
    {
        if ($contentType === null || $contentType === '' || strlen($contentType) > 200) {
            return false;
        }

        return preg_match(
            '/\A[A-Za-z0-9!#    private function callbackString($value, bool $trim = true): ?string
^_.+-]+\/[A-Za-z0-9!#    private function callbackString($value, bool $trim = true): ?string
^_.+-]+(?:\s*;\s*[A-Za-z0-9!#    private function callbackString($value, bool $trim = true): ?string
^_.+-]+\s*=\s*(?:\"[^\"\r\n]*\"|[A-Za-z0-9!#    private function callbackString($value, bool $trim = true): ?string
^_.+-]+))*\z/',
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
