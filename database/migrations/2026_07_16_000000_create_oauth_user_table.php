<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OAuth 自动注册用户独立表：
 * - 后台「OAuth 管理」只操作此表
 * - 后台「用户管理」不再展示这些用户
 * - user_id 关联 v2_user 仅为订阅/JWT/节点/订单等系统运行时需要（不在用户管理列表出现）
 */
class CreateOauthUserTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_oauth_user')) {
            Schema::create('v2_oauth_user', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id')->unique()->comment('关联系统运行账号 ID（v2_user），仅供订阅/鉴权/订单使用');
                $table->string('email', 64)->comment('本站邮箱（可占位）');
                $table->string('primary_provider', 32)->comment('首次注册平台，如 linuxdo/telegram');
                $table->string('primary_provider_user_id', 128)->comment('平台用户唯一 ID（LinuxDo=论坛ID，Telegram=TGID）');
                $table->string('primary_provider_username', 128)->nullable()->comment('第三方用户名');
                $table->string('primary_provider_email', 128)->nullable()->comment('第三方邮箱');
                $table->string('primary_provider_avatar', 512)->nullable()->comment('第三方头像');
                $table->tinyInteger('password_never_set')->default(1)->comment('是否尚未设置真实登录密码');
                $table->text('remarks')->nullable()->comment('管理员备注（OAuth 侧）');
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->unique(['primary_provider', 'primary_provider_user_id'], 'uniq_oauth_user_provider');
                $table->index('email');
                $table->index('primary_provider');
            });
        }

        // 回填：历史 OAuth 自动注册用户（注册时即产生绑定，或仍标记未设密码 / 占位邮箱）
        if (Schema::hasTable('v2_user_oauth') && Schema::hasTable('v2_user')) {
            $bindings = DB::table('v2_user_oauth as o')
                ->join('v2_user as u', 'u.id', '=', 'o.user_id')
                ->orderBy('o.id', 'asc')
                ->select([
                    'o.*',
                    'u.email as user_email',
                    'u.created_at as user_created_at',
                ])
                ->get();

            $seenUserIds = [];
            $now = time();
            foreach ($bindings as $binding) {
                $userId = (int)$binding->user_id;
                if (isset($seenUserIds[$userId])) {
                    continue;
                }
                if (DB::table('v2_oauth_user')->where('user_id', $userId)->exists()) {
                    $seenUserIds[$userId] = true;
                    continue;
                }

                $isPlaceholderEmail = (bool)preg_match('/@oauth\.local$/i', (string)$binding->user_email);
                $passwordNeverSet = (int)($binding->password_never_set ?? 0) === 1;
                $createdClose = abs((int)$binding->created_at - (int)$binding->user_created_at) <= 120;
                if (!$isPlaceholderEmail && !$passwordNeverSet && !$createdClose) {
                    // 邮箱用户后来绑定第三方：不属于 OAuth 独立用户表
                    continue;
                }

                DB::table('v2_oauth_user')->insert([
                    'user_id' => $userId,
                    'email' => $binding->user_email,
                    'primary_provider' => $binding->provider,
                    'primary_provider_user_id' => $binding->provider_user_id,
                    'primary_provider_username' => $binding->provider_username,
                    'primary_provider_email' => $binding->provider_email,
                    'primary_provider_avatar' => $binding->provider_avatar,
                    'password_never_set' => $passwordNeverSet ? 1 : 0,
                    'remarks' => null,
                    'created_at' => (int)$binding->created_at ?: $now,
                    'updated_at' => $now,
                ]);
                $seenUserIds[$userId] = true;
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('v2_oauth_user');
    }
}
