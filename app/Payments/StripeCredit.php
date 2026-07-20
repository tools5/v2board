<?php

namespace App\Payments;

use App\Payments\Support\StripeSupport;
use Stripe\Charge;
use Stripe\Stripe;

class StripeCredit
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
            'stripe_pk_live' => [
                'label' => 'PK_LIVE',
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
        $token = trim((string) ($order['stripe_token'] ?? ''));
        if ($token === '') {
            abort(500, __('Payment token is missing'));
        }

        Stripe::setApiKey($this->stripeSecretKey());
        try {
            $charge = Charge::create([
                'amount' => $amount,
                'currency' => strtolower($currency),
                'source' => $token,
                'metadata' => $this->stripeMetadata($order, $amount, $currency, 'stripe_credit')
            ], $this->stripeIdempotencyOptions('credit-charge', $order['trade_no']));
        } catch (\Throwable $e) {
            report($e);
            abort(500, __('Payment failed. Please check your credit card information'));
        }

        if (!$charge->paid || $charge->status !== 'succeeded') {
            abort(500, __('Payment failed. Please check your credit card information'));
        }

        return [
            'type' => 2,
            'data' => true
        ];
    }

    public function notify($params)
    {
        $event = $this->stripeWebhookEvent();
        if ($event->type !== 'charge.succeeded') {
            return $this->stripeAcknowledgeOnly();
        }

        $charge = $event->data->object;
        if ($this->stripeValue($charge, 'status') !== 'succeeded') {
            return $this->stripeAcknowledgeOnly();
        }

        $metadata = $this->stripeChargeMetadata($charge);
        if (!$this->stripeMetadataMatchesGateway($metadata, 'stripe_credit')) {
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
