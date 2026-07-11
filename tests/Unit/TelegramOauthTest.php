<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\User\UserController;
use App\Models\User;
use App\Models\UserOauth;
use App\Services\Oauth\OauthProviderRegistry;
use App\Services\Oauth\OauthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class TelegramOauthTest extends TestCase
{
    private $originalV2boardConfig;

    /** @var array */
    private $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalV2boardConfig = config('v2board');
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdUserIds) && Schema::hasTable('v2_user')) {
            if (Schema::hasTable('v2_user_oauth')) {
                UserOauth::whereIn('user_id', $this->createdUserIds)->delete();
            }
            User::whereIn('id', $this->createdUserIds)->delete();
        }
        config(['v2board' => $this->originalV2boardConfig]);
        parent::tearDown();
    }

    public function testTelegramIsRegisteredAsWidgetProvider(): void
    {
        $meta = OauthProviderRegistry::get('telegram');
        $this->assertNotNull($meta);
        $this->assertTrue(OauthProviderRegistry::isTelegramWidget('telegram'));
        $this->assertSame('telegram_login_widget', OauthProviderRegistry::authType('telegram'));
    }

    public function testBotTokenFallsBackToSystemToken(): void
    {
        config([
            'v2board.login_telegram_bot_token' => '',
            'v2board.telegram_bot_token' => '123456:ABCDEF-fallback',
        ]);
        $this->assertSame('123456:ABCDEF-fallback', OauthProviderRegistry::resolveBotToken('telegram'));

        config([
            'v2board.login_telegram_bot_token' => '999:PRIMARY',
            'v2board.telegram_bot_token' => '123456:ABCDEF-fallback',
        ]);
        $this->assertSame('999:PRIMARY', OauthProviderRegistry::resolveBotToken('telegram'));
    }

    public function testEnabledRequiresUsernameAndToken(): void
    {
        config([
            'v2board.login_telegram_enable' => 1,
            'v2board.login_telegram_bot_username' => '',
            'v2board.login_telegram_bot_token' => '',
            'v2board.telegram_bot_token' => '',
        ]);
        $this->assertFalse(OauthProviderRegistry::isEnabled('telegram'));

        config([
            'v2board.login_telegram_enable' => 1,
            'v2board.login_telegram_bot_username' => 'MyLoginBot',
            'v2board.login_telegram_bot_token' => '',
            'v2board.telegram_bot_token' => '1:token',
        ]);
        $this->assertTrue(OauthProviderRegistry::isEnabled('telegram'));
    }

    public function testPublicListExposesBotUsernameWithoutRedirect(): void
    {
        config([
            'v2board.login_telegram_enable' => 1,
            'v2board.login_telegram_bot_username' => '@MyLoginBot',
            'v2board.login_telegram_bot_token' => '1:token',
        ]);
        $list = OauthProviderRegistry::enabledPublicList();
        $telegram = null;
        foreach ($list as $item) {
            if (($item['provider'] ?? '') === 'telegram') {
                $telegram = $item;
                break;
            }
        }
        $this->assertNotNull($telegram);
        $this->assertSame('telegram_login_widget', $telegram['auth_type']);
        $this->assertSame('MyLoginBot', $telegram['bot_username']);
        $this->assertNull($telegram['redirect_url']);
    }

    public function testAdminProviderListReportsFallbackTokenConfigured(): void
    {
        config([
            'v2board.login_telegram_enable' => 0,
            'v2board.login_telegram_bot_username' => 'MyLoginBot',
            'v2board.login_telegram_bot_token' => '',
            'v2board.telegram_bot_token' => 'system:fallback-token',
        ]);
        $list = OauthProviderRegistry::adminProviderList();
        $telegram = null;
        foreach ($list as $item) {
            if (($item['provider'] ?? '') === 'telegram') {
                $telegram = $item;
                break;
            }
        }
        $this->assertNotNull($telegram);
        $this->assertFalse($telegram['bot_token_configured']);
        $this->assertTrue($telegram['bot_token_fallback_configured']);
    }

    /**
     * 后台「清除登录专用 Token」时的可用性规则：
     * - 清除且无系统回退 → 不可用
     * - 清除但有系统回退 → 可用
     * - 未清除且有专用/回退任一 → 可用
     */
    public function testAdminClearLoginTokenTokenReadinessTruthTable(): void
    {
        $this->assertFalse($this->isTelegramTokenUsable(true, false, false, ''));
        $this->assertTrue($this->isTelegramTokenUsable(true, false, true, ''));
        $this->assertTrue($this->isTelegramTokenUsable(true, true, true, ''));
        $this->assertTrue($this->isTelegramTokenUsable(false, true, false, ''));
        $this->assertTrue($this->isTelegramTokenUsable(false, false, true, ''));
        $this->assertFalse($this->isTelegramTokenUsable(false, false, false, ''));
        $this->assertTrue($this->isTelegramTokenUsable(true, false, false, 'new:token'));
    }

    public function testWidgetHashVerificationAcceptsValidPayload(): void
    {
        $botToken = '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11';
        $payload = [
            'id' => '42',
            'first_name' => 'John',
            'username' => 'john_doe',
            'auth_date' => (string)time(),
        ];
        $payload['hash'] = $this->signTelegramPayload($payload, $botToken);

        $service = new OauthService();
        $service->assertTelegramWidgetPayload($payload, $botToken, 86400);
        $this->assertTrue(true);
    }

    public function testWidgetHashVerificationRejectsTamperedPayload(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $botToken = '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11';
        $payload = [
            'id' => '42',
            'first_name' => 'John',
            'auth_date' => (string)time(),
        ];
        $payload['hash'] = $this->signTelegramPayload($payload, $botToken);
        $payload['id'] = '999';

        $service = new OauthService();
        $service->assertTelegramWidgetPayload($payload, $botToken, 86400);
    }

    public function testSyncTelegramIdColumnReleasesOtherUsersOccupation(): void
    {
        if (!Schema::hasTable('v2_user')) {
            $this->markTestSkipped('v2_user table is required');
        }

        $telegramId = (string)(900000000000 + random_int(1, 999999));
        $owner = $this->createTestUser('tg-owner-' . uniqid('', true) . '@test.local', $telegramId);
        $target = $this->createTestUser('tg-target-' . uniqid('', true) . '@test.local', null);

        $service = new OauthService();
        $method = new ReflectionMethod(OauthService::class, 'syncTelegramIdColumn');
        $method->setAccessible(true);
        $method->invoke($service, $target, $telegramId);

        $owner->refresh();
        $target->refresh();
        $this->assertNull($owner->telegram_id);
        $this->assertSame($telegramId, (string)$target->telegram_id);
    }

    public function testOauthUnbindTelegramAlsoClearsTelegramIdColumn(): void
    {
        if (!Schema::hasTable('v2_user') || !Schema::hasTable('v2_user_oauth')) {
            $this->markTestSkipped('oauth tables are required');
        }

        $telegramId = (string)(910000000000 + random_int(1, 999999));
        $user = $this->createTestUser('tg-unbind-' . uniqid('', true) . '@test.local', $telegramId);
        $this->createTelegramOauthBinding($user->id, $telegramId, 0);

        // 再挂一个非 Telegram 绑定，避免「最后一个第三方 + 未设密码」拦截
        $extra = new UserOauth();
        $extra->user_id = $user->id;
        $extra->provider = 'linuxdo';
        $extra->provider_user_id = 'extra-' . uniqid();
        $extra->password_never_set = 0;
        $extra->save();

        (new OauthService())->unbind((int)$user->id, 'telegram');

        $user->refresh();
        $this->assertNull($user->telegram_id);
        $this->assertFalse(
            UserOauth::where('user_id', $user->id)->where('provider', 'telegram')->exists()
        );
    }

    public function testLegacyUnbindTelegramDelegatesToOauthWhenBindingExists(): void
    {
        if (!Schema::hasTable('v2_user') || !Schema::hasTable('v2_user_oauth')) {
            $this->markTestSkipped('oauth tables are required');
        }

        $telegramId = (string)(920000000000 + random_int(1, 999999));
        $user = $this->createTestUser('tg-legacy-' . uniqid('', true) . '@test.local', $telegramId);
        $this->createTelegramOauthBinding($user->id, $telegramId, 0);

        $extra = new UserOauth();
        $extra->user_id = $user->id;
        $extra->provider = 'github';
        $extra->provider_user_id = 'gh-' . uniqid();
        $extra->password_never_set = 0;
        $extra->save();

        $request = Request::create('/api/v1/user/unbindTelegram', 'POST');
        $request->merge(['user' => ['id' => $user->id]]);

        $response = (new UserController())->unbindTelegram($request);
        $this->assertSame(200, $response->getStatusCode());

        $user->refresh();
        $this->assertNull($user->telegram_id);
        $this->assertFalse(
            UserOauth::where('user_id', $user->id)->where('provider', 'telegram')->exists()
        );
    }

    public function testLegacyUnbindTelegramOnlyClearsColumnWithoutOauthBinding(): void
    {
        if (!Schema::hasTable('v2_user')) {
            $this->markTestSkipped('v2_user table is required');
        }

        $telegramId = (string)(930000000000 + random_int(1, 999999));
        $user = $this->createTestUser('tg-column-' . uniqid('', true) . '@test.local', $telegramId);

        $request = Request::create('/api/v1/user/unbindTelegram', 'POST');
        $request->merge(['user' => ['id' => $user->id]]);

        $response = (new UserController())->unbindTelegram($request);
        $this->assertSame(200, $response->getStatusCode());

        $user->refresh();
        $this->assertNull($user->telegram_id);
    }

    /**
     * 与后台 umi.js isProviderReady / validateBeforeSave 中 Telegram Token 判定一致。
     */
    private function isTelegramTokenUsable(
        bool $clearSecrets,
        bool $botTokenConfigured,
        bool $botTokenFallbackConfigured,
        string $enteredToken
    ): bool {
        if ($enteredToken !== '') {
            return true;
        }
        if ($clearSecrets) {
            return $botTokenFallbackConfigured;
        }
        return $botTokenConfigured || $botTokenFallbackConfigured;
    }

    private function createTestUser(string $email, ?string $telegramId): User
    {
        $now = time();
        $user = new User();
        $user->email = $email;
        $user->password = password_hash('test-password', PASSWORD_DEFAULT);
        $user->uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
        $user->token = bin2hex(random_bytes(16));
        $user->telegram_id = $telegramId;
        $user->created_at = $now;
        $user->updated_at = $now;
        $user->save();
        $this->createdUserIds[] = $user->id;
        return $user;
    }

    private function createTelegramOauthBinding(int $userId, string $telegramId, int $passwordNeverSet): UserOauth
    {
        $binding = new UserOauth();
        $binding->user_id = $userId;
        $binding->provider = 'telegram';
        $binding->provider_user_id = $telegramId;
        $binding->provider_username = 'test_tg_user';
        $binding->password_never_set = $passwordNeverSet;
        $binding->save();
        return $binding;
    }

    private function signTelegramPayload(array $payload, string $botToken): string
    {
        $checkData = [];
        foreach ($payload as $key => $value) {
            if ($key === 'hash' || $value === null || $value === '') {
                continue;
            }
            $checkData[(string)$key] = (string)$value;
        }
        ksort($checkData);
        $lines = [];
        foreach ($checkData as $key => $value) {
            $lines[] = $key . '=' . $value;
        }
        $secretKey = hash('sha256', $botToken, true);
        return hash_hmac('sha256', implode("\n", $lines), $secretKey);
    }
}
