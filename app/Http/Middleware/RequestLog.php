<?php

namespace App\Http\Middleware;

use Closure;

class RequestLog
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($request->method() === 'POST') {
            $path = $request->path();
            // 节点通信接口由后端高频轮询（上报流量/在线、拉取用户），逐条落盘意义不大且量极大，跳过。
            if (!$this->isHighFrequencyPath($path)) {
                info("POST {$path}");
            }
        };
        return $next($request);
    }

    private function isHighFrequencyPath($path): bool
    {
        $path = ltrim((string)$path, '/');
        return strpos($path, 'api/v1/server/') === 0
            || strpos($path, 'api/v2/server/') === 0;
    }
}
