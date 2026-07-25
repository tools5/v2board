<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'trade_no' => 'required|string',
            'status' => 'nullable|in:0,1,2,3',
            'commission_status' => 'nullable|in:0,1,3'
        ];
    }

    public function messages()
    {
        return [
            'trade_no.required' => '订单号不能为空',
            'status.in' => '销售状态格式不正确',
            'commission_status.in' => '佣金状态格式不正确'
        ];
    }
}
