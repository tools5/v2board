<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Google 等平台返回的头像 URL 可能超过 512 字符，
 * 原 varchar(512) 会触发 SQLSTATE[22001] Data too long 导致绑定失败。
 * 统一改为 TEXT，去掉长度上限。
 */
class ExpandOauthAvatarColumns extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_user_oauth') && Schema::hasColumn('v2_user_oauth', 'provider_avatar')) {
            DB::statement("ALTER TABLE `v2_user_oauth` MODIFY `provider_avatar` TEXT NULL COMMENT '第三方头像'");
        }

        if (Schema::hasTable('v2_oauth_user') && Schema::hasColumn('v2_oauth_user', 'primary_provider_avatar')) {
            DB::statement("ALTER TABLE `v2_oauth_user` MODIFY `primary_provider_avatar` TEXT NULL COMMENT '第三方头像'");
        }
    }

    public function down()
    {
        // TEXT -> varchar(512) 可能导致超长数据被截断，这里不做回退以免数据丢失。
    }
}
