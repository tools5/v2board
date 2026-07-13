<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class EnhanceWebPushSubscriptionsAndMessages extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_web_push_subscription')) {
            Schema::table('v2_web_push_subscription', function (Blueprint $table) {
                if (!Schema::hasColumn('v2_web_push_subscription', 'device_name')) {
                    $table->string('device_name', 120)->nullable()->after('user_agent');
                }
                if (!Schema::hasColumn('v2_web_push_subscription', 'last_used_at')) {
                    $table->unsignedInteger('last_used_at')->nullable()->after('device_name');
                }
            });
        }

        if (!Schema::hasTable('v2_web_push_message')) {
            Schema::create('v2_web_push_message', function (Blueprint $table) {
                $table->increments('id');
                $table->string('title', 255);
                $table->text('body')->nullable();
                $table->string('icon', 512)->nullable();
                $table->string('image', 512)->nullable();
                $table->string('url', 512)->nullable();
                $table->string('tag', 120)->nullable();
                $table->text('actions')->nullable();
                $table->string('target_type', 32)->default('all');
                $table->unsignedInteger('target_user_id')->nullable()->index();
                $table->text('target_filter')->nullable();
                $table->unsignedInteger('admin_id')->nullable();
                $table->string('status', 32)->default('queued');
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('sent')->default(0);
                $table->unsignedInteger('failed')->default(0);
                $table->unsignedInteger('expired')->default(0);
                $table->text('error_message')->nullable();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('v2_web_push_message');

        if (Schema::hasTable('v2_web_push_subscription')) {
            Schema::table('v2_web_push_subscription', function (Blueprint $table) {
                if (Schema::hasColumn('v2_web_push_subscription', 'last_used_at')) {
                    $table->dropColumn('last_used_at');
                }
                if (Schema::hasColumn('v2_web_push_subscription', 'device_name')) {
                    $table->dropColumn('device_name');
                }
            });
        }
    }
}
