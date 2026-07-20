<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddUniqueCodeIndexes extends Migration
{
    private const INDEXES = [
        'v2_coupon' => 'v2_coupon_code_unique',
        'v2_giftcard' => 'v2_giftcard_code_unique',
        'v2_invite_code' => 'v2_invite_code_code_unique',
    ];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->assertNoDuplicateCodes();

        foreach (self::INDEXES as $tableName => $indexName) {
            if (!$this->canManage($tableName) || $this->hasIndex($tableName, $indexName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->unique('code', $indexName);
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
        foreach (array_reverse(self::INDEXES, true) as $tableName => $indexName) {
            if (!$this->canManage($tableName) || !$this->hasIndex($tableName, $indexName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->dropUnique($indexName);
            });
        }
    }

    private function assertNoDuplicateCodes(): void
    {
        $problems = [];
        foreach (self::INDEXES as $tableName => $indexName) {
            if (!$this->canManage($tableName) || $this->hasIndex($tableName, $indexName)) {
                continue;
            }

            $duplicateRow = DB::selectOne(
                'SELECT COUNT(*) AS aggregate FROM ('
                . 'SELECT 1 FROM `' . $tableName . '` GROUP BY `code` HAVING COUNT(*) > 1'
                . ') AS `duplicate_codes`'
            );
            $duplicateGroups = isset($duplicateRow->aggregate)
                ? (int) $duplicateRow->aggregate
                : 0;
            if ($duplicateGroups > 0) {
                $problems[] = $tableName . '（' . $duplicateGroups . ' 组）';
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                '无法创建 code 唯一索引，以下表存在重复码：'
                . implode('、', $problems)
                . '。请先合并或重命名重复记录后重新运行迁移。'
            );
        }
    }

    private function canManage(string $tableName): bool
    {
        return Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'code');
    }

    private function hasIndex(string $tableName, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            return DB::table('information_schema.statistics')
                ->whereRaw('table_schema = DATABASE()')
                ->where('table_name', $tableName)
                ->where('index_name', $indexName)
                ->exists();
        }

        if ($driver === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('" . str_replace("'", "''", $tableName) . "')") as $index) {
                if (isset($index->name) && $index->name === $indexName) {
                    return true;
                }
            }
        }

        return false;
    }
}
