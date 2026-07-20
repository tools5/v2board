<?php

namespace Tests\Unit;

use App\Jobs\SendEmailJob;
use App\Support\MailHeaderValidator;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendEmailJobTest extends TestCase
{
    private $originalMailConfig;
    private $originalV2boardConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalMailConfig = config('mail');
        $this->originalV2boardConfig = config('v2board');
    }

    protected function tearDown(): void
    {
        config([
            'mail' => $this->originalMailConfig,
            'v2board' => $this->originalV2boardConfig
        ]);
        parent::tearDown();
    }

    public function testHeaderValidatorAcceptsNormalValues(): void
    {
        $this->assertSame(
            'user@example.com',
            MailHeaderValidator::address('user@example.com', '收件人邮箱')
        );
        $this->assertSame('正常主题', MailHeaderValidator::text('正常主题', '邮件主题'));
    }

    /**
     * @dataProvider invalidRecipientProvider
     */
    public function testInvalidRecipientIsLoggedWithoutSending($recipient): void
    {
        Mail::shouldReceive('send')->never();
        $job = new CapturingSendEmailJob($this->mailParams([
            'email' => $recipient
        ]));

        try {
            $job->handle();
            $this->fail('无效收件人未被拒绝');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertCount(1, $job->logs);
        $this->assertNotEmpty($job->logs[0]['error']);
    }

    public function invalidRecipientProvider(): array
    {
        return [
            'header injection with CRLF' => ["victim@example.com\r\nBcc: attacker@example.com"],
            'header injection with LF' => ["victim@example.com\nBcc: attacker@example.com"],
            'malformed address' => ['not-an-email'],
            'non-scalar address' => [['user@example.com']]
        ];
    }

    public function testInjectedSubjectIsLoggedWithoutSending(): void
    {
        Mail::shouldReceive('send')->never();
        $job = new CapturingSendEmailJob($this->mailParams([
            'subject' => "正常主题\r\nBcc: attacker@example.com"
        ]));

        try {
            $job->handle();
            $this->fail('带换行符的主题未被拒绝');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('换行符', $e->getMessage());
        }

        $this->assertCount(1, $job->logs);
        $this->assertNotEmpty($job->logs[0]['error']);
    }

    public function testInjectedConfiguredSenderNameIsLoggedWithoutSending(): void
    {
        Mail::shouldReceive('send')->never();
        config([
            'v2board.email_host' => 'smtp.example.com',
            'v2board.email_from_address' => 'sender@example.com',
            'v2board.app_name' => "V2Board\nBcc: attacker@example.com"
        ]);
        $job = new CapturingSendEmailJob($this->mailParams());

        try {
            $job->handle();
            $this->fail('带换行符的发件人名称未被拒绝');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('换行符', $e->getMessage());
        }

        $this->assertCount(1, $job->logs);
    }

    private function mailParams(array $overrides = []): array
    {
        return array_merge([
            'email' => 'user@example.com',
            'subject' => '测试邮件',
            'template_name' => 'notify',
            'template_value' => [
                'name' => 'V2Board',
                'content' => 'test',
                'url' => 'https://example.com'
            ]
        ], $overrides);
    }
}

class CapturingSendEmailJob extends SendEmailJob
{
    public $logs = [];

    protected function writeLog(array $params, $error): void
    {
        $this->logs[] = [
            'params' => $params,
            'error' => $error
        ];
    }
}
