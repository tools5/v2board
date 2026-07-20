<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\ConfigController as AdminConfigController;
use App\Http\Controllers\V2\Server\ServerController;
use App\Http\Requests\Admin\ConfigSave;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NodeAuthenticationTest extends TestCase
{
    public function testLegacyNodeTokenIsAcceptedWhenCompatibilitySettingIsMissing(): void
    {
        $this->configureNodeAuthentication('legacy-shared-token');

        $this->assertTrue(Helper::verifyNodeToken('legacy-shared-token', 7, 'v2node'));
    }

    public function testLegacyNodeTokenCanBeExplicitlyDisabled(): void
    {
        $this->configureNodeAuthentication('legacy-shared-token', false);

        $this->assertFalse(Helper::verifyNodeToken('legacy-shared-token', 7, 'v2node'));
    }

    public function testPerNodeHmacTokenWorksWhenLegacyCompatibilityIsDisabled(): void
    {
        $this->configureNodeAuthentication('legacy-shared-token', false);
        $token = Helper::getNodeToken(7, 'v2node');

        $this->assertNotSame('', $token);
        $this->assertTrue(Helper::verifyNodeToken($token, 7, 'v2node'));
        $this->assertFalse(Helper::verifyNodeToken($token, 8, 'v2node'));
    }

    public function testV2nodeManagementReturnsInstallCommandWithoutSeparateToken(): void
    {
        Schema::create('v2_server_v2node', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('sort')->nullable();
            $table->text('padding_scheme')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        try {
            DB::table('v2_server_v2node')->insert([
                'id' => 7,
                'name' => 'Test node',
                'sort' => 1,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
            config([
                'v2board.server_token' => 'legacy-shared-token',
                'v2board.server_api_url' => 'https://panel.example.com',
            ]);

            $node = (new ServerService())->getAllV2node()[0];
            $expectedToken = Helper::getNodeToken(7, 'v2node');

            $this->assertArrayNotHasKey('node_token', $node);
            $this->assertStringContainsString('--node-id 7', $node['install_command']);
            $this->assertStringContainsString($expectedToken, $node['install_command']);
        } finally {
            Schema::dropIfExists('v2_server_v2node');
        }
    }

    public function testAuthenticationFailuresDoNotTerminateThePhpProcess(): void
    {
        $this->configureNodeAuthentication('legacy-shared-token', false);
        $serverService = $this->createMock(ServerService::class);
        $serverService->expects($this->never())->method('getServer');
        $controller = new ServerController($serverService);

        $invalidTokenResponse = $controller->config(Request::create(
            '/api/v2/server/config',
            'GET',
            ['node_id' => 7, 'token' => 'invalid-token']
        ));
        $missingTokenResponse = $controller->config(Request::create(
            '/api/v2/server/config',
            'GET',
            ['node_id' => 7]
        ));

        $this->assertSame(200, $invalidTokenResponse->getStatusCode());
        $this->assertSame(
            ['status' => 'fail', 'message' => 'token is error'],
            json_decode($invalidTokenResponse->getContent(), true, 512, JSON_THROW_ON_ERROR)
        );
        $this->assertSame(200, $missingTokenResponse->getStatusCode());
        $this->assertSame(
            ['status' => 'fail', 'message' => 'token is null'],
            json_decode($missingTokenResponse->getContent(), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testMissingNodeReturnsProtocolFailureWithoutExiting(): void
    {
        $this->configureNodeAuthentication('legacy-shared-token', false);
        $token = Helper::getNodeToken(7, 'v2node');
        $serverService = $this->createMock(ServerService::class);
        $serverService->expects($this->once())
            ->method('getServer')
            ->with(7, 'v2node')
            ->willReturn(null);
        $controller = new ServerController($serverService);

        $response = $controller->config(Request::create(
            '/api/v2/server/config',
            'GET',
            ['node_id' => 7, 'token' => $token]
        ));

        $this->assertSame(
            ['status' => 'fail', 'message' => 'server is not exist'],
            json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testAdminConfigurationExposesAndValidatesLegacyCompatibilitySwitch(): void
    {
        $this->configureNodeAuthentication('legacy-shared-token');
        $response = (new AdminConfigController())->fetch(
            Request::create('/api/v1/admin/config/fetch', 'GET', ['key' => 'server'])
        );
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $payload['data']['server']['server_token_allow_legacy']);

        foreach ([0, 1] as $value) {
            $validator = $this->app['validator']->make(
                ['server_token_allow_legacy' => $value],
                ConfigSave::allRules()
            );
            $this->assertFalse($validator->fails());
        }

        $validator = $this->app['validator']->make(
            ['server_token_allow_legacy' => 2],
            ConfigSave::allRules()
        );
        $this->assertTrue($validator->fails());
    }

    private function configureNodeAuthentication(string $token, ?bool $allowLegacy = null): void
    {
        $settings = (array)config('v2board', []);
        $settings['server_token'] = $token;
        unset($settings['server_token_allow_legacy']);
        if ($allowLegacy !== null) {
            $settings['server_token_allow_legacy'] = $allowLegacy;
        }

        config(['v2board' => $settings]);
    }
}
