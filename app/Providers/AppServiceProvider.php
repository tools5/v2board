<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\SubscriptionTokenObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * webman/AdapterMan 多 worker 下 boot() 在每个 worker 里各跑一次，且同一 worker
     * 可能被多次 bootstrap：static 防止观察者被重复登记（重复登记 = 同一次轮换记两遍）。
     */
    private static $observersRegistered = false;

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['view']->addNamespace('theme', public_path() . '/theme');
        // 这里绝不能碰数据库：Schema 探测会让 v2board:install、key:generate 在空库上
        // 直接炸掉。观察者内部的表探测全部延迟到事件真正触发时进行。
        if (!self::$observersRegistered) {
            self::$observersRegistered = true;
            User::observe(SubscriptionTokenObserver::class);
        }
    }
}
