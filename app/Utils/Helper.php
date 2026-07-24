<?php

namespace App\Utils;
use App\Models\User;
use App\Support\ConfiguredUrl;
use Illuminate\Support\Facades\Cache;

class Helper
{
    public static function uuidToBase64($uuid, $length)
    {
        return base64_encode(substr($uuid, 0, $length));
    }

    public static function getServerKey($timestamp, $length)
    {
        $length = max(1, min(32, (int)$length));
        $secret = (string)config('v2board.server_token', '');
        if ($secret === '') {
            $secret = (string)config('app.key', '');
        }
        if ($secret === '') {
            throw new \RuntimeException('A server token or application key is required');
        }

        $key = hash_hmac('sha256', 'server-key:' . (string)$timestamp, $secret, true);
        return base64_encode(substr($key, 0, $length));
    }

    public static function getNodeToken($nodeId, $nodeType)
    {
        $nodeId = filter_var($nodeId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $nodeType = self::normalizeNodeType($nodeType);
        $secret = (string)config('v2board.server_token', '');

        if (!$nodeId || $nodeType === '' || $secret === '') {
            return '';
        }

        return hash_hmac('sha256', $nodeType . ':' . $nodeId, $secret);
    }

    public static function verifyNodeToken($token, $nodeId, $nodeType)
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $expected = self::getNodeToken($nodeId, $nodeType);
        if ($expected !== '' && hash_equals($expected, $token)) {
            return true;
        }

        $allowLegacy = filter_var(
            config('v2board.server_token_allow_legacy', true),
            FILTER_VALIDATE_BOOLEAN
        );
        $legacyToken = (string)config('v2board.server_token', '');

        return $allowLegacy
            && $legacyToken !== ''
            && hash_equals($legacyToken, $token);
    }

    public static function normalizeNodeType($nodeType)
    {
        $nodeType = strtolower(trim((string)$nodeType));
        if ($nodeType === 'v2ray') {
            return 'vmess';
        }
        if ($nodeType === 'hysteria2') {
            return 'hysteria';
        }

        return preg_match('/^[a-z0-9_-]+$/', $nodeType) ? $nodeType : '';
    }

    public static function guid($format = false)
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        if ($format) {
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }

        // Existing callers expect a 32-character token rather than a UUID.
        return bin2hex(random_bytes(16));
    }

    public static function getRealClientIp($request = null)
    {
        $request = $request ?: request();
        $candidates = [];

        // Proxy headers are examined directly because TrustProxies is not
        // configured; ordered by trustworthiness for CDN/reverse-proxy setups.
        $headerCandidates = [
            'CF-Connecting-IP',
            'True-Client-IP',
            'X-Real-IP',
            'X-Client-IP',
            'X-Forwarded-For',
        ];

        foreach ($headerCandidates as $headerName) {
            $headerValue = $request->headers->get($headerName);
            if (!$headerValue) {
                $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
                $headerValue = $request->server($serverKey)
                    ?: (isset($_SERVER[$serverKey]) ? $_SERVER[$serverKey] : null);
            }
            if (!$headerValue) {
                continue;
            }

            foreach (explode(',', (string)$headerValue) as $ipPart) {
                $candidateIp = self::normalizeIpCandidate(trim($ipPart));
                if ($candidateIp) {
                    $candidates[] = $candidateIp;
                }
            }
        }

        $fallbackIp = self::normalizeIpCandidate($request->ip());
        if ($fallbackIp) {
            $candidates[] = $fallbackIp;
        }

        $remoteAddr = $request->server('REMOTE_ADDR')
            ?: (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null);
        $remoteAddr = self::normalizeIpCandidate($remoteAddr);
        if ($remoteAddr) {
            $candidates[] = $remoteAddr;
        }

        // Prefer non-loopback so 127.0.0.1 does not hide the real client IP.
        foreach ($candidates as $candidateIp) {
            if (!self::isLoopbackIp($candidateIp)) {
                return $candidateIp;
            }
        }
        foreach ($candidates as $candidateIp) {
            return $candidateIp;
        }

        return '0.0.0.0';
    }

    private static function normalizeIpCandidate($ip)
    {
        if ($ip === null || $ip === false || $ip === '') {
            return null;
        }
        $candidateIp = trim((string)$ip);
        if ($candidateIp === '') {
            return null;
        }
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $candidateIp, $matches)) {
            $candidateIp = $matches[1];
        }
        if (preg_match('/^\[([^\]]+)\]:\d+$/', $candidateIp, $matches)) {
            $candidateIp = $matches[1];
        }
        if (filter_var($candidateIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return $candidateIp;
    }

    private static function isLoopbackIp($ip)
    {
        if ($ip === '::1' || $ip === '0.0.0.0' || $ip === '::ffff:127.0.0.1') {
            return true;
        }
        return strpos($ip, '127.') === 0;
    }

    public static function generateOrderNo(): string
    {
        return (new \DateTimeImmutable('now'))->format('YmdHisv') . random_int(10000, 99999);
    }

    public static function exchange($from, $to)
    {
        $from = strtoupper(trim((string)$from));
        $to = strtoupper(trim((string)$to));
        if (!preg_match('/\A[A-Z]{3}\z/', $from) || !preg_match('/\A[A-Z]{3}\z/', $to)) {
            throw new \InvalidArgumentException('Currency codes must be ISO 4217 three-letter codes');
        }

        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.exchangerate.host/',
                'connect_timeout' => 5,
                'timeout' => 15,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
            $response = $client->get('latest', [
                'query' => [
                    'symbols' => $to,
                    'base' => $from,
                ],
            ]);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Unable to retrieve exchange rate', 0, $error);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException('Exchange rate service returned an unsuccessful response');
        }

        try {
            $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Exchange rate service returned invalid JSON', 0, $error);
        }

        $rate = $payload['rates'][$to] ?? null;
        if (!is_numeric($rate) || (float)$rate <= 0) {
            throw new \RuntimeException('Exchange rate service returned an invalid rate');
        }

        return (float)$rate;
    }

    public static function randomChar($len, $special = false)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        if ($special) {
            $chars .= '!@#$?|{/:%^&*()-_[]}<>=+,.';
        }
        
        $str = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $len; $i++) {
            $str .= $chars[random_int(0, $max)];
        }
        return $str;
    }

    public static function multiPasswordVerify($algo, $salt, $password, $hash)
    {
        $password = (string)$password;
        $hash = (string)$hash;
        switch($algo) {
            case 'md5': return hash_equals($hash, md5($password));
            case 'sha256': return hash_equals($hash, hash('sha256', $password));
            case 'md5salt': return hash_equals($hash, md5($password . (string)$salt));
            default: return password_verify($password, $hash);
        }
    }

    public static function emailSuffixVerify($email, $suffixs)
    {
        $email = trim((string)$email);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            return false;
        }

        $suffix = strtolower(substr($email, $at + 1));
        if (!is_array($suffixs)) {
            $suffixs = explode(',', (string)$suffixs);
        }
        $suffixs = array_values(array_filter(array_map(function ($value) {
            return strtolower(trim((string)$value));
        }, $suffixs), function ($value) {
            return $value !== '';
        }));

        return in_array($suffix, $suffixs, true);
    }

    public static function trafficConvert(int $byte)
    {
        $kb = 1024;
        $mb = 1048576;
        $gb = 1073741824;
        if ($byte > $gb) {
            return round($byte / $gb, 2) . ' GB';
        } else if ($byte > $mb) {
            return round($byte / $mb, 2) . ' MB';
        } else if ($byte > $kb) {
            return round($byte / $kb, 2) . ' KB';
        } else if ($byte < 0) {
            return 0;
        } else {
            return round($byte, 2) . ' B';
        }
    }

    public static function getSubscribeUrl($token)
    {
        $token = trim((string)$token);
        if ($token === '') {
            throw new \InvalidArgumentException('Subscription token is required');
        }

        $submethod = (int)config('v2board.show_subscribe_method', 0);
        $path = trim((string)config('v2board.subscribe_path', '/api/v1/client/subscribe'));
        if ($path === '') {
            $path = '/api/v1/client/subscribe';
        }
        $path = '/' . ltrim($path, '/');

        $subscribeUrls = [];
        foreach (explode(',', (string)config('v2board.subscribe_url', '')) as $configuredUrl) {
            $configuredUrl = ConfiguredUrl::normalizeHttpUrl($configuredUrl);
            if ($configuredUrl !== '') {
                $subscribeUrls[] = $configuredUrl;
            }
        }
        $subscribeUrl = $subscribeUrls
            ? $subscribeUrls[random_int(0, count($subscribeUrls) - 1)]
            : '';

        $buildUrl = static function (string $subscriptionToken) use ($path, $subscribeUrl): string {
            $separator = strpos($path, '?') === false ? '?' : '&';
            $requestPath = $path . $separator . http_build_query([
                'token' => $subscriptionToken,
            ], '', '&', PHP_QUERY_RFC3986);
            if ($subscribeUrl !== '') {
                return $subscribeUrl . $requestPath;
            }

            return ConfiguredUrl::applicationPathUrl($requestPath);
        };

        switch ($submethod) {
            case 1:
                $newtoken = Cache::get("otp_{$token}");
                if (!is_string($newtoken) || $newtoken === '') {
                    $newtoken = self::base64EncodeUrlSafe(random_bytes(24));
                    if (!Cache::add("otp_{$token}", $newtoken, 86400)) {
                        $existingToken = Cache::get("otp_{$token}");
                        if (is_string($existingToken) && $existingToken !== '') {
                            $newtoken = $existingToken;
                        }
                    }
                    Cache::put("otpn_{$newtoken}", $token, 86400);
                }

                return $buildUrl($newtoken);
            case 2:
                $timestep = max(60, (int)config('v2board.show_subscribe_expire', 5) * 60);
                $counter = intdiv(time(), $timestep);
                $counterBytes = pack('N*', 0) . pack('N*', $counter);
                $hash = hash_hmac('sha1', $counterBytes, $token, false);
                $user = User::where('token', $token)->select('id')->first();
                if (!$user) {
                    throw new \RuntimeException('Subscription token user was not found');
                }

                return $buildUrl(self::base64EncodeUrlSafe("{$user->id}:{$hash}"));
            case 0:
            default:
                return $buildUrl($token);
        }
    }

    public static function randomPort($range)
    {
        $parts = array_map('trim', explode('-', (string)$range));
        if (count($parts) !== 2
            || !preg_match('/\A\d{1,5}\z/', $parts[0])
            || !preg_match('/\A\d{1,5}\z/', $parts[1])) {
            throw new \InvalidArgumentException('Port range must be in min-max format');
        }

        $min = (int)$parts[0];
        $max = (int)$parts[1];
        if ($min < 1 || $max > 65535 || $min > $max) {
            throw new \InvalidArgumentException('Port range is outside the valid TCP/UDP range');
        }

        return random_int($min, $max);
    }

    public static function base64EncodeUrlSafe($data)
    {
        $encoded = base64_encode($data);
        return str_replace(['+', '/', '='], ['-', '_', ''], $encoded);
    }

    public static function base64DecodeUrlSafe($data)
    {
        $data = (string)$data;
        if (!preg_match('/\A[A-Za-z0-9_-]*\z/', $data)) {
            return false;
        }

        $b64 = str_replace(['-', '_'], ['+', '/'], $data);
        $remainder = strlen($b64) % 4;
        if ($remainder === 1) {
            return false;
        }
        if ($remainder > 0) {
            $b64 .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode($b64, true);
    }

    public static function encodeURIComponent($str) {
        $revert = array('%21'=>'!', '%2A'=>'*', '%27'=>"'", '%28'=>'(', '%29'=>')');
        return strtr(rawurlencode($str), $revert);
    }

    public static function buildUri($uuid, $server)
    {
        if ($server['type'] == 'v2node') {
            $server['type'] = $server['protocol'];
        } 
        $method = "build" . ucfirst($server['type']) . "Uri";

        if (method_exists(self::class, $method)) {
            return self::$method($uuid, $server);
        }

        return '';
    }

    public static function buildUriString($scheme, $auth, $server, $name, $params = [])
    {
        $host = self::formatHost($server['host']);
        $port = $server['port'];
        $query = http_build_query($params);

        return "{$scheme}://{$auth}@{$host}:{$port}?{$query}#{$name}\r\n";
    }

    public static function formatHost($host)
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$host]" : $host;
    }

    public static function buildShadowsocksUri($uuid, $server)
    {
        $cipher = $server['cipher'];
        if (strpos($cipher, '2022-blake3') !== false) {
            $length = $cipher === '2022-blake3-aes-128-gcm' ? 16 : 32;
            $serverKey = Helper::getServerKey($server['created_at'], $length);
            $userKey = Helper::uuidToBase64($uuid, $length);
            $password = "{$serverKey}:{$userKey}";
        } else {
            $password = $uuid;
        }
        $name = rawurlencode($server['name']);
        $str = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode("{$cipher}:{$password}"));
        $add = self::formatHost($server['host']);
        $uri = "ss://{$str}@{$add}:{$server['port']}";
        if ($server['obfs'] == 'http') {
            $uri .= "?plugin=obfs-local;obfs=http;obfs-host={$server['obfs-host']};path={$server['obfs-path']}";
        } else if ((($server['network'] ?? null) == 'http') && isset($server['network_settings']['Host'])) {
            $path = $server['network_settings']['path'] ?? '/';
            $uri .= "?plugin=obfs-local;obfs=tls;obfs-host={$server['network_settings']['Host']};path={$path}";
        }
        return $uri."#{$name}\r\n";
    }

    public static function buildVmessUri($uuid, $server)
    {
        $config = [
            "v" => "2",
            "ps" => $server['name'],
            "add" => self::formatHost($server['host']),
            "port" => (string)$server['port'],
            "id" => $uuid,
            "aid" => '0',
            "scy" => 'auto',
            "net" => $server['network'],
            "type" => 'none',
            "host" => '',
            "path" => '',
            "tls" => $server['tls'] ? "tls" : "",
            "fp" => 'chrome',
        ];

        if ($server['tls']) {
            $tlsSettings = $server['tls_settings'] ?? $server['tlsSettings'] ?? [];
            $config['allowInsecure'] = (int)($tlsSettings['allow_insecure'] ?? $tlsSettings['allowInsecure'] ?? 0);
            $config['sni'] = $tlsSettings['server_name'] ?? $tlsSettings['serverName'] ?? '';
        }
        
        $network = (string)$server['network'];
        $networkSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? []);
    
        switch ($network) {
            case 'tcp':
                if (!empty($networkSettings['header']['type']) && $networkSettings['header']['type'] === 'http') {
                    $config['type'] = $networkSettings['header']['type'];
                    $config['host'] = $networkSettings['header']['request']['headers']['Host'][0] ?? null;
                    $config['path'] = $networkSettings['header']['request']['path'][0] ?? null;
                }
                break;
    
            case 'ws':
                $config['path'] = $networkSettings['path'] ?? null;
                $config['host'] = $networkSettings['headers']['Host'] ?? null;
                isset($networkSettings['security']) && $config['scy'] = $networkSettings['security'];
                break;
    
            case 'grpc':
                $config['path'] = $networkSettings['serviceName'] ?? null;
                break;

            case 'kcp':
                if (isset($networkSettings['seed'])) {
                    $config['path'] = $networkSettings['seed'];
                }
                $config['type'] = $networkSettings['header']['type'] ?? 'none';
                break;

            case 'httpupgrade':
                $config['path'] = $networkSettings['path'] ?? null;
                $config['host'] = $networkSettings['host'] ?? null;
                break;
            
            case 'xhttp':
                $config['path'] = $networkSettings['path'] ?? null;
                $config['host'] = $networkSettings['host'] ?? null;
                $config['mode'] = $networkSettings['mode'] ?? 'auto';
                $config['extra'] = isset($networkSettings['extra']) ? json_encode($networkSettings['extra'], JSON_UNESCAPED_SLASHES) : null;
                break;
        }

        return "vmess://" . base64_encode(json_encode($config)) . "\r\n";
    }

    public static function buildVlessUri($uuid, $server)
    {
        $name = self::encodeURIComponent($server['name']);
        $tlsSettings = $server['tls_settings'] ?? [];

        $config = [
            "type" => $server['network'],
            "encryption" => "none",
            "host" => "",
            "path" => "",
            "headerType" => "none",
            "quicSecurity" => "none",
            "serviceName" => "",
            "security" => $server['tls'] != 0 ? ($server['tls'] == 2 ? "reality" : "tls") : "",
            "flow" => $server['flow'],
            "fp" => $tlsSettings['fingerprint'] ?? 'chrome',
            "insecure" => $tlsSettings['allow_insecure'] ?? 0,
        ];

        if ($server['tls']) {
            $tlsSettings = $server['tls_settings'] ?? [];
            $config['sni'] = $tlsSettings['server_name'] ?? '';
            if ($server['tls'] == 2) {
                $config['pbk'] = $tlsSettings['public_key'] ?? '';
                $config['sid'] = $tlsSettings['short_id'] ?? '';
            }
        }
        if (!empty($tlsSettings['ech'])) {
            if ($tlsSettings['ech'] === 'cloudflare') {
                $config['ech'] = 'cloudflare-ech.com+https://doh.pub/dns-query';
            } elseif ($tlsSettings['ech'] === 'custom' && !empty($tlsSettings['ech_config'])) {
                $config['ech'] = is_array($tlsSettings['ech_config']) ? $tlsSettings['ech_config'][0] : $tlsSettings['ech_config'];
            }
        }
        if (isset($server['encryption']) && $server['encryption'] == 'mlkem768x25519plus') {
            $encSettings = $server['encryption_settings'];
            $enc = 'mlkem768x25519plus.' . ($encSettings['mode'] ?? 'native') . '.' . ($encSettings['rtt'] ?? '1rtt');
            if (isset($encSettings['client_padding']) && !empty($encSettings['client_padding'])) {
                $enc .= '.' . $encSettings['client_padding'];
            }
            $enc .= '.' . ($encSettings['password'] ?? '');
            $config['encryption'] = $enc;
        }

        self::configureNetworkSettings($server, $config);

        return self::buildUriString('vless', $uuid, $server, $name, $config);
    }

    public static function buildTrojanUri($password, $server)
    {
        $tlsSettings = $server['tls_settings'] ?? [];
        $config = [
            'allowInsecure' => $server['allow_insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0),
            'peer' => $server['server_name'] ?? ($tlsSettings['server_name'] ?? ''),
            'sni' => $server['server_name'] ?? ($tlsSettings['server_name'] ?? ''),
            'type'=> $server['network'],
        ];

        if(isset($server['network']) && in_array($server['network'], ["grpc", "ws"])){
            if($server['network'] === "grpc" && isset($server['network_settings']['serviceName'])) {
                $config['serviceName'] = $server['network_settings']['serviceName'];
            }
            if($server['network'] === "ws") {
                if(isset($server['network_settings']['path'])) {
                    $config['path'] = $server['network_settings']['path'];
                }
                if(isset($server['network_settings']['headers']['Host'])) {
                    $config['host'] = $server['network_settings']['headers']['Host'];
                }
            }
        }
        if (!empty($tlsSettings['ech'])) {
            if ($tlsSettings['ech'] === 'cloudflare') {
                $config['ech'] = 'cloudflare-ech.com+https://doh.pub/dns-query';
            } elseif ($tlsSettings['ech'] === 'custom' && !empty($tlsSettings['ech_config'])) {
                $config['ech'] = is_array($tlsSettings['ech_config']) ? $tlsSettings['ech_config'][0] : $tlsSettings['ech_config'];
            }
        }
        $query = http_build_query($config);
        return "trojan://{$password}@" . self::formatHost($server['host']) . ":{$server['port']}?{$query}#". rawurlencode($server['name']) . "\r\n";
    }

    public static function buildHysteriaUri($password, $server)
    {
        $remote = self::formatHost($server['host']);
        $name = self::encodeURIComponent($server['name']);

        $parts = explode(",", $server['port']);
        $firstPort = strpos($parts[0], '-') !== false ? explode('-', $parts[0])[0] : $parts[0];

        $uri = $server['version'] == 2 ?
            "hysteria2://{$password}@{$remote}:{$firstPort}/?insecure={$server['insecure']}&sni={$server['server_name']}" :
            "hysteria://{$remote}:{$firstPort}/?protocol=udp&auth={$password}&insecure={$server['insecure']}&peer={$server['server_name']}&upmbps={$server['down_mbps']}&downmbps={$server['up_mbps']}";

        if (isset($server['obfs']) && isset($server['obfs_password'])) {
            $obfs_password = rawurlencode($server['obfs_password']);
            $uri .= $server['version'] == 2 ? 
                "&obfs={$server['obfs']}&obfs-password={$obfs_password}" :
                "&obfs={$server['obfs']}&obfsParam{$obfs_password}";
        }
        if (count($parts) !== 1 || strpos($parts[0], '-') !== false) {
            $uri .= "&mport={$server['mport']}";
        }
        return "{$uri}#{$name}\r\n";
    }

    public static function buildHysteria2Uri($password, $server)
    {
        $remote = self::formatHost($server['host']);
        $name = self::encodeURIComponent($server['name']);

        $parts = explode(",", $server['port']);
        $firstPort = strpos($parts[0], '-') !== false ? explode('-', $parts[0])[0] : $parts[0];
        $tlsSettings = $server['tls_settings'] ?? [];
        $insecure = $tlsSettings['allow_insecure'] ?? 0;
        $sni = $tlsSettings['server_name'] ?? '';
        $uri = "hysteria2://{$password}@{$remote}:{$firstPort}/?insecure={$insecure}&sni={$sni}";

        if (isset($server['obfs']) && isset($server['obfs_password'])) {
            $obfs_password = rawurlencode($server['obfs_password']);
            $uri .= "&obfs={$server['obfs']}&obfs-password={$obfs_password}";
        }
        if (count($parts) !== 1 || strpos($parts[0], '-') !== false) {
            $uri .= "&mport={$server['mport']}";
        }
        return "{$uri}#{$name}\r\n";
    }

    public static function buildTuicUri($password, $server)
    {
        $tlsSettings = $server['tls_settings'] ?? [];
        $config = [
            'sni' => $server['server_name'] ?? ($tlsSettings['server_name'] ?? ''),
            'alpn'=> 'h3',
            'congestion_control' => $server['congestion_control'],
            'allow_insecure' => $server['insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0),
            'disable_sni' => $server['disable_sni'],
            'udp_relay_mode' => $server['udp_relay_mode'],
        ];

        $remote = self::formatHost($server['host']);
        $port = $server['port'];
        $name = self::encodeURIComponent($server['name']);

        $query = http_build_query($config);
        return "tuic://{$password}:{$password}@{$remote}:{$port}?{$query}#{$name}\r\n";
    }

    public static function buildAnytlsUri($password, $server)
    {
        $tlsSettings = $server['tls_settings'] ?? [];
        $config = [
            'type' => $server['network'] ?? 'tcp',
            'insecure' => $server['insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0),
            'fp' => $tlsSettings['fingerprint'] ?? 'chrome',
        ];
        if (isset($server['server_name']) || isset($tlsSettings['server_name'])) {
            $config['sni'] = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');
        }
        if (isset($server['tls']) && $server['tls'] == 2) {
            $config['security'] = 'reality';
            $config['pbk'] = $tlsSettings['public_key'] ?? '';
            $config['sid'] = $tlsSettings['short_id'] ?? '';
        }
        $remote = self::formatHost($server['host']);
        $port = $server['port'];
        $name = self::encodeURIComponent($server['name']);
        if (isset($server['network']) && isset($server['network_settings'])) {
            self::configureNetworkSettings($server, $config);
        }
        $query = http_build_query($config);
        return "anytls://{$password}@{$remote}:{$port}/?{$query}#{$name}\r\n";
    }

    /**
     * Generate ECH (Encrypted Client Hello) key pair for sing-box.
     * Produces ech_key (MarshalECHKeys format, for server inbound)
     * and ech_config (ECHConfigList, for client outbound).
     *
     * @param string $outerSni The cover/front domain for the outer ClientHello SNI (public_name).
     *                         This is the FAKE domain visible to network observers.
     *                         The real server_name is encrypted in the inner ClientHello.
     */
    public static function generateEchKeyPair($outerSni)
    {
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        // ECHConfig contents per draft-ietf-tls-esni
        $configData = pack('C', $configId);              // config_id
        $configData .= pack('n', 0x0020);                // kem_id: DHKEM(X25519, HKDF-SHA256)
        $configData .= pack('n', 32) . $publicKey;       // public_key with length prefix
        // cipher suites: {HKDF-SHA256, AES-128-GCM}, {HKDF-SHA256, AES-256-GCM}, {HKDF-SHA256, ChaCha20-Poly1305}
        $suites = pack('nnnnnn', 0x0001, 0x0001, 0x0001, 0x0002, 0x0001, 0x0003);
        $configData .= pack('n', strlen($suites)) . $suites;
        $configData .= pack('C', 0);                     // maximum_name_length
        $configData .= pack('C', strlen($outerSni)) . $outerSni; // public_name (cover domain, NOT real SNI)
        $configData .= pack('n', 0);                     // extensions (empty)

        // ECHConfig = version(0xfe0d) + length + data
        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($configData)) . $configData;

        // ECHConfigList for client (no outer length prefix, per Go crypto/tls)
        $echConfigList = $echConfig;

        // MarshalECHKeys for server: length-prefixed configs + key entries
        $echKeys = pack('n', strlen($echConfig)) . $echConfig;
        $echKeys .= pack('n', 1);                        // num_keys = 1
        $echKeys .= pack('C', $configId);                // config_id
        $echKeys .= pack('n', 32) . $privateKey;         // private key with length prefix

        return [
            'ech_key' => base64_encode($echKeys),
            'ech_config' => base64_encode($echConfigList),
        ];
    }

    public static function configureNetworkSettings($server, &$config)
    {
        $network = $server['network'];
        $settings = $server['network_settings'] ?? ($server['networkSettings'] ?? []);

        switch ($network) {
            case 'tcp':
                self::configureTcpSettings($settings, $config);
                break;
            case 'ws':
                self::configureWsSettings($settings, $config);
                break;
            case 'grpc':
                self::configureGrpcSettings($settings, $config);
                break;
            case 'kcp':
                self::configureKcpSettings($settings, $config);
                break;
            case 'httpupgrade':
                self::configureHttpupgradeSettings($settings, $config);
                break;
            case 'xhttp':
                self::configureXhttpSettings($settings, $config);
                break;
        }
    }

    public static function configureTcpSettings($settings, &$config)
    {
        $header = $settings['header'] ?? [];
        if (isset($header['type']) && $header['type'] === 'http') {
            $config['headerType'] = 'http';
            $config['host'] = $header['request']['headers']['Host'][0] ?? '';
            $config['path'] = $header['request']['path'][0] ?? '';
        }
    }

    public static function configureWsSettings($settings, &$config)
    {
        $config['path'] = $settings['path'] ?? '';
        $config['host'] = $settings['headers']['Host'] ?? '';
    }

    public static function configureGrpcSettings($settings, &$config)
    {
        $config['serviceName'] = $settings['serviceName'] ?? '';
    }

    public static function configureKcpSettings($settings, &$config)
    {
        $config['headerType'] = $settings['header']['type'] ?? 'none';
        if (isset($settings['seed'])) {
            $config['seed'] = $settings['seed'];
        }
    }

    public static function configureHttpupgradeSettings($settings, &$config)
    {
        $config['path'] = $settings['path'] ?? '';
        $config['host'] = $settings['host'] ?? '';
    }

    public static function configureXhttpSettings($settings, &$config)
    {
        $config['path'] = $settings['path'] ?? '';
        $config['host'] = $settings['host'] ?? '';
        $config['mode'] = $settings['mode'] ?? 'auto';
        $config['extra'] = isset($settings['extra']) ? json_encode($settings['extra'], JSON_UNESCAPED_SLASHES) : null;
    }
}
