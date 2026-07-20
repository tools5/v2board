<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FixStatUserRecordTypeUniqueIndex extends Migration
{
    private const INDEX_NAME = 'server_rate_user_id_record_at';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->replaceIndex([
            'server_rate',
            'user_id',
            'record_type',
            'record_at'
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->replaceIndex([
            'server_rate',
            'user_id',
            'record_at'
        ]);
    }

    private function replaceIndex(array $columns): void
    {
        if (!Schema::hasTable('v2_stat_user')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            $currentColumns = array_map(function ($row) {
                return $row->COLUMN_NAME;
            }, DB::select(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? '
                . 'ORDER BY SEQ_IN_INDEX',
                ['v2_stat_user', self::INDEX_NAME]
            ));

            if ($currentColumns === $columns) {
                return;
            }
            if ($currentColumns) {
                DB::statement('ALTER TABLE `v2_stat_user` DROP INDEX `' . self::INDEX_NAME . '`');
            }

            DB::statement(
                'ALTER TABLE `v2_stat_user` ADD UNIQUE `' . self::INDEX_NAME . '` (`'
                . implode('`,`', $columns)
                . '`)'
            );
            return;
        }

        if ($driver === 'sqlite') {
            $currentColumns = array_map(function ($row) {
                return $row->name;
            }, DB::select("PRAGMA index_info('" . self::INDEX_NAME . "')"));

            if ($currentColumns === $columns) {
                return;
            }
            if ($currentColumns) {
                DB::statement('DROP INDEX "' . self::INDEX_NAME . '"');
            }

            DB::statement(
                'CREATE UNIQUE INDEX "' . self::INDEX_NAME . '" ON "v2_stat_user" ("'
                . implode('","', $columns)
                . '")'
            );
        }
    }
}
