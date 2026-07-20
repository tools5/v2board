<?php

namespace App\Payments;

use App\Payments\Support\PaymentAmountSupport;

class EPay {
    use PaymentAmountSupport;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'url' => [
                'label' => 'URL',
                'description' => '',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => '',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'KEY',
                'description' => '',
                'type' => 'input',
            ],
            'type' => [
                'label' => 'TYPE',
                'description' => '支付类型，如: alipay, wxpay, qqpay',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'money' => $order['total_amount'] / 100,
            'name' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->config['pid']
        ];
        if (!empty($this->config['type'])) {
            $params['type'] = $this->config['type'];
        }
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => rtrim((string) $this->config['url'], '/') . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params)
    {
        $requiredFields = ['sign', 'pid', 'out_trade_no', 'trade_no', 'trade_status', 'money'];
        if (!$this->hasScalarCallbackFields($params, $requiredFields)
            || !$this->hasOnlyScalarCallbackValues($params)) {
            abort(400, 'Required EPay callback fields are missing');
        }
        $merchantId = $this->config['pid'] ?? null;
        $secret = $this->config['key'] ?? null;
        if (!is_string($merchantId) || trim($merchantId) === '' || !is_string($secret) || trim($secret) === '') {
            abort(400, 'EPay callback configuration is incomplete');
        }
        $sign = strtolower(trim((string) $params['sign']));
        unset($params['sign']);
        unset($params['sign_type']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $secret;
        $generateSignature = md5($str);
        if (!hash_equals($generateSignature, $sign)) {
            abort(400, 'EPay callback signature is invalid');
        }
        if (!hash_equals($merchantId, (string) $params['pid'])) {
            abort(400, 'EPay merchant does not match');
        }

        $tradeStatus = (string) $params['trade_status'];
        if ($tradeStatus !== 'TRADE_SUCCESS') {
            return [
                'acknowledge_only' => true,
                'custom_result' => 'success'
            ];
        }

        $amount = $this->decimalToCents($params['money']);
        if ($amount === null || $amount <= 0 || trim((string) $params['out_trade_no']) === '' || trim((string) $params['trade_no']) === '') {
            abort(400, 'EPay callback payment details are invalid');
        }

        return [
            'trade_no' => (string) $params['out_trade_no'],
            'callback_no' => (string) $params['trade_no'],
            'amount' => $amount,
            'currency' => 'CNY',
            'expected_currency' => 'CNY',
            'custom_result' => 'success'
        ];
    }
}
