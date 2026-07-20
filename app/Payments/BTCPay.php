<?php

namespace App\Payments;

use App\Payments\Support\PaymentAmountSupport;

class BTCPay {
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
            'btcpay_url' => [
                'label' => 'API接口所在网址(包含最后的斜杠)',
                'description' => '',
                'type' => 'input',
            ],
            'btcpay_storeId' => [
                'label' => 'storeId',
                'description' => '',
                'type' => 'input',
            ],
            'btcpay_api_key' => [
                'label' => 'API KEY',
                'description' => '个人设置中的API KEY(非商店设置中的)',
                'type' => 'input',
            ],
            'btcpay_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'description' => '',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order) {
        $storeId = $this->callbackScalarString($this->config['btcpay_storeId'] ?? null);
        if ($storeId === null || $storeId === '') {
            abort(500, __('Payment gateway configuration is incomplete'));
        }

        $params = [
            'jsonResponse' => true,
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => 'CNY',
            'metadata' => [
                'orderId' => $order['trade_no']
            ]
        ];

        $params_string = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($params_string === false) {
            abort(500, __('Payment gateway request failed'));
        }

        $ret_raw = $this->request(
            'POST',
            'api/v1/stores/' . rawurlencode($storeId) . '/invoices',
            $params_string
        );

        $ret = @json_decode($ret_raw, true);

        $checkoutLink = is_array($ret)
            ? $this->callbackScalarString($ret['checkoutLink'] ?? null)
            : null;
        if ($checkoutLink === null || $checkoutLink === '') {
            abort(500, 'BTCPay returned an invalid response');
        }
        return [
            'type' => 1, // Redirect to url
            'data' => $checkoutLink,
        ];
    }

    public function notify($params) {
        $payload = request()->getContent();
        if (!is_string($payload) || $payload === '') {
            abort(400, 'BTCPay callback payload is missing');
        }

        $signatureHeader = $this->callbackScalarString(request()->header('Btcpay-Sig', ''));
        $json_param = json_decode($payload, true);

        $webhookKey = $this->config['btcpay_webhook_key'] ?? null;
        if ($signatureHeader === null || $signatureHeader === ''
            || !is_string($webhookKey) || $webhookKey === '') {
            abort(400, 'HMAC signature is missing');
        }
        $computedSignature = 'sha256=' . \hash_hmac('sha256', $payload, $webhookKey);

        if (!self::hashEqual($signatureHeader, $computedSignature)) {
            abort(400, 'HMAC signature does not match');
        }

        if (!is_array($json_param)
            || !$this->hasScalarCallbackFields($json_param, ['type', 'invoiceId'])) {
            abort(400, 'BTCPay event is invalid');
        }
        $eventType = $this->callbackScalarString($json_param['type']);
        $invoiceId = $this->callbackScalarString($json_param['invoiceId']);
        if ($eventType === null || $eventType === '' || $invoiceId === null || $invoiceId === '') {
            abort(400, 'BTCPay event is invalid');
        }

        $storeId = $this->callbackScalarString($this->config['btcpay_storeId'] ?? null);
        if ($storeId === null || $storeId === '') {
            abort(500, 'BTCPay configuration is incomplete');
        }
        if (array_key_exists('storeId', $json_param)) {
            $eventStoreId = $this->callbackScalarString($json_param['storeId']);
            if ($eventStoreId === null || !hash_equals($storeId, $eventStoreId)) {
                abort(400, 'BTCPay store does not match');
            }
        }
        if ($eventType !== 'InvoiceSettled') {
            return [
                'acknowledge_only' => true,
                'custom_result' => 'success'
            ];
        }

        $invoiceRaw = $this->request(
            'GET',
            'api/v1/stores/' . rawurlencode($storeId) . '/invoices/' . rawurlencode($invoiceId)
        );
        $invoiceDetail = json_decode($invoiceRaw, true);
        if (!is_array($invoiceDetail)) {
            abort(400, 'BTCPay invoice is invalid');
        }
        $invoiceStatus = $this->callbackScalarString($invoiceDetail['status'] ?? null);
        if ($invoiceStatus !== 'Settled') {
            abort(400, 'BTCPay invoice is not settled');
        }
        if (array_key_exists('id', $invoiceDetail)) {
            $detailInvoiceId = $this->callbackScalarString($invoiceDetail['id']);
            if ($detailInvoiceId === null || !hash_equals($invoiceId, $detailInvoiceId)) {
                abort(400, 'BTCPay invoice ID does not match');
            }
        }

        $metadata = $invoiceDetail['metadata'] ?? null;
        if (!is_array($metadata)
            || !$this->hasScalarCallbackFields($metadata, ['orderId'])
            || !$this->hasScalarCallbackFields($invoiceDetail, ['currency'])) {
            abort(400, 'BTCPay invoice details are incomplete');
        }
        $out_trade_no = $this->callbackScalarString($metadata['orderId']);
        $amount = $this->decimalToCents($invoiceDetail['amount'] ?? null);
        $currency = $this->callbackScalarString($invoiceDetail['currency']);
        if ($out_trade_no === null || $out_trade_no === ''
            || $amount === null || $amount <= 0
            || $currency === null || $currency === '') {
            abort(400, 'BTCPay invoice details are incomplete');
        }

        return [
            'trade_no' => $out_trade_no,
            'callback_no' => $invoiceId,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'expected_currency' => 'CNY'
        ];
    }

    private function request(string $method, string $path, ?string $body = null): string
    {
        $baseUrl = $this->callbackScalarString($this->config['btcpay_url'] ?? null);
        $apiKey = $this->callbackScalarString($this->config['btcpay_api_key'] ?? null, false);
        if ($baseUrl === null || $baseUrl === '' || $apiKey === null || $apiKey === '') {
            abort(500, __('Payment gateway configuration is incomplete'));
        }

        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'Authorization: token ' . $apiKey,
                'Content-Type: application/json'
            ]
        );
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($result === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException($error !== '' ? $error : 'BTCPay API request failed');
        }
        return (string) $result;
    }

    /**
     * @param string $str1
     * @param string $str2
     * @return bool
     */
    private function hashEqual($str1, $str2)
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
