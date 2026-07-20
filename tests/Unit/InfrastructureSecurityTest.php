<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\ConfigController as AdminConfigController;
use App\Http\Controllers\V1\Admin\TicketController as AdminTicketController;
use App\Http\Controllers\V1\Guest\CommController as GuestCommController;
use App\Http\Controllers\V1\Client\AppController;
use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Middleware\CORS;
use App\Http\Requests\Admin\ConfigSave;
use App\Protocols\v2RayTun;
use App\Http\Controllers\V1\Guest\TelegramController;
use App\Http\Controllers\V1\Passport\OauthController;
use App\Models\User;
use App\Services\ServerService;
use App\Services\TelegramService;
use App\Services\ThemeService;
use App\Support\ConfiguredUrl;
use App\Support\EtagMatcher;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Tests\TestCase;

class InfrastructureSecurityTest extends TestCase
{
    public function testConfiguredSubscriptionHostUsesTrustedUrl(): void
    {
        config([
            'v2board.subscribe_url' => 'https://configured.example.com/sub',
            'v2board.app_url' => 'https://app.example.com',
            'app.url' => 'https://fallback.example.com',
        ]);

        $this->assertSame(
            'primary.example.org',
            ConfiguredUrl::subscriptionHost('https://Primary.Example.org/client/subscribe?token=secret')
        );
    }

    public function testConfiguredSubscriptionHostRejectsHeaderInjectionAndInvalidSchemes(): void
    {
        config([
            'v2board.subscribe_url' => 'https://configured.example.com/sub',
            'v2board.app_url' => 'https://app.example.com',
        ]);

        $this->assertSame(
            'configured.example.com',
            ConfiguredUrl::subscriptionHost("https://victim.example\r\nX-Injected: true")
        );
        $this->assertSame(
            'configured.example.com',
            ConfiguredUrl::subscriptionHost('javascript://attacker.example')
        );
    }

    public function testConfiguredApplicationHostOnlyUsesHttpConfiguration(): void
    {
        config([
            'v2board.app_url' => 'javascript://attacker.example',
            'app.url' => 'https://Fallback.Example.com/path',
        ]);

        $this->assertSame('fallback.example.com', ConfiguredUrl::applicationHost());
        $this->assertSame(
            'configured.example.org',
            ConfiguredUrl::applicationHost('https://Configured.Example.org:8443/path')
        );
    }

    public function testEmptyRouteListDoesNotBuildInvalidSql(): void
    {
        $routes = (new ServerService())->getRoutes([]);

        $this->assertTrue($routes->isEmpty());
    }
    public function testTelegramMarkdownEscapingCoversControlCharacters(): void
    {
        $service = new TelegramService('12345:abcdefghijklmnopqrstuvwxyz');
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('escapeMarkdownV2');
        $method->setAccessible(true);

        $this->assertSame('a\\_b\\*c\\!d', $method->invoke($service, 'a_b*c!d'));
    }

    public function testTelegramRejectsInvalidTokenBeforeNetworkRequest(): void
    {
        $service = new TelegramService('invalid-token');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Telegram bot token is not configured or invalid');
        $service->getMe();
    }

    public function testTelegramWebhookRequiresSecretHeaderAfterMigration(): void
    {
        config([
            'v2board.telegram_webhook_secret' => 'webhook-secret',
            'v2board.telegram_bot_token' => 'legacy-bot-token',
        ]);
        $controller = new TelegramController();
        $method = new \ReflectionMethod($controller, 'isAuthorizedWebhookRequest');
        $method->setAccessible(true);

        $valid = Request::create('/api/v1/guest/telegram/webhook?access_token=' . md5('legacy-bot-token'), 'POST');
        $valid->headers->set('X-Telegram-Bot-Api-Secret-Token', 'webhook-secret');
        $missingHeader = Request::create('/api/v1/guest/telegram/webhook?access_token=' . md5('legacy-bot-token'), 'POST');

        $this->assertTrue($method->invoke($controller, $valid));
        $this->assertFalse($method->invoke($controller, $missingHeader));
    }

    public function testTelegramWebhookLegacyQueryTokenOnlyWorksWithoutSecret(): void
    {
        config([
            'v2board.telegram_webhook_secret' => '',
            'v2board.telegram_bot_token' => 'legacy-bot-token',
        ]);
        $controller = new TelegramController();
        $method = new \ReflectionMethod($controller, 'isAuthorizedWebhookRequest');
        $method->setAccessible(true);

        $valid = Request::create('/api/v1/guest/telegram/webhook?access_token=' . md5('legacy-bot-token'), 'POST');
        $bodyOnly = Request::create('/api/v1/guest/telegram/webhook', 'POST', [
            'access_token' => md5('legacy-bot-token'),
        ]);

        $this->assertTrue($method->invoke($controller, $valid));
        $this->assertFalse($method->invoke($controller, $bodyOnly));
    }

    public function testConfigFetchDoesNotExposeSecretsAndBlankChangesPreserveThem(): void
    {
        config([
            'v2board.server_token' => 'server-secret-value',
            'v2board.email_password' => 'mail-secret-value',
            'v2board.telegram_bot_token' => 'telegram-secret-value',
            'v2board.recaptcha_key' => 'recaptcha-secret-value',
            'v2board.login_linuxdo_client_secret' => 'oauth-secret-value',
        ]);
        $controller = new AdminConfigController();
        $response = $controller->fetch(Request::create('/api/v1/admin/config/fetch', 'GET'));
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $data = $payload['data'];

        $this->assertSame('', $data['server']['server_token']);
        $this->assertTrue($data['server']['server_token_configured']);
        $this->assertSame('', $data['email']['email_password']);
        $this->assertTrue($data['email']['email_password_configured']);
        $this->assertSame('', $data['telegram']['telegram_bot_token']);
        $this->assertTrue($data['telegram']['telegram_bot_token_configured']);
        $this->assertSame('', $data['safe']['recaptcha_key']);
        $this->assertTrue($data['safe']['recaptcha_key_configured']);

        $method = new \ReflectionMethod($controller, 'preserveExistingSecrets');
        $method->setAccessible(true);
        $changes = $method->invoke($controller, [
            'server_token' => '',
            'email_password' => null,
            'login_linuxdo_client_secret' => '',
        ]);
        $this->assertSame('server-secret-value', $changes['server_token']);
        $this->assertSame('mail-secret-value', $changes['email_password']);
        $this->assertSame('oauth-secret-value', $changes['login_linuxdo_client_secret']);
    }

    public function testOauthPopupEncodingCannotTerminateScriptTag(): void
    {
        $controller = new OauthController();
        $method = new \ReflectionMethod($controller, 'popupPostMessage');
        $method->setAccessible(true);

        $response = $method->invoke($controller, [
            'token' => '</script><img src=x onerror=alert(1)>',
        ]);
        $content = $response->getContent();

        $this->assertStringNotContainsString('</script><img', $content);
        $this->assertStringContainsString('\u003C/script\u003E', $content);
    }

    public function testHelperUsesSecureFormatsAndStrictUrlSafeBase64(): void
    {
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', Helper::guid());
        $this->assertMatchesRegularExpression(
            '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/',
            Helper::guid(true)
        );
        $this->assertMatchesRegularExpression('/\A\d{22}\z/', Helper::generateOrderNo());
        $this->assertSame('test', Helper::base64DecodeUrlSafe('dGVzdA'));
        $this->assertFalse(Helper::base64DecodeUrlSafe('not+url-safe'));
        $this->assertContains(1000, range(1000, 1001));
        $this->assertContains(Helper::randomPort('1000-1001'), [1000, 1001]);

        $this->expectException(\InvalidArgumentException::class);
        Helper::exchange('US', 'CNY');
    }

    public function testUserSerializationHidesPasswordMaterial(): void
    {
        $user = new User();
        $this->assertContains('password', $user->getHidden());
        $this->assertContains('password_algo', $user->getHidden());
        $this->assertContains('password_salt', $user->getHidden());
    }
    public function testConfiguredApplicationUrlRejectsUnsafeConfiguration(): void
    {
        config([
            'v2board.app_url' => "https://bad.example\r\nInjected: true",
            'app.url' => 'https://Fallback.Example.com/base/',
        ]);

        $this->assertSame('https://fallback.example.com/base', ConfiguredUrl::applicationUrl());
    }

    public function testConfiguredExternalUrlsPreserveSafeComponentsAndRejectUnsafeValues(): void
    {
        $this->assertSame(
            'https://cdn.example.com/assets/logo%20file.svg?version=1#preview',
            ConfiguredUrl::normalizeExternalHttpUrl('https://CDN.Example.com/assets/logo%20file.svg?version=1#preview')
        );

        foreach ([
            'ftp://cdn.example.com/logo.svg',
            'https://user:password@cdn.example.com/logo.svg',
            'https://cdn.example.com/logo%250dInjected',
            'https://cdn.example.com/logo%5Cname.svg',
            'javascript://cdn.example.com/logo.svg',
        ] as $url) {
            $this->assertSame('', ConfiguredUrl::normalizeExternalHttpUrl($url), $url);
        }

        $this->assertSame('', ConfiguredUrl::normalizeHttpUrl('https://app.example.com/%250dInjected'));
        $this->assertSame('', ConfiguredUrl::applicationPathUrl('/%250dInjected'));
    }

    public function testFrameworkStrictEmailRuleRejectsHeaderInjection(): void
    {
        $validator = Validator::make(
            ['email' => "alice@example.com\r\nBcc: victim@example.com"],
            ['email' => 'required|email:strict']
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    public function testPublicConfigurationOmitsUnsafeExternalUrls(): void
    {
        config([
            'v2board.tos_url' => 'https://user:password@attacker.example/terms',
            'v2board.logo' => 'javascript://attacker.example/logo.svg',
        ]);

        $response = (new GuestCommController())->config();
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('', $payload['data']['tos_url']);
        $this->assertSame('', $payload['data']['logo']);
    }

    public function testClientVersionConfigurationOmitsUnsafeDownloadUrls(): void
    {
        config([
            'v2board.windows_download_url' => 'https://user:password@attacker.example/windows.exe',
            'v2board.macos_download_url' => 'ftp://attacker.example/macos.dmg',
            'v2board.android_download_url' => 'https://cdn.example.com/android%250dInjected.apk',
        ]);

        $request = Request::create('/api/v1/client/version', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'test-client']);
        $response = (new AppController())->getVersion($request);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('', $payload['data']['windows_download_url']);
        $this->assertSame('', $payload['data']['macos_download_url']);
        $this->assertSame('', $payload['data']['android_download_url']);
    }

    public function testEtagMatcherHandlesQuotedWeakAndMultipleValues(): void
    {
        $etag = sha1('representation');

        $this->assertTrue(EtagMatcher::matches('"' . $etag . '"', $etag));
        $this->assertTrue(EtagMatcher::matches('W/"' . $etag . '", "other"', $etag));
        $this->assertTrue(EtagMatcher::matches('*', $etag));
        $this->assertTrue(EtagMatcher::matches($etag, $etag));
        $this->assertFalse(EtagMatcher::matches('"not-' . $etag . '"', $etag));
        $this->assertFalse(EtagMatcher::matches(null, $etag));
    }


    public function testApplicationPathUrlUsesOnlyTrustedConfiguration(): void
    {
        config([
            'v2board.app_url' => 'https://App.Example.com/base/',
            'app.url' => 'https://fallback.example.com',
        ]);
        $this->assertSame(
            'https://app.example.com/base/#/plan',
            ConfiguredUrl::applicationPathUrl('/#/plan')
        );

        config([
            'v2board.app_url' => 'javascript://attacker.example',
            'app.url' => '',
        ]);
        $this->assertSame('/#/plan', ConfiguredUrl::applicationPathUrl('/#/plan'));
        $this->assertSame('', ConfiguredUrl::applicationPathUrl('//attacker.example'));
    }

    public function testFrontendRedirectNormalizationRejectsExternalAndEncodedTargets(): void
    {
        $this->assertSame('dashboard', ConfiguredUrl::normalizeFrontendRedirect('dashboard'));
        $this->assertSame('plans', ConfiguredUrl::normalizeFrontendRedirect('plans'));
        $this->assertSame('dashboard', ConfiguredUrl::normalizeFrontendRedirect('https://attacker.example'));
        $this->assertSame('dashboard', ConfiguredUrl::normalizeFrontendRedirect('//attacker.example'));
        $this->assertSame('dashboard', ConfiguredUrl::normalizeFrontendRedirect('javascript:alert(1)'));
        $this->assertSame('dashboard', ConfiguredUrl::normalizeFrontendRedirect('%252F%252Fattacker.example'));
        $this->assertSame('dashboard', ConfiguredUrl::normalizeFrontendRedirect("dashboard\r\nX-Injected: true"));
    }

    public function testQuickLoginUrlUsesTrustedOriginAndEncodedQueryValues(): void
    {
        config([
            'v2board.app_url' => 'https://app.example.com',
            'app.url' => 'https://fallback.example.com',
        ]);
        $controller = new AuthController();
        $method = new \ReflectionMethod($controller, 'buildQuickLoginUrl');
        $method->setAccessible(true);

        $url = $method->invoke($controller, 'token value&=/?', '%252F%252Fattacker.example');

        $this->assertSame(
            'https://app.example.com/#/login?verify=token%20value%26%3D%2F%3F&redirect=dashboard',
            $url
        );
    }

    public function testV2RayTunRejectsUnsafeDynamicHeaders(): void
    {
        config(['v2board.app_name' => "Unsafe\r\nX-Injected: true"]);
        $response = (new v2RayTun([
            'uuid' => 'unused-without-servers',
            'u' => "1\r\nX-Injected: true",
            'd' => 2,
            'transfer_enable' => 3,
            'expired_at' => 4,
        ], [], [
            'profile_update_interval' => "24\r\nX-Injected: true",
            'routing' => "route\r\nX-Injected: true",
            'announce' => "notice\r\nX-Injected: true",
            'announce_url' => 'https://user:password@notice.example.com/',
        ]))->handle();

        $this->assertSame('V2Board', $response->headers->get('profile-title'));
        $this->assertSame('upload=0; download=2; total=3; expire=4', $response->headers->get('subscription-userinfo'));
        $this->assertSame('24', $response->headers->get('profile-update-interval'));
        $this->assertNull($response->headers->get('routing'));
        $this->assertNull($response->headers->get('announce'));
        $this->assertNull($response->headers->get('announce-url'));
        $this->assertNull($response->headers->get('X-Injected'));
    }

    public function testV2RayTunAcceptsBoundedValidOptionalHeaders(): void
    {
        config(['v2board.app_name' => 'V2Board']);
        $response = (new v2RayTun([
            'uuid' => 'unused-without-servers',
            'u' => 1,
            'd' => 2,
            'transfer_enable' => 3,
            'expired_at' => 4,
        ], [], [
            'profile_title_base64' => true,
            'profile_update_interval' => '36',
            'routing' => 'eyJydWxlcyI6W119',
            'announce' => 'Read the service notice',
            'announce_base64' => true,
            'announce_url' => 'https://notice.example.com/path?source=subscription',
            'update_always' => 'true',
        ]))->handle();

        $this->assertSame('base64:' . base64_encode('V2Board'), $response->headers->get('profile-title'));
        $this->assertSame('36', $response->headers->get('profile-update-interval'));
        $this->assertSame('eyJydWxlcyI6W119', $response->headers->get('routing'));
        $this->assertSame('base64:' . base64_encode('Read the service notice'), $response->headers->get('announce'));
        $this->assertSame(
            'https://notice.example.com/path?source=subscription',
            $response->headers->get('announce-url')
        );
        $this->assertSame('true', $response->headers->get('update-always'));
    }


    public function testSubscriptionUrlDoesNotUseRequestHostWhenNoTrustedOriginExists(): void
    {
        config([
            'v2board.show_subscribe_method' => 0,
            'v2board.subscribe_path' => '/api/v1/client/subscribe',
            'v2board.subscribe_url' => 'https://user:password@attacker.example',
            'v2board.app_url' => 'javascript://attacker.example',
            'app.url' => '',
        ]);

        $this->assertSame(
            '/api/v1/client/subscribe?token=token%20value',
            Helper::getSubscribeUrl('token value')
        );
    }

    public function testPublicConfigurationUsesOnlyATrustedApplicationUrl(): void
    {
        config([
            'v2board.app_url' => 'javascript://attacker.example',
            'app.url' => 'https://fallback.example.com/base/'
        ]);

        $response = (new GuestCommController())->config();
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('https://fallback.example.com/base', $payload['data']['app_url']);
    }

    public function testAdminTicketEmailFilterRejectsHeaderInjection(): void
    {
        $request = Request::create('/api/v1/admin/ticket/fetch', 'GET', [
            'email' => "user@example.com\r\nBcc: attacker@example.com",
        ]);

        $this->expectException(ValidationException::class);
        (new AdminTicketController())->fetch($request);
    }
    public function testConfigSaveRejectsANonexistentFrontendTheme(): void
    {
        $request = ConfigSave::create('/api/v1/admin/config/save', 'POST', [
            'frontend_theme' => 'theme-that-does-not-exist',
        ]);
        $request->setContainer($this->app);

        $validator = $this->app['validator']->make(
            $request->all(),
            $request->rules(),
            $request->messages()
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('frontend_theme', $validator->errors()->toArray());
    }

    public function testThemeBackgroundUrlIsNormalizedAndRejectsUnsafeValues(): void
    {
        $service = new ThemeService('default');
        $method = (new ReflectionClass($service))->getMethod('normalizeValue');
        $method->setAccessible(true);
        $definition = ['field_name' => 'background_url', 'field_type' => 'input'];

        $this->assertSame(
            'https://cdn.example.com/image%20one.jpg?size=large#cover',
            $method->invoke($service, $definition, 'https://CDN.Example.com/image%20one.jpg?size=large#cover')
        );

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($service, $definition, 'https://user:password@cdn.example.com/image.jpg');
    }
    public function testCorsNeverTrustsTheIncomingHostHeader(): void
    {
        config([
            'v2board.app_url' => 'https://configured.example.com/base',
            'cors.allowed_origins' => [],
            'v2board.cors_allowed_origins' => [],
            'cors.max_age' => 0,
            'cors.supports_credentials' => false,
        ]);
        $middleware = new CORS();
        $next = static function () {
            return response('unexpected', 200);
        };

        $attackerRequest = Request::create('/api/v1/guest/comm/config', 'OPTIONS', [], [], [], [
            'HTTP_ORIGIN' => 'https://attacker.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'attacker.example',
            'HTTPS' => 'on',
        ]);
        $this->assertSame(403, $middleware->handle($attackerRequest, $next)->getStatusCode());

        $trustedRequest = Request::create('/api/v1/guest/comm/config', 'OPTIONS', [], [], [], [
            'HTTP_ORIGIN' => 'https://configured.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'attacker.example',
            'HTTPS' => 'on',
        ]);
        $trustedResponse = $middleware->handle($trustedRequest, $next);
        $this->assertSame(204, $trustedResponse->getStatusCode());
        $this->assertSame('https://configured.example.com', $trustedResponse->headers->get('Access-Control-Allow-Origin'));
    }

}
