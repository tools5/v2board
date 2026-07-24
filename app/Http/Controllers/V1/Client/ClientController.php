<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = strtolower((string)($request->input('flag')
            ?? $request->userAgent()
            ?? ''));
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            if ($flag !== '') {
                if (strpos($flag, 'sing') === false) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (self::protocolFlagMap() as $class => $protocolFlag) {
                        if ($protocolFlag !== '' && strpos($flag, $protocolFlag) !== false) {
                            $instance = new $class($user, $servers);
                            return $instance->handle();
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if ($version !== null && version_compare($version, '1.12.0', '>=')) {
                        $class = new Singbox($user, $servers);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    return $class->handle();
                }
            }
            $class = new General($user, $servers);
            return $class->handle();
        }
    }

    /**
     * 有序的「协议类 => flag」映射，按 array_reverse(glob) 顺序（与原逻辑一致）。
     * flag 是各协议类的字面量默认属性，用 get_class_vars 读取即可，无需实例化；
     * 结果在进程内静态缓存，避免每次订阅请求都做一次目录 glob 与全量实例化。
     */
    private static function protocolFlagMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
            $class = 'App\\Protocols\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            $vars = get_class_vars($class);
            $map[$class] = isset($vars['flag']) ? (string)$vars['flag'] : '';
        }
        return $map;
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }
}
