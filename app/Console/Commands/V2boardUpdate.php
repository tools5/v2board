<?php

namespace App\Console\Commands;

use App\Support\SqlFileRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Throwable;

class V2boardUpdate extends Command
{
    private const IGNORABLE_MYSQL_CODES = [1050, 1060, 1061, 1068, 1091];

    private const REMOVED_LEGACY_TABLES = [
        'v2_server',
        'v2_server_log',
        'v2_server_stat',
        'v2_server_v2ray',
        'v2_stat_order',
        'v2_tutorial',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'v2board 更新';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $lockAcquired = false;

        try {
            DB::connection()->getPdo();
            $lockAcquired = $this->acquireUpdateLock();
            if (!$lockAcquired) {
                throw new RuntimeException('另一个更新任务正在运行，请稍后重试');
            }

            $this->ensurePatchTableExists();
            $path = base_path('database/update.sql');
            $checksum = $this->checksum($path);

            if (DB::table('v2_sql_patch')->where('checksum', $checksum)->exists()) {
                $this->info('数据库 SQL 补丁已应用，继续检查 Laravel 迁移...');
            } else {
                $this->applySqlPatch($path, $checksum);
            }

            $this->runArtisanCommand('migrate', ['--force' => true], 'Laravel 数据库迁移失败');
            $this->runArtisanCommand('config:cache', [], '配置缓存生成失败');
            $this->runArtisanCommand('horizon:terminate', [], '队列服务重启失败');

            $this->info('更新完毕，数据库迁移和配置缓存均已完成，队列服务已重启。');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('更新失败：' . $exception->getMessage());
            return self::FAILURE;
        } finally {
            if ($lockAcquired) {
                $this->releaseUpdateLock();
            }
        }
    }

    private function applySqlPatch(string $path, string $checksum): void
    {
        $statements = SqlFileRunner::statementsFromFile($path);
        if ($statements === []) {
            throw new RuntimeException("数据库更新文件没有可执行 SQL：{$path}");
        }

        $this->info('正在应用数据库 SQL 补丁，请稍等...');
        foreach ($statements as $index => $statement) {
            try {
                DB::unprepared($statement);
            } catch (Throwable $exception) {
                $mysqlCode = $this->mysqlErrorCode($exception);
                if ($this->isIgnorableLegacyError($mysqlCode, $statement)) {
                    $this->warn(sprintf(
                        '跳过已应用的历史变更（SQL #%d，MySQL %d）',
                        $index + 1,
                        $mysqlCode
                    ));
                    continue;
                }

                throw new RuntimeException(
                    sprintf('数据库更新第 %d 条 SQL 失败：%s', $index + 1, $this->rootCauseMessage($exception)),
                    0,
                    $exception
                );
            }
        }

        DB::table('v2_sql_patch')->insert([
            'checksum' => $checksum,
            'filename' => basename($path),
            'applied_at' => time(),
        ]);
        $this->info('数据库 SQL 补丁应用完成');
    }

    private function isIgnorableLegacyError(int $mysqlCode, string $statement): bool
    {
        if (in_array($mysqlCode, self::IGNORABLE_MYSQL_CODES, true)) {
            return true;
        }
        if ($mysqlCode !== 1146) {
            return false;
        }

        $table = $this->targetTable($statement);
        return $table !== null && in_array($table, self::REMOVED_LEGACY_TABLES, true);
    }

    private function targetTable(string $statement): ?string
    {
        if (preg_match(
            '/\b(?:ALTER\s+TABLE|UPDATE|TRUNCATE\s+TABLE)\s+`?([a-zA-Z0-9_]+)`?/i',
            $statement,
            $matches
        ) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function mysqlErrorCode(Throwable $exception): int
    {
        do {
            if ($exception instanceof PDOException
                && isset($exception->errorInfo[1])
                && is_numeric($exception->errorInfo[1])) {
                return (int)$exception->errorInfo[1];
            }
            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return 0;
    }

    private function ensurePatchTableExists(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `v2_sql_patch` (
    `checksum` char(64) NOT NULL,
    `filename` varchar(255) NOT NULL,
    `applied_at` int(10) unsigned NOT NULL,
    PRIMARY KEY (`checksum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );
    }

    private function checksum(string $path): string
    {
        if (!is_readable($path)) {
            throw new RuntimeException("数据库更新文件不可读：{$path}");
        }

        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new RuntimeException("无法计算数据库更新文件校验和：{$path}");
        }

        return $checksum;
    }

    private function acquireUpdateLock(): bool
    {
        $result = DB::selectOne('SELECT GET_LOCK(?, 30) AS acquired', [$this->lockName()]);
        return isset($result->acquired) && (int)$result->acquired === 1;
    }

    private function releaseUpdateLock(): void
    {
        try {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->lockName()]);
        } catch (Throwable $exception) {
            $this->warn('更新锁释放失败，数据库连接关闭后会自动释放：' . $this->rootCauseMessage($exception));
        }
    }

    private function lockName(): string
    {
        return 'v2board:update:' . sha1((string)config('database.connections.mysql.database'));
    }

    private function runArtisanCommand(string $command, array $arguments, string $failureMessage): void
    {
        $status = Artisan::call($command, $arguments);
        if ($status !== self::SUCCESS) {
            $output = trim(Artisan::output());
            throw new RuntimeException($output === '' ? $failureMessage : "{$failureMessage}：{$output}");
        }
    }

    private function rootCauseMessage(Throwable $exception): string
    {
        while ($exception->getPrevious() instanceof Throwable) {
            $exception = $exception->getPrevious();
        }

        return $exception->getMessage();
    }
}
