<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'passport'
        ], function ($router) {
            // Auth
            $router->post('/auth/register', 'V1\\Passport\\AuthController@register');
            $router->post('/auth/sendRegisterLink', 'V1\\Passport\\AuthController@sendRegisterLink');
            $router->post('/auth/registerWithLink', 'V1\\Passport\\AuthController@registerWithLink');
            $router->get('/auth/checkRegisterLink', 'V1\\Passport\\AuthController@checkRegisterLink');
            $router->post('/auth/login', 'V1\\Passport\\AuthController@login');
            $router->get ('/auth/token2Login', 'V1\\Passport\\AuthController@token2Login');
            $router->post('/auth/forget', 'V1\\Passport\\AuthController@forget');
            $router->post('/auth/sendPasswordResetLink', 'V1\\Passport\\AuthController@sendPasswordResetLink');
            $router->post('/auth/resetPasswordWithLink', 'V1\\Passport\\AuthController@resetPasswordWithLink');
            $router->get('/auth/checkPasswordResetLink', 'V1\\Passport\\AuthController@checkPasswordResetLink');
            $router->post('/auth/getQuickLoginUrl', 'V1\\Passport\\AuthController@getQuickLoginUrl');
            // 第三方登录
            $router->get('/auth/oauth/providers', 'V1\\Passport\\OauthController@providers');
            $router->get('/auth/oauth/redirect', 'V1\\Passport\\OauthController@redirect');
            $router->get('/auth/oauth/callback', 'V1\\Passport\\OauthController@callback');
            $router->post('/auth/oauth/telegram', 'V1\\Passport\\OauthController@telegram');
            // Comm
            $router->post('/comm/sendEmailVerify', 'V1\\Passport\\CommController@sendEmailVerify');
            $router->post('/comm/pv', 'V1\\Passport\\CommController@pv');
        });
    }
}
