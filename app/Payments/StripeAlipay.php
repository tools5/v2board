<?php

namespace App\Payments;

use App\Payments\Support\StripeSupport;
use Stripe\Charge;
use Stripe\Source;
use Stripe\Stripe;

class StripeAlipay
{
    use StripeSupport;

    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'currency' => [
                'label' => '货币单位',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_sk_live' => [
                'label' => 'SK_LIVE',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_webhook_key' => [
                'label' => 'WebHook密钥签名',
                'description' => '',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $currency = $this->stripeCurrency();
        $amount = $this->stripeAmount(
            $order,
            $this->stripeExchangeRate('CNY', $currency),
            $currency
        );
        Stripe::setApiKey($this->stripeSecretKey());

        try {
            $source = Source::create([
                'amount' => $amount,
                'currency' => strtolower($currency),
                'type' => 'alipay',
                'statement_descriptor' => (string) $order['trade_no'],
                'metadata' => $this->stripeMetadata($order, $amount, $currency, 'stripe_alipay'),
                'redirect' => [
                    'return_url' => $order['return_url']
                ]
            ], $this->stripeIdempotencyOptions('alipay-source', $order['trade_no']));
        } catch (\Throwable $e) {
            report($e);
            abort(500, __('Payment gateway request failed'));
        }

        $redirectUrl = $this->stripeValue($this->stripeValue($source, 'redirect'), 'url');
        if (!$redirectUrl) {
            abort(500, __('Payment gateway request failed'));
        }

        return [
            'type' => 1,
            'data' => $redirectUrl
        ];
    }

    public function notify($params)
    {
        Stripe::setApiKey($this->stripeSecretKey());
        $event = $this->stripeWebhookEvent();

        if ($event->type === 'source.chargeable') {
            $source = $event->data->object;
            if ($this->stripeValue($source, 'type') !== 'alipay') {
                return $this->stripeAcknowledgeOnly();
            }

            $metadata = $this->stripeMetadataFrom($source);
            if (!$this->stripeMetadataMatchesGateway($metadata, 'stripe_alipay')) {
                return $this->stripeAcknowledgeOnly();
            }
            $validation = $this->stripeCallbackResult(
                $metadata,
                '',
                $this->stripeValue($source, 'id'),
                $this->stripeValue($source, 'amount'),
                $this->stripeValue($source, 'currency')
            );
            if (!empty($validation['acknowledge_only'])) {
                return $validation;
            }

            Charge::create([
                'amount' => (int) $this->stripeValue($source, 'amount'),
                'currency' => strtolower((string) $this->stripeValue($source, 'currency')),
                'source' => (string) $this->stripeValue($source, 'id'),
                'metadata' => $this->stripeObjectToArray($metadata)
            ], $this->stripeIdempotencyOptions('alipay-charge', $this->stripeValue($source, 'id')));

            return $this->stripeAcknowledgeOnly();
        }

        if ($event->type !== 'charge.succeeded') {
            return $this->stripeAcknowledgeOnly();
        }

        $charge = $event->data->object;
        if ($this->stripeValue($charge, 'status') !== 'succeeded') {
            return $this->stripeAcknowledgeOnly();
        }
        $metadata = $this->stripeChargeMetadata($charge);
        if (!$this->stripeMetadataMatchesGateway($metadata, 'stripe_alipay')) {
            return $this->stripeAcknowledgeOnly();
        }

        return $this->stripeCallbackResult(
            $metadata,
            '',
            $this->stripeValue($charge, 'id'),
            $this->stripeValue($charge, 'amount'),
            $this->stripeValue($charge, 'currency')
        );
    }
}
