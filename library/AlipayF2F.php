<?php
namespace Library;

use Illuminate\Support\Facades\Http;

class AlipayF2F {
    private $appId;
    private $privateKey;
    private $alipayPublicKey;
    private $signType = 'RSA2';
    public $bizContent;
    public $method;
    public $notifyUrl;
    public $response;

    public function __construct()
    {
    }

    public function verify($data): bool
    {
        if (is_string($data)) {
            parse_str($data, $data);
        }
        if (!is_array($data) || empty($data['sign'])) {
            return false;
        }
        foreach ($data as $value) {
            if ($value !== null && !is_string($value) && !is_int($value) && !is_float($value)) {
                return false;
            }
        }

        $sign = base64_decode((string) $data['sign'], true);
        if ($sign === false) {
            return false;
        }
        unset($data['sign']);
        unset($data['sign_type']);
        ksort($data);
        $data = $this->buildQuery($data);
        $publicKey = $this->loadPublicKey($this->alipayPublicKey);
        $algorithm = $this->signType === 'RSA2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
        $result = openssl_verify($data, $sign, $publicKey, $algorithm) === 1;
        openssl_pkey_free($publicKey);

        return $result;
    }

    public function setBizContent($bizContent = [])
    {
        $this->bizContent = json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->bizContent === false) {
            throw new \InvalidArgumentException('支付宝业务参数编码失败');
        }
    }

    public function setMethod($method)
    {
        $this->method = $method;
    }

    public function setAppId($appId)
    {
        $this->appId = $appId;
    }

    public function setPrivateKey($privateKey)
    {
        $this->privateKey = $privateKey;
    }

    public function setAlipayPublicKey($alipayPublicKey)
    {
        $this->alipayPublicKey = $alipayPublicKey;
    }

    public function setNotifyUrl($url)
    {
        $this->notifyUrl = $url;
    }

    public function send()
    {
        $response = Http::withOptions(['connect_timeout' => 10])
            ->timeout(30)
            ->get('https://openapi.alipay.com/gateway.do', $this->buildParam())
            ->throw()
            ->json();
        $resKey = str_replace('.', '_', $this->method) . '_response';
        if (!is_array($response) || !isset($response[$resKey]) || !is_array($response[$resKey])) {
            throw new \RuntimeException('从支付宝请求失败');
        }
        $response = $response[$resKey];
        if (($response['msg'] ?? null) !== 'Success') {
            throw new \RuntimeException((string) ($response['sub_msg'] ?? '支付宝返回失败'));
        }
        $this->response = $response;
    }

    public function getQrCodeUrl()
    {
        $response = $this->response;
        if (!isset($response['qr_code'])) throw new \Exception('获取付款二维码失败');
        return $response['qr_code'];
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function buildParam(): array
    {
        $params = [
            'app_id' => $this->appId,
            'method' => $this->method,
            'charset' => 'UTF-8',
            'sign_type' => $this->signType,
            'timestamp' => date('Y-m-d H:i:s'),
            'biz_content' => $this->bizContent,
            'version' => '1.0',
            '_input_charset' => 'UTF-8'
        ];
        if ($this->notifyUrl) $params['notify_url'] = $this->notifyUrl;
        ksort($params);
        $params['sign'] = $this->buildSign($this->buildQuery($params));
        return $params;
    }

    public function buildQuery($query)
    {
        if (!$query) {
            throw new \Exception('参数构造错误');
        }
        //将要 参数 排序
        ksort($query);

        //重新组装参数
        $params = array();
        foreach ($query as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $params[] = $key . '=' . $value;
        }
        $data = implode('&', $params);
        return $data;
    }

    private function buildSign(string $signData): string
    {
        $privateKey = $this->loadPrivateKey($this->privateKey);
        $signature = '';
        $algorithm = $this->signType === 'RSA2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
        if (!openssl_sign($signData, $signature, $privateKey, $algorithm)) {
            openssl_pkey_free($privateKey);
            throw new \RuntimeException('支付宝请求签名失败');
        }
        openssl_pkey_free($privateKey);

        return base64_encode($signature);
    }

    private function loadPrivateKey($key)
    {
        $key = $this->readKeyMaterial($key);
        $candidates = [$key];
        if (strpos($key, '-----BEGIN') === false) {
            $body = preg_replace('/\s+/', '', $key);
            if (!is_string($body) || $body === '') {
                throw new \RuntimeException('支付宝私钥无效');
            }
            $body = wordwrap($body, 64, "\n", true);
            $candidates = [
                "-----BEGIN PRIVATE KEY-----\n{$body}\n-----END PRIVATE KEY-----",
                "-----BEGIN RSA PRIVATE KEY-----\n{$body}\n-----END RSA PRIVATE KEY-----"
            ];
        }

        foreach ($candidates as $candidate) {
            $privateKey = @openssl_pkey_get_private($candidate);
            if ($privateKey !== false) {
                return $privateKey;
            }
        }

        throw new \RuntimeException('支付宝私钥无效');
    }

    private function loadPublicKey($key)
    {
        $key = $this->readKeyMaterial($key);
        $candidates = [$key];
        if (strpos($key, '-----BEGIN') === false) {
            $body = preg_replace('/\s+/', '', $key);
            if (!is_string($body) || $body === '') {
                throw new \RuntimeException('支付宝公钥无效');
            }
            $body = wordwrap($body, 64, "\n", true);
            $candidates = [
                "-----BEGIN PUBLIC KEY-----\n{$body}\n-----END PUBLIC KEY-----",
                "-----BEGIN RSA PUBLIC KEY-----\n{$body}\n-----END RSA PUBLIC KEY-----"
            ];
        }

        foreach ($candidates as $candidate) {
            $publicKey = @openssl_pkey_get_public($candidate);
            if ($publicKey !== false) {
                return $publicKey;
            }
        }

        throw new \RuntimeException('支付宝公钥无效');
    }

    private function readKeyMaterial($key): string
    {
        $key = trim((string) $key);
        if ($key === '') {
            throw new \RuntimeException('支付宝密钥不能为空');
        }
        $looksLikeInlineKey = strpos($key, '-----BEGIN') !== false
            || (strlen($key) >= 128 && preg_match('/\A[A-Za-z0-9+\/=\s]+\z/', $key) === 1);
        $maxPathLength = defined('PHP_MAXPATHLEN') ? PHP_MAXPATHLEN : 4096;
        $isSafePath = !$looksLikeInlineKey
            && strpos($key, "\0") === false
            && strlen($key) <= $maxPathLength;
        if ($isSafePath && is_file($key)) {
            if (!is_readable($key)) {
                throw new \RuntimeException('支付宝密钥不可读');
            }
            $contents = file_get_contents($key);
            if ($contents === false) {
                throw new \RuntimeException('支付宝密钥读取失败');
            }
            $key = trim($contents);
        }

        return str_replace("\r\n", "\n", $key);
    }
}
