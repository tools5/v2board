<?php

namespace App\Payments;

use App\Payments\Support\StripeSupport;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class StripeCheckout
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
                'description' => 'API 密钥',
                'type' => 'input',
            ],
            'stripe_pk_live' => [
                'label' => 'PK_LIVE',
                'description' => 'API 公钥',
                'type' => 'input',
            ],
            'stripe_webhook_key' => [
                'label' => 'WebHook 密钥签名',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_custom_field_name' => [
                'label' => '自定义字段名称',
                'description' => '例如可设置为“联系方式”，以便及时与客户取得联系',
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
        $metadata = $this->stripeMetadata($order, $amount, $currency, 'stripe_checkout');
        $customFieldName = trim((string) ($this->config['stripe_custom_field_name'] ?? ''));
        if ($customFieldName === '') {
            $customFieldName = 'Contact Information';
        }

        $params = [
            'success_url' => $order['return_url'],
            'cancel_url' => $order['return_url'],
            'client_reference_id' => (string) $order['trade_no'],
            'metadata' => $metadata,
            'payment_intent_data' => [
                'metadata' => $metadata
            ],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product_data' => [
                            'name' => (string) $order['trade_no']
                        ],
                        'unit_amount' => $amount
                    ],
                    'quantity' => 1
                ]
            ],
            'mode' => 'payment',
            'invoice_creation' => ['enabled' => true],
            'phone_number_collection' => ['enabled' => true],
            'custom_fields' => [
                [
                    'key' => 'contactinfo',
                    'label' => ['type' => 'custom', 'custom' => $customFieldName],
                    'type' => 'text',
                ],
            ]
        ];

        Stripe::setApiKey($this->stripeSecretKey());
        try {
            $session = Session::create(
                $params,
                $this->stripeIdempotencyOptions('checkout-session', $order['trade_no'])
            );
        } catch (\Throwable $e) {
            report($e);
            abort(500, __('Failed to create order'));
        }

        if (!$session->url) {
            abort(500, __('Payment gateway request failed'));
        }

        return [
            'type' => 1,
            'data' => $session->url
        ];
    }

    public function notify($params)
    {
        $event = $this->stripeWebhookEvent();
        if (!in_array($event->type, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded'
        ], true)) {
            return $this->stripeAcknowledgeOnly();
        }

        $session = $event->data->object;
        if ($this->stripeValue($session, 'payment_status') !== 'paid') {
            return $this->stripeAcknowledgeOnly();
        }
        $metadata = $this->stripeMetadataFrom($session);
        if (!$this->stripeMetadataMatchesGateway($metadata, 'stripe_checkout')) {
            return $this->stripeAcknowledgeOnly();
        }

        return $this->stripeCallbackResult(
            $metadata,
            $this->stripeValue($session, 'client_reference_id'),
            $this->stripeValue($session, 'payment_intent'),
            $this->stripeValue($session, 'amount_total'),
            $this->stripeValue($session, 'currency')
        );
    }
}
