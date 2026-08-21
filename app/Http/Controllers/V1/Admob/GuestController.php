<?php

namespace App\Http\Controllers\V1\Admob;

use App\Http\Controllers\Controller;
use App\Services\AdmobRewardService;
use Illuminate\Http\Request;

class GuestController extends Controller
{
    /**
     * Google AdMob 服务端验证（SSV）回调。
     * 在 AdMob 后台把回调 URL 配置为 {站点}/api/v1/admob/guest/ssv。
     */
    public function ssv(Request $request)
    {
        $service = new AdmobRewardService();
        $service->handleSsvCallback(
            (string)$request->server('QUERY_STRING'),
            $request->query()
        );
        return response(['data' => true]);
    }
}
