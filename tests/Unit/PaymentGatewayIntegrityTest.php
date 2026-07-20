<?php

namespace Tests\Unit;

use App\Payments\BTCPay;
use App\Payments\Coinbase;
use App\Payments\CoinPayments;
use App\Payments\EPay;
use App\Payments\Epusdt;
use App\Payments\Support\PaymentAmountSupport;
use Illuminate\Http\Request;
use Library\AlipayF2F;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class PaymentGatewayIntegrityTest extends TestCase
{
    public function testPaymentAmountParserUsesExactMinorUnitsAndRejectsUnsafeValues(): void
    {
        $parser = new TestablePaymentAmountParser();

        $this->assertSame(123, $parser->decimal('1.23'));
        $this->assertSame(123, $parser->decimal('1.2300'));
        $this->assertSame(100, $parser->decimal(1));
        $this->assertSame(123, $parser->decimal(1.23));
        $this->assertSame(123, $parser->integer('000123'));
        $this->assertNull($parser->decimal('1.231'));
        $this->assertNull($parser->decimal('1e2'));
        $this->assertNull($parser->decimal('-1.00'));
        $this->assertNull($parser->decimal([]));
        $this->assertNull($parser->decimal(true));
        $this->assertNull($parser->decimal(INF));
        $this->assertNull($parser->integer('1.0'));

        $whole = intdiv(PHP_INT_MAX, 100);
        $fraction = PHP_INT_MAX % 100;
        $this->assertSame(
            PHP_INT_MAX,
            $parser->decimal($whole . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT))
        );

        $overflow = $fraction < 99
            ? $whole . '.' . str_pad((string) ($fraction + 1), 2, '0', STR_PAD_LEFT)
            : ($whole + 1) . '.00';
        $this->assertNull($parser->decimal($overflow));
        $this->assertNull($parser->integer((string) PHP_INT_MAX . '0'));
    }

    public function testEPayCallbackRequiresAValidSignatureAndReturnsExactAmount(): void
    {
        $config = ['pid' => 'merchant-1', 'key' => 'secret-key'];
        $gateway = new EPay($config);
        $params = [
            'pid' => 'merchant-1',
            'out_trade_no' => 'ORDER-EPAY',
            'trade_no' => 'EPAY-TXN-1',
            'trade_status' => 'TRADE_SUCCESS',
            'money' => '12.3400'
        ];
        $params['sign'] = $this->ePaySignature($params, $config['key']);
        $params['sign_type'] = 'MD5';

        $result = $gateway->notify($params);

        $this->assertSame('ORDER-EPAY', $result['trade_no']);
        $this->assertSame('EPAY-TXN-1', $result['callback_no']);
        $this->assertSame(1234, $result['amount']);
        $this->assertSame('CNY', $result['currency']);

        $invalidAmount = $params;
        $invalidAmount['money'] = '12.341';
        unset($invalidAmount['sign']);
        $invalidAmount['sign'] = $this->ePaySignature($invalidAmount, $config['key']);
        $this->assertHttp400(function () use ($gateway, $invalidAmount) {
            $gateway->notify($invalidAmount);
        });

        $this->assertHttp400(function () use ($gateway) {
            $gateway->notify([]);
        });
    }

    public function testEpusdtCallbackRequiresSignedPaymentDetails(): void
    {
        $config = ['epusdt_token' => 'epusdt-secret', 'epusdt_currency' => 'cny'];
        $gateway = new Epusdt($config);
        $params = [
            'status' => '2',
            'order_id' => 'ORDER-EPUSDT',
            'trade_id' => 'EPUSDT-TXN-1',
            'amount' => '45.67',
            'currency' => 'cny'
        ];
        $params['signature'] = $this->epusdtSignature($params, $config['epusdt_token']);

        $result = $gateway->notify($params);

        $this->assertSame('ORDER-EPUSDT', $result['trade_no']);
        $this->assertSame('EPUSDT-TXN-1', $result['callback_no']);
        $this->assertSame(4567, $result['amount']);
        $this->assertSame('CNY', $result['currency']);

        $malformedConfig = new Epusdt([
            'epusdt_token' => $config['epusdt_token'],
            'epusdt_currency' => ['cny'],
        ]);
        $this->assertHttp400(function () use ($malformedConfig, $params) {
            $malformedConfig->notify($params);
        });

        $params['signature'] = str_repeat('0', 32);
        $this->assertHttp400(function () use ($gateway, $params) {
            $gateway->notify($params);
        });
    }

    public function testCoinbaseUsesTheExactRawBodyAndStableChargeId(): void
    {
        $secret = 'coinbase-secret';
        $gateway = new Coinbase(['coinbase_webhook_key' => $secret]);
        $event = [
            'event' => [
                'type' => 'charge:confirmed',
                'data' => [
                    'id' => 'charge-stable-id',
                    'metadata' => ['outTradeNo' => 'ORDER-COINBASE'],
                    'pricing' => ['local' => ['amount' => '18.75', 'currency' => 'CNY']],
                    'timeline' => [['status' => 'COMPLETED']]
                ]
            ]
        ];
        $payload = " \n" . json_encode($event, JSON_UNESCAPED_SLASHES) . "\n";
        $result = $this->notifyCoinbase($gateway, $payload, hash_hmac('sha256', $payload, $secret));

        $this->assertSame('ORDER-COINBASE', $result['trade_no']);
        $this->assertSame('charge-stable-id', $result['callback_no']);
        $this->assertSame(1875, $result['amount']);

        $event['event']['type'] = 'charge:resolved';
        $event['event']['data']['timeline'] = [['status' => 'RESOLVED']];
        $resolvedPayload = json_encode($event, JSON_UNESCAPED_SLASHES);
        $resolved = $this->notifyCoinbase(
            $gateway,
            $resolvedPayload,
            hash_hmac('sha256', $resolvedPayload, $secret)
        );
        $this->assertSame('charge-stable-id', $resolved['callback_no']);

        $this->assertHttp400(function () use ($gateway, $payload, $secret) {
            $this->notifyCoinbase($gateway, $payload, hash_hmac('sha256', trim($payload), $secret));
        });
    }

    public function testCoinPaymentsVerifiesTheExactRawBody(): void
    {
        $secret = 'coinpayments-secret';
        $gateway = new CoinPayments([
            'coinpayments_ipn_secret' => $secret,
            'coinpayments_merchant_id' => 'merchant-2',
            'coinpayments_currency' => 'CNY'
        ]);
        $params = [
            'merchant' => 'merchant-2',
            'status' => '100',
            'item_number' => 'ORDER+CP',
            'txn_id' => 'CP-TXN-1',
            'amount1' => '9.99',
            'currency1' => 'CNY'
        ];
        $payload = 'status=100&merchant=merchant-2&item_number=ORDER%2BCP&txn_id=CP-TXN-1&amount1=9.99&currency1=CNY';
        $result = $this->notifyCoinPayments(
            $gateway,
            $params,
            $payload,
            hash_hmac('sha512', $payload, $secret)
        );

        $this->assertSame('ORDER+CP', $result['trade_no']);
        $this->assertSame('CP-TXN-1', $result['callback_no']);
        $this->assertSame(999, $result['amount']);

        $this->assertHttp400(function () use ($gateway, $params, $payload, $secret) {
            $this->notifyCoinPayments(
                $gateway,
                $params,
                $payload,
                hash_hmac('sha512', http_build_query($params), $secret)
            );
        });
    }

    public function testCallbacksRejectMalformedArrayFieldsWithoutPhpWarnings(): void
    {
        $ePay = new EPay(['pid' => 'merchant-1', 'key' => 'secret-key']);
        $ePayParams = [
            'pid' => 'merchant-1',
            'out_trade_no' => 'ORDER-EPAY',
            'trade_no' => 'EPAY-TXN-1',
            'trade_status' => 'TRADE_SUCCESS',
            'money' => ['12.34']
        ];
        $ePayParams['sign'] = $this->ePaySignature($ePayParams, 'secret-key');
        $this->assertHttp400(function () use ($ePay, $ePayParams) {
            $ePay->notify($ePayParams);
        });

        $coinbaseSecret = 'coinbase-secret';
        $coinbase = new Coinbase(['coinbase_webhook_key' => $coinbaseSecret]);
        $coinbaseEvent = [
            'event' => [
                'type' => 'charge:confirmed',
                'data' => [
                    'id' => 'charge-id',
                    'metadata' => ['outTradeNo' => 'ORDER-COINBASE'],
                    'pricing' => ['local' => ['amount' => '1.00', 'currency' => ['CNY']]],
                    'timeline' => [['status' => 'COMPLETED']]
                ]
            ]
        ];
        $coinbasePayload = json_encode($coinbaseEvent, JSON_UNESCAPED_SLASHES);
        $this->assertHttp400(function () use ($coinbase, $coinbasePayload, $coinbaseSecret) {
            $this->notifyCoinbase(
                $coinbase,
                $coinbasePayload,
                hash_hmac('sha256', $coinbasePayload, $coinbaseSecret)
            );
        });

        $btcpaySecret = 'btcpay-secret';
        $btcpay = new BTCPay([
            'btcpay_webhook_key' => $btcpaySecret,
            'btcpay_storeId' => 'store-1'
        ]);
        $btcpayPayload = json_encode([
            'type' => 'InvoiceSettled',
            'invoiceId' => ['invoice-1'],
            'storeId' => 'store-1'
        ], JSON_UNESCAPED_SLASHES);
        $this->assertHttp400(function () use ($btcpay, $btcpayPayload, $btcpaySecret) {
            $this->notifyBTCPay(
                $btcpay,
                $btcpayPayload,
                'sha256=' . hash_hmac('sha256', $btcpayPayload, $btcpaySecret)
            );
        });

        $coinPaymentsSecret = 'coinpayments-secret';
        $coinPayments = new CoinPayments([
            'coinpayments_ipn_secret' => $coinPaymentsSecret,
            'coinpayments_merchant_id' => 'merchant-2',
            'coinpayments_currency' => 'CNY'
        ]);
        $coinPaymentsParams = [
            'merchant' => 'merchant-2',
            'status' => '100',
            'item_number' => 'ORDER-CP',
            'txn_id' => 'CP-TXN-1',
            'amount1' => ['9.99'],
            'currency1' => 'CNY'
        ];
        $coinPaymentsPayload = 'merchant=merchant-2&status=100&item_number=ORDER-CP&txn_id=CP-TXN-1'
            . '&amount1%5B0%5D=9.99&currency1=CNY';
        $this->assertHttp400(function () use (
            $coinPayments,
            $coinPaymentsParams,
            $coinPaymentsPayload,
            $coinPaymentsSecret
        ) {
            $this->notifyCoinPayments(
                $coinPayments,
                $coinPaymentsParams,
                $coinPaymentsPayload,
                hash_hmac('sha512', $coinPaymentsPayload, $coinPaymentsSecret)
            );
        });
    }

    public function testCallbacksFailClosedWhenConfiguredSecretsAreEmpty(): void
    {
        $ePayParams = [
            'pid' => 'merchant-1',
            'out_trade_no' => 'ORDER-EPAY',
            'trade_no' => 'EPAY-TXN-1',
            'trade_status' => 'TRADE_SUCCESS',
            'money' => '1.00'
        ];
        $ePayParams['sign'] = $this->ePaySignature($ePayParams, '');
        $this->assertHttp400(function () use ($ePayParams) {
            (new EPay(['pid' => 'merchant-1', 'key' => '']))->notify($ePayParams);
        });

        $epusdtParams = [
            'status' => '2',
            'order_id' => 'ORDER-EPUSDT',
            'trade_id' => 'EPUSDT-TXN-1',
            'amount' => '1.00',
            'currency' => 'CNY'
        ];
        $epusdtParams['signature'] = $this->epusdtSignature($epusdtParams, '');
        $this->assertHttp400(function () use ($epusdtParams) {
            (new Epusdt(['epusdt_token' => '', 'epusdt_currency' => 'CNY']))->notify($epusdtParams);
        });

        $coinbaseEvent = ['event' => ['type' => 'charge:failed']];
        $coinbasePayload = json_encode($coinbaseEvent, JSON_UNESCAPED_SLASHES);
        $this->assertHttp400(function () use ($coinbasePayload) {
            $this->notifyCoinbase(
                new Coinbase(['coinbase_webhook_key' => '']),
                $coinbasePayload,
                hash_hmac('sha256', $coinbasePayload, '')
            );
        });

        $coinPaymentsPayload = 'merchant=merchant-2&status=0&item_number=ORDER-CP&txn_id=CP-TXN-1';
        $coinPaymentsParams = [
            'merchant' => 'merchant-2',
            'status' => '0',
            'item_number' => 'ORDER-CP',
            'txn_id' => 'CP-TXN-1'
        ];
        $this->assertHttp400(function () use ($coinPaymentsPayload, $coinPaymentsParams) {
            $this->notifyCoinPayments(
                new CoinPayments([
                    'coinpayments_ipn_secret' => '',
                    'coinpayments_merchant_id' => 'merchant-2',
                    'coinpayments_currency' => 'CNY'
                ]),
                $coinPaymentsParams,
                $coinPaymentsPayload,
                hash_hmac('sha512', $coinPaymentsPayload, '')
            );
        });
    }

    public function testAlipaySupportsPemAndOneLineKeysWithARealTimestamp(): void
    {
        $keyOptions = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA
        ];
        foreach ([
            getenv('OPENSSL_CONF'),
            'C:/php/extras/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf'
        ] as $configPath) {
            if (is_string($configPath) && $configPath !== '' && is_file($configPath)) {
                $keyOptions['config'] = $configPath;
                break;
            }
        }

        $key = openssl_pkey_new($keyOptions);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privatePem, null, $keyOptions));
        $details = openssl_pkey_get_details($key);
        openssl_pkey_free($key);
        $this->assertIsArray($details);
        $publicPem = $details['key'];

        $client = $this->makeAlipayClient($this->pemBody($privatePem), $this->pemBody($publicPem));
        $params = $client->buildParam();
        $timestamp = strtotime($params['timestamp']);

        $this->assertNotFalse($timestamp);
        $this->assertLessThanOrEqual(5, abs(time() - $timestamp));
        $this->assertNotEmpty($params['sign']);

        $callback = [
            'app_id' => 'test-app-id',
            'out_trade_no' => 'ORDER-ALIPAY',
            'trade_no' => 'ALIPAY-TXN-1',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '1.23'
        ];
        $this->assertTrue(openssl_sign(
            $client->buildQuery($callback),
            $signature,
            $privatePem,
            OPENSSL_ALGO_SHA256
        ));
        $callback['sign'] = base64_encode($signature);
        $callback['sign_type'] = 'RSA2';
        $this->assertTrue($client->verify($callback));

        $pemClient = $this->makeAlipayClient($privatePem, $publicPem);
        $this->assertNotEmpty($pemClient->buildParam()['sign']);
        $this->assertTrue($pemClient->verify($callback));
    }

    public function testAlipayRejectsLongInvalidInlineKeysWithoutFilesystemWarnings(): void
    {
        $client = $this->makeAlipayClient(str_repeat('A', 2048), str_repeat('B', 512));

        $this->expectException(\RuntimeException::class);
        $client->buildParam();
    }

    private function ePaySignature(array $params, string $key): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);

        return md5(stripslashes(urldecode(http_build_query($params))) . $key);
    }

    private function epusdtSignature(array $params, string $token): string
    {
        unset($params['signature']);
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }

        return strtolower(md5(implode('&', $pairs) . $token));
    }

    private function notifyCoinbase(Coinbase $gateway, string $payload, string $signature): array
    {
        $request = Request::create('/coinbase', 'POST', [], [], [], [
            'HTTP_X_CC_WEBHOOK_SIGNATURE' => $signature
        ], $payload);

        return $this->withRequest($request, function () use ($gateway) {
            return $gateway->notify([]);
        });
    }

    private function notifyBTCPay(BTCPay $gateway, string $payload, string $signature): array
    {
        $request = Request::create('/btcpay', 'POST', [], [], [], [
            'HTTP_BTCPAY_SIG' => $signature
        ], $payload);

        return $this->withRequest($request, function () use ($gateway) {
            return $gateway->notify([]);
        });
    }

    private function notifyCoinPayments(
        CoinPayments $gateway,
        array $params,
        string $payload,
        string $signature
    ): array {
        $request = Request::create('/coinpayments', 'POST', [], [], [], [
            'HTTP_HMAC' => $signature
        ], $payload);

        return $this->withRequest($request, function () use ($gateway, $params) {
            return $gateway->notify($params);
        });
    }

    private function withRequest(Request $request, callable $callback)
    {
        $originalRequest = $this->app->make('request');
        $this->app->instance('request', $request);
        try {
            return $callback();
        } finally {
            $this->app->instance('request', $originalRequest);
        }
    }

    private function assertHttp400(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the callback to be rejected');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    private function makeAlipayClient(string $privateKey, string $publicKey): AlipayF2F
    {
        $client = new AlipayF2F();
        $client->setAppId('test-app-id');
        $client->setMethod('alipay.trade.precreate');
        $client->setBizContent(['out_trade_no' => 'ORDER-ALIPAY', 'total_amount' => '1.23']);
        $client->setPrivateKey($privateKey);
        $client->setAlipayPublicKey($publicKey);

        return $client;
    }

    private function pemBody(string $pem): string
    {
        return (string) preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $pem);
    }
}

class TestablePaymentAmountParser
{
    use PaymentAmountSupport;

    public function decimal($value): ?int
    {
        return $this->decimalToCents($value);
    }

    public function integer($value): ?int
    {
        return $this->integerAmount($value);
    }
}
