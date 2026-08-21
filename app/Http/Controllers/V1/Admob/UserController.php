<?php

namespace App\Http\Controllers\V1\Admob;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Services\AdmobRewardService;
use App\Support\ConfiguredUrl;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * XBClient 客户端的 AdMob 插件接口（与 XBoard admob 插件同路径同契约）。
 */
class UserController extends Controller
{
    public function config(Request $request)
    {
        $userId = (int)$request->user['id'];
        $planEnabled = AdmobRewardService::sceneEnabled(AdmobRewardService::SCENE_PLAN);
        $pointsEnabled = AdmobRewardService::sceneEnabled(AdmobRewardService::SCENE_POINTS);
        $appOpenUnitId = trim((string)config('v2board.admob_app_open_ad_unit_id', ''));

        // 客户端（electron）严格校验：所有字符串字段必须始终存在
        $data = [
            'payment_enabled' => (bool)(int)config('v2board.admob_payment_enabled', 0),
            'app_open_ad_enabled' => (bool)((int)config('v2board.admob_app_open_ad_enabled', 0) && $appOpenUnitId !== ''),
            'app_open_ad_unit_id' => $appOpenUnitId,
            'plan_reward_ad_enabled' => $planEnabled,
            'plan_rewarded_ad_unit_id' => trim((string)config('v2board.admob_plan_rewarded_ad_unit_id', '')),
            'plan_ssv_user_id' => (string)$userId,
            'plan_ssv_custom_data' => AdmobRewardService::customData($userId, AdmobRewardService::SCENE_PLAN),
            'points_reward_ad_enabled' => $pointsEnabled,
            'points_rewarded_ad_unit_id' => trim((string)config('v2board.admob_points_rewarded_ad_unit_id', '')),
            'points_ssv_user_id' => (string)$userId,
            'points_ssv_custom_data' => AdmobRewardService::customData($userId, AdmobRewardService::SCENE_POINTS),
        ];
        $githubProjectUrl = trim((string)config('v2board.xbclient_github_project_url', ''));
        if ($githubProjectUrl !== '') {
            $data['github_project_url'] = $githubProjectUrl;
        }
        return response(['data' => $data]);
    }

    /**
     * 返回同源网页快捷登录 URL，客户端在浏览器里完成套餐购买。
     */
    public function planPayment(Request $request)
    {
        if (!(int)config('v2board.admob_payment_enabled', 0)) {
            abort(500, '在线购买未启用');
        }
        $planId = (int)$request->input('plan_id');
        if ($planId <= 0) {
            abort(400, 'plan_id 无效');
        }
        if (!Plan::find($planId)) {
            abort(400, '套餐不存在');
        }
        $user = User::find((int)$request->user['id']);
        if (!$user) {
            abort(403, '用户不存在');
        }
        $code = Helper::guid();
        Cache::put(CacheKey::get('TEMP_TOKEN', $code), $user->id, 120);
        $query = http_build_query([
            'verify' => $code,
            'redirect' => ConfiguredUrl::normalizeFrontendRedirect('plan'),
        ], '', '&', PHP_QUERY_RFC3986);
        return response([
            'data' => ConfiguredUrl::applicationPathUrl('/#/login?' . $query),
        ]);
    }

    public function rewardHistory(Request $request)
    {
        $service = new AdmobRewardService();
        return response([
            'data' => $service->historyRows((int)$request->user['id']),
        ]);
    }

    public function rewardPending(Request $request)
    {
        $custom = AdmobRewardService::parseCustomData($request->input('custom_data'));
        if (!$custom) {
            abort(400, 'custom_data 无效，请刷新广告配置后重试');
        }
        if ($custom['user_id'] !== (int)$request->user['id']) {
            abort(403, 'custom_data 与当前用户不匹配');
        }
        $service = new AdmobRewardService();
        return response([
            'data' => $service->pendingResult($custom['user_id'], $custom['scene']),
        ]);
    }
}
