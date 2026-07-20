<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'id' => 'required|integer|min:1',
            'email' => 'required|string|email:strict|max:255',
            'password' => 'nullable|string|min:8|max:72',
            'transfer_enable' => 'sometimes|integer|min:0',
            'device_limit' => 'nullable|integer|min:0',
            'expired_at' => 'nullable|integer|min:0',
            'banned' => 'required|in:0,1',
            'plan_id' => 'nullable|integer|min:1',
            'commission_rate' => 'nullable|integer|min:0|max:100',
            'discount' => 'nullable|integer|min:0|max:100',
            'u' => 'sometimes|integer|min:0',
            'd' => 'sometimes|integer|min:0',
            'balance' => 'sometimes|integer|min:0',
            'commission_balance' => 'sometimes|integer|min:0'
        ];
    }

    public function messages()
    {
        return [
            'id.required' => '用户ID不能为空',
            'id.integer' => '用户ID格式不正确',
            'email.required' => '邮箱不能为空',
            'email.email' => '邮箱格式不正确',
            'email.max' => '邮箱长度不能超过255位',
            'password.min' => '密码长度最小8位',
            'password.max' => '密码长度不能超过72位',
            'transfer_enable.numeric' => '流量格式不正确',
            'device_limit.integer' => '设备数限制格式不正确',
            'expired_at.integer' => '到期时间格式不正确',
            'banned.required' => '是否封禁不能为空',
            'banned.in' => '是否封禁格式不正确',
            'plan_id.integer' => '订阅计划格式不正确',
            'commission_rate.integer' => '推荐返利比例格式不正确',
            'commission_rate.nullable' => '推荐返利比例格式不正确',
            'commission_rate.min' => '推荐返利比例最小为0',
            'commission_rate.max' => '推荐返利比例最大为100',
            'discount.integer' => '专属折扣比例格式不正确',
            'discount.nullable' => '专属折扣比例格式不正确',
            'discount.min' => '专属折扣比例最小为0',
            'discount.max' => '专属折扣比例最大为100',
            'u.integer' => '上行流量格式不正确',
            'd.integer' => '下行流量格式不正确',
            'balance.integer' => '余额格式不正确',
            'commission_balance.integer' => '佣金格式不正确'
        ];
    }
}
