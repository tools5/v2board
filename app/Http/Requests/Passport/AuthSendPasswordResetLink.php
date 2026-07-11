<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class AuthSendPasswordResetLink extends FormRequest
{
    public function rules()
    {
        return [
            'email' => 'required|email:strict',
            'recaptcha_data' => 'nullable|string',
        ];
    }

    public function messages()
    {
        return [
            'email.required' => __('Email can not be empty'),
            'email.email' => __('Email format is incorrect'),
        ];
    }
}
