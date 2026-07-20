<?php

namespace App\Payments {
    class DisabledTestPayment
    {
        public function __construct($config)
        {
        }

        public function form()
        {
            return [];
        }

        public function pay($order)
        {
            return ['type' => 1, 'data' => 'unused'];
        }

        public function notify($params)
        {
            return [
                'trade_no' => 'ORDER-DISABLED',
                'callback_no' => 'CALLBACK-DISABLED',
                'amount' => 100
            ];
        }
    }

    class UrlCaptureTestPayment
    {
        public static $lastOrder = null;

        public function __construct($config)
        {
        }

        public function form()
        {
            return [];
        }

        public function pay($order)
        {
            self::$lastOrder = $order;

            return ['type' => 1, 'data' => 'captured'];
        }

        public function notify($params)
        {
            return [];
        }
    }

    class MalformedTestPayment
    {
        public static $result = [];

        public function __construct($config)
        {
        }

        public function form()
        {
            return [];
        }

        public function pay($order)
        {
            return ['type' => 1, 'data' => 'unused'];
        }

        public function notify($params)
        {
            return self::$result;
        }
    }
}

namespace Tests\Unit {
    use App\Http\Controllers\V1\Guest\PaymentController as GuestPaymentController;
    use App\Jobs\OrderHandleJob;
    use App\Models\Coupon;
    use App\Models\Order;
    use App\Models\Payment;
    use App\Models\User;
    use App\Services\OrderService;
    use App\Services\PaymentService;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Queue;
    use Illuminate\Support\Facades\Schema;
    use Tests\TestCase;

    class OrderPaymentIntegrityTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            Schema::dropIfExists('v2_order');
            Schema::dropIfExists('v2_user');
            Schema::dropIfExists('v2_coupon');
            Schema::dropIfExists('v2_payment');

            Schema::create('v2_user', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('balance')->default(0);
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
            Schema::create('v2_coupon', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('limit_use')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
            Schema::create('v2_payment', function (Blueprint $table) {
                $table->increments('id');
                $table->string('uuid');
                $table->string('payment');
                $table->text('config')->nullable();
                $table->boolean('enable')->default(false);
                $table->string('notify_domain')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
            Schema::create('v2_order', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->integer('plan_id')->default(0);
                $table->string('period')->nullable();
                $table->string('trade_no')->unique();
                $table->integer('total_amount')->default(0);
                $table->integer('balance_amount')->default(0);
                $table->integer('handling_amount')->nullable();
                $table->integer('coupon_id')->nullable();
                $table->integer('payment_id')->nullable();
                $table->integer('status')->default(0);
                $table->integer('type')->default(0);
                $table->string('callback_no')->nullable();
                $table->integer('paid_at')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });

            Queue::fake();
        }

        protected function tearDown(): void
        {
            Schema::dropIfExists('v2_order');
            Schema::dropIfExists('v2_payment');
            Schema::dropIfExists('v2_coupon');
            Schema::dropIfExists('v2_user');
            parent::tearDown();
        }

        public function testPaymentAmountAndPaymentMethodMustMatchTheOrder(): void
        {
            $order = $this->createOrder([
                'trade_no' => 'ORDER-MATCH',
                'total_amount' => 100,
                'handling_amount' => 10,
                'payment_id' => 5
            ]);
            $service = new OrderService($order);

            $this->assertFalse($service->paid('CALLBACK-1', 5, 109));
            $this->assertFalse($service->paid('CALLBACK-1', 6, 110));
            $this->assertSame(OrderService::STATUS_PENDING, (int) $order->fresh()->status);
            Queue::assertNothingPushed();
        }

        public function testRepeatedPaidCallbackDoesNotRecordThePaymentTwice(): void
        {
            $order = $this->createOrder([
                'trade_no' => 'ORDER-PAID',
                'total_amount' => 100,
                'payment_id' => 7
            ]);
            $service = new OrderService($order);

            $this->assertTrue($service->paid('CALLBACK-PAID', 7, 100));
            $this->assertTrue($service->wasPaymentRecorded());
            $paidAt = $order->fresh()->paid_at;

            $this->assertTrue($service->paid('CALLBACK-PAID', 7, 100));
            $this->assertFalse($service->wasPaymentRecorded());
            $this->assertSame($paidAt, $order->fresh()->paid_at);
            $this->assertSame('CALLBACK-PAID', $order->fresh()->callback_no);
            Queue::assertPushed(OrderHandleJob::class);
        }

        public function testRepeatedDepositOpenOnlyAddsBalanceOnce(): void
        {
            $user = User::create(['balance' => 50]);
            $order = $this->createOrder([
                'trade_no' => 'ORDER-OPEN',
                'user_id' => $user->id,
                'total_amount' => 100,
                'type' => 9,
                'status' => OrderService::STATUS_PROCESSING
            ]);
            $service = new OrderService($order);

            $this->assertTrue($service->open());
            $this->assertTrue($service->open());
            $this->assertSame(150, (int) $user->fresh()->balance);
            $this->assertSame(OrderService::STATUS_COMPLETED, (int) $order->fresh()->status);
        }

        public function testRepeatedCancelOnlyRefundsBalanceAndCouponOnce(): void
        {
            $user = User::create(['balance' => 10]);
            $coupon = Coupon::create(['limit_use' => 2]);
            $order = $this->createOrder([
                'trade_no' => 'ORDER-CANCEL',
                'user_id' => $user->id,
                'balance_amount' => 20,
                'coupon_id' => $coupon->id
            ]);
            $service = new OrderService($order);

            $this->assertTrue($service->cancel());
            $this->assertTrue($service->cancel());
            $this->assertSame(30, (int) $user->fresh()->balance);
            $this->assertSame(3, (int) $coupon->fresh()->limit_use);
            $this->assertSame(OrderService::STATUS_CANCELLED, (int) $order->fresh()->status);
        }

        public function testDisabledPaymentStillAcceptsHistoricalCallbacksButCannotStartPayments(): void
        {
            $payment = Payment::create([
                'uuid' => 'disabled-payment',
                'payment' => 'DisabledTestPayment',
                'config' => [],
                'enable' => 0
            ]);
            $service = new PaymentService('DisabledTestPayment', null, $payment->uuid);

            $this->assertSame('ORDER-DISABLED', $service->notify([])['trade_no']);
            $this->expectException(\RuntimeException::class);
            $service->pay([
                'trade_no' => 'ORDER-DISABLED',
                'total_amount' => 100,
                'user_id' => 1
            ]);
        }

        public function testPaymentUrlsUseTrustedConfigurationAndEncodePathValues(): void
        {
            $payment = Payment::create([
                'uuid' => 'callback uuid/1',
                'payment' => 'UrlCaptureTestPayment',
                'config' => [],
                'enable' => 1,
                'notify_domain' => 'https://callbacks.example.com/receiver/'
            ]);
            config([
                'v2board.app_url' => 'https://panel.example.com/base/',
                'app.url' => ''
            ]);

            (new PaymentService('UrlCaptureTestPayment', null, $payment->uuid))->pay([
                'trade_no' => 'ORDER/ with?=&',
                'total_amount' => 100,
                'user_id' => 1
            ]);
            $captured = \App\Payments\UrlCaptureTestPayment::$lastOrder;
            $this->assertSame(
                'https://callbacks.example.com/receiver/api/v1/guest/payment/notify/UrlCaptureTestPayment/callback%20uuid%2F1',
                $captured['notify_url']
            );
            $this->assertSame(
                'https://panel.example.com/base/#/order/ORDER%2F%20with%3F%3D%26',
                $captured['return_url']
            );

            $payment->notify_domain = 'https://user:password@attacker.example';
            $payment->save();
            (new PaymentService('UrlCaptureTestPayment', null, $payment->uuid))->pay([
                'trade_no' => 'ORDER-FALLBACK',
                'total_amount' => 100,
                'user_id' => 1
            ]);
            $this->assertSame(
                'https://panel.example.com/base/api/v1/guest/payment/notify/UrlCaptureTestPayment/callback%20uuid%2F1',
                \App\Payments\UrlCaptureTestPayment::$lastOrder['notify_url']
            );

            config([
                'v2board.app_url' => 'javascript://attacker.example',
                'app.url' => ''
            ]);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('A valid application URL is required');
            (new PaymentService('UrlCaptureTestPayment', null, $payment->uuid))->pay([
                'trade_no' => 'ORDER-NO-APP-URL',
                'total_amount' => 100,
                'user_id' => 1
            ]);
        }

        public function testPaymentControllerRejectsMalformedGatewayResultFields(): void
        {
            $payment = Payment::create([
                'uuid' => 'malformed-payment',
                'payment' => 'MalformedTestPayment',
                'config' => [],
                'enable' => 1
            ]);
            $controller = new GuestPaymentController();
            $request = Request::create('/payment/notify', 'POST');
            $valid = [
                'trade_no' => 'ORDER-MALFORMED',
                'callback_no' => 'CALLBACK-MALFORMED',
                'amount' => 100
            ];
            $cases = [
                array_merge($valid, ['trade_no' => ['ORDER-MALFORMED']]),
                array_merge($valid, ['callback_no' => ['CALLBACK-MALFORMED']]),
                array_merge($valid, ['amount' => (string) PHP_INT_MAX . '0']),
                array_merge($valid, ['currency' => 'CNY', 'expected_currency' => ['CNY']]),
                array_merge($valid, ['custom_result' => ['success']]),
                array_merge($valid, ['custom_content_type' => "text/plain\r\nX-Test: injected"]),
                array_merge($valid, ['custom_content_type' => 'not-a-mime']),
                ['acknowledge_only' => 'true', 'custom_result' => 'success']
            ];

            foreach ($cases as $result) {
                \App\Payments\MalformedTestPayment::$result = $result;
                $response = $controller->notify(
                    'MalformedTestPayment',
                    $payment->uuid,
                    $request
                );

                $this->assertSame(400, $response->getStatusCode());
                $this->assertSame('fail', $response->getContent());
            }
        }

        public function testPaymentControllerAllowsWellFormedCustomContentType(): void
        {
            $payment = Payment::create([
                'uuid' => 'content-type-payment',
                'payment' => 'MalformedTestPayment',
                'config' => [],
                'enable' => 1
            ]);
            \App\Payments\MalformedTestPayment::$result = [
                'acknowledge_only' => true,
                'custom_result' => '<xml><return_code>SUCCESS</return_code></xml>',
                'custom_content_type' => 'application/xml; charset=UTF-8',
            ];

            $response = (new GuestPaymentController())->notify(
                'MalformedTestPayment',
                $payment->uuid,
                Request::create('/payment/notify', 'POST')
            );

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));
        }

        private function createOrder(array $attributes)
        {
            return Order::create(array_merge([
                'user_id' => 1,
                'plan_id' => 0,
                'period' => 'deposit',
                'trade_no' => 'ORDER-' . bin2hex(random_bytes(4)),
                'total_amount' => 0,
                'balance_amount' => 0,
                'handling_amount' => null,
                'coupon_id' => null,
                'payment_id' => null,
                'status' => OrderService::STATUS_PENDING,
                'type' => 0
            ], $attributes));
        }
    }
}
