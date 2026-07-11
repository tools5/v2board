<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserOauthTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('v2_user_oauth')) {
            return;
        }

        Schema::create('v2_user_oauth', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->comment('本站用户ID');
            $table->string('provider', 32)->comment('平台标识，如 linuxdo');
            $table->string('provider_user_id', 128)->comment('第三方用户唯一ID');
            $table->string('provider_username', 128)->nullable()->comment('第三方用户名');
            $table->string('provider_email', 128)->nullable()->comment('第三方邮箱');
            $table->string('provider_avatar', 512)->nullable()->comment('第三方头像');
            $table->text('access_token')->nullable()->comment('访问令牌（加密存储）');
            $table->text('refresh_token')->nullable()->comment('刷新令牌（加密存储）');
            $table->mediumText('raw')->nullable()->comment('原始用户信息JSON');
            $table->tinyInteger('password_never_set')->default(0)->comment('OAuth自动注册且用户从未设置真实密码时为1');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->unique(['provider', 'provider_user_id'], 'uniq_provider_user');
            $table->index('user_id', 'idx_user_id');
            $table->index('provider', 'idx_provider');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('v2_user_oauth');
    }
}
