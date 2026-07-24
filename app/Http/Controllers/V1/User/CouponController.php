<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\CouponService;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function check(Request $request)
    {
        if (empty($request->input('code'))) {
            abort(500, __('Coupon cannot be empty'));
        }
        $couponService = new CouponService($request->input('code'));
        $couponService->setPlanId($request->input('plan_id'));
        $couponService->setUserId($request->user['id']);
        $couponService->check();
        $coupon = $couponService->getCoupon();
        // 不向普通用户暴露券的内部库存/使用限制等字段，只保留前端计算优惠所需信息。
        $coupon->makeHidden(['id', 'limit_use', 'limit_use_with_user', 'show', 'created_at', 'updated_at']);
        return response([
            'data' => $coupon
        ]);
    }
}
