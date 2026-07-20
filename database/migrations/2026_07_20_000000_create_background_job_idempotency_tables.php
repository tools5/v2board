<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBackgroundJobIdempotencyTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('v2_job_idempotency')) {
            Schema::create('v2_job_idempotency', function (Blueprint $table) {
                $table->string('scope', 64);
                $table->string('job_id', 64);
                $table->unsignedInteger('created_at');
                $table->primary(['scope', 'job_id']);
                $table->index('created_at');
            });
        }

        if (!Schema::hasTable('v2_sql_patch')) {
            Schema::create('v2_sql_patch', function (Blueprint $table) {
                $table->char('checksum', 64)->primary();
                $table->string('filename', 255);
                $table->unsignedInteger('applied_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('v2_sql_patch');
        Schema::dropIfExists('v2_job_idempotency');
    }
}
