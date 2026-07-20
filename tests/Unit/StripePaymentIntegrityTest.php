<?php

namespace Tests\Unit;

use App\Payments\StripeCheckout;
use App\Payments\Support\StripeSupport;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StripePaymentIntegrityTest extends TestCase
{
    public function testMinorUnitConversionSupportsZeroAndThreeDecimalCurrencies(): void
    {
        $gateway = new TestableStripeGateway();
        $order = ['total_amount' => 1234];

        $this->assertSame(1234, $gateway->amount($order, 1, 'CNY'));
        $this->assertSame(247, $gateway->amount($order, 20, 'JPY'));
        $this->assertSame(617, $gateway->amount($order, 0.05, 'KWD'));
    }

    public function testValidStripeCallbackReturnsTheInternalOrderAmount(): void
    {
        $gateway = new TestableStripeGateway();
        $result = $gateway->callback([
            'out_trade_no' => 'ORDER-1',
            'order_amount' => '1234',
            'gateway_amount' => '987',
            'gateway_currency' => 'USD'
        ], 'ORDER-1', 'pi_123', 987, 'usd');

        $this->assertSame('ORDER-1', $result['trade_no']);
        $this->assertSame('pi_123', $result['callback_no']);
        $this->assertSame(1234, $result['amount']);
        $this->assertSame('USD', $result['currency']);
    }

    public function testStripeCallbackRejectsGatewayAmountMismatch(): void
    {
        $gateway = new TestableStripeGateway();

        try {
            $gateway->callback([
                'out_trade_no' => 'ORDER-2',
                'order_amount' => '1234',
                'gateway_amount' => '987',
                'gateway_currency' => 'USD'
            ], 'ORDER-2', 'pi_456', 986, 'usd');
            $this->fail('Amount mismatch was not rejected');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function testStripeCallbackRejectsGatewayCurrencyMismatch(): void
    {
        $gateway = new TestableStripeGateway();

        try {
            $gateway->callback([
                'out_trade_no' => 'ORDER-3',
                'order_amount' => '1234',
                'gateway_amount' => '987',
                'gateway_currency' => 'USD'
            ], 'ORDER-3', 'pi_789', 987, 'eur');
            $this->fail('Currency mismatch was not rejected');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function testStripeCallbackRejectsMalformedScalarMetadataAndOverflow(): void
    {
        $gateway = new TestableStripeGateway();
        $metadata = [
            'out_trade_no' => 'ORDER-4',
            'order_amount' => '1234',
            'gateway_amount' => '987',
            'gateway_currency' => 'USD'
        ];

        $cases = [
            [array_merge($metadata, ['out_trade_no' => ['ORDER-4']]), 'ORDER-4', 'pi_1', 987, 'USD'],
            [array_merge($metadata, ['order_amount' => ['1234']]), 'ORDER-4', 'pi_2', 987, 'USD'],
            [array_merge($metadata, ['gateway_currency' => ['USD']]), 'ORDER-4', 'pi_3', 987, 'USD'],
            [$metadata, ['ORDER-4'], 'pi_4', 987, 'USD'],
            [$metadata, 'ORDER-4', ['pi_5'], 987, 'USD'],
            [$metadata, 'ORDER-4', 'pi_6', 987, ['USD']],
            [$metadata, 'ORDER-4', 'pi_7', (string) PHP_INT_MAX . '0', 'USD'],
            [array_merge($metadata, ['order_amount' => (string) PHP_INT_MAX . '0']), 'ORDER-4', 'pi_8', 987, 'USD']
        ];

        foreach ($cases as $case) {
            $this->assertHttp400(function () use ($gateway, $case) {
                $gateway->callback(...$case);
            });
        }

        $this->assertFalse($gateway->gatewayMatches(['gateway' => ['stripe_checkout']], 'stripe_checkout'));
    }

    public function testSignedNonTargetStripeEventIsAcknowledgedWithoutAnOrder(): void
    {
        $secret = 'whsec_test_secret';
        $payload = json_encode([
            'id' => 'evt_non_target',
            'object' => 'event',
            'api_version' => null,
            'created' => time(),
            'data' => ['object' => ['id' => 'cus_test']],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            'type' => 'customer.created'
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't=' . $timestamp . ',v1=' . $signature
        ], $payload);
        $originalRequest = $this->app->make('request');
        $this->app->instance('request', $request);

        try {
            $gateway = new StripeCheckout(['stripe_webhook_key' => $secret]);
            $result = $gateway->notify([]);
        } finally {
            $this->app->instance('request', $originalRequest);
        }

        $this->assertTrue($result['acknowledge_only']);
        $this->assertSame('success', $result['custom_result']);
    }

    private function assertHttp400(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected Stripe callback to be rejected');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }
}

class TestableStripeGateway
{
    use StripeSupport;

    protected $config = [];

    public function amount(array $order, $exchangeRate, $currency)
    {
        return $this->stripeAmount($order, $exchangeRate, $currency);
    }

    public function callback($metadata, $fallbackTradeNo, $callbackNo, $actualAmount, $actualCurrency)
    {
        return $this->stripeCallbackResult(
            $metadata,
            $fallbackTradeNo,
            $callbackNo,
            $actualAmount,
            $actualCurrency
        );
    }

    public function gatewayMatches($metadata, $gateway)
    {
        return $this->stripeMetadataMatchesGateway($metadata, $gateway);
    }
}
