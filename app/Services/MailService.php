<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Support\ConfiguredUrl;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class MailService
{
    private const TRAFFIC_REMINDER_TTL = 86400;
    private const EXPIRE_REMINDER_TTL = 172800;

    public function remindTraffic(User $user): void
    {
        if (!$user->remind_traffic) {
            return;
        }
        if (!$this->remindTrafficIsWarnValue($user->u, $user->d, $user->transfer_enable)) {
            return;
        }

        $flag = CacheKey::get('LAST_SEND_EMAIL_REMIND_TRAFFIC', $user->id);
        if (!Cache::add($flag, 1, self::TRAFFIC_REMINDER_TTL)) {
            return;
        }

        try {
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => __('The traffic usage in :app_name has reached 95%', [
                    'app_name' => config('v2board.app_name', 'V2board')
                ]),
                'template_name' => 'remindTraffic',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => ConfiguredUrl::applicationUrl()
                ]
            ]);
        } catch (\Throwable $exception) {
            Cache::forget($flag);
            throw $exception;
        }
    }

    public function remindExpire(User $user): void
    {
        $now = time();
        $expiredAt = (int) $user->expired_at;
        if ($expiredAt <= $now || $expiredAt - 86400 >= $now) {
            return;
        }

        $flag = CacheKey::get(
            'LAST_SEND_EMAIL_REMIND_EXPIRE',
            $user->id . ':' . date('Y-m-d', $now)
        );
        if (!Cache::add($flag, 1, self::EXPIRE_REMINDER_TTL)) {
            return;
        }

        try {
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => __('The service in :app_name is about to expire', [
                   'app_name' => config('v2board.app_name', 'V2board')
                ]),
                'template_name' => 'remindExpire',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => ConfiguredUrl::applicationUrl()
                ]
            ]);
        } catch (\Throwable $exception) {
            Cache::forget($flag);
            throw $exception;
        }
    }

    private function remindTrafficIsWarnValue($u, $d, $transferEnable): bool
    {
        $ud = $u + $d;
        if (!$ud || !$transferEnable) {
            return false;
        }

        $percentage = ($ud / $transferEnable) * 100;
        return $percentage >= 95 && $percentage < 100;
    }
}
