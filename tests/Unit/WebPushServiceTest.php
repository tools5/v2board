<?php

namespace Tests\Unit;

use App\Exceptions\WebPushEndpointResolutionException;
use App\Services\WebPushService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WebPushServiceTest extends TestCase
{
    private $originalStoragePath;
    private $temporaryStoragePath;
    private $originalV2boardConfig;
    private $originalWebPushConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->temporaryStoragePath = $this->originalStoragePath
            . DIRECTORY_SEPARATOR . 'framework'
            . DIRECTORY_SEPARATOR . 'testing'
            . DIRECTORY_SEPARATOR . 'webpush-' . bin2hex(random_bytes(8));
        File::makeDirectory($this->temporaryStoragePath, 0750, true);
        $this->app->useStoragePath($this->temporaryStoragePath);

        $this->originalV2boardConfig = config('v2board');
        $this->originalWebPushConfig = config('webpush');
        config([
            'v2board' => [],
            'webpush' => [
                'enabled' => false,
                'vapid' => [
                    'subject' => 'mailto:admin@example.com',
                    'public_key' => '',
                    'private_key' => '',
                ],
                'allowed_endpoint_hosts' => [],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $temporaryStoragePath = $this->temporaryStoragePath;
        $this->app->useStoragePath($this->originalStoragePath);
        config([
            'v2board' => $this->originalV2boardConfig,
            'webpush' => $this->originalWebPushConfig,
        ]);

        if (is_string($temporaryStoragePath)
            && strpos($temporaryStoragePath, $this->originalStoragePath . DIRECTORY_SEPARATOR) === 0
        ) {
            File::deleteDirectory($temporaryStoragePath);
        }

        parent::tearDown();
    }

    public function testSettingsAreSavedAndAnEmptyPrivateKeyKeepsTheExistingSecret(): void
    {
        $service = new WebPushService();
        $publicKey = $this->base64UrlEncode("\x04" . str_repeat("\x01", 64));
        $privateKey = $this->base64UrlEncode(str_repeat("\x02", 32));

        $saved = $service->saveSettings([
            'enabled' => true,
            'vapid_subject' => 'mailto:admin@example.com',
            'public_key' => $publicKey,
            'private_key' => $privateKey,
        ]);

        $this->assertSame($privateKey, $saved['private_key']);
        $this->assertSame('storage', $saved['source']);
        $this->assertFileExists(storage_path('app/webpush-settings.json'));

        $updated = $service->saveSettings([
            'private_key' => '',
            'ttl' => 1234,
        ]);

        $this->assertSame($privateKey, $updated['private_key']);
        $this->assertSame(1234, $updated['ttl']);
    }

    public function testKnownPublicPushEndpointAndValidKeysAreAccepted(): void
    {
        $service = new TestableWebPushService(['8.8.8.8']);

        $service->assertValidSubscription(
            'https://fcm.googleapis.com/fcm/send/example',
            $this->base64UrlEncode("\x04" . str_repeat("\x03", 64)),
            $this->base64UrlEncode(str_repeat("\x04", 16))
        );

        $this->assertTrue(true);
    }

    public function testUnknownEndpointHostIsRejectedUnlessExplicitlyAllowed(): void
    {
        $service = new TestableWebPushService(['8.8.8.8']);

        $this->expectException(\InvalidArgumentException::class);
        $service->assertValidSubscriptionEndpoint('https://push.example.com/send/example');
    }

    public function testExplicitEndpointHostIsAccepted(): void
    {
        config(['webpush.allowed_endpoint_hosts' => ['push.example.com']]);
        $service = new TestableWebPushService(['8.8.8.8']);

        $service->assertValidSubscriptionEndpoint('https://push.example.com/send/example');

        $this->assertTrue(true);
    }

    public function testEndpointResolvingToAPrivateAddressIsRejected(): void
    {
        $service = new TestableWebPushService(['127.0.0.1']);

        $this->expectException(\InvalidArgumentException::class);
        $service->assertValidSubscriptionEndpoint('https://fcm.googleapis.com/fcm/send/example');
    }

    public function testTemporaryEndpointResolutionFailureIsNotReportedAsAnInvalidSubscription(): void
    {
        $service = new TestableWebPushService([]);

        $this->expectException(WebPushEndpointResolutionException::class);
        $service->assertValidSubscriptionEndpoint('https://fcm.googleapis.com/fcm/send/example');
    }

    public function testInvalidSubscriptionKeyLengthsAreRejected(): void
    {
        $service = new TestableWebPushService(['8.8.8.8']);

        $this->expectException(\InvalidArgumentException::class);
        $service->assertValidSubscription(
            'https://fcm.googleapis.com/fcm/send/example',
            $this->base64UrlEncode("\x04" . str_repeat("\x03", 63)),
            $this->base64UrlEncode(str_repeat("\x04", 16))
        );
    }

    public function testGeneratedVapidKeysHaveExpectedP256Lengths(): void
    {
        $keys = (new WebPushService())->generateVapidKeys();

        $this->assertSame(65, strlen($this->base64UrlDecode($keys['public_key'])));
        $this->assertSame(32, strlen($this->base64UrlDecode($keys['private_key'])));
        $this->assertSame("\x04", $this->base64UrlDecode($keys['public_key'])[0]);
    }

    private function base64UrlEncode($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode($value)
    {
        $padding = (4 - strlen($value) % 4) % 4;
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    }
}

class TestableWebPushService extends WebPushService
{
    private $testAddresses;

    public function __construct(array $testAddresses)
    {
        $this->testAddresses = $testAddresses;
    }

    protected function resolveEndpointHostAddresses($host, $depth = 0, array &$visited = [])
    {
        return $this->testAddresses;
    }
}
