<?php

namespace App\Http\Controllers\V2\Server;

use App\Support\EtagMatcher;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use Illuminate\Http\Request;
use App\Utils\Helper;
use Illuminate\Http\JsonResponse;

class ServerController extends Controller
{
    private $nodeInfo;
    private $nodeId;
    private $serverService;

    public function __construct(ServerService $serverService)
    {
        $this->serverService = $serverService;
    }

    private function authenticateNode(Request $request): ?JsonResponse
    {
        $token = $request->input('token');

        $this->nodeId = filter_var($request->input('node_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        // token 为空（业务失败，不抛异常）
        if (empty($token)) {
            return response()->json([
                'status' => 'fail',
                'message' => 'token is null'
            ], 200);
        }

        if (!$this->nodeId) {
            return response()->json([
                'status' => 'fail',
                'message' => 'node parameter is error'
            ], 200);
        }

        // token 错误
        if (!Helper::verifyNodeToken($token, $this->nodeId, 'v2node')) {
            return response()->json([
                'status' => 'fail',
                'message' => 'token is error'
            ], 200);
        }

        $this->nodeInfo = $this->serverService->getServer($this->nodeId, "v2node");

        // 节点不存在
        if (!$this->nodeInfo) {
            return response()->json([
                'status' => 'fail',
                'message' => 'server is not exist'
            ], 200);
        }

        return null;
    }

    // 后端获取配置
    public function config(Request $request)
    {
        if ($authFailure = $this->authenticateNode($request)) {
            return $authFailure;
        }

        $response = [
            'listen_ip' => $this->nodeInfo->listen_ip,
            'server_port' => $this->nodeInfo->server_port,
            'network' => $this->nodeInfo->network,
            'network_settings' => $this->nodeInfo->network_settings,
            'trusted_x_forwarded_for'=> $this->nodeInfo->trusted_x_forwarded_for,
            'protocol' => $this->nodeInfo->protocol,
            'tls' => $this->nodeInfo->tls,
            'tls_settings' => $this->nodeInfo->tls_settings,
            'encryption' => $this->nodeInfo->encryption,
            'encryption_settings' => $this->nodeInfo->encryption_settings,
            'flow' => $this->nodeInfo->flow,
            'cipher' => $this->nodeInfo->cipher,
            'congestion_control' => $this->nodeInfo->congestion_control,
            'zero_rtt_handshake' => $this->nodeInfo->zero_rtt_handshake ? true : false,
            'up_mbps' => $this->nodeInfo->up_mbps,
            'down_mbps' => $this->nodeInfo->down_mbps,
            'obfs' => $this->nodeInfo->obfs,
            'obfs_password' => $this->nodeInfo->obfs_password,
            'padding_scheme' => $this->nodeInfo->padding_scheme
        ];

        if ($this->nodeInfo->cipher === '2022-blake3-aes-128-gcm') {
            $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 16);
        }

        if ($this->nodeInfo->cipher === '2022-blake3-aes-256-gcm') {
            $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 32);
        }

        if ($this->nodeInfo->up_mbps == 0 && $this->nodeInfo->down_mbps == 0) {
            $response['ignore_client_bandwidth'] = true;
        } else {
            $response['ignore_client_bandwidth'] = false;
        }

        $response['base_config'] = [
            'push_interval' => (int)config('v2board.server_push_interval', 60),
            'pull_interval' => (int)config('v2board.server_pull_interval', 60),
            'node_report_min_traffic' => (int)config('v2board.server_node_report_min_traffic', 0),
            'device_online_min_traffic' => (int)config('v2board.server_device_online_min_traffic', 0)
        ];

        if ($this->nodeInfo['route_id']) {
            $response['routes'] = $this->serverService->getRoutes($this->nodeInfo['route_id']);
        }

        $rsp = json_encode($response);
        $eTag = sha1($rsp);

        // 不使用 abort(304)，避免异常路径
        if (EtagMatcher::matches($request->header('If-None-Match'), $eTag)) {
            return response('', 304)->header('ETag', "\"{$eTag}\"");
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }
}
