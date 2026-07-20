<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\ConfigSave;
use App\Services\Oauth\OauthProviderRegistry;
use App\Services\Oauth\OauthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OauthProviderRegistryTest extends TestCase
{
    private $originalV2boardConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalV2boardConfig = config('v2board');
        $this->resetInviteCodeTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('v2_invite_code');
        config(['v2board' => $this->originalV2boardConfig]);
        parent::tearDown();
    }

    public function testEveryProviderFieldHasASaveRule(): void
    {
        $rules = ConfigSave::allRules();

        foreach (OauthProviderRegistry::all() as $provider => $meta) {
            foreach ([
                'enable_key',
                'client_id_key',
                'client_secret_key',
                'auto_register_key',
                'min_trust_level_key',
                'callback_url_key',
            ] as $field) {
                if (empty($meta[$field])) {
                    continue;
                }
                $this->assertArrayHasKey(
                    $meta[$field],
                    $rules,
                    $provider . ' is missing a validation rule for ' . $field
                );
            }
        }
    }

    public function testApplicationUrlOnlyAcceptsSafeHttpUrls(): void
    {
        foreach ([
            'ftp://panel.example.com',
            'https://user:password@panel.example.com',
            "https://panel.example.com\r\nInjected: true",
        ] as $url) {
            $validator = $this->validateConfigRequest(['app_url' => $url]);
            $this->assertTrue($validator->fails(), $url);
            $this->assertArrayHasKey('app_url', $validator->errors()->toArray());
        }

        $validator = $this->validateConfigRequest([
            'app_url' => 'https://panel.example.com/base'
        ]);
        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function testExternalConfigurationUrlsRejectUnsafeValues(): void
    {
        foreach ([
            ['logo', 'ftp://cdn.example.com/logo.svg'],
            ['tos_url', 'https://user:password@docs.example.com/terms'],
            ['frontend_background_url', 'javascript://attacker.example/image'],
            ['server_api_url', 'https://api.example.com/%250dInjected'],
            ['telegram_discuss_link', 'https://chat.example.com/%5Cchannel'],
            ['windows_download_url', 'https://downloads.example.com/%250dsetup.exe'],
            ['subscribe_url', 'https://one.example.com,ftp://two.example.com'],
            ['subscribe_path', '/api/%250dInjected'],
            ['email_from_address', "sender@example.com\r\nBcc: victim@example.com"],
        ] as [$field, $value]) {
            $validator = $this->validateConfigRequest([$field => $value]);

            $this->assertTrue($validator->fails(), $field . ' should fail');
            $this->assertArrayHasKey($field, $validator->errors()->toArray());
        }
    }

    public function testExternalConfigurationUrlsAcceptSafeHttpValues(): void
    {
        $validator = $this->validateConfigRequest([
            'logo' => 'https://cdn.example.com/logo%20file.svg?version=1#preview',
            'tos_url' => 'https://docs.example.com/terms#full',
            'frontend_background_url' => 'https://images.example.com/background.jpg?fit=cover',
            'server_api_url' => 'http://127.0.0.1:8080/api',
            'telegram_discuss_link' => 'https://chat.example.com/channel',
            'windows_download_url' => 'https://downloads.example.com/windows.exe',
            'macos_download_url' => 'https://downloads.example.com/macos.dmg',
            'android_download_url' => 'https://downloads.example.com/android.apk',
            'subscribe_url' => 'https://sub-one.example.com,https://sub-two.example.com/base',
            'subscribe_path' => '/api/v1/client/subscribe',
            'email_from_address' => 'noreply@example.com',
        ]);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function testMicrosoftConfigurationIsAccepted(): void
    {
        $validator = $this->validateConfigRequest([
            'login_microsoft_enable' => 1,
            'login_microsoft_client_id' => 'client-id',
            'login_microsoft_client_secret' => 'client-secret',
            'login_microsoft_auto_register' => 1,
            'login_microsoft_callback_url' => 'https://panel.example.com/api/v1/passport/auth/oauth/callback?provider=microsoft',
        ]);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function testEnabledProviderRequiresBothCredentials(): void
    {
        config([
            'v2board.login_linuxdo_client_id' => '',
            'v2board.login_linuxdo_client_secret' => '',
        ]);

        $validator = $this->validateConfigRequest([
            'login_linuxdo_enable' => 1,
            'login_linuxdo_client_id' => '',
            'login_linuxdo_client_secret' => '',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('login_linuxdo_client_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('login_linuxdo_client_secret', $validator->errors()->toArray());
    }

    public function testExistingSecretCanBeOmittedWithoutBeingCleared(): void
    {
        config([
            'v2board.login_linuxdo_client_id' => 'existing-id',
            'v2board.login_linuxdo_client_secret' => 'existing-secret',
        ]);

        $validator = $this->validateConfigRequest([
            'login_linuxdo_enable' => 1,
            'login_linuxdo_client_id' => 'updated-id',
        ]);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function testExistingSecretCanBeSubmittedBlankWithoutFailingValidation(): void
    {
        config([
            'v2board.login_linuxdo_client_id' => 'existing-id',
            'v2board.login_linuxdo_client_secret' => 'existing-secret',
        ]);

        $validator = $this->validateConfigRequest([
            'login_linuxdo_enable' => 1,
            'login_linuxdo_client_id' => 'updated-id',
            'login_linuxdo_client_secret' => '',
        ]);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function testAdminMetadataDoesNotExposeClientSecrets(): void
    {
        config(['v2board.login_linuxdo_client_secret' => 'do-not-return-this']);

        $linuxdo = $this->providerFromAdminList('linuxdo');

        $this->assertSame('', $linuxdo['client_secret']);
        $this->assertTrue($linuxdo['client_secret_configured']);
    }

    public function testCallbackMustTargetTheMatchingProvider(): void
    {
        $validator = $this->validateConfigRequest([
            'login_linuxdo_callback_url' => 'https://panel.example.com/api/v1/passport/auth/oauth/callback?provider=github',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('login_linuxdo_callback_url', $validator->errors()->toArray());
    }

    public function testDefaultCallbackUsesCanonicalAppUrl(): void
    {
        config(['v2board.app_url' => 'https://panel.example.com/']);

        $this->assertSame(
            'https://panel.example.com/api/v1/passport/auth/oauth/callback?provider=linuxdo',
            OauthProviderRegistry::defaultCallbackUrl('linuxdo')
        );
    }

    public function testMicrosoftUnknownEmailVerificationIsNotTrusted(): void
    {
        $this->assertFalse(OauthProviderRegistry::get('microsoft')['email_trusted_when_unknown']);
    }

    public function testInactiveAccountIsRejectedWhenMinimumTrustLevelIsZero(): void
    {
        config(['v2board.login_linuxdo_min_trust_level' => 0]);
        $method = new \ReflectionMethod(OauthService::class, 'assertTrustLevel');
        $method->setAccessible(true);

        $this->expectException(HttpException::class);
        $method->invoke(
            new OauthService(),
            OauthProviderRegistry::get('linuxdo'),
            ['active' => false, 'trust_level' => 4]
        );
    }

    public function testOauthAutoRegisterRequiresInviteWhenForceEnabled(): void
    {
        config([
            'v2board.invite_force' => 1,
            'v2board.invite_never_expire' => 1,
        ]);

        $method = new \ReflectionMethod(OauthService::class, 'resolveInviteUserIdForRegistration');
        $method->setAccessible(true);

        try {
            $method->invoke(new OauthService(), '');
            $this->fail('Expected HttpException when invite code is missing under invite_force');
        } catch (HttpException $exception) {
            $this->assertSame(500, $exception->getStatusCode());
        }

        try {
            $method->invoke(new OauthService(), 'not-a-real-invite-code');
            $this->fail('Expected HttpException when invite code is invalid under invite_force');
        } catch (HttpException $exception) {
            $this->assertSame(500, $exception->getStatusCode());
        }
    }

    public function testOauthAutoRegisterAllowsEmptyInviteWhenForceDisabled(): void
    {
        config([
            'v2board.invite_force' => 0,
        ]);

        $method = new \ReflectionMethod(OauthService::class, 'resolveInviteUserIdForRegistration');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(new OauthService(), ''));
        $this->assertNull($method->invoke(new OauthService(), 'invalid-when-optional'));
    }

    public function testBuildAuthorizeUrlStoresInviteCodeInOauthState(): void
    {
        config([
            'v2board.login_github_enable' => 1,
            'v2board.login_github_client_id' => 'gh-client',
            'v2board.login_github_client_secret' => 'gh-secret',
            'v2board.app_url' => 'https://panel.example.com',
        ]);

        // 避免缺表 abort：ensureTableExists 在有表时通过；本地开发库应有表
        if (!\Illuminate\Support\Facades\Schema::hasTable('v2_user_oauth')) {
            $this->markTestSkipped('v2_user_oauth table is required');
        }

        $service = new OauthService();
        $url = $service->buildAuthorizeUrl('github', 'login', null, '  ABCD1234  ');
        $this->assertStringContainsString('github.com', $url);

        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['state'] ?? null);

        $stateData = \Illuminate\Support\Facades\Cache::get(
            \App\Utils\CacheKey::get('OAUTH_STATE', $query['state'])
        );
        $this->assertIsArray($stateData);
        $this->assertSame('ABCD1234', $stateData['invite_code'] ?? null);
        $this->assertSame('github', $stateData['provider'] ?? null);
    }

    private function resetInviteCodeTable(): void
    {
        Schema::dropIfExists('v2_invite_code');
        Schema::create('v2_invite_code', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->default(0);
            $table->string('code', 32)->unique();
            $table->boolean('status')->default(false);
            $table->unsignedInteger('pv')->default(0);
            $table->unsignedInteger('created_at')->nullable();
            $table->unsignedInteger('updated_at')->nullable();
        });
    }

    private function validateConfigRequest(array $input): Validator
    {
        $request = ConfigSave::create('/config/save', 'POST', $input);
        $request->setContainer($this->app);
        $validator = $this->app['validator']->make(
            $request->all(),
            $request->rules(),
            $request->messages()
        );
        $request->withValidator($validator);

        return $validator;
    }

    private function providerFromAdminList(string $provider): array
    {
        foreach (OauthProviderRegistry::adminProviderList() as $item) {
            if ($item['provider'] === $provider) {
                return $item;
            }
        }

        $this->fail('Provider not found: ' . $provider);
    }
}
