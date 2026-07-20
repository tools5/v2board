<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\SqlFileRunner;
use App\Utils\Helper;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class V2boardInstall extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'v2board 安装';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $environmentCreated = false;
        $environmentPath = app()->environmentFilePath();

        try {
            $this->renderBanner();

            if (File::exists($environmentPath)) {
                $securePath = config(
                    'v2board.secure_path',
                    config('v2board.frontend_admin_path', hash('crc32b', (string)config('app.key')))
                );
                $this->info("访问 http(s)://你的站点/{$securePath} 进入管理面板，你可以在用户中心修改你的密码。");
                throw new RuntimeException('如需重新安装，请先删除项目目录下的 .env 文件');
            }

            $settings = [
                'APP_KEY' => 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC')),
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $this->ask('请输入数据库地址（默认:localhost）', 'localhost'),
                'DB_PORT' => $this->askDatabasePort(),
                'DB_DATABASE' => $this->askRequired('请输入数据库名'),
                'DB_USERNAME' => $this->askRequired('请输入数据库用户名'),
                'DB_PASSWORD' => $this->ask('请输入数据库密码', ''),
            ];

            $this->writeEnvironmentFile($settings);
            $environmentCreated = true;
            Artisan::call('config:clear');
            $this->applyRuntimeConfiguration($settings);

            try {
                DB::purge('mysql');
                DB::connection('mysql')->getPdo();
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    '数据库连接失败：' . $this->rootCauseMessage($exception),
                    0,
                    $exception
                );
            }

            $this->info('正在导入数据库，请稍等...');
            $this->importSqlFile(base_path('database/install.sql'));
            $this->info('数据库基础结构导入完成');

            $migrationStatus = Artisan::call('migrate', ['--force' => true]);
            if ($migrationStatus !== self::SUCCESS) {
                throw new RuntimeException('Laravel 数据库迁移失败：' . trim(Artisan::output()));
            }
            $this->recordCurrentUpdatePatch();

            $email = $this->askValidEmail();
            $password = Helper::guid(false);
            if (!$this->registerAdmin($email, $password)) {
                throw new RuntimeException('管理员账号注册失败，请重试');
            }

            $cacheStatus = Artisan::call('config:cache');
            if ($cacheStatus !== self::SUCCESS) {
                throw new RuntimeException('配置缓存生成失败：' . trim(Artisan::output()));
            }

            $this->info('一切就绪');
            $this->info("管理员邮箱：{$email}");
            $this->info("管理员密码：{$password}");

            $defaultSecurePath = hash('crc32b', $settings['APP_KEY']);
            $this->info("访问 http(s)://你的站点/{$defaultSecurePath} 进入管理面板，你可以在用户中心修改你的密码。");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($environmentCreated) {
                File::delete($environmentPath);
                try {
                    Artisan::call('config:clear');
                } catch (Throwable $ignored) {
                }
            }

            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function renderBanner(): void
    {
        $this->info('__     ______  ____                      _  ');
        $this->info('\ \   / /___ \| __ )  ___   __ _ _ __ __| | ');
        $this->info(' \ \ / /  __) |  _ \ / _ \ / _` | \'__\/ _` | ');
        $this->info('  \ V /  / __/| |_) | (_) | (_| | | | (_| | ');
        $this->info('   \_/  |_____|____/ \___/ \__,_|_|  \__,_| ');
    }

    private function askRequired(string $question): string
    {
        do {
            $value = trim((string)$this->ask($question));
            if ($value === '') {
                $this->warn('该项不能为空');
            }
        } while ($value === '');

        return $value;
    }

    private function askValidEmail(): string
    {
        do {
            $email = trim((string)$this->ask('请输入管理员邮箱?'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->warn('请输入有效的邮箱地址');
                $email = '';
            }
        } while ($email === '');

        return $email;
    }

    private function askDatabasePort(): string
    {
        do {
            $port = trim((string) $this->ask('请输入数据库端口（默认:3306）', '3306'));
            $valid = preg_match('/\A[0-9]+\z/D', $port) === 1
                && (int) $port >= 1
                && (int) $port <= 65535;
            if (!$valid) {
                $this->warn('数据库端口必须是 1 到 65535 的整数');
            }
        } while (!$valid);

        return (string) (int) $port;
    }

    private function importSqlFile(string $path): void
    {
        $statements = SqlFileRunner::statementsFromFile($path);
        if ($statements === []) {
            throw new RuntimeException("数据库文件没有可执行 SQL：{$path}");
        }

        foreach ($statements as $index => $statement) {
            try {
                DB::connection('mysql')->unprepared($statement);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    sprintf('导入数据库第 %d 条 SQL 失败：%s', $index + 1, $this->rootCauseMessage($exception)),
                    0,
                    $exception
                );
            }
        }
    }

    private function recordCurrentUpdatePatch(): void
    {
        $path = base_path('database/update.sql');
        if (!is_readable($path)) {
            throw new RuntimeException("更新 SQL 文件不可读：{$path}");
        }

        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new RuntimeException("无法计算更新 SQL 校验和：{$path}");
        }

        DB::table('v2_sql_patch')->updateOrInsert(
            ['checksum' => $checksum],
            ['filename' => basename($path), 'applied_at' => time()]
        );
    }

    private function registerAdmin(string $email, string $password): bool
    {
        if (strlen($password) < 8) {
            throw new RuntimeException('管理员密码长度最小为 8 位字符');
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        if ($hashedPassword === false) {
            throw new RuntimeException('管理员密码加密失败');
        }

        $user = new User();
        $user->email = $email;
        $user->password = $hashedPassword;
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->is_admin = 1;

        return $user->save();
    }

    private function writeEnvironmentFile(array $data): void
    {
        $templatePath = base_path('.env.example');
        $environmentPath = app()->environmentFilePath();
        if (!is_readable($templatePath)) {
            throw new RuntimeException("环境模板不可读：{$templatePath}");
        }
        if (File::exists($environmentPath)) {
            throw new RuntimeException('.env 文件已存在，安装已停止');
        }

        $contents = file_get_contents($templatePath);
        if ($contents === false) {
            throw new RuntimeException("读取环境模板失败：{$templatePath}");
        }

        foreach ($data as $key => $value) {
            $key = strtoupper((string)$key);
            $line = $key . '=' . $this->quoteEnvironmentValue((string)$value);
            $pattern = '/^' . preg_quote($key, '/') . '=[^\r\n]*$/m';

            if (preg_match($pattern, $contents) === 1) {
                $contents = preg_replace($pattern, $line, $contents, 1);
            } else {
                $contents = rtrim($contents, "\r\n") . PHP_EOL . $line . PHP_EOL;
            }
        }

        $temporaryPath = $environmentPath . '.tmp.' . bin2hex(random_bytes(8));
        try {
            $written = file_put_contents($temporaryPath, $contents, LOCK_EX);
            if ($written === false || $written !== strlen($contents)) {
                throw new RuntimeException('写入临时环境文件失败，请检查目录权限');
            }
            if (File::exists($environmentPath) || !rename($temporaryPath, $environmentPath)) {
                throw new RuntimeException('保存 .env 文件失败，请检查目录权限');
            }
        } finally {
            if (File::exists($temporaryPath)) {
                File::delete($temporaryPath);
            }
        }
    }

    private function quoteEnvironmentValue(string $value): string
    {
        $escaped = str_replace(
            ['\\', '"', '$', "\r", "\n"],
            ['\\\\', '\\"', '\\$', '\\r', '\\n'],
            $value
        );

        return '"' . $escaped . '"';
    }

    private function applyRuntimeConfiguration(array $settings): void
    {
        config([
            'app.key' => $settings['APP_KEY'],
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $settings['DB_HOST'],
            'database.connections.mysql.port' => $settings['DB_PORT'],
            'database.connections.mysql.database' => $settings['DB_DATABASE'],
            'database.connections.mysql.username' => $settings['DB_USERNAME'],
            'database.connections.mysql.password' => $settings['DB_PASSWORD'],
        ]);
    }

    private function rootCauseMessage(Throwable $exception): string
    {
        while ($exception->getPrevious() instanceof Throwable) {
            $exception = $exception->getPrevious();
        }

        return $exception->getMessage();
    }
}
