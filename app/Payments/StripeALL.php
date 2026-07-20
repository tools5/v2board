<?php

namespace App\Payments;

use App\Models\User;
use App\Payments\Support\StripeSupport;
use Stripe\StripeClient;

class StripeALL
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
                'description' => '请使用符合ISO 4217标准的三位字母，例如GBP',
                'type' => 'input',
            ],
            'stripe_sk_live' => [
                'label' => 'SK_LIVE',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_webhook_key' => [
                'label' => 'WebHook密钥签名',
                'description' => 'whsec_....',
                'type' => 'input',
            ],
            'payment_method' => [
                'label' => '支付方式',
                'description' => '请输入alipay, wechat_pay, cards',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $paymentMethod = strtolower(trim((string) ($this->config['payment_method'] ?? '')));
        if ($paymentMethod === 'card') {
            $paymentMethod = 'cards';
        }
        if (!in_array($paymentMethod, ['alipay', 'wechat_pay', 'cards'], true)) {
            abort(500, __('Payment gateway configuration is invalid'));
        }

        $currency = $this->stripeCurrency();
        $amount = $this->stripeAmount(
            $order,
            $this->stripeExchangeRate('CNY', $currency),
            $currency
        );
        $metadata = $this->stripeMetadata($order, $amount, $currency, 'stripe_all');
        $stripe = new StripeClient($this->stripeSecretKey());
        $userEmail = $this->getUserEmail($order['user_id']);

        try {
            if ($paymentMethod === 'cards') {
                $params = [
                    'success_url' => $order['return_url'],
                    'cancel_url' => $order['return_url'],
                    'client_reference_id' => (string) $order['trade_no'],
                    'payment_method_types' => ['card'],
                    'metadata' => $metadata,
                    'payment_intent_data' => [
                        'metadata' => $metadata
                    ],
                    'line_items' => [
                        [
                            'price_data' => [
                                'currency' => strtolower($currency),
                                'unit_amount' => $amount,
                                'product_data' => [
                                    'name' => 'user-#' . $order['user_id'] . '-' . substr($order['trade_no'], -8),
                                ]
                            ],
                            'quantity' => 1,
                        ],
                    ],
                    'mode' => 'payment',
                    'invoice_creation' => ['enabled' => true],
                    'phone_number_collection' => ['enabled' => false]
                ];
                if ($userEmail !== null) {
                    $params['customer_email'] = $userEmail;
                }

                $session = $stripe->checkout->sessions->create(
                    $params,
                    $this->stripeIdempotencyOptions('all-card-session', $order['trade_no'])
                );
                if (!$session->url) {
                    abort(500, __('Payment gateway request failed'));
                }

                return [
                    'type' => 1,
                    'data' => $session->url
                ];
            }

            $stripePaymentMethod = $stripe->paymentMethods->create(
                ['type' => $paymentMethod],
                $this->stripeIdempotencyOptions('all-' . $paymentMethod . '-method', $order['trade_no'])
            );
            $params = [
                'amount' => $amount,
                'currency' => strtolower($currency),
                'confirm' => true,
                'payment_method' => $stripePaymentMethod->id,
                'payment_method_types' => [$paymentMethod],
                'metadata' => $metadata,
                'return_url' => $order['return_url']
            ];
            if ($userEmail !== null) {
                $params['receipt_email'] = $userEmail;
            }
            if ($paymentMethod === 'wechat_pay') {
                $params['payment_method_options'] = [
                    'wechat_pay' => [
                        'client' => 'web'
                    ]
                ];
            }

            $intent = $stripe->paymentIntents->create(
                $params,
                $this->stripeIdempotencyOptions('all-' . $paymentMethod . '-intent', $order['trade_no'])
            );
            if ($intent->status === 'succeeded') {
                return [
                    'type' => 2,
                    'data' => true
                ];
            }

            $nextAction = $this->stripeValue($intent, 'next_action');
            if ($paymentMethod === 'alipay') {
                $redirect = $this->stripeValue($nextAction, 'alipay_handle_redirect');
                $url = $this->stripeValue($redirect, 'url');
                if (!$url) {
                    abort(500, __('Payment gateway request failed'));
                }
                return ['type' => 1, 'data' => $url];
            }

            $displayQrCode = $this->stripeValue($nextAction, 'wechat_pay_display_qr_code');
            $qrCode = $this->stripeValue($displayQrCode, 'data');
            if (!$qrCode) {
                abort(500, __('Payment gateway request failed'));
            }
            return ['type' => 0, 'data' => $qrCode];
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            abort(500, __('Payment gateway request failed'));
        }
    }

    public function notify($params)
    {
        $event = $this->stripeWebhookEvent();
        if ($event->type === 'payment_intent.succeeded') {
            $intent = $event->data->object;
            if ($this->stripeValue($intent, 'status') !== 'succeeded') {
                return $this->stripeAcknowledgeOnly();
            }
            $metadata = $this->stripeMetadataFrom($intent);
            if (!$this->stripeMetadataMatchesGateway($metadata, 'stripe_all')) {
                return $this->stripeAcknowledgeOnly();
            }

            return $this->stripeCallbackResult(
                $metadata,
                '',
                $this->stripeValue($intent, 'id'),
                $this->stripeValue($intent, 'amount_received'),
                $this->stripeValue($intent, 'currency')
            );
        }

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
        if (!$this->stripeMetadataMatchesGateway($metadata, 'stripe_all')) {
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

    private function getUserEmail($userId)
    {
        $user = User::find($userId);
        if (!$user || !filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $user->email;
    }
}
