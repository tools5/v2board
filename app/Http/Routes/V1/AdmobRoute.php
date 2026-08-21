<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class AdmobRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'admob'
        ], function ($router) {
            // XBClient 客户端（需登录）
            $router->group([
                'prefix' => 'user',
                'middleware' => 'user'
            ], function ($router) {
                $router->get ('/config', 'V1\\Admob\\UserController@config');
                $router->post('/plan-payment', 'V1\\Admob\\UserController@planPayment');
                $router->get ('/reward-history', 'V1\\Admob\\UserController@rewardHistory');
                $router->post('/reward-pending', 'V1\\Admob\\UserController@rewardPending');
            });
            // Google AdMob SSV 回调（无鉴权，内部做 ECDSA 签名验证）
            $router->get('/guest/ssv', 'V1\\Admob\\GuestController@ssv');
        });
    }
}
