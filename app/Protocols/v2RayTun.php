<?php

namespace App\Protocols;

use App\Support\SubscriptionHeaders;
use App\Utils\Helper;

class v2RayTun
{
    private const MAX_ANNOUNCE_LENGTH = 4096;
    private const MAX_ANNOUNCE_URL_LENGTH = 2048;
    private const MAX_PROFILE_UPDATE_INTERVAL = 720;

    public $flag = 'v2raytun';
    private $servers;
    private $user;
    private $options;

    public function __construct($user, $servers, array $options = null)
    {
        $this->user = $user;
        $this->servers = $servers;
        $this->options = $options ?? [];
    }

    public function handle()
    {
        // 节点组内容，和 V2rayNG 一致
        $uri = '';
        foreach ($this->servers as $server) {
            $uri .= Helper::buildUri($this->user['uuid'], $server);
        }
        $body = base64_encode($uri);

        // Build v2raytun headers from bounded, validated values only.
        $appName = SubscriptionHeaders::applicationName();
        $headers = [
            'profile-title' => $this->getProfileTitle($appName),
            'subscription-userinfo' => $this->getUserInfoHeader($this->user),
            'profile-update-interval' => $this->getProfileUpdateInterval(),
        ];

        $routing = SubscriptionHeaders::value($this->options['routing'] ?? null);
        if ($routing !== null) {
            $headers['routing'] = $routing;
        }

        $announce = $this->getAnnounceHeader($this->options['announce'] ?? null);
        if ($announce !== null) {
            $headers['announce'] = $announce;
        }

        $announceUrl = $this->getAnnounceUrlHeader($this->options['announce_url'] ?? null);
        if ($announceUrl !== null) {
            $headers['announce-url'] = $announceUrl;
        }

        if ($this->isEnabled($this->options['update_always'] ?? null)) {
            $headers['update-always'] = 'true';
        }

        $headers['Content-Disposition'] = SubscriptionHeaders::contentDisposition($appName);

        $response = response($body, 200);
        foreach ($headers as $name => $value) {
            $safeValue = SubscriptionHeaders::value($value);
            if ($safeValue !== null) {
                $response->header($name, $safeValue);
            }
        }

        return $response;
    }

    // profile-title 支持 base64 和原文
    protected function getProfileTitle($appName)
    {
        if ($this->isEnabled($this->options['profile_title_base64'] ?? null)) {
            return SubscriptionHeaders::base64ProfileTitle($appName);
        }

        return $appName;
    }

    // subscription-userinfo
    protected function getUserInfoHeader($user)
    {
        return SubscriptionHeaders::userInfo($user);
    }

    // announce 支持 base64 和原文
    protected function getAnnounceHeader($announce)
    {
        $base64 = $this->isEnabled($this->options['announce_base64'] ?? null);
        // A base64 payload grows by roughly a third, so bound the source accordingly.
        $maxLength = $base64 ? 3000 : self::MAX_ANNOUNCE_LENGTH;
        $announce = SubscriptionHeaders::value($announce, $maxLength);
        if ($announce === null) {
            return null;
        }

        return $base64 ? 'base64:' . base64_encode($announce) : $announce;
    }

    private function getProfileUpdateInterval(): string
    {
        $value = $this->options['profile_update_interval'] ?? 24;
        if ((!is_int($value) && !is_string($value))
            || !preg_match('/\A\d{1,3}\z/', (string)$value)) {
            return '24';
        }

        $interval = (int)$value;
        if ($interval < 1 || $interval > self::MAX_PROFILE_UPDATE_INTERVAL) {
            return '24';
        }

        return (string)$interval;
    }

    private function getAnnounceUrlHeader($url): ?string
    {
        $url = SubscriptionHeaders::value($url, self::MAX_ANNOUNCE_URL_LENGTH);
        if ($url === null || strpos($url, '\\') !== false) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower(rtrim(trim((string)$parts['host'], '[]'), '.'));
        if ($host === ''
            || (filter_var($host, FILTER_VALIDATE_IP) === false
                && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            return null;
        }

        if (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535)) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) === false ? null : $url;
    }

    private function isEnabled($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
