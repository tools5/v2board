<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StatUserIndexMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('v2_stat_user');
        Schema::create('v2_stat_user', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('server_rate', 10, 2);
            $table->integer('user_id');
            $table->string('record_type', 2);
            $table->integer('record_at');
        });
        DB::statement(
            'CREATE UNIQUE INDEX "server_rate_user_id_record_at" '
            . 'ON "v2_stat_user" ("server_rate", "user_id", "record_at")'
        );

        require_once database_path(
            'migrations/2026_07_20_000001_fix_stat_user_record_type_unique_index.php'
        );
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('v2_stat_user');
        parent::tearDown();
    }

    public function testMigrationAddsRecordTypeToTheUniqueIndex(): void
    {
        $migration = new \FixStatUserRecordTypeUniqueIndex();
        $migration->up();
        $migration->up();

        $columns = array_map(function ($row) {
            return $row->name;
        }, DB::select("PRAGMA index_info('server_rate_user_id_record_at')"));

        $this->assertSame([
            'server_rate',
            'user_id',
            'record_type',
            'record_at'
        ], $columns);
    }
}
