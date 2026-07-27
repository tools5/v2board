<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单增加下单环境信息：客户端 IP 与 User-Agent，供后台订单详情排查用。
 * 只在用户侧下单时记录；管理员手动分配的订单这两列为空。
 * 地理位置不落库——按 IP 实时查询并缓存，避免历史数据随 IP 库老化。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_order', 'created_ip')) {
                // 45 足够放 IPv6 完整字面量
                $table->string('created_ip', 45)->nullable()->after('callback_no')->comment('下单IP');
            }
            if (!Schema::hasColumn('v2_order', 'created_user_agent')) {
                $table->string('created_user_agent', 512)->nullable()->after('created_ip')->comment('下单User-Agent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            if (Schema::hasColumn('v2_order', 'created_user_agent')) {
                $table->dropColumn('created_user_agent');
            }
            if (Schema::hasColumn('v2_order', 'created_ip')) {
                $table->dropColumn('created_ip');
            }
        });
    }
};
