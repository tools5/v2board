<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserChangePassword extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            // 旧密码是否必填由控制器按用户类型决定：
            // 从未设过密码的 OAuth 用户走「设置密码」流程，无需旧密码。
            'old_password' => 'nullable',
            'new_password' => 'required|min:8'
        ];
    }

    public function messages()
    {
        return [
            'old_password.required' => __('Old password cannot be empty'),
            'new_password.required' => __('New password cannot be empty'),
            'new_password.min' => __('Password must be greater than 8 digits')
        ];
    }
}
