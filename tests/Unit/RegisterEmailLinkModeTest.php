<?php

namespace Tests\Unit;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegisterEmailLinkModeTest extends TestCase
{
    private $originalV2boardConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalV2boardConfig = config('v2board');
        $this->resetUserTable();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('v2_user');
        Cache::flush();
        config(['v2board' => $this->originalV2boardConfig]);
        parent::tearDown();
    }

    private function resetUserTable(): void
    {
        Schema::dropIfExists('v2_user');
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('uuid', 36)->nullable();
            $table->string('token', 64)->nullable();
            $table->unsignedInteger('invite_user_id')->nullable();
            $table->unsignedBigInteger('transfer_enable')->default(0);
            $table->unsignedInteger('device_limit')->default(0);
            $table->unsignedInteger('plan_id')->default(0);
            $table->unsignedInteger('group_id')->default(0);
            $table->unsignedInteger('expired_at')->default(0);
            $table->unsignedInteger('speed_limit')->default(0);
            $table->unsignedInteger('last_login_at')->default(0);
            $table->boolean('banned')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
            $table->string('password_algo')->nullable();
            $table->string('password_salt')->nullable();
            $table->unsignedInteger('created_at')->nullable();
            $table->unsignedInteger('updated_at')->nullable();
        });
    }

    public function testGuestConfigExposesRegisterEmailMode(): void
    {
        config([
            'v2board.email_verify' => 1,
            'v2board.register_email_mode' => 'link',
        ]);

        $response = $this->getJson('/api/v1/guest/comm/config');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(1, (int)($data['is_email_verify'] ?? 0));
        $this->assertEquals('link', $data['register_email_mode'] ?? null);
    }

    public function testGuestConfigDefaultsRegisterEmailModeToCode(): void
    {
        config([
            'v2board.email_verify' => 1,
            'v2board.register_email_mode' => 'code',
        ]);

        $response = $this->getJson('/api/v1/guest/comm/config');
        $response->assertStatus(200);
        $this->assertEquals('code', $response->json('data.register_email_mode'));
    }

    public function testRegisterBlockedWhenLinkModeEnabled(): void
    {
        config([
            'v2board.email_verify' => 1,
            'v2board.register_email_mode' => 'link',
            'v2board.stop_register' => 0,
            'v2board.invite_force' => 0,
            'v2board.recaptcha_enable' => 0,
            'v2board.email_whitelist_enable' => 0,
            'v2board.register_limit_by_ip_enable' => 0,
        ]);

        $response = $this->postJson('/api/v1/passport/auth/register', [
            'email' => 'linkmode-block-' . time() . '@example.com',
            'password' => 'password123',
            'invite_code' => '',
            'email_code' => '123456',
        ]);

        $this->assertTrue(in_array($response->status(), [500, 422], true));
        $message = (string)($response->json('message') ?? $response->getContent());
        $this->assertStringContainsString('邮箱', $message);
    }

    public function testSendRegisterLinkRequiresLinkMode(): void
    {
        config([
            'v2board.email_verify' => 1,
            'v2board.register_email_mode' => 'code',
            'v2board.stop_register' => 0,
            'v2board.recaptcha_enable' => 0,
        ]);

        $response = $this->postJson('/api/v1/passport/auth/sendRegisterLink', [
            'email' => 'someone@example.com',
        ]);

        $this->assertEquals(500, $response->status());
        $this->assertStringContainsString('未开启', (string)$response->json('message'));
    }

    public function testCheckRegisterLinkAndCompleteFlowWithCacheToken(): void
    {
        config([
            'v2board.email_verify' => 1,
            'v2board.register_email_mode' => 'link',
            'v2board.stop_register' => 0,
            'v2board.invite_force' => 0,
            'v2board.recaptcha_enable' => 0,
            'v2board.email_whitelist_enable' => 0,
            'v2board.register_limit_by_ip_enable' => 0,
            'v2board.try_out_plan_id' => 0,
        ]);

        $email = 'link-reg-' . time() . '-' . mt_rand(1000, 9999) . '@example.com';
        $token = Helper::guid();
        Cache::put(CacheKey::get('REGISTER_LINK_TOKEN', $token), [
            'email' => $email,
            'invite_code' => '',
            'created_at' => time(),
        ], 1800);

        $check = $this->getJson('/api/v1/passport/auth/checkRegisterLink?token=' . urlencode($token));
        $check->assertStatus(200);
        $this->assertEquals($email, $check->json('data.email'));

        $complete = $this->postJson('/api/v1/passport/auth/registerWithLink', [
            'token' => $token,
            'password' => 'password12345',
        ]);

        $complete->assertStatus(200);
        $this->assertNotEmpty($complete->json('data.auth_data'));
        $this->assertNull(Cache::get(CacheKey::get('REGISTER_LINK_TOKEN', $token)));

        // token 一次性，再次完成应失败
        $again = $this->postJson('/api/v1/passport/auth/registerWithLink', [
            'token' => $token,
            'password' => 'password12345',
        ]);
        $this->assertEquals(500, $again->status());

        // 清理用户
        \App\Models\User::where('email', $email)->delete();
    }

    public function testCacheKeyRegisterLinkExists(): void
    {
        $key = CacheKey::get('REGISTER_LINK_TOKEN', 'abc');
        $this->assertEquals('REGISTER_LINK_TOKEN_abc', $key);
        $key2 = CacheKey::get('LAST_SEND_REGISTER_LINK_TIMESTAMP', 'a@b.com');
        $this->assertStringContainsString('LAST_SEND_REGISTER_LINK_TIMESTAMP', $key2);
    }

    public function testForgetBlockedWhenLinkModeEnabled(): void
    {
        config([
            'v2board.register_email_mode' => 'link',
            'v2board.recaptcha_enable' => 0,
        ]);

        $response = $this->postJson('/api/v1/passport/auth/forget', [
            'email' => 'someone@example.com',
            'password' => 'password12345',
            'email_code' => '123456',
        ]);

        $this->assertEquals(500, $response->status());
        $this->assertStringContainsString('邮箱', (string)$response->json('message'));
    }

    public function testPasswordResetLinkFlow(): void
    {
        config([
            'v2board.register_email_mode' => 'link',
            'v2board.recaptcha_enable' => 0,
            'v2board.email_whitelist_enable' => 0,
        ]);

        $email = 'reset-link-' . time() . '-' . mt_rand(1000, 9999) . '@example.com';
        $user = new \App\Models\User();
        $user->email = $email;
        $user->password = password_hash('oldpassword123', PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->save();

        $token = Helper::guid();
        Cache::put(CacheKey::get('PASSWORD_RESET_LINK_TOKEN', $token), [
            'email' => $email,
            'user_id' => $user->id,
            'created_at' => time(),
        ], 1800);

        $check = $this->getJson('/api/v1/passport/auth/checkPasswordResetLink?token=' . urlencode($token));
        $check->assertStatus(200);
        $this->assertEquals($email, $check->json('data.email'));

        $complete = $this->postJson('/api/v1/passport/auth/resetPasswordWithLink', [
            'token' => $token,
            'password' => 'newpassword12345',
        ]);
        $complete->assertStatus(200);
        $this->assertTrue((bool)$complete->json('data'));
        $this->assertNull(Cache::get(CacheKey::get('PASSWORD_RESET_LINK_TOKEN', $token)));

        $user->refresh();
        $this->assertTrue(password_verify('newpassword12345', $user->password));

        $again = $this->postJson('/api/v1/passport/auth/resetPasswordWithLink', [
            'token' => $token,
            'password' => 'anotherpassword12',
        ]);
        $this->assertEquals(500, $again->status());

        \App\Models\User::where('id', $user->id)->delete();
    }

    public function testCacheKeyPasswordResetLinkExists(): void
    {
        $key = CacheKey::get('PASSWORD_RESET_LINK_TOKEN', 'xyz');
        $this->assertEquals('PASSWORD_RESET_LINK_TOKEN_xyz', $key);
    }
}
