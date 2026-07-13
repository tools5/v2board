<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWebPushSentAtToNotices extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_notice') || Schema::hasColumn('v2_notice', 'web_push_sent_at')) {
            return;
        }

        Schema::table('v2_notice', function (Blueprint $table) {
            $table->unsignedInteger('web_push_sent_at')->nullable()->after('show');
        });
    }

    public function down()
    {
        if (!Schema::hasTable('v2_notice') || !Schema::hasColumn('v2_notice', 'web_push_sent_at')) {
            return;
        }

        Schema::table('v2_notice', function (Blueprint $table) {
            $table->dropColumn('web_push_sent_at');
        });
    }
}
