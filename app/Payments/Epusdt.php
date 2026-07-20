<?php

namespace App\Payments;

use App\Payments\Support\PaymentAmountSupport;
use \Curl\Curl;

class Epusdt
{
    use PaymentAmountSupport;

    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'epusdt_url' => [
                'label' => 'API 地址',
                'description' => 'Epusdt API 接口地址(例如: https://xxx.com)',
                'type' => 'input',
            ],
            'epusdt_pid' => [
                'label' => 'PID',
                'description' => 'Epusdt 后台的 pid',
                'type' => 'input',
            ],
            'epusdt_token' => [
                'label' => 'Token',
                'description' => 'Epusdt 后台的 secret_key',
                'type' => 'input',
            ],
            'epusdt_currency' => [
                'label' => '法币',
                'description' => '默认 cny',
                'type' => 'input',
            ],
            'epusdt_asset' => [
                'label' => '代币',
                'description' => '默认 usdt',
                'type' => 'input',
            ],
            'epusdt_network' => [
                'label' => '网络',
                'description' => '留空时进入 GMPay 选择链路界面，填写时按该网络直接发起订单',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        $network = strtolower(trim((string) ($this->config['epusdt_network'] ?? '')));
        $token = empty($this->config['epusdt_asset']) ? 'usdt' : strtolower(trim((string) $this->config['epusdt_asset']));
        $params = [
            'pid' => trim((string) ($this->config['epusdt_pid'] ?? '')),
            'order_id' => (string) $order['trade_no'],
            'currency' => empty($this->config['epusdt_currency']) ? 'cny' : strtolower(trim((string) $this->config['epusdt_currency'])),
            'token' => $token,
            'network' => $network === '' ? 'tron' : $network,
            'amount' => round($order['total_amount'] / 100, 2),
            'notify_url' => $order['notify_url'],
            'redirect_url' => $order['return_url'],
        ];

        $params['signature'] = $this->makeSignature($params, trim((string) ($this->config['epusdt_token'] ?? '')));

        $curl = new Curl();
        $curl->setUserAgent('epusdt');
        $curl->setConnectTimeout(10);
        $curl->setTimeout(30);
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOpt(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->setOpt(CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $curl->post(
            rtrim((string) $this->config['epusdt_url'], '/') . '/payments/gmpay/v1/order/create-transaction',
            json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $result = $curl->response;
        $requestFailed = $curl->error;
        $errorMessage = $curl->errorMessage;
        $curl->close();

        if ($requestFailed || !is_object($result) || !isset($result->status_code) || (int) $result->status_code !== 200) {
            report(new \RuntimeException('epusdt create order failed: ' . ($errorMessage ?: 'invalid response')));
            abort(500, __('Payment gateway request failed'));
        }

        $paymentUrl = $result->data->payment_url ?? null;

        if ($network !== '') {
            if (!isset($result->data->trade_id) || $result->data->trade_id === '') {
                abort(500, 'epusdt create order response missing trade_id');
            }

            $switchParams = [
                'trade_id' => (string) $result->data->trade_id,
                'token' => $token,
                'network' => $network,
            ];

            $curl = new Curl();
            $curl->setUserAgent('epusdt');
            $curl->setConnectTimeout(10);
            $curl->setTimeout(30);
            $curl->setOpt(CURLOPT_SSL_VERIFYPEER, true);
            $curl->setOpt(CURLOPT_SSL_VERIFYHOST, 2);
            $curl->setOpt(CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $curl->post(
                rtrim((string) $this->config['epusdt_url'], '/') . '/pay/switch-network',
                json_encode($switchParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $switchResult = $curl->response;
            $requestFailed = $curl->error;
            $errorMessage = $curl->errorMessage;
            $curl->close();

            if ($requestFailed || !is_object($switchResult) || !isset($switchResult->status_code) || (int) $switchResult->status_code !== 200) {
                report(new \RuntimeException('epusdt switch network failed: ' . ($errorMessage ?: 'invalid response')));
                abort(500, __('Payment gateway request failed'));
            }

            $paymentUrl = $switchResult->data->payment_url ?? $paymentUrl;
        }

        if (empty($paymentUrl)) {
            abort(500, 'epusdt payment url missing');
        }

        return [
            'type' => 1,
            'data' => $paymentUrl,
        ];
    }

    public function notify($params)
    {
        $requiredFields = ['signature', 'status', 'order_id', 'trade_id', 'amount', 'currency'];
        if (!$this->hasScalarCallbackFields($params, $requiredFields)
            || !$this->hasOnlyScalarCallbackValues($params)) {
            abort(400, 'Required epusdt callback fields are missing');
        }
        $token = $this->config['epusdt_token'] ?? null;
        if (!is_string($token) || trim($token) === '') {
            abort(400, 'epusdt callback configuration is incomplete');
        }

        $signature = strtolower((string) $params['signature']);
        unset($params['signature']);

        if (!hash_equals($this->makeSignature($params, trim($token)), $signature)) {
            abort(400, 'epusdt callback signature is invalid');
        }

        if (trim((string) $params['status']) !== '2') {
            return [
                'acknowledge_only' => true,
                'custom_result' => 'failed'
            ];
        }

        $expectedCurrencyConfig = $this->config['epusdt_currency'] ?? 'CNY';
        if (!is_string($expectedCurrencyConfig)) {
            abort(400, 'epusdt callback configuration is incomplete');
        }

        $amount = $this->decimalToCents($params['amount']);
        $currency = strtoupper(trim((string) $params['currency']));
        $expectedCurrency = strtoupper(trim($expectedCurrencyConfig));
        if ($amount === null || $amount <= 0 || $currency === '' || $expectedCurrency === ''
            || trim((string) $params['order_id']) === '' || trim((string) $params['trade_id']) === '') {
            abort(400, 'epusdt callback payment details are invalid');
        }

        return [
            'trade_no' => (string) $params['order_id'],
            'callback_no' => (string) $params['trade_id'],
            'amount' => $amount,
            'currency' => $currency,
            'expected_currency' => $expectedCurrency,
            'custom_result' => 'ok',
        ];
    }

    private function makeSignature($params, $token)
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($key === 'signature' || $value === '' || $value === null) {
                continue;
            }

            if (is_float($value) || is_int($value)) {
                $value = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
                $value = $value === '' ? '0' : $value;
            }

            $pairs[] = $key . '=' . (string) $value;
        }

        return strtolower(md5(implode('&', $pairs) . $token));
    }
}
