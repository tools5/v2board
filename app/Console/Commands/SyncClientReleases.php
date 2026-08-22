<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 把客户端 GitHub Releases 镜像到 public/clients/，供国内无法直连 GitHub 的
 * 客户端做应用内更新：
 *
 * - Windows（electron-updater generic provider）：直接读 /clients/latest.yml，
 *   再按 yml 里的相对文件名拉 exe / blockmap；
 * - Android：读 /clients/android-latest.json（本命令生成），拿版本号与 APK 直链。
 *
 * nginx 静态服务 public/，无需任何接口。幂等：VERSION 标记一致且文件齐全时跳过。
 */
class SyncClientReleases extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'client:sync-releases {--repo= : GitHub 仓库（owner/repo）} {--force : 忽略本地 VERSION 标记强制重新同步}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '镜像客户端 GitHub Releases 到 public/clients/ 供应用内更新';

    private const DEFAULT_REPO = 'tools5/XBClient';

    // 只镜像应用内更新会用到的资产；aab / deb 不走应用内更新，不占镜像空间
    private const MIRROR_EXTENSIONS = ['exe', 'blockmap', 'yml', 'apk'];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        ini_set('memory_limit', '-1');
        set_time_limit(0);

        $repo = trim((string) ($this->option('repo') ?: config('v2board.client_sync_repo', self::DEFAULT_REPO)));
        if (!preg_match('#\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z#', $repo)) {
            $this->error("GitHub 仓库标识无效：{$repo}");
            return 1;
        }

        $appUrl = rtrim((string) config('v2board.app_url'), '/');
        if ($appUrl === '') {
            $this->error('config/v2board.php 未配置 app_url，无法生成 android-latest.json 的下载直链');
            return 1;
        }

        $clientsDir = public_path('clients');

        try {
            $release = $this->fetchLatestRelease($repo);
        } catch (\Throwable $e) {
            $this->error('获取 GitHub latest release 失败：' . $e->getMessage());
            return 1;
        }

        $tag = trim((string) ($release['tag_name'] ?? ''));
        if ($tag === '') {
            $this->error('GitHub Release 缺少 tag_name');
            return 1;
        }

        $assets = $this->mirrorAssets($release);
        if (!$assets) {
            $this->error("Release {$tag} 中没有可镜像的资产（exe / blockmap / yml / apk）");
            return 1;
        }

        $versionFile = $clientsDir . DIRECTORY_SEPARATOR . 'VERSION';
        $localTag = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
        if (!$this->option('force') && $localTag === $tag && $this->allAssetsPresent($clientsDir, $assets)) {
            $this->info("已是最新版本：{$tag}，无需同步");
            return 0;
        }

        if (!is_dir($clientsDir) && !mkdir($clientsDir, 0755, true) && !is_dir($clientsDir)) {
            $this->error("无法创建目录：{$clientsDir}");
            return 1;
        }
        $this->cleanStaleStagingDirs($clientsDir);

        // 临时目录放在 clients 下，保证 rename 同文件系统内原子生效
        $staging = $clientsDir . DIRECTORY_SEPARATOR . '.staging-' . getmypid();
        if (!mkdir($staging, 0755, true)) {
            $this->error("无法创建临时目录：{$staging}");
            return 1;
        }

        try {
            foreach ($assets as $asset) {
                $this->line(sprintf('下载 %s（%.1f MB）', $asset['name'], $asset['size'] / 1048576));
                $this->downloadAsset($asset['url'], $staging . DIRECTORY_SEPARATOR . $asset['name'], $asset['size']);
            }

            $names = array_column($assets, 'name');
            $ymlName = $this->latestYmlName($names);
            if ($ymlName !== '') {
                // electron-updater 按 feed URL + yml 内相对文件名拼下载地址，
                // 引用文件必须与本地保存名一致；绝对 URL（指向 GitHub）重写为相对名
                $this->normalizeLatestYml($staging . DIRECTORY_SEPARATOR . $ymlName, $names);
            }

            $apkNames = array_values(array_filter($names, function ($name) {
                return str_ends_with(strtolower($name), '.apk');
            }));
            if ($apkNames) {
                $json = $this->buildAndroidLatestJson($tag, $appUrl, $apkNames, $release);
                if (file_put_contents($staging . DIRECTORY_SEPARATOR . 'android-latest.json', $json) === false) {
                    throw new \RuntimeException('写入 android-latest.json 失败');
                }
            }

            if (file_put_contents($staging . DIRECTORY_SEPARATOR . 'VERSION', $tag . "\n") === false) {
                throw new \RuntimeException('写入 VERSION 失败');
            }

            // 发布顺序：安装包 → latest.yml → android-latest.json → VERSION。
            // 任一时刻被读到的元数据引用的文件都已就位；VERSION 最后落盘保证幂等标记可信
            $publishOrder = array_merge(
                array_values(array_diff($names, [$ymlName])),
                $ymlName !== '' ? [$ymlName] : [],
                $apkNames ? ['android-latest.json'] : [],
                ['VERSION']
            );
            foreach ($publishOrder as $name) {
                if (!rename($staging . DIRECTORY_SEPARATOR . $name, $clientsDir . DIRECTORY_SEPARATOR . $name)) {
                    throw new \RuntimeException("发布文件失败：{$name}");
                }
            }

            $removed = $this->cleanOldAssets($clientsDir, $names, $ymlName !== '', (bool) $apkNames);
        } catch (\Throwable $e) {
            $this->removeDirectory($staging);
            Log::error('客户端 Release 镜像同步失败', [
                'repo' => $repo,
                'tag' => $tag,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            $this->error('同步失败（未留下半成品）：' . $e->getMessage());
            return 1;
        }

        $this->removeDirectory($staging);
        $this->info(sprintf(
            '同步完成：%s（%d 个文件，清理旧文件 %d 个）',
            $tag,
            count($assets),
            $removed
        ));
        return 0;
    }

    private function fetchLatestRelease(string $repo): array
    {
        $body = $this->httpGet("https://api.github.com/repos/{$repo}/releases/latest", [
            'Accept: application/vnd.github+json',
        ]);
        $release = json_decode($body, true);
        if (!is_array($release)) {
            throw new \RuntimeException('GitHub API 返回的不是有效 JSON');
        }
        return $release;
    }

    /**
     * 从 release 资产中筛出需要镜像的文件，并做文件名安全校验。
     *
     * @return array<int, array{name: string, url: string, size: int}>
     */
    private function mirrorAssets(array $release): array
    {
        $assets = [];
        foreach ((array) ($release['assets'] ?? []) as $asset) {
            $name = (string) ($asset['name'] ?? '');
            $url = (string) ($asset['browser_download_url'] ?? '');
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($url === '' || !in_array($extension, self::MIRROR_EXTENSIONS, true)) {
                continue;
            }
            // 资产名直接用作本地文件名：只放行常规字符，杜绝路径穿越与隐藏文件
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $name)) {
                throw new \RuntimeException("资产文件名不安全，拒绝镜像：{$name}");
            }
            $assets[] = [
                'name' => $name,
                'url' => $url,
                'size' => (int) ($asset['size'] ?? 0),
            ];
        }
        return $assets;
    }

    private function allAssetsPresent(string $clientsDir, array $assets): bool
    {
        foreach ($assets as $asset) {
            if (!is_file($clientsDir . DIRECTORY_SEPARATOR . $asset['name'])) {
                return false;
            }
        }
        return true;
    }

    private function latestYmlName(array $names): string
    {
        foreach ($names as $name) {
            if (strtolower($name) === 'latest.yml') {
                return $name;
            }
        }
        return '';
    }

    /**
     * 校验并规范 latest.yml：
     * - url / path 字段若是绝对 URL（历史版本可能直接指向 GitHub），重写为纯文件名，
     *   让 electron-updater 相对 feed URL（面板 /clients/）解析；
     * - 引用的相对文件名必须存在于本次下载集合中，否则视为 Release 不完整，中止发布。
     */
    private function normalizeLatestYml(string $ymlPath, array $downloadedNames): void
    {
        $content = file_get_contents($ymlPath);
        if ($content === false) {
            throw new \RuntimeException('读取 latest.yml 失败');
        }

        $rewritten = preg_replace_callback(
            '/^(?<head>\s*(?:-\s+)?(?:url|path):\s*)(?<value>\S+)\s*$/m',
            function (array $match) use ($downloadedNames) {
                $value = trim($match['value'], "'\"");
                if (preg_match('#\Ahttps?://#i', $value)) {
                    $value = rawurldecode(basename(parse_url($value, PHP_URL_PATH) ?: ''));
                }
                if (!in_array($value, $downloadedNames, true)) {
                    throw new \RuntimeException("latest.yml 引用的文件不在本次 Release 资产中：{$value}");
                }
                return $match['head'] . $value;
            },
            $content
        );
        if ($rewritten === null) {
            throw new \RuntimeException('解析 latest.yml 失败');
        }
        if ($rewritten !== $content && file_put_contents($ymlPath, $rewritten) === false) {
            throw new \RuntimeException('重写 latest.yml 失败');
        }
    }

    /**
     * Android 客户端读取的镜像元数据。url 给出首选 APK 直链（universal 优先，
     * 其次 arm64-v8a），apks 列出全部 APK 供客户端按 ABI 自选。
     */
    private function buildAndroidLatestJson(string $tag, string $appUrl, array $apkNames, array $release): string
    {
        $preferred = $apkNames[0];
        foreach ($apkNames as $name) {
            if (stripos($name, 'arm64-v8a') !== false) {
                $preferred = $name;
            }
        }
        foreach ($apkNames as $name) {
            if (stripos($name, 'universal') !== false) {
                $preferred = $name;
            }
        }

        $downloadUrl = function (string $name) use ($appUrl) {
            return $appUrl . '/clients/' . rawurlencode($name);
        };

        $payload = [
            'version' => ltrim($tag, 'vV'),
            'url' => $downloadUrl($preferred),
            'notes' => mb_substr((string) ($release['body'] ?? ''), 0, 4000),
            'release_url' => (string) ($release['html_url'] ?? ''),
            'apks' => array_map(function (string $name) use ($downloadUrl) {
                return ['name' => $name, 'url' => $downloadUrl($name)];
            }, $apkNames),
            'synced_at' => date('c'),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('序列化 android-latest.json 失败：' . json_last_error_msg());
        }
        return $json . "\n";
    }

    /**
     * 清理 clients 目录中不属于当前版本的镜像文件（只保留最新 1 版）。
     * 只动镜像扩展名的文件；目录及手工放置的其它文件一律不碰。
     * Release 缺某端资产时（如 Android 构建失败）保留该端旧文件，
     * 避免删掉旧 latest.yml / android-latest.json 仍在引用的安装包。
     */
    private function cleanOldAssets(string $clientsDir, array $currentNames, bool $hasDesktop, bool $hasApk): int
    {
        $removableExtensions = array_merge(
            $hasDesktop ? ['exe', 'blockmap', 'yml'] : [],
            $hasApk ? ['apk'] : []
        );
        $removed = 0;
        foreach (scandir($clientsDir) ?: [] as $entry) {
            $path = $clientsDir . DIRECTORY_SEPARATOR . $entry;
            if ($entry === '.' || $entry === '..' || !is_file($path)) {
                continue;
            }
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($extension, $removableExtensions, true) || in_array($entry, $currentNames, true)) {
                continue;
            }
            if (unlink($path)) {
                $removed++;
            } else {
                Log::warning('客户端镜像旧文件清理失败', ['path' => $path]);
            }
        }
        return $removed;
    }

    /**
     * 清理崩溃残留的临时目录（1 小时前的 .staging-*），避免磁盘被半成品占满；
     * 更近的目录可能属于并发运行中的手工同步，不碰。
     */
    private function cleanStaleStagingDirs(string $clientsDir): void
    {
        foreach (glob($clientsDir . DIRECTORY_SEPARATOR . '.staging-*') ?: [] as $dir) {
            if (is_dir($dir) && filemtime($dir) < time() - 3600) {
                $this->removeDirectory($dir);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($dir . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($dir);
    }

    private function httpGet(string $url, array $headers): string
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'v2board-client-release-sync',
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body === false) {
            throw new \RuntimeException("请求 {$url} 失败：{$error}");
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("请求 {$url} 返回 HTTP {$status}");
        }
        return $body;
    }

    private function downloadAsset(string $url, string $destination, int $expectedSize): void
    {
        $handle = fopen($destination, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("无法写入临时文件：{$destination}");
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 1800,
            // 连续 60 秒低于 1 KB/s 视为断流，尽早失败
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_USERAGENT => 'v2board-client-release-sync',
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($ok === false) {
            throw new \RuntimeException("下载 {$url} 失败：{$error}");
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("下载 {$url} 返回 HTTP {$status}");
        }
        clearstatcache(true, $destination);
        $actualSize = (int) filesize($destination);
        if ($expectedSize > 0 && $actualSize !== $expectedSize) {
            throw new \RuntimeException(sprintf(
                '下载 %s 大小不符：期望 %d 字节，实际 %d 字节',
                basename($destination),
                $expectedSize,
                $actualSize
            ));
        }
    }
}
