<?php

namespace App\Payments\Support;

use GuzzleHttp\Client;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeObject;
use Stripe\Webhook;

trait StripeSupport
{
    protected function stripeCurrency()
    {
        $currency = $this->stripeScalarString($this->config['currency'] ?? null);
        $currency = $currency === null ? '' : strtoupper($currency);
        if (!preg_match('/\A[A-Z]{3}\z/', $currency)) {
            abort(500, __('Payment currency configuration is invalid'));
        }

        return $currency;
    }

    protected function stripeSecretKey()
    {
        $secretKey = $this->stripeScalarString($this->config['stripe_sk_live'] ?? null);
        if ($secretKey === null || $secretKey === '') {
            abort(500, __('Payment gateway configuration is incomplete'));
        }

        return $secretKey;
    }

    protected function stripeWebhookEvent()
    {
        $payload = request()->getContent();
        $signature = $this->stripeScalarString(request()->header('Stripe-Signature', ''));
        $webhookSecret = $this->stripeScalarString($this->config['stripe_webhook_key'] ?? null);
        if (!is_string($payload) || $payload === ''
            || $signature === null || $signature === ''
            || $webhookSecret === null || $webhookSecret === '') {
            abort(400, 'Invalid Stripe webhook request');
        }

        try {
            return Webhook::constructEvent($payload, $signature, $webhookSecret);
        } catch (SignatureVerificationException $e) {
            abort(400, 'Invalid Stripe webhook signature');
        } catch (\UnexpectedValueException $e) {
            abort(400, 'Invalid Stripe webhook payload');
        }
    }

    protected function stripeExchangeRate($from, $to)
    {
        $from = $this->stripeScalarString($from);
        $to = $this->stripeScalarString($to);
        if ($from === null || $to === null) {
            abort(500, __('Payment currency configuration is invalid'));
        }
        $from = strtoupper($from);
        $to = strtoupper($to);
        if (!preg_match('/\A[A-Z]{3}\z/', $from) || !preg_match('/\A[A-Z]{3}\z/', $to)) {
            abort(500, __('Payment currency configuration is invalid'));
        }
        if ($from === $to) {
            return 1.0;
        }

        $requests = [
            [
                'url' => 'https://api.exchangerate-api.com/v4/latest/' . rawurlencode($from),
                'rate_path' => ['rates', $to]
            ],
            [
                'url' => 'https://api.frankfurter.app/latest?from=' . rawurlencode($from) . '&to=' . rawurlencode($to),
                'rate_path' => ['rates', $to]
            ]
        ];

        $client = new Client([
            'connect_timeout' => 3,
            'timeout' => 5,
            'http_errors' => false
        ]);

        foreach ($requests as $request) {
            try {
                $response = $client->get($request['url'], [
                    'headers' => ['Accept' => 'application/json']
                ]);
                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    continue;
                }

                $data = json_decode((string) $response->getBody(), true);
                if (!is_array($data)) {
                    continue;
                }

                $rate = $data;
                foreach ($request['rate_path'] as $segment) {
                    if (!is_array($rate) || !array_key_exists($segment, $rate)) {
                        $rate = null;
                        break;
                    }
                    $rate = $rate[$segment];
                }

                if (is_numeric($rate)) {
                    $rate = (float) $rate;
                    if (is_finite($rate) && $rate > 0) {
                        return $rate;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        abort(500, __('Currency conversion has timed out, please try again later'));
    }

    protected function stripeAmount(array $order, $exchangeRate, $currency)
    {
        $orderAmount = $this->stripeUnsignedInteger($order['total_amount'] ?? null);
        if ($orderAmount === null || $orderAmount <= 0) {
            abort(500, __('Payment amount is invalid'));
        }
        if (!is_numeric($exchangeRate)) {
            abort(500, __('Currency conversion has timed out, please try again later'));
        }
        $exchangeRate = (float) $exchangeRate;
        if (!is_finite($exchangeRate) || $exchangeRate <= 0) {
            abort(500, __('Currency conversion has timed out, please try again later'));
        }

        $amount = ($orderAmount / 100)
            * $exchangeRate
            * pow(10, $this->stripeCurrencyExponent($currency));
        if (!is_finite($amount) || $amount > PHP_INT_MAX) {
            abort(500, __('Payment amount is invalid'));
        }
        $amount = (int) round($amount, 0, PHP_ROUND_HALF_UP);
        if ($amount <= 0) {
            abort(500, __('Payment amount is invalid'));
        }

        return $amount;
    }

    protected function stripeMetadata(array $order, $gatewayAmount, $currency, $gateway = null)
    {
        $metadata = [
            'out_trade_no' => (string) $order['trade_no'],
            'user_id' => (string) $order['user_id'],
            'order_amount' => (string) (int) $order['total_amount'],
            'gateway_amount' => (string) (int) $gatewayAmount,
            'gateway_currency' => strtoupper((string) $currency)
        ];
        if ($gateway !== null && trim((string) $gateway) !== '') {
            $metadata['gateway'] = (string) $gateway;
        }

        return $metadata;
    }

    protected function stripeIdempotencyOptions($scope, $tradeNo)
    {
        return [
            'idempotency_key' => 'v2board-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $scope)
                . '-' . hash('sha256', (string) $tradeNo)
        ];
    }

    protected function stripeMetadataFrom($object)
    {
        $metadata = $this->stripeValue($object, 'metadata');
        return $this->stripeObjectToArray($metadata);
    }

    protected function stripeChargeMetadata($charge)
    {
        $metadata = $this->stripeMetadataFrom($charge);
        if (!empty($metadata['out_trade_no'])) {
            return $metadata;
        }

        return $this->stripeMetadataFrom($this->stripeValue($charge, 'source'));
    }

    protected function stripeMetadataMatchesGateway($metadata, $gateway)
    {
        $metadata = $this->stripeObjectToArray($metadata);
        $metadataGateway = array_key_exists('gateway', $metadata)
            ? $this->stripeScalarString($metadata['gateway'])
            : '';
        $expectedGateway = $this->stripeScalarString($gateway);
        if ($metadataGateway === null || $expectedGateway === null || $expectedGateway === '') {
            return false;
        }

        return $metadataGateway === '' || hash_equals($expectedGateway, $metadataGateway);
    }

    protected function stripeCallbackResult($metadata, $fallbackTradeNo, $callbackNo, $actualAmount, $actualCurrency)
    {
        $metadata = $this->stripeObjectToArray($metadata);
        $metadataTradeNo = array_key_exists('out_trade_no', $metadata)
            ? $this->stripeScalarString($metadata['out_trade_no'])
            : '';
        $fallbackTradeNo = $this->stripeScalarString($fallbackTradeNo);
        if ($metadataTradeNo === null || $fallbackTradeNo === null) {
            abort(400, 'Stripe order metadata is invalid');
        }
        if ($metadataTradeNo !== '' && $fallbackTradeNo !== '' && !hash_equals($metadataTradeNo, $fallbackTradeNo)) {
            abort(400, 'Stripe order metadata does not match');
        }

        $tradeNo = $metadataTradeNo !== '' ? $metadataTradeNo : $fallbackTradeNo;
        if ($tradeNo === '') {
            return $this->stripeAcknowledgeOnly();
        }
        if (strlen($tradeNo) > 255) {
            abort(400, 'Stripe order metadata is invalid');
        }

        foreach (['order_amount', 'gateway_amount', 'gateway_currency'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $metadata)
                || $this->stripeScalarString($metadata[$requiredKey]) === '') {
                abort(400, 'Stripe payment metadata is incomplete');
            }
            if ($this->stripeScalarString($metadata[$requiredKey]) === null) {
                abort(400, 'Stripe payment metadata is invalid');
            }
        }

        $orderAmount = $this->stripeUnsignedInteger($metadata['order_amount']);
        $gatewayAmount = $this->stripeUnsignedInteger($metadata['gateway_amount']);
        $actualAmount = $this->stripeUnsignedInteger($actualAmount);
        if ($orderAmount === null || $orderAmount <= 0
            || $gatewayAmount === null || $gatewayAmount <= 0) {
            abort(400, 'Stripe payment metadata is invalid');
        }
        if ($actualAmount === null || $actualAmount <= 0) {
            abort(400, 'Stripe payment amount is invalid');
        }

        $actualCurrency = $this->stripeScalarString($actualCurrency);
        $expectedCurrency = $this->stripeScalarString($metadata['gateway_currency']);
        if ($actualCurrency === null || $actualCurrency === ''
            || $expectedCurrency === null || $expectedCurrency === '') {
            abort(400, 'Stripe payment currency does not match');
        }
        $actualCurrency = strtoupper($actualCurrency);
        $expectedCurrency = strtoupper($expectedCurrency);
        if (!hash_equals($expectedCurrency, $actualCurrency)) {
            abort(400, 'Stripe payment currency does not match');
        }
        if ($gatewayAmount !== $actualAmount) {
            abort(400, 'Stripe payment amount does not match');
        }

        $callbackNo = $this->stripeScalarString($callbackNo);
        if ($callbackNo === null || $callbackNo === '' || strlen($callbackNo) > 255) {
            abort(400, 'Stripe transaction number is missing');
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $callbackNo,
            'amount' => $orderAmount,
            'currency' => $actualCurrency,
            'expected_currency' => $expectedCurrency,
            'custom_result' => 'success'
        ];
    }

    protected function stripeAcknowledgeOnly()
    {
        return [
            'acknowledge_only' => true,
            'custom_result' => 'success'
        ];
    }

    protected function stripeValue($object, $key)
    {
        if (is_array($object)) {
            return array_key_exists($key, $object) ? $object[$key] : null;
        }
        if ($object instanceof \ArrayAccess && isset($object[$key])) {
            return $object[$key];
        }
        if (is_object($object) && isset($object->{$key})) {
            return $object->{$key};
        }

        return null;
    }

    protected function stripeObjectToArray($value)
    {
        if ($value instanceof StripeObject) {
            return $value->toArray();
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return get_object_vars($value);
        }

        return [];
    }

    private function stripeCurrencyExponent($currency)
    {
        $currency = $this->stripeScalarString($currency);
        if ($currency === null) {
            abort(500, __('Payment currency configuration is invalid'));
        }
        $currency = strtoupper($currency);
        $zeroDecimalCurrencies = [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
        ];
        $threeDecimalCurrencies = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

        if (in_array($currency, $zeroDecimalCurrencies, true)) {
            return 0;
        }
        if (in_array($currency, $threeDecimalCurrencies, true)) {
            return 3;
        }

        return 2;
    }

    private function stripeScalarString($value): ?string
    {
        if (!is_string($value) && !is_int($value)
            && !(is_float($value) && is_finite($value))) {
            return null;
        }

        return trim((string) $value);
    }

    private function stripeUnsignedInteger($value): ?int
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
