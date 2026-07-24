<?php

namespace App\Http\Middleware;

use Fideloper\Proxy\TrustProxies as Middleware;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array|string
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_ALL;

    /**
     * Trusted proxies are read from config('v2board.trusted_proxies') so that
     * operators can make $request->ip() resolve the real client behind their
     * reverse proxy. Accepts a comma-separated string or an array of IPs/CIDRs
     * ('*' trusts all). When unset it defaults to null (trust no proxy), which
     * preserves the previous behaviour and keeps existing deployments intact.
     */
    public function __construct(Repository $config)
    {
        parent::__construct($config);

        $configured = $config->get('v2board.trusted_proxies');

        if (is_string($configured)) {
            $configured = trim($configured);
            $this->proxies = $configured === '' ? null : $configured;
            return;
        }

        if (is_array($configured)) {
            $configured = array_values(array_filter(array_map(function ($value) {
                return is_string($value) ? trim($value) : $value;
            }, $configured), function ($value) {
                return $value !== '' && $value !== null;
            }));
            $this->proxies = $configured === [] ? null : $configured;
            return;
        }

        $this->proxies = null;
    }
}
