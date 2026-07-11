<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class AuthRegisterWithLink extends FormRequest
{
    public function rules()
    {
        return [
            'token' => 'required|string|min:16|max:128',
            'password' => 'required|min:8',
            'invite_code' => 'nullable|string|max:64',
        ];
    }

    public function messages()
    {
        return [
            'token.required' => __('Token error'),
            'password.required' => __('Password can not be empty'),
            'password.min' => __('Password must be greater than 8 digits'),
        ];
    }
}
