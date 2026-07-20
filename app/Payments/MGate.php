<?php

/**
 * 自己写别抄，抄NMB抄
 */
namespace App\Payments;

use App\Payments\Support\PaymentAmountSupport;
use \Curl\Curl;

class MGate {
    use PaymentAmountSupport;

    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'mgate_url' => [
                'label' => 'API地址',
                'description' => '',
                'type' => 'input',
            ],
            'mgate_app_id' => [
                'label' => 'APPID',
                'description' => '',
                'type' => 'input',
            ],
            'mgate_app_secret' => [
                'label' => 'AppSecret',
                'description' => '',
                'type' => 'input',
            ],
            'mgate_source_currency' => [
                'label' => '源货币',
                'description' => '默认CNY',
                'type' => 'input'
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'out_trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url']
        ];
        if (isset($this->config['mgate_source_currency'])) {
            $params['source_currency'] = $this->config['mgate_source_currency'];
        }
        $params['app_id'] = $this->config['mgate_app_id'];
        ksort($params);
        $str = http_build_query($params) . $this->config['mgate_app_secret'];
        $params['sign'] = md5($str);
        $curl = new Curl();
        $curl->setUserAgent('MGate');
        $curl->setConnectTimeout(10);
        $curl->setTimeout(30);
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOpt(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->post(rtrim((string) $this->config['mgate_url'], '/') . '/v1/gateway/fetch', http_build_query($params));
        $result = $curl->response;
        $requestFailed = $curl->error;
        $errorMessage = $curl->errorMessage;
        $curl->close();
        if ($requestFailed || !is_object($result) || empty($result->data->trade_no) || empty($result->data->pay_url)) {
            report(new \RuntimeException('MGate create order failed: ' . ($errorMessage ?: 'invalid response')));
            abort(500, __('Payment gateway request failed'));
        }
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $result->data->pay_url
        ];
    }

    public function notify($params)
    {
        $requiredFields = ['sign', 'out_trade_no', 'trade_no', 'total_amount'];
        if (!$this->hasScalarCallbackFields($params, $requiredFields)
            || !$this->hasOnlyScalarCallbackValues($params)) {
            abort(400, 'Required MGate callback fields are missing');
        }
        $appId = $this->config['mgate_app_id'] ?? null;
        $secret = $this->config['mgate_app_secret'] ?? null;
        if (!is_string($appId) || trim($appId) === '' || !is_string($secret) || trim($secret) === '') {
            abort(400, 'MGate callback configuration is incomplete');
        }
        $sign = strtolower(trim((string) $params['sign']));
        unset($params['sign']);
        ksort($params);
        reset($params);
        $str = http_build_query($params) . $secret;
        $generateSignature = md5($str);
        if (!hash_equals($generateSignature, $sign)) {
            abort(400, 'MGate callback signature is invalid');
        }
        if (isset($params['app_id']) && !hash_equals($appId, (string) $params['app_id'])) {
            abort(400, 'MGate application does not match');
        }

        $amount = $this->integerAmount($params['total_amount']);
        if ($amount === null || $amount <= 0 || trim((string) $params['out_trade_no']) === '' || trim((string) $params['trade_no']) === '') {
            abort(400, 'MGate callback payment details are invalid');
        }
        return [
            'trade_no' => (string) $params['out_trade_no'],
            'callback_no' => (string) $params['trade_no'],
            'amount' => $amount,
            'custom_result' => 'success'
        ];
    }
}
