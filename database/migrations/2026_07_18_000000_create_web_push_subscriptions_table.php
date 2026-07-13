<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWebPushSubscriptionsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_web_push_subscription')) {
            return;
        }

        Schema::create('v2_web_push_subscription', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 32)->default('aes128gcm');
            $table->string('user_agent', 500)->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_web_push_subscription');
    }
}
