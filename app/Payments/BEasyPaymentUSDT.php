<?php

namespace App\Payments;

use App\Payments\Support\PaymentAmountSupport;
use \Curl\Curl;

class BEasyPaymentUSDT {
    use PaymentAmountSupport;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'bepusdt_url' => [
                'label' => 'API 地址',
                'description' => '您的 BEPUSDT API 接口地址(例如: https://xxx.com)',
                'type' => 'input',
            ],
            'bepusdt_apitoken' => [
                'label' => 'API Token',
                'description' => '您的 BEPUSDT API Token',
                'type' => 'input',
            ],
            'bepusdt_trade_type' => [
                'label' => '交易类型',
                'description' => '您的 BEPUSDT 交易类型',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'amount' => $order['total_amount'] / 100,
            'trade_type' => $this->config['bepusdt_trade_type'],
            'notify_url' => $order['notify_url'],
            'order_id' => $order['trade_no'],
            'redirect_url' => $order['return_url']
        ];
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['bepusdt_apitoken'];
        $params['signature'] = md5($str);

        $payload = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            abort(500, __('Payment gateway request failed'));
        }

        $curl = new Curl();
        $curl->setUserAgent('BEPUSDT');
        $curl->setConnectTimeout(10);
        $curl->setTimeout(30);
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOpt(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->setOpt(CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        $curl->post(rtrim((string) $this->config['bepusdt_url'], '/') . '/api/v1/order/create-transaction', $payload);
        $result = $curl->response;
        $requestFailed = $curl->error;
        $errorMessage = $curl->errorMessage;
        $curl->close();

        if ($requestFailed || !is_object($result) || !isset($result->status_code) || (int) $result->status_code !== 200) {
            report(new \RuntimeException('BEPUSDT create order failed: ' . ($errorMessage ?: 'invalid response')));
            abort(500, __('Payment gateway request failed'));
        }

        $paymentURL = $result->data->payment_url ?? null;
        if (!is_string($paymentURL) || $paymentURL === '') {
            report(new \RuntimeException('BEPUSDT create order response is missing payment_url'));
            abort(500, __('Payment gateway request failed'));
        }
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $paymentURL
        ];
    }

    public function notify($params)
    {
        $requiredFields = ['signature', 'order_id', 'trade_id', 'status', 'amount'];
        if (!$this->hasScalarCallbackFields($params, $requiredFields)
            || !$this->hasOnlyScalarCallbackValues($params)) {
            abort(400, 'Required BEPUSDT callback fields are missing');
        }
        $apiToken = $this->config['bepusdt_apitoken'] ?? null;
        if (!is_string($apiToken) || trim($apiToken) === '') {
            abort(400, 'BEPUSDT callback configuration is incomplete');
        }
        $sign = strtolower(trim((string) $params['signature']));
        unset($params['signature']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $apiToken;
        $generateSignature = md5($str);
        if (!hash_equals($generateSignature, $sign)) {
            abort(400, 'BEPUSDT callback signature is invalid');
        }
        // 1: pending 2: success 3: expired
        if (trim((string) $params['status']) !== '2') {
            return [
                'acknowledge_only' => true,
                'custom_result' => 'failed'
            ];
        }

        $amount = $this->decimalToCents($params['amount']);
        if ($amount === null || $amount <= 0 || trim((string) $params['order_id']) === '' || trim((string) $params['trade_id']) === '') {
            abort(400, 'BEPUSDT callback payment details are invalid');
        }

        return [
            'trade_no' => (string) $params['order_id'],
            'callback_no' => (string) $params['trade_id'],
            'amount' => $amount,
            'currency' => 'CNY',
            'expected_currency' => 'CNY',
            'custom_result' => 'ok'
        ];
    }
}
