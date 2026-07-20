<?php

namespace Tests\Unit;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function testTrafficReminderIsQueuedOnlyOnceWithinTheCacheWindow(): void
    {
        $user = $this->user([
            'remind_traffic' => 1,
            'u' => 950,
            'd' => 0,
            'transfer_enable' => 1000
        ]);
        $service = new MailService();

        $service->remindTraffic($user);
        $service->remindTraffic($user);

        Queue::assertPushed(SendEmailJob::class, 1);
    }

    public function testTrafficReminderUsesOnlyATrustedApplicationUrl(): void
    {
        config([
            'v2board.app_url' => 'javascript://attacker.example',
            'app.url' => 'https://fallback.example.com/base/'
        ]);

        (new MailService())->remindTraffic($this->user([
            'remind_traffic' => 1,
            'u' => 950,
            'd' => 0,
            'transfer_enable' => 1000
        ]));

        Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) {
            $property = new \ReflectionProperty($job, 'params');
            $property->setAccessible(true);
            $params = $property->getValue($job);

            return $params['template_value']['url'] === 'https://fallback.example.com/base';
        });
    }

    public function testTrafficReminderIsNotQueuedOutsideTheWarningRange(): void
    {
        $service = new MailService();

        $service->remindTraffic($this->user([
            'remind_traffic' => 1,
            'u' => 949,
            'd' => 0,
            'transfer_enable' => 1000
        ]));
        $service->remindTraffic($this->user([
            'id' => 2,
            'remind_traffic' => 1,
            'u' => 1000,
            'd' => 0,
            'transfer_enable' => 1000
        ]));

        Queue::assertNothingPushed();
    }

    public function testExpireReminderIsQueuedOnlyOncePerDay(): void
    {
        $user = $this->user(['expired_at' => time() + 3600]);
        $service = new MailService();

        $service->remindExpire($user);
        $service->remindExpire($user);

        Queue::assertPushed(SendEmailJob::class, 1);
    }

    public function testExpireReminderRequiresAnExpiryWithinTwentyFourHours(): void
    {
        $service = new MailService();

        $service->remindExpire($this->user(['expired_at' => time() - 1]));
        $service->remindExpire($this->user(['id' => 2, 'expired_at' => time() + 86401]));

        Queue::assertNothingPushed();
    }

    private function user(array $attributes = []): User
    {
        $user = new User();
        foreach (array_merge([
            'id' => 1,
            'email' => 'user@example.com',
            'remind_traffic' => 0,
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 1000,
            'expired_at' => null
        ], $attributes) as $key => $value) {
            $user->{$key} = $value;
        }

        return $user;
    }
}
