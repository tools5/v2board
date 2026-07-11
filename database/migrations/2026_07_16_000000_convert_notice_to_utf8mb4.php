<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConvertNoticeToUtf8mb4 extends Migration
{
    /**
     * Run the migrations.
     *
     * 原 v2_notice 表使用 utf8（最多 3 字节），无法存储 emoji 等 4 字节字符，
     * 提交含 emoji 的公告会触发 MySQL「Incorrect string value」而保存失败。
     * 这里将该表转换为 utf8mb4 以支持完整的 Unicode（含 emoji）。
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('v2_notice')) {
            return;
        }
        // 仅对 MySQL 生效；其它驱动（如 sqlite）无需处理且不支持该语法。
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE `v2_notice` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('v2_notice')) {
            return;
        }
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE `v2_notice` CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci');
    }
}
