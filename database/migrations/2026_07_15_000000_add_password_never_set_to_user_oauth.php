<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPasswordNeverSetToUserOauth extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('v2_user_oauth')) {
            return;
        }
        if (Schema::hasColumn('v2_user_oauth', 'password_never_set')) {
            return;
        }
        Schema::table('v2_user_oauth', function (Blueprint $table) {
            $table->tinyInteger('password_never_set')->default(0)->comment('OAuth自动注册且用户从未设置真实密码时为1');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('v2_user_oauth')) {
            return;
        }
        if (!Schema::hasColumn('v2_user_oauth', 'password_never_set')) {
            return;
        }
        Schema::table('v2_user_oauth', function (Blueprint $table) {
            $table->dropColumn('password_never_set');
        });
    }
}
