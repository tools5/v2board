<?php

/**
 * 自己写别抄，抄NMB抄
 */
namespace App\Payments;

use App\Payments\Support\PaymentAmountSupport;

class AlipayF2F {
    use PaymentAmountSupport;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'app_id' => [
                'label' => '支付宝APPID',
                'description' => '',
                'type' => 'input',
            ],
            'private_key' => [
                'label' => '支付宝私钥',
                'description' => '',
                'type' => 'input',
            ],
            'public_key' => [
                'label' => '支付宝公钥',
                'description' => '',
                'type' => 'input',
            ],
            'product_name' => [
                'label' => '自定义商品名称',
                'description' => '将会体现在支付宝账单中',
                'type' => 'input'
            ]
        ];
    }

    public function pay($order)
    {
        try {
            $gateway = new \Library\AlipayF2F();
            $gateway->setMethod('alipay.trade.precreate');
            $gateway->setAppId($this->config['app_id']);
            $gateway->setPrivateKey($this->config['private_key']); // 可以是路径，也可以是密钥内容
            $gateway->setAlipayPublicKey($this->config['public_key']); // 可以是路径，也可以是密钥内容
            $gateway->setNotifyUrl($order['notify_url']);
            $gateway->setBizContent([
                'subject' => $this->config['product_name'] ?? (config('v2board.app_name', 'V2Board') . ' - 订阅'),
                'out_trade_no' => $order['trade_no'],
                'total_amount' => $order['total_amount'] / 100
            ]);
            $gateway->send();
            return [
                'type' => 0, // 0:qrcode 1:url
                'data' => $gateway->getQrCodeUrl()
            ];
        } catch (\Throwable $e) {
            report($e);
            abort(500, __('Payment gateway request failed'));
        }
    }

    public function notify($params)
    {
        $requiredFields = [
            'sign',
            'app_id',
            'trade_status',
            'out_trade_no',
            'trade_no',
            'total_amount'
        ];
        if (!$this->hasScalarCallbackFields($params, $requiredFields)
            || !$this->hasOnlyScalarCallbackValues($params)) {
            abort(400, 'Required Alipay callback fields are missing');
        }
        $appId = $this->config['app_id'] ?? null;
        $publicKey = $this->config['public_key'] ?? null;
        if (!is_string($appId) || trim($appId) === '' || !is_string($publicKey) || trim($publicKey) === '') {
            abort(400, 'Alipay callback configuration is incomplete');
        }

        $gateway = new \Library\AlipayF2F();
        $gateway->setAppId($appId);
        $gateway->setAlipayPublicKey($publicKey); // 可以是路径，也可以是密钥内容
        try {
            $verified = $gateway->verify($params);
        } catch (\Throwable $e) {
            report($e);
            abort(400, 'Alipay callback signature is invalid');
        }
        if (!$verified) {
            abort(400, 'Alipay callback signature is invalid');
        }
        if (!hash_equals($appId, (string) $params['app_id'])) {
            abort(400, 'Alipay application does not match');
        }
        if (!in_array((string) $params['trade_status'], ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return [
                'acknowledge_only' => true,
                'custom_result' => 'success'
            ];
        }

        $amount = $this->decimalToCents($params['total_amount']);
        if ($amount === null || $amount <= 0 || trim((string) $params['out_trade_no']) === ''
            || trim((string) $params['trade_no']) === '') {
            abort(400, 'Alipay payment amount is invalid');
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
