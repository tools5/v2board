<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CouponGenerate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $couponId = $this->input('id');

        return [
            'id' => 'nullable|integer|min:1',
            'generate_count' => 'nullable|integer|min:1|max:500',
            'name' => 'required|string|max:255',
            'type' => 'required|in:1,2',
            'value' => [
                'required',
                'integer',
                'min:1',
                Rule::when((int)$this->input('type') === 2, ['max:100'])
            ],
            'started_at' => 'required|integer',
            'ended_at' => 'required|integer|gt:started_at',
            'limit_use' => 'nullable|integer|min:1',
            'limit_use_with_user' => 'nullable|integer|min:1',
            'limit_plan_ids' => 'nullable|array|max:100',
            'limit_plan_ids.*' => 'integer|distinct|exists:v2_plan,id',
            'limit_period' => 'nullable|array|max:8',
            'limit_period.*' => 'string|distinct|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price',
            'code' => [
                'nullable',
                'string',
                'max:64',
                'regex:/^[^\\x00-\\x1F\\x7F]+$/u',
                Rule::unique('v2_coupon', 'code')->ignore($couponId)
            ]
        ];
    }

    public function messages()
    {
        return [
            'generate_count.integer' => '生成数量必须为数字',
            'generate_count.max' => '生成数量最大为500个',
            'name.required' => '名称不能为空',
            'type.required' => '类型不能为空',
            'type.in' => '类型格式有误',
            'value.required' => '金额或比例不能为空',
            'value.integer' => '金额或比例格式有误',
            'started_at.required' => '开始时间不能为空',
            'started_at.integer' => '开始时间格式有误',
            'ended_at.required' => '结束时间不能为空',
            'ended_at.integer' => '结束时间格式有误',
            'limit_use.integer' => '最大使用次数格式有误',
            'limit_use_with_user.integer' => '限制用户使用次数格式有误',
            'limit_plan_ids.array' => '指定订阅格式有误',
            'limit_period.array' => '指定周期格式有误'
        ];
    }
}
