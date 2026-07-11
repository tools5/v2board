<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\ConfigSave;
use App\Services\Oauth\OauthProviderRegistry;
use App\Services\Oauth\OauthService;
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
    }

    protected function tearDown(): void
    {
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
