<?php

namespace App\Payments;

use App\Payments\Support\PaymentAmountSupport;

class CoinPayments {
    use PaymentAmountSupport;

    public function __construct($config) {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'coinpayments_merchant_id' => [
                'label' => 'Merchant ID',
                'description' => '商户 ID，填写您在 Account Settings 中得到的 ID',
                'type' => 'input',
            ],
            'coinpayments_ipn_secret' => [
                'label' => 'IPN Secret',
                'description' => '通知密钥，填写您在 Merchant Settings 中自行设置的值',
                'type' => 'input',
            ],
            'coinpayments_currency' => [
                'label' => '货币代码',
                'description' => '填写您的货币代码（大写），建议与 Merchant Settings 中的值相同',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {

        // IPN notifications are slow, when the transaction is successful, we should return to the user center to avoid user confusion
        $parseUrl = parse_url($order['return_url']);
        $port = isset($parseUrl['port']) ? ":{$parseUrl['port']}" : '';
        $successUrl = "{$parseUrl['scheme']}://{$parseUrl['host']}{$port}";

        $params = [
            'cmd' => '_pay_simple',
            'reset' => 1,
            'merchant' => $this->config['coinpayments_merchant_id'],
            'item_name' => $order['trade_no'],
            'item_number' => $order['trade_no'],
            'want_shipping' => 0,
            'currency' => $this->config['coinpayments_currency'],
            'amountf' => sprintf('%.2f', $order['total_amount'] / 100),
            'success_url' => $successUrl,
            'cancel_url' => $order['return_url'],
            'ipn_url' => $order['notify_url']
        ];

        $params_string = http_build_query($params);

        return [
            'type' => 1, // Redirect to url
            'data' =>  'https://www.coinpayments.net/index.php?' . $params_string
        ];
    }

    public function notify($params)
    {
        $secret = $this->callbackScalarString($this->config['coinpayments_ipn_secret'] ?? null);
        $signHeader = $this->callbackScalarString(request()->header('Hmac', ''));
        $payload = request()->getContent();
        if ($secret === null || $secret === '' || $signHeader === null || $signHeader === ''
            || !is_string($payload) || $payload === '') {
            abort(400, 'HMAC signature is missing');
        }

        $hmac = hash_hmac('sha512', $payload, $secret);
        if (!hash_equals($hmac, $signHeader)) {
            abort(400, 'HMAC signature does not match');
        }

        $requiredFields = ['merchant', 'status', 'item_number', 'txn_id'];
        if (!$this->hasScalarCallbackFields($params, $requiredFields)
            || !$this->hasOnlyScalarCallbackValues($params)) {
            abort(400, 'Required IPN fields are missing');
        }
        $merchantId = $this->callbackScalarString($this->config['coinpayments_merchant_id'] ?? null);
        $callbackMerchant = $this->callbackScalarString($params['merchant']);
        $statusValue = $this->callbackScalarString($params['status']);
        if ($merchantId === null || $merchantId === ''
            || $callbackMerchant === null || !hash_equals($merchantId, $callbackMerchant)) {
            abort(400, 'CoinPayments merchant does not match');
        }
        if ($statusValue === null || !preg_match('/\A-?\d+\z/', $statusValue)) {
            abort(400, 'CoinPayments status is invalid');
        }

        $status = (int) $statusValue;
        if ($status >= 100 || $status == 2) {
            if (!$this->hasScalarCallbackFields($params, ['amount1', 'currency1'])) {
                abort(400, 'Payment amount or currency is missing');
            }
            $amount = $this->decimalToCents($params['amount1']);
            $currencyValue = $this->callbackScalarString($params['currency1']);
            $expectedCurrencyValue = $this->callbackScalarString(
                $this->config['coinpayments_currency'] ?? null
            );
            $itemNumber = $this->callbackScalarString($params['item_number']);
            $transactionId = $this->callbackScalarString($params['txn_id']);
            $currency = $currencyValue === null ? '' : strtoupper($currencyValue);
            $expectedCurrency = $expectedCurrencyValue === null ? '' : strtoupper($expectedCurrencyValue);
            if ($amount === null || $amount <= 0 || $currency === '' || $expectedCurrency === ''
                || $itemNumber === null || $itemNumber === ''
                || $transactionId === null || $transactionId === '') {
                abort(400, 'Payment amount is invalid');
            }

            return [
                'trade_no' => $itemNumber,
                'callback_no' => $transactionId,
                'amount' => $amount,
                'currency' => $currency,
                'expected_currency' => $expectedCurrency,
                'custom_result' => 'IPN OK'
            ];
        }

        return [
            'acknowledge_only' => true,
            'custom_result' => $status < 0 ? 'IPN OK' : 'IPN OK: pending'
        ];
    }

}
