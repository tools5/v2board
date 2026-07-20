<?php

namespace App\Payments;

use App\Payments\Support\PaymentAmountSupport;
use Omnipay\Omnipay;
use Omnipay\WechatPay\Helper;

class WechatPayNative {
    use PaymentAmountSupport;

    public static function isAvailable(): bool
    {
        return class_exists(Omnipay::class) && class_exists(Helper::class);
    }

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'app_id' => [
                'label' => 'APPID',
                'description' => '绑定微信支付商户的APPID',
                'type' => 'input',
            ],
            'mch_id' => [
                'label' => '商户号',
                'description' => '微信支付商户号',
                'type' => 'input',
            ],
            'api_key' => [
                'label' => 'APIKEY(v1)',
                'description' => '',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $appId = $this->callbackScalarString($this->config['app_id'] ?? null);
        $merchantId = $this->callbackScalarString($this->config['mch_id'] ?? null);
        $apiKey = $this->callbackScalarString($this->config['api_key'] ?? null, false);
        if ($appId === null || $appId === '' || $merchantId === null || $merchantId === ''
            || $apiKey === null || $apiKey === '') {
            abort(500, __('Payment gateway configuration is incomplete'));
        }

        $gateway = Omnipay::create('WechatPay_Native');
        $gateway->setAppId($appId);
        $gateway->setMchId($merchantId);
        $gateway->setApiKey($apiKey);
        $gateway->setNotifyUrl($order['notify_url']);

        $params = [
            'body'              => $order['trade_no'],
            'out_trade_no'      => $order['trade_no'],
            'total_fee'         => $order['total_amount'],
            'spbill_create_ip'  => '0.0.0.0',
            'fee_type'          => 'CNY'
        ];

        $request  = $gateway->purchase($params);
        $response = $request->send();
        $response = $response->getData();
        if (!is_array($response) || !$this->hasScalarCallbackFields($response, ['return_code'])) {
            abort(500, __('Payment gateway request failed'));
        }
        $returnCode = $this->callbackScalarString($response['return_code']);
        if ($returnCode !== 'SUCCESS') {
            $returnMessage = $this->callbackScalarString($response['return_msg'] ?? null);
            abort(500, $returnMessage ?: __('Payment gateway request failed'));
        }
        $codeUrl = $this->callbackScalarString($response['code_url'] ?? null);
        if ($codeUrl === null || $codeUrl === '') {
            abort(500, __('Payment gateway request failed'));
        }
        return [
            'type' => 0,
            'data' => $codeUrl
        ];
    }

    public function notify($params)
    {
        $payload = request()->getContent();
        if (!is_string($payload) || trim($payload) === '') {
            abort(400, 'Wechat Pay callback payload is missing');
        }

        $appId = $this->callbackScalarString($this->config['app_id'] ?? null);
        $merchantId = $this->callbackScalarString($this->config['mch_id'] ?? null);
        $apiKey = $this->callbackScalarString($this->config['api_key'] ?? null, false);
        if ($appId === null || $appId === '' || $merchantId === null || $merchantId === ''
            || $apiKey === null || $apiKey === '') {
            abort(500, __('Payment gateway configuration is incomplete'));
        }

        $gateway = Omnipay::create('WechatPay');
        $gateway->setAppId($appId);
        $gateway->setMchId($merchantId);
        $gateway->setApiKey($apiKey);
        try {
            $data = Helper::xml2array($payload);
            $response = $gateway->completePurchase([
                'request_params' => $payload
            ])->send();
        } catch (\Throwable $e) {
            report($e);
            abort(400, 'Wechat Pay callback is invalid');
        }

        if (!$response->isPaid()) {
            return [
                'acknowledge_only' => true,
                'custom_result' => '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[NOT PAID]]></return_msg></xml>',
                'custom_content_type' => 'application/xml; charset=UTF-8'
            ];
        }

        $requiredFields = ['appid', 'mch_id', 'out_trade_no', 'transaction_id', 'total_fee'];
        if (!is_array($data) || !$this->hasScalarCallbackFields($data, $requiredFields)) {
            abort(400, 'Required Wechat Pay callback fields are missing');
        }
        $callbackAppId = $this->callbackScalarString($data['appid']);
        $callbackMerchantId = $this->callbackScalarString($data['mch_id']);
        if ($callbackAppId === null || $callbackMerchantId === null
            || !hash_equals($appId, $callbackAppId)
            || !hash_equals($merchantId, $callbackMerchantId)) {
            abort(400, 'Wechat Pay merchant does not match');
        }

        $amount = $this->integerAmount($data['total_fee']);
        $tradeNo = $this->callbackScalarString($data['out_trade_no']);
        $transactionId = $this->callbackScalarString($data['transaction_id']);
        $feeType = $this->callbackScalarString($data['fee_type'] ?? 'CNY');
        if ($amount === null || $amount <= 0 || $tradeNo === null || $tradeNo === ''
            || $transactionId === null || $transactionId === ''
            || $feeType === null || $feeType === '') {
            abort(400, 'Wechat Pay callback payment details are invalid');
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $transactionId,
            'amount' => $amount,
            'currency' => strtoupper($feeType),
            'expected_currency' => 'CNY',
            'custom_result' => '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>',
            'custom_content_type' => 'application/xml; charset=UTF-8'
        ];
    }
}
