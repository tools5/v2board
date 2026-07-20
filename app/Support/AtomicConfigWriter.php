<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

final class AtomicConfigWriter
{
    public static function write($path, $contents)
    {
        $path = (string)$path;
        $contents = (string)$contents;
        $directory = dirname($path);
        File::ensureDirectoryExists($directory, 0750, true);

        self::withLock(function () use ($path, $contents) {
            self::replaceAndRebuild($path, $contents);
        });
    }

    public static function updateArray($path, array $changes, array $fallback = [])
    {
        $path = (string)$path;
        File::ensureDirectoryExists(dirname($path), 0750, true);

        return self::withLock(function () use ($path, $changes, $fallback) {
            $current = $fallback;
            if (is_file($path)) {
                self::invalidateOpcache($path);
                $loaded = require $path;
                if (!is_array($loaded)) {
                    throw new \RuntimeException('现有配置文件未返回数组');
                }
                $current = $loaded;
            }

            foreach ($changes as $key => $value) {
                $current[$key] = $value;
            }

            $contents = "<?php\n\nreturn " . var_export($current, true) . ";\n";
            self::replaceAndRebuild($path, $contents);

            return $current;
        });
    }

    private static function withLock(callable $callback)
    {
        $lockDirectory = storage_path('framework/cache');
        File::ensureDirectoryExists($lockDirectory, 0750, true);
        $lockHandle = @fopen($lockDirectory . DIRECTORY_SEPARATOR . 'config-write.lock', 'c+');
        if ($lockHandle === false) {
            throw new \RuntimeException('无法创建配置写入锁');
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new \RuntimeException('无法获取配置写入锁');
            }

            return $callback();
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    private static function replaceAndRebuild($path, $contents)
    {
        $directory = dirname($path);
        $tempPath = tempnam($directory, '.config-');
        if ($tempPath === false) {
            throw new \RuntimeException('无法创建配置临时文件');
        }

        $backupPath = null;
        $installed = false;
        try {
            if (file_put_contents($tempPath, $contents, LOCK_EX) !== strlen($contents)) {
                throw new \RuntimeException('配置文件写入不完整');
            }
            @chmod($tempPath, 0640);

            if (is_file($path)) {
                $backupPath = $path . '.backup-' . bin2hex(random_bytes(6));
                if (!@rename($path, $backupPath)) {
                    throw new \RuntimeException('无法备份原配置文件');
                }
            }

            if (!@rename($tempPath, $path)) {
                if ($backupPath !== null && is_file($backupPath)) {
                    if (!@rename($backupPath, $path)) {
                        throw new \RuntimeException('无法安装新配置，原配置保留在备份文件中');
                    }
                    $backupPath = null;
                }
                throw new \RuntimeException('无法原子替换配置文件');
            }
            $tempPath = null;
            $installed = true;
            @chmod($path, 0640);
            self::invalidateOpcache($path);
            self::rebuildCache();

            if ($backupPath !== null && is_file($backupPath)) {
                if (!@unlink($backupPath)) {
                    throw new \RuntimeException('无法清理配置备份文件');
                }
                $backupPath = null;
            }
        } catch (\Throwable $error) {
            if ($installed) {
                self::restoreBackup($path, $backupPath);
                $backupPath = null;
                self::invalidateOpcache($path);
                try {
                    self::rebuildCache();
                } catch (\Throwable $ignored) {
                    // Keep the original failure as the actionable error.
                }
            }

            throw new \RuntimeException('配置保存失败', 0, $error);
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
            if ($backupPath !== null && is_file($backupPath) && !is_file($path)) {
                @rename($backupPath, $path);
            }
        }
    }

    private static function restoreBackup($path, $backupPath)
    {
        if (is_file($path) && !@unlink($path)) {
            $failedPath = $path . '.failed-' . bin2hex(random_bytes(6));
            if (!@rename($path, $failedPath)) {
                throw new \RuntimeException('新配置文件无法移除，原配置保留在备份文件中');
            }
            @unlink($failedPath);
        }

        if ($backupPath !== null && is_file($backupPath) && !@rename($backupPath, $path)) {
            throw new \RuntimeException('配置回滚失败，原配置保留在备份文件中');
        }
    }

    private static function rebuildCache()
    {
        $exitCode = Artisan::call('config:cache');
        if ((int)$exitCode !== 0) {
            throw new \RuntimeException('配置缓存刷新失败');
        }
    }

    private static function invalidateOpcache($path)
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
