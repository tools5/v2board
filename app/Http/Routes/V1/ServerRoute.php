<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'server'
        ], function ($router) {
            $router->match(['get', 'post'], '/{class}/{action}', function ($class, $action) {
                $controllers = [
                    'uniproxy' => [
                        'class' => \App\Http\Controllers\V1\Server\UniProxyController::class,
                        'actions' => ['user', 'push', 'alivelist', 'alive', 'config'],
                    ],
                    'deepbwork' => [
                        'class' => \App\Http\Controllers\V1\Server\DeepbworkController::class,
                        'actions' => ['user', 'submit', 'config'],
                    ],
                    'shadowsockstidalab' => [
                        'class' => \App\Http\Controllers\V1\Server\ShadowsocksTidalabController::class,
                        'actions' => ['user', 'submit'],
                    ],
                    'trojantidalab' => [
                        'class' => \App\Http\Controllers\V1\Server\TrojanTidalabController::class,
                        'actions' => ['user', 'submit', 'config'],
                    ],
                ];

                $class = strtolower((string)$class);
                $action = strtolower((string)$action);
                if (!isset($controllers[$class])
                    || !in_array($action, $controllers[$class]['actions'], true)
                ) {
                    abort(404);
                }

                $controller = \App::make($controllers[$class]['class']);
                return \App::call([$controller, $action]);
            });
        });
    }
}
