<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class UniqueCodeIndexMigrationTest extends TestCase
{
    private const CONNECTION = 'unique_code_index_test';

    private const TABLES = [
        'v2_coupon' => 'v2_coupon_code_unique',
        'v2_giftcard' => 'v2_giftcard_code_unique',
        'v2_invite_code' => 'v2_invite_code_code_unique',
    ];

    private $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.' . self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection(self::CONNECTION);

        foreach (self::TABLES as $tableName => $indexName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->increments('id');
                $table->string('code');
            });
        }

        require_once database_path(
            'migrations/2026_07_20_000002_add_unique_code_indexes.php'
        );
    }

    protected function tearDown(): void
    {
        try {
            DB::purge(self::CONNECTION);
            DB::setDefaultConnection($this->originalConnection);
            config()->set('database.connections.' . self::CONNECTION, null);
        } finally {
            parent::tearDown();
        }
    }

    public function testMigrationAddsUniqueCodeIndexesIdempotently(): void
    {
        $migration = new \AddUniqueCodeIndexes();
        $migration->up();
        $migration->up();

        foreach (self::TABLES as $tableName => $indexName) {
            $this->assertTrue($this->hasSqliteIndex($tableName, $indexName));

            DB::table($tableName)->insert(['code' => 'same-code']);
            try {
                DB::table($tableName)->insert(['code' => 'same-code']);
                $this->fail($tableName . ' accepted a duplicate code');
            } catch (\Throwable $exception) {
                $this->assertStringContainsString('UNIQUE', strtoupper($exception->getMessage()));
            }
        }
    }

    public function testMigrationReportsEveryTableWithDuplicateCodesBeforeChangingSchema(): void
    {
        DB::table('v2_coupon')->insert([
            ['code' => 'duplicate'],
            ['code' => 'duplicate'],
        ]);
        DB::table('v2_invite_code')->insert([
            ['code' => 'invite-duplicate'],
            ['code' => 'invite-duplicate'],
        ]);

        try {
            (new \AddUniqueCodeIndexes())->up();
            $this->fail('Migration should reject duplicate codes');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('v2_coupon（1 组）', $exception->getMessage());
            $this->assertStringContainsString('v2_invite_code（1 组）', $exception->getMessage());
        }

        foreach (self::TABLES as $tableName => $indexName) {
            $this->assertFalse($this->hasSqliteIndex($tableName, $indexName));
        }
    }

    private function hasSqliteIndex(string $tableName, string $indexName): bool
    {
        foreach (DB::select("PRAGMA index_list('{$tableName}')") as $index) {
            if ($index->name === $indexName) {
                return true;
            }
        }

        return false;
    }
}
