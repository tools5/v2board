<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2node 增加纯备注字段 ip_remark：给管理员记录节点机器的真实 IP 用。
 *
 * 背景：listen_ip 会原样下发到节点端做监听绑定，之前把真实 IP 备注进
 * listen_ip 会导致机器上不存在该 IP 时 bind 失败、节点离线。
 * ip_remark 只存后台展示，任何下发/订阅链路都不读它。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('v2_server_v2node', 'ip_remark')) {
            return;
        }
        Schema::table('v2_server_v2node', function (Blueprint $table) {
            $table->string('ip_remark')->nullable()->after('listen_ip')->comment('备注IP，仅后台展示');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('v2_server_v2node', 'ip_remark')) {
            return;
        }
        Schema::table('v2_server_v2node', function (Blueprint $table) {
            $table->dropColumn('ip_remark');
        });
    }
};
