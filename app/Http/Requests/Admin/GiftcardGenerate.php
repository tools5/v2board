<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GiftcardGenerate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $giftcardId = $this->input('id');

        return [
            'id' => 'nullable|integer|min:1',
            'generate_count' => 'nullable|integer|min:1|max:500',
            'name' => 'required|string|max:255',
            'type' => 'required|in:1,2,3,4,5',
            'value' => ['required_if:type,1,2,3,5', 'nullable', 'integer', 'min:0'],
            'plan_id' => ['required_if:type,5', 'nullable', 'integer', 'exists:v2_plan,id'],
            'started_at' => 'required|integer',
            'ended_at' => 'required|integer|gt:started_at',
            'limit_use' => 'nullable|integer|min:1',
            'code' => [
                'nullable',
                'string',
                'max:64',
                'regex:/^[^\\x00-\\x1F\\x7F]+$/u',
                Rule::unique('v2_giftcard', 'code')->ignore($giftcardId)
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
            'value.required' => '数值不能为空',
            'value.integer' => '数值格式有误',
            'plan_id.required' => '订阅不能为空',
            'started_at.required' => '开始时间不能为空',
            'started_at.integer' => '开始时间格式有误',
            'ended_at.required' => '结束时间不能为空',
            'ended_at.integer' => '结束时间格式有误',
            'limit_use.integer' => '最大使用次数格式有误'
        ];
    }
}
