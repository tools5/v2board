<?php

namespace App\Services;

use App\Exceptions\WebPushEndpointResolutionException;
use App\Models\Notice;
use App\Models\User;
use App\Models\WebPushSubscription as WebPushSubscriptionModel;
use App\Support\ConfiguredUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    private const SETTINGS_FILE = 'app/webpush-settings.json';

    private const DEFAULT_ENDPOINT_HOSTS = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        '*.push.services.mozilla.com',
        '*.notify.windows.com',
        '*.push.apple.com',
    ];

    private $endpointSafetyCache = [];

    /**
     * Resolved runtime settings.
     * Priority: admin-saved v2board.php values (when present) > .env / config/webpush.php.
     */
    public function getSettings()
    {
        $board = $this->loadBoardConfig();
        $stored = $this->loadStoredSettings();
        $storedKeyMap = [
            'enabled' => 'web_push_enabled',
            'vapid_subject' => 'web_push_vapid_subject',
            'public_key' => 'web_push_public_key',
            'private_key' => 'web_push_private_key',
            'ttl' => 'web_push_ttl',
            'urgency' => 'web_push_urgency',
            'batch_size' => 'web_push_batch_size',
            'request_timeout' => 'web_push_request_timeout',
            'proxy' => 'web_push_proxy',
            'ca_bundle' => 'web_push_ca_bundle',
            'remind_expire' => 'web_push_remind_expire',
            'remind_traffic' => 'web_push_remind_traffic',
            'remind_expire_days' => 'web_push_remind_expire_days',
            'remind_traffic_percent' => 'web_push_remind_traffic_percent',
            'remind_expire_url' => 'web_push_remind_expire_url',
            'remind_traffic_url' => 'web_push_remind_traffic_url',
        ];
        foreach ($storedKeyMap as $storedKey => $boardKey) {
            if (array_key_exists($storedKey, $stored)) {
                $board[$boardKey] = $stored[$storedKey];
            }
        }
        $envConfig = config('webpush', []);

        $enabled = $this->resolveBool(
            $board,
            'web_push_enabled',
            (bool)($envConfig['enabled'] ?? false)
        );

        $subject = $this->resolveString(
            $board,
            'web_push_vapid_subject',
            (string)($envConfig['vapid']['subject'] ?? '')
        );
        $subject = $this->normalizeVapidSubject($subject);

        $publicKey = $this->resolveString(
            $board,
            'web_push_public_key',
            (string)($envConfig['vapid']['public_key'] ?? '')
        );
        $privateKey = $this->resolveString(
            $board,
            'web_push_private_key',
            (string)($envConfig['vapid']['private_key'] ?? '')
        );

        $ttl = $this->resolveInt(
            $board,
            'web_push_ttl',
            (int)($envConfig['ttl'] ?? 86400),
            0,
            604800
        );
        $urgency = $this->resolveString(
            $board,
            'web_push_urgency',
            (string)($envConfig['urgency'] ?? 'normal')
        );
        if (!in_array($urgency, ['very-low', 'low', 'normal', 'high'], true)) {
            $urgency = 'normal';
        }

        $batchSize = $this->resolveInt(
            $board,
            'web_push_batch_size',
            (int)($envConfig['batch_size'] ?? 500),
            1,
            5000
        );
        $requestTimeout = $this->resolveInt(
            $board,
            'web_push_request_timeout',
            (int)($envConfig['request_timeout'] ?? 30),
            1,
            120
        );

        $proxy = $this->resolveString(
            $board,
            'web_push_proxy',
            (string)($envConfig['proxy'] ?? '')
        );
        $caBundle = $this->resolveString(
            $board,
            'web_push_ca_bundle',
            (string)($envConfig['ca_bundle'] ?? '')
        );

        $remindExpireEnabled = $this->resolveBool(
            $board,
            'web_push_remind_expire',
            (bool)($envConfig['remind']['expire_enabled'] ?? true)
        );
        $remindTrafficEnabled = $this->resolveBool(
            $board,
            'web_push_remind_traffic',
            (bool)($envConfig['remind']['traffic_enabled'] ?? true)
        );

        $expireDaysRaw = $this->resolveString(
            $board,
            'web_push_remind_expire_days',
            is_array($envConfig['remind']['expire_days'] ?? null)
                ? implode(',', $envConfig['remind']['expire_days'])
                : '3,1,0'
        );
        $expireDays = $this->parseExpireDays($expireDaysRaw);

        $trafficPercent = $this->resolveInt(
            $board,
            'web_push_remind_traffic_percent',
            (int)($envConfig['remind']['traffic_percent'] ?? 95),
            1,
            99
        );

        $expireUrl = $this->resolveString(
            $board,
            'web_push_remind_expire_url',
            (string)($envConfig['remind']['expire_url'] ?? '')
        );
        $trafficUrl = $this->resolveString(
            $board,
            'web_push_remind_traffic_url',
            (string)($envConfig['remind']['traffic_url'] ?? '')
        );

        return [
            'enabled' => $enabled,
            'vapid_subject' => $subject,
            'public_key' => $publicKey,
            'private_key' => $privateKey,
            'ttl' => $ttl,
            'urgency' => $urgency,
            'batch_size' => $batchSize,
            'request_timeout' => $requestTimeout,
            'proxy' => $proxy,
            'ca_bundle' => $caBundle,
            'remind_expire' => $remindExpireEnabled,
            'remind_traffic' => $remindTrafficEnabled,
            'remind_expire_days' => implode(',', $expireDays),
            'remind_expire_days_list' => $expireDays,
            'remind_traffic_percent' => $trafficPercent,
            'remind_expire_url' => $expireUrl,
            'remind_traffic_url' => $trafficUrl,
            'source' => !empty($stored) ? 'storage' : $this->detectConfigSource($board),
        ];
    }

    public function isConfigured()
    {
        $settings = $this->getSettings();

        return (bool)$settings['enabled']
            && (string)$settings['vapid_subject'] !== ''
            && (string)$settings['public_key'] !== ''
            && (string)$settings['private_key'] !== '';
    }

    /**
     * Persist Web Push settings independently from the generated application config.
     */
    public function saveSettings(array $input)
    {
        $current = $this->getSettings();

        $enabled = array_key_exists('enabled', $input)
            ? $this->toBool($input['enabled'])
            : (bool)$current['enabled'];

        $subject = array_key_exists('vapid_subject', $input)
            ? trim((string)$input['vapid_subject'])
            : (string)$current['vapid_subject'];
        $subject = $this->normalizeVapidSubject($subject);

        $publicKey = array_key_exists('public_key', $input)
            ? trim((string)$input['public_key'])
            : (string)$current['public_key'];
        $privateKeyInput = array_key_exists('private_key', $input)
            ? trim((string)$input['private_key'])
            : '';
        // An empty value from the admin form means "keep the existing secret".
        $privateKey = $privateKeyInput !== ''
            ? $privateKeyInput
            : (string)$current['private_key'];

        $ttl = array_key_exists('ttl', $input)
            ? max(0, min(604800, (int)$input['ttl']))
            : (int)$current['ttl'];
        $urgency = array_key_exists('urgency', $input)
            ? trim((string)$input['urgency'])
            : (string)$current['urgency'];
        if (!in_array($urgency, ['very-low', 'low', 'normal', 'high'], true)) {
            $urgency = 'normal';
        }

        $batchSize = array_key_exists('batch_size', $input)
            ? max(1, min(5000, (int)$input['batch_size']))
            : (int)$current['batch_size'];
        $requestTimeout = array_key_exists('request_timeout', $input)
            ? max(1, min(120, (int)$input['request_timeout']))
            : (int)$current['request_timeout'];

        $proxy = array_key_exists('proxy', $input)
            ? trim((string)$input['proxy'])
            : (string)$current['proxy'];
        $caBundle = array_key_exists('ca_bundle', $input)
            ? trim((string)$input['ca_bundle'])
            : (string)$current['ca_bundle'];

        $remindExpire = array_key_exists('remind_expire', $input)
            ? $this->toBool($input['remind_expire'])
            : (bool)$current['remind_expire'];
        $remindTraffic = array_key_exists('remind_traffic', $input)
            ? $this->toBool($input['remind_traffic'])
            : (bool)$current['remind_traffic'];

        $expireDaysRaw = array_key_exists('remind_expire_days', $input)
            ? (string)$input['remind_expire_days']
            : (string)$current['remind_expire_days'];
        $expireDays = $this->parseExpireDays($expireDaysRaw);

        $trafficPercent = array_key_exists('remind_traffic_percent', $input)
            ? max(1, min(99, (int)$input['remind_traffic_percent']))
            : (int)$current['remind_traffic_percent'];

        $expireUrl = array_key_exists('remind_expire_url', $input)
            ? trim((string)$input['remind_expire_url'])
            : (string)$current['remind_expire_url'];
        $trafficUrl = array_key_exists('remind_traffic_url', $input)
            ? trim((string)$input['remind_traffic_url'])
            : (string)$current['remind_traffic_url'];

        if ($subject !== '') {
            $this->assertValidVapidSubject($subject);
        }
        if (($publicKey === '') xor ($privateKey === '')) {
            throw new \InvalidArgumentException('VAPID 公钥与私钥必须同时配置');
        }
        if ($publicKey !== '' && $privateKey !== '') {
            $this->assertValidVapidKeyPair($subject, $publicKey, $privateKey);
        }
        if ($enabled && ($subject === '' || $publicKey === '' || $privateKey === '')) {
            throw new \InvalidArgumentException('启用推送前请填写 Subject，或生成 VAPID 密钥');
        }

        $this->writeStoredSettings([
            'enabled' => $enabled,
            'vapid_subject' => $subject,
            'public_key' => $publicKey,
            'private_key' => $privateKey,
            'ttl' => $ttl,
            'urgency' => $urgency,
            'batch_size' => $batchSize,
            'request_timeout' => $requestTimeout,
            'proxy' => $proxy !== '' ? $proxy : null,
            'ca_bundle' => $caBundle !== '' ? $caBundle : null,
            'remind_expire' => $remindExpire,
            'remind_traffic' => $remindTraffic,
            'remind_expire_days' => implode(',', $expireDays),
            'remind_traffic_percent' => $trafficPercent,
            'remind_expire_url' => $expireUrl !== '' ? $expireUrl : null,
            'remind_traffic_url' => $trafficUrl !== '' ? $trafficUrl : null,
        ]);

        return $this->getSettings();
    }

    /**
     * Generate a new VAPID key pair (does not auto-save).
     */
    public function generateVapidKeys()
    {
        $subject = $this->normalizeVapidSubject(ConfiguredUrl::applicationUrl());

        if (class_exists(VAPID::class)) {
            try {
                $keys = VAPID::createVapidKeys();
                return [
                    'public_key' => (string)($keys['publicKey'] ?? ''),
                    'private_key' => (string)($keys['privateKey'] ?? ''),
                    'vapid_subject' => $subject,
                ];
            } catch (\Throwable $error) {
                // Fall through to the OpenSSL generator below. Some Windows PHP
                // packages expose OpenSSL but point it at a non-existent config.
            }
        }

        return $this->generateVapidKeysWithOpenSsl($subject);
    }

    /**
     * Generate VAPID keys using OpenSSL when minishlink/web-push is unavailable.
     */
    private function generateVapidKeysWithOpenSsl($subject)
    {
        if (!function_exists('openssl_pkey_new')) {
            throw new \RuntimeException(
                '缺少 minishlink/web-push 且 OpenSSL 不可用。请在服务器执行：composer require minishlink/web-push:^7.0'
            );
        }

        $options = [
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];
        $privateKeyResource = $this->createOpenSslEcKey($options);
        if ($privateKeyResource === false) {
            throw new \RuntimeException(
                'OpenSSL 无法生成 EC 密钥。请安装扩展后执行：composer require minishlink/web-push:^7.0'
            );
        }

        $details = openssl_pkey_get_details($privateKeyResource);
        if (!is_array($details) || empty($details['ec']['x']) || empty($details['ec']['y']) || empty($details['ec']['d'])) {
            throw new \RuntimeException('OpenSSL 密钥详情读取失败，请安装 minishlink/web-push');
        }

        $x = $this->normalizeP256Field($details['ec']['x'], 'x');
        $y = $this->normalizeP256Field($details['ec']['y'], 'y');
        $privateRaw = $this->normalizeP256Field($details['ec']['d'], 'd');
        $publicRaw = "\x04" . $x . $y;

        return [
            'public_key' => $this->base64UrlEncode($publicRaw),
            'private_key' => $this->base64UrlEncode($privateRaw),
            'vapid_subject' => $subject,
        ];
    }

    /**
     * Try the system OpenSSL configuration first, then common PHP package paths.
     */
    private function createOpenSslEcKey(array $options)
    {
        $attempts = [$options];
        foreach ($this->openSslConfigCandidates() as $configPath) {
            $attempts[] = ['config' => $configPath] + $options;
        }

        foreach ($attempts as $attempt) {
            try {
                $key = @openssl_pkey_new($attempt);
            } catch (\Throwable $error) {
                $key = false;
            }
            if ($key !== false) {
                return $key;
            }
        }

        return false;
    }

    private function openSslConfigCandidates()
    {
        $phpDirectory = dirname(PHP_BINARY);
        $phpIni = php_ini_loaded_file();
        $phpIniDirectory = is_string($phpIni) && $phpIni !== ''
            ? dirname($phpIni)
            : $phpDirectory;
        $candidates = [
            getenv('OPENSSL_CONF'),
            getenv('SSLEAY_CONF'),
            $phpDirectory . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            $phpDirectory . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            $phpIniDirectory . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
        ];

        $valid = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '' || !is_file($candidate)) {
                continue;
            }
            $realPath = realpath($candidate);
            if ($realPath !== false) {
                $valid[$realPath] = $realPath;
            }
        }

        return array_values($valid);
    }

    private function base64UrlEncode($binary)
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function normalizeP256Field($value, $name)
    {
        if (!is_string($value) || strlen($value) > 32) {
            throw new \RuntimeException('OpenSSL 返回了无效的 P-256 ' . $name . ' 字段');
        }

        return str_pad($value, 32, "\0", STR_PAD_LEFT);
    }

    public function defaultIconUrl()
    {
        $logo = ConfiguredUrl::normalizeExternalHttpUrl(config('v2board.logo', ''));
        if ($logo !== '') {
            return $this->absoluteAssetUrl($logo);
        }

        return $this->absoluteAssetUrl('/theme/blued/images/logo.png');
    }

    public function defaultClickUrl()
    {
        return ConfiguredUrl::applicationPathUrl('/#/dashboard');
    }

    /**
     * Browser notification icon/image must be absolute https URLs.
     * Relative paths like /uploads/xxx never render as large images.
     */
    public function absoluteAssetUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }

        $absoluteParts = parse_url($url);
        if (is_array($absoluteParts) && isset($absoluteParts['scheme'])) {
            return ConfiguredUrl::normalizeExternalHttpUrl($url);
        }

        $baseUrl = rtrim(ConfiguredUrl::applicationUrl(), '/');

        if (strpos($url, '//') === 0) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            if (!in_array(strtolower((string)$scheme), ['http', 'https'], true)) {
                $scheme = 'https';
            }

            return ConfiguredUrl::normalizeExternalHttpUrl($scheme . ':' . $url);
        }

        return ConfiguredUrl::applicationPathUrl($url);
    }

    private function loadBoardConfig()
    {
        $config = config('v2board', []);
        if (!is_array($config)) {
            $config = [];
        }

        if (app()->environment('testing')) {
            return $config;
        }

        $path = base_path('config/v2board.php');
        if (!is_file($path)) {
            return $config;
        }

        try {
            clearstatcache(true, $path);
            $diskConfig = (static function ($configPath) {
                return require $configPath;
            })($path);
            if (is_array($diskConfig)) {
                return $diskConfig;
            }
        } catch (\Throwable $error) {
            Log::warning('Unable to reload v2board config for Web Push', [
                'reason' => $error->getMessage(),
            ]);
        }

        return $config;
    }

    private function loadStoredSettings()
    {
        $path = storage_path(self::SETTINGS_FILE);
        $lockPath = $path . '.lock';
        $lock = @fopen($lockPath, 'c+');

        try {
            if ($lock !== false && !flock($lock, LOCK_SH)) {
                throw new \RuntimeException('lock failed');
            }
            if (!is_file($path)) {
                return [];
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException('read failed');
            }
            $settings = json_decode($contents, true, 32);
            if (!is_array($settings) || json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('invalid JSON');
            }
            return $settings;
        } catch (\Throwable $error) {
            Log::error('Unable to read stored Web Push settings', [
                'reason' => $error->getMessage(),
            ]);
            return [];
        } finally {
            if ($lock !== false) {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function writeStoredSettings(array $settings)
    {
        $path = storage_path(self::SETTINGS_FILE);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Web Push 配置目录不可创建，请检查 storage 写入权限');
        }

        $lockPath = $path . '.lock';
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new \RuntimeException('Web Push 配置锁不可创建，请检查 storage 写入权限');
        }

        $tempPath = null;
        $backupPath = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('无法锁定 Web Push 配置文件');
            }

            $json = json_encode(
                $settings,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if ($json === false) {
                throw new \RuntimeException('Web Push 配置编码失败');
            }
            $json .= PHP_EOL;

            $tempPath = tempnam($directory, '.webpush-');
            if ($tempPath === false) {
                throw new \RuntimeException('无法创建 Web Push 临时配置文件');
            }
            if (file_put_contents($tempPath, $json, LOCK_EX) !== strlen($json)) {
                throw new \RuntimeException('Web Push 临时配置写入失败');
            }
            @chmod($tempPath, 0600);

            if (is_file($path)) {
                $backupPath = $path . '.backup-' . bin2hex(random_bytes(6));
                if (!@rename($path, $backupPath)) {
                    throw new \RuntimeException('Web Push 旧配置无法锁定替换');
                }
                @chmod($backupPath, 0600);
            }

            if (!@rename($tempPath, $path)) {
                if ($backupPath !== null && is_file($backupPath)) {
                    @rename($backupPath, $path);
                    $backupPath = null;
                }
                throw new \RuntimeException('Web Push 配置原子替换失败');
            }
            $tempPath = null;
            if ($backupPath !== null) {
                @unlink($backupPath);
                $backupPath = null;
            }
            clearstatcache(true, $path);
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
            if ($backupPath !== null && is_file($backupPath)) {
                if (!is_file($path)) {
                    @rename($backupPath, $path);
                } else {
                    @unlink($backupPath);
                }
            }
            @flock($lock, LOCK_UN);
            fclose($lock);
            @chmod($lockPath, 0600);
        }
    }

    private function assertValidVapidSubject($subject)
    {
        $subject = trim((string)$subject);
        if (stripos($subject, 'mailto:') === 0) {
            $email = substr($subject, 7);
            if (!preg_match('/^[^@\s]+@[^@\s]+$/', $email)) {
                throw new \InvalidArgumentException('VAPID Subject 的邮箱格式无效');
            }
            return;
        }

        $parts = parse_url($subject);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new \InvalidArgumentException('VAPID Subject 必须是 https:// 地址或 mailto:邮箱');
        }
    }

    private function assertValidVapidKeyPair($subject, $publicKey, $privateKey)
    {
        $publicRaw = $this->base64UrlDecode($publicKey);
        $privateRaw = $this->base64UrlDecode($privateKey);
        if ($publicRaw === false || strlen($publicRaw) !== 65 || $publicRaw[0] !== "\x04") {
            throw new \InvalidArgumentException('VAPID 公钥格式无效，应为 65 字节 P-256 公钥');
        }
        if ($privateRaw === false || strlen($privateRaw) !== 32) {
            throw new \InvalidArgumentException('VAPID 私钥格式无效，应为 32 字节 P-256 私钥');
        }

        if (class_exists(VAPID::class)) {
            try {
                VAPID::validate([
                    'subject' => (string)$subject,
                    'publicKey' => (string)$publicKey,
                    'privateKey' => (string)$privateKey,
                ]);
            } catch (\Throwable $error) {
                throw new \InvalidArgumentException('VAPID 密钥校验失败');
            }
        }
    }

    private function base64UrlDecode($value)
    {
        $value = trim((string)$value);
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value)) {
            return false;
        }

        $value = rtrim($value, '=');
        if (strlen($value) % 4 === 1) {
            return false;
        }

        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    public function assertValidSubscription($endpoint, $publicKey, $authToken)
    {
        $this->assertValidSubscriptionEndpoint($endpoint);

        $publicRaw = $this->base64UrlDecode($publicKey);
        if ($publicRaw === false || strlen($publicRaw) !== 65 || $publicRaw[0] !== "\x04") {
            throw new \InvalidArgumentException('浏览器推送公钥格式无效');
        }

        $authRaw = $this->base64UrlDecode($authToken);
        if ($authRaw === false || strlen($authRaw) !== 16) {
            throw new \InvalidArgumentException('浏览器推送认证密钥格式无效');
        }
    }

    public function assertValidSubscriptionEndpoint($endpoint)
    {
        $endpoint = trim((string)$endpoint);
        $parts = parse_url($endpoint);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
        ) {
            throw new \InvalidArgumentException('推送订阅地址无效');
        }

        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if (!$this->isAllowedEndpointHost($host)) {
            throw new \InvalidArgumentException('推送订阅服务不受信任');
        }

        if (!array_key_exists($host, $this->endpointSafetyCache)) {
            $addresses = $this->resolveEndpointHostAddresses($host);
            if (empty($addresses)) {
                $this->endpointSafetyCache[$host] = 'unresolved';
            } elseif (count(array_filter($addresses, [$this, 'isPublicIpAddress'])) !== count($addresses)) {
                $this->endpointSafetyCache[$host] = 'unsafe';
            } else {
                $this->endpointSafetyCache[$host] = 'public';
            }
        }

        if ($this->endpointSafetyCache[$host] === 'unresolved') {
            throw new WebPushEndpointResolutionException('推送订阅服务地址暂时无法解析');
        }
        if ($this->endpointSafetyCache[$host] !== 'public') {
            throw new \InvalidArgumentException('推送订阅服务地址解析失败');
        }
    }

    protected function resolveEndpointHostAddresses($host, $depth = 0, array &$visited = [])
    {
        $host = strtolower(rtrim((string)$host, '.'));
        if ($host === '' || $depth > 5 || isset($visited[$host])) {
            return [];
        }
        $visited[$host] = true;

        $addresses = [];
        $records = function_exists('dns_get_record')
            ? @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME)
            : false;
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $addresses[] = (string)$record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $addresses[] = (string)$record['ipv6'];
                }
                if (!empty($record['target'])) {
                    $addresses = array_merge(
                        $addresses,
                        $this->resolveEndpointHostAddresses($record['target'], $depth + 1, $visited)
                    );
                }
            }
        }

        if (empty($addresses) && function_exists('gethostbynamel')) {
            $ipv4Addresses = @gethostbynamel($host);
            if (is_array($ipv4Addresses)) {
                $addresses = array_merge($addresses, $ipv4Addresses);
            }
        }

        return array_values(array_unique(array_filter($addresses)));
    }

    protected function getAllowedEndpointHosts()
    {
        $configured = config('webpush.allowed_endpoint_hosts', []);
        if (is_string($configured)) {
            $configured = preg_split('/[\s,]+/', $configured, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($configured)) {
            $configured = [];
        }

        return array_values(array_unique(array_merge(self::DEFAULT_ENDPOINT_HOSTS, array_map(function ($host) {
            return strtolower(rtrim(trim((string)$host), '.'));
        }, $configured))));
    }

    private function isAllowedEndpointHost($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host)) {
            return false;
        }

        foreach ($this->getAllowedEndpointHosts() as $pattern) {
            if ($pattern === $host) {
                return true;
            }
            if (strpos($pattern, '*.') === 0) {
                $suffix = substr($pattern, 1);
                if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isPublicIpAddress($address)
    {
        $address = trim((string)$address);
        if (stripos($address, '::ffff:') === 0) {
            $mapped = substr($address, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $address = $mapped;
            }
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function resolveString(array $board, $key, $fallback)
    {
        if (array_key_exists($key, $board) && $board[$key] !== null && $board[$key] !== '') {
            return trim((string)$board[$key]);
        }

        return trim((string)$fallback);
    }

    private function resolveBool(array $board, $key, $fallback)
    {
        if (array_key_exists($key, $board) && $board[$key] !== null && $board[$key] !== '') {
            return $this->toBool($board[$key]);
        }

        return (bool)$fallback;
    }

    private function resolveInt(array $board, $key, $fallback, $min, $max)
    {
        $value = $fallback;
        if (array_key_exists($key, $board) && $board[$key] !== null && $board[$key] !== '') {
            $value = (int)$board[$key];
        }

        return max($min, min($max, (int)$value));
    }

    private function toBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    private function normalizeVapidSubject($subject)
    {
        $subject = trim((string)$subject);
        if ($subject === '') {
            $appUrl = ConfiguredUrl::applicationUrl();
            if (preg_match('/^https:\/\//i', $appUrl)) {
                return $appUrl;
            }
            $mailFrom = trim((string)config('v2board.email_from_address', env('MAIL_FROM_ADDRESS', '')));
            if (filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
                return 'mailto:' . $mailFrom;
            }
            return 'mailto:admin@localhost';
        }

        if (preg_match('/^(https:\/\/|mailto:)/i', $subject)) {
            return $subject;
        }
        if (filter_var($subject, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:' . $subject;
        }
        if (preg_match('/^https?:\/\//i', $subject)) {
            // Force https for VAPID subject when a full URL is given.
            return preg_replace('/^http:\/\//i', 'https://', $subject);
        }

        return $subject;
    }

    private function parseExpireDays($raw)
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = explode(',', (string)$raw);
        }

        $days = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '' || !is_numeric($part)) {
                continue;
            }
            $days[] = max(0, (int)$part);
        }

        $days = array_values(array_unique($days));
        if (empty($days)) {
            $days = [3, 1, 0];
        }
        sort($days);

        return $days;
    }

    private function detectConfigSource(array $board)
    {
        $adminKeys = [
            'web_push_enabled',
            'web_push_public_key',
            'web_push_private_key',
            'web_push_vapid_subject',
        ];
        foreach ($adminKeys as $key) {
            if (array_key_exists($key, $board) && $board[$key] !== null && $board[$key] !== '') {
                return 'admin';
            }
        }

        return 'env';
    }

    /**
     * Normalize a free-form admin/user payload into a browser-safe structure.
     */
    public function normalizePayload(array $input)
    {
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('推送标题不能为空');
        }

        $body = trim((string)($input['body'] ?? ''));
        $icon = $this->absoluteAssetUrl(trim((string)($input['icon'] ?? '')));
        $image = $this->absoluteAssetUrl(trim((string)($input['image'] ?? '')));
        $url = $this->normalizeNotificationUrl((string)($input['url'] ?? ''));
        $tag = trim((string)($input['tag'] ?? ''));
        $badge = $this->absoluteAssetUrl(trim((string)($input['badge'] ?? '')));

        if ($icon === '') {
            $icon = $this->defaultIconUrl();
        }
        if ($badge === '') {
            $badge = $icon !== '' ? $icon : $this->defaultIconUrl();
        }
        // Large image: only send when valid absolute URL; browsers ignore relative paths.
        if ($image === '' && $icon !== '' && $icon !== $this->defaultIconUrl()) {
            // Prefer explicit image; if only a custom icon is set, reuse as image on capable clients.
            $image = $icon;
        }
        if ($url === '') {
            $url = $this->defaultClickUrl();
        }
        if ($tag === '') {
            $tag = 'web-push-' . date('YmdHis');
        }

        $actions = [];
        if (!empty($input['actions']) && is_array($input['actions'])) {
            foreach ($input['actions'] as $actionItem) {
                if (!is_array($actionItem)) {
                    continue;
                }
                $action = trim((string)($actionItem['action'] ?? ''));
                $actionTitle = trim((string)($actionItem['title'] ?? ''));
                if ($action === '' || $actionTitle === '') {
                    continue;
                }
                $actions[] = [
                    'action' => mb_substr($action, 0, 64),
                    'title' => mb_substr($actionTitle, 0, 64),
                    'url' => $this->normalizeNotificationUrl((string)($actionItem['url'] ?? $url)),
                ];
                if (count($actions) >= 2) {
                    break;
                }
            }
        } elseif (!empty($input['action_title'])) {
            $actions[] = [
                'action' => 'open',
                'title' => mb_substr(trim((string)$input['action_title']), 0, 64),
                'url' => $url,
            ];
        }

        $settings = $this->getSettings();
        $ttl = isset($input['ttl']) ? (int)$input['ttl'] : (int)$settings['ttl'];
        $ttl = max(0, min(604800, $ttl));

        $urgency = $input['urgency'] ?? $settings['urgency'];
        if (!in_array($urgency, ['very-low', 'low', 'normal', 'high'], true)) {
            $urgency = 'normal';
        }

        return [
            'title' => mb_substr($title, 0, 120),
            'body' => mb_substr($body, 0, 500),
            'icon' => mb_substr($icon, 0, 512),
            'image' => $image !== '' ? mb_substr($image, 0, 512) : null,
            'badge' => mb_substr($badge, 0, 512),
            'url' => mb_substr($url, 0, 512),
            'tag' => mb_substr($tag, 0, 120),
            'actions' => $actions,
            'ttl' => $ttl,
            'urgency' => $urgency,
            'renotify' => !empty($input['renotify']),
            'requireInteraction' => !empty($input['require_interaction']),
        ];
    }

    private function normalizeNotificationUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url)
            || strpos($url, '\\') !== false) {
            return $this->defaultClickUrl();
        }

        $parts = parse_url($url);
        if (is_array($parts) && isset($parts['scheme'])) {
            $scheme = strtolower((string)$parts['scheme']);
            if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass'])) {
                return $this->defaultClickUrl();
            }
        } elseif (strpos($url, '//') === 0) {
            $baseScheme = parse_url($this->defaultClickUrl(), PHP_URL_SCHEME) ?: 'https';
            $url = $baseScheme . ':' . $url;
        }

        return $url;
    }

    public function encodePayload(array $payload)
    {
        $json = json_encode([
            'title' => $payload['title'],
            'body' => $payload['body'],
            'icon' => $payload['icon'],
            'image' => $payload['image'],
            'badge' => $payload['badge'],
            'url' => $payload['url'],
            'tag' => $payload['tag'],
            'actions' => $payload['actions'],
            'renotify' => !empty($payload['renotify']),
            'requireInteraction' => !empty($payload['requireInteraction']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('推送内容编码失败');
        }

        return $json;
    }

    public function sendNotice(Notice $notice)
    {
        $plainText = html_entity_decode(strip_tags((string)$notice->content), ENT_QUOTES, 'UTF-8');
        $plainText = trim((string)preg_replace('/\s+/u', ' ', $plainText));
        $iconUrl = trim((string)$notice->img_url);
        if ($iconUrl === '') {
            $iconUrl = $this->defaultIconUrl();
        }

        $payload = $this->normalizePayload([
            'title' => (string)$notice->title,
            'body' => mb_substr($plainText, 0, 180),
            'icon' => $iconUrl,
            'image' => $iconUrl,
            'url' => $this->defaultClickUrl(),
            'tag' => 'notice-' . $notice->id,
            'renotify' => true,
        ]);

        return $this->sendToQuery(WebPushSubscriptionModel::query(), $payload);
    }

    /**
     * @param Builder $query
     * @param array $payload normalized payload
     * @return array{configured:bool,total:int,sent:int,failed:int,expired:int}
     */
    public function sendToQuery(Builder $query, array $payload)
    {
        $stats = [
            'configured' => $this->isConfigured(),
            'total' => 0,
            'sent' => 0,
            'failed' => 0,
            'expired' => 0,
        ];

        if (!$stats['configured']) {
            return $stats;
        }

        $encodedPayload = $this->encodePayload($payload);
        $settings = $this->getSettings();
        $batchSize = max(1, (int)$settings['batch_size']);
        $service = $this;
        $topic = !empty($payload['tag']) ? (string)$payload['tag'] : null;

        $query->orderBy('id')->chunkById($batchSize, function ($subscriptions) use (
            &$stats,
            $encodedPayload,
            $payload,
            $service,
            $batchSize,
            $topic
        ) {
            $webPush = $service->createClient($batchSize, $payload);

            foreach ($subscriptions as $storedSubscription) {
                $stats['total']++;
                try {
                    $service->assertValidSubscription(
                        $storedSubscription->endpoint,
                        $storedSubscription->public_key,
                        $storedSubscription->auth_token
                    );
                } catch (WebPushEndpointResolutionException $error) {
                    $stats['failed']++;
                    Log::warning('Web push endpoint resolution failed; subscription retained', [
                        'subscription_id' => $storedSubscription->id,
                        'reason' => $error->getMessage(),
                    ]);
                    continue;
                } catch (\InvalidArgumentException $error) {
                    $stats['failed']++;
                    $storedSubscription->delete();
                    Log::warning('Invalid web push subscription removed', [
                        'subscription_id' => $storedSubscription->id,
                        'reason' => $error->getMessage(),
                    ]);
                    continue;
                }

                try {
                    $subscription = Subscription::create([
                        'endpoint' => $storedSubscription->endpoint,
                        'publicKey' => $storedSubscription->public_key,
                        'authToken' => $storedSubscription->auth_token,
                        'contentEncoding' => $storedSubscription->content_encoding ?: 'aes128gcm',
                    ]);

                    $options = [];
                    if ($topic) {
                        $options['topic'] = mb_substr($topic, 0, 32);
                    }

                    $webPush->queueNotification($subscription, $encodedPayload, $options);
                } catch (\Throwable $error) {
                    $stats['failed']++;
                    Log::warning('Web push subscription could not be queued; subscription retained', [
                        'subscription_id' => $storedSubscription->id,
                        'reason' => $error->getMessage(),
                    ]);
                }
            }

            foreach ($webPush->flush() as $report) {
                $endpointHash = hash('sha256', $report->getEndpoint());
                if ($report->isSuccess()) {
                    $stats['sent']++;
                    WebPushSubscriptionModel::where('endpoint_hash', $endpointHash)->update([
                        'last_used_at' => time(),
                    ]);
                    continue;
                }

                $stats['failed']++;
                if ($report->isSubscriptionExpired()) {
                    $stats['expired']++;
                    WebPushSubscriptionModel::where('endpoint_hash', $endpointHash)->delete();
                }

                Log::warning('Web push delivery failed', [
                    'endpoint_hash' => $endpointHash,
                    'expired' => $report->isSubscriptionExpired(),
                    'reason' => $report->getReason(),
                ]);
            }
        });

        return $stats;
    }

    public function sendToUserIds(array $userIds, array $payload)
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (empty($userIds)) {
            return [
                'configured' => $this->isConfigured(),
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
                'expired' => 0,
            ];
        }

        return $this->sendToQuery(
            WebPushSubscriptionModel::query()->whereIn('user_id', $userIds),
            $payload
        );
    }

    public function sendToSubscriptionIds(array $subscriptionIds, array $payload)
    {
        $subscriptionIds = array_values(array_unique(array_filter(array_map('intval', $subscriptionIds))));
        if (empty($subscriptionIds)) {
            return [
                'configured' => $this->isConfigured(),
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
                'expired' => 0,
            ];
        }

        return $this->sendToQuery(
            WebPushSubscriptionModel::query()->whereIn('id', $subscriptionIds),
            $payload
        );
    }

    /**
     * Build subscription query by admin target filters.
     */
    public function buildTargetQuery(array $target)
    {
        $query = WebPushSubscriptionModel::query();
        $targetType = (string)($target['type'] ?? 'all');

        if ($targetType === 'user') {
            $userId = (int)($target['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new \InvalidArgumentException('请指定用户 ID');
            }
            $query->where('user_id', $userId);
            return $query;
        }

        if ($targetType === 'subscription') {
            $subscriptionId = (int)($target['subscription_id'] ?? 0);
            if ($subscriptionId <= 0) {
                throw new \InvalidArgumentException('请指定订阅 ID');
            }
            $query->where('id', $subscriptionId);
            return $query;
        }

        if ($targetType === 'filter') {
            $planId = $target['plan_id'] ?? null;
            $banned = $target['banned'] ?? null;
            $hasPlan = array_key_exists('has_plan', $target) ? (bool)$target['has_plan'] : null;

            $query->whereIn('user_id', function ($subQuery) use ($planId, $banned, $hasPlan) {
                $subQuery->select('id')->from((new User())->getTable());
                if ($planId !== null && $planId !== '') {
                    $subQuery->where('plan_id', (int)$planId);
                }
                if ($banned !== null && $banned !== '') {
                    $subQuery->where('banned', (int)$banned);
                }
                if ($hasPlan === true) {
                    $subQuery->whereNotNull('plan_id')->where('plan_id', '>', 0);
                } elseif ($hasPlan === false) {
                    $subQuery->where(function ($innerQuery) {
                        $innerQuery->whereNull('plan_id')->orWhere('plan_id', 0);
                    });
                }
            });

            return $query;
        }

        // all
        return $query;
    }

    public function createClient($batchSize = 500, array $payload = [])
    {
        if (!class_exists(WebPush::class)) {
            throw new \RuntimeException(
                '缺少依赖 minishlink/web-push。请在服务器执行：composer require minishlink/web-push:^7.0 --no-dev -o'
            );
        }

        $settings = $this->getSettings();
        $clientOptions = [];
        $clientOptions['allow_redirects'] = false;
        $proxy = trim((string)$settings['proxy']);
        if ($proxy !== '') {
            $clientOptions['proxy'] = $proxy;
        }

        $caBundle = trim((string)$settings['ca_bundle']);
        if ($caBundle !== '') {
            if (!preg_match('/^(?:[a-z]:[\\\\\/]|[\\\\\/]{1,2})/i', $caBundle)) {
                $caBundle = base_path($caBundle);
            }
            if (!is_file($caBundle)) {
                throw new \RuntimeException('Web Push CA bundle 文件不存在');
            }
            $clientOptions['verify'] = $caBundle;
        }

        $ttl = isset($payload['ttl'])
            ? max(0, (int)$payload['ttl'])
            : max(0, (int)$settings['ttl']);
        $urgency = (string)($payload['urgency'] ?? $settings['urgency']);

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string)$settings['vapid_subject'],
                'publicKey' => (string)$settings['public_key'],
                'privateKey' => (string)$settings['private_key'],
            ],
        ], [
            'TTL' => $ttl,
            'urgency' => $urgency,
            'batchSize' => max(1, (int)$batchSize),
        ], max(1, (int)$settings['request_timeout']), $clientOptions);

        $webPush->setReuseVAPIDHeaders(true);
        return $webPush;
    }
}
