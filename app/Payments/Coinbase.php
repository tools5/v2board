<?php

namespace App\Payments;

use App\Payments\Support\PaymentAmountSupport;

class Coinbase {
    use PaymentAmountSupport;

    public static function isAvailable(): bool
    {
        return function_exists('curl_init');
    }

    public function __construct($config) {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'coinbase_url' => [
                'label' => '接口地址',
                'description' => '',
                'type' => 'input',
            ],
            'coinbase_api_key' => [
                'label' => 'API KEY',
                'description' => '',
                'type' => 'input',
            ],
            'coinbase_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'description' => '',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order) {

        $params = [
            'name' => '订阅套餐',
            'description' => '订单号 ' . $order['trade_no'],
            'pricing_type' => 'fixed_price',
            'local_price' => [
                'amount' => sprintf('%.2f', $order['total_amount'] / 100),
                'currency' => 'CNY'
            ],
            'metadata' => [
                "outTradeNo" => $order['trade_no'],
            ],
        ];

        $params_string = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($params_string === false) {
            abort(500, 'Unable to encode Coinbase request');
        }
        
        $ret_raw = $this->_curlPost($this->config['coinbase_url'], $params_string);

        $ret = @json_decode($ret_raw, true);
        
        if (empty($ret['data']['hosted_url'])) {
            abort(500, 'Coinbase returned an invalid response');
        }
        return [
            'type' => 1,
            'data' => $ret['data']['hosted_url'],
        ];
    }

    public function notify($params) {
        $payload = request()->getContent();
        if (!is_string($payload) || $payload === '') {
            abort(400, 'Coinbase callback payload is missing');
        }
        $json_param = json_decode($payload, true);

        $signatureHeader = $this->callbackScalarString(
            request()->header('X-Cc-Webhook-Signature', '')
        );
        $webhookKey = $this->config['coinbase_webhook_key'] ?? null;
        if ($signatureHeader === null || $signatureHeader === ''
            || !is_string($webhookKey) || $webhookKey === '') {
            abort(400, 'HMAC signature is missing');
        }
        $computedSignature = \hash_hmac('sha256', $payload, $webhookKey);

        if (!self::hashEqual($signatureHeader, $computedSignature)) {
            abort(400, 'HMAC signature does not match');
        }
        
        $event = is_array($json_param) ? ($json_param['event'] ?? null) : null;
        if (!is_array($event) || !$this->hasScalarCallbackFields($event, ['type'])) {
            abort(400, 'Coinbase event is invalid');
        }

        $eventType = $this->callbackScalarString($event['type']);
        if ($eventType === null || $eventType === '') {
            abort(400, 'Coinbase event is invalid');
        }
        if (!in_array($eventType, ['charge:confirmed', 'charge:resolved'], true)) {
            return [
                'acknowledge_only' => true,
                'custom_result' => 'success'
            ];
        }

        $data = $event['data'] ?? null;
        if (!is_array($data)) {
            abort(400, 'Coinbase charge data is invalid');
        }

        $timeline = $data['timeline'] ?? null;
        $lastTimeline = is_array($timeline) && !empty($timeline) ? end($timeline) : null;
        if (!is_array($lastTimeline) || !$this->hasScalarCallbackFields($lastTimeline, ['status'])) {
            abort(400, 'Coinbase charge timeline is invalid');
        }
        $finalStatus = strtoupper((string) $this->callbackScalarString($lastTimeline['status']));
        if (!in_array($finalStatus, ['COMPLETED', 'RESOLVED'], true)) {
            abort(400, 'Coinbase charge is not in a final paid state');
        }

        $pricing = null;
        if (array_key_exists('pricing', $data)) {
            if (!is_array($data['pricing'])) {
                abort(400, 'Coinbase payment amount is invalid');
            }
            $pricing = $data['pricing']['local'] ?? null;
        }
        if ($pricing === null && array_key_exists('local_price', $data)) {
            $pricing = $data['local_price'];
        }
        if (!is_array($pricing)
            || !$this->hasScalarCallbackFields($pricing, ['amount', 'currency'])) {
            abort(400, 'Coinbase payment amount is missing');
        }
        $amount = $this->decimalToCents($pricing['amount']);
        if ($amount === null) {
            abort(400, 'Coinbase payment amount is invalid');
        }

        $metadata = $data['metadata'] ?? null;
        if (!is_array($metadata)
            || !$this->hasScalarCallbackFields($metadata, ['outTradeNo'])
            || !$this->hasScalarCallbackFields($data, ['id'])) {
            abort(400, 'Coinbase order metadata is missing');
        }
        $out_trade_no = $this->callbackScalarString($metadata['outTradeNo']);
        $pay_trade_no = $this->callbackScalarString($data['id']);
        $currency = $this->callbackScalarString($pricing['currency']);
        if ($out_trade_no === null || $out_trade_no === ''
            || $pay_trade_no === null || $pay_trade_no === ''
            || $currency === null || $currency === '') {
            abort(400, 'Coinbase order metadata is missing');
        }
        if ($amount <= 0) {
            abort(400, 'Coinbase payment amount is invalid');
        }

        return [
            'trade_no' => $out_trade_no,
            'callback_no' => $pay_trade_no,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'expected_currency' => 'CNY'
        ];
    }


    private function _curlPost($url,$params=false){
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'X-CC-Api-Key: ' . $this->config['coinbase_api_key'],
                'X-CC-Version: 2018-03-22',
                'Content-Type: application/json'
            ]
        );
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($result === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException($error !== '' ? $error : 'Coinbase API request failed');
        }
        return $result;
    }

    /**
     * @param string $str1
     * @param string $str2
     * @return bool
     */
    public function hashEqual($str1, $str2)
    {
        if (function_exists('hash_equals')) {
            return \hash_equals($str1, $str2);
        }

        if (strlen($str1) != strlen($str2)) {
            return false;
        } else {
            $res = $str1 ^ $str2;
            $ret = 0;

            for ($i = strlen($res) - 1; $i >= 0; $i--) {
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
    }
    
}
