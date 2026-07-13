<?php

namespace App\Console\Commands;

use App\Services\WebPushReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendRemindWebPush extends Command
{
    protected $signature = 'send:remindWebPush
                            {--force : 忽略去重缓存，允许再次推送给符合条件的用户}';

    protected $description = '发送套餐到期 / 流量不足的浏览器推送提醒';

    public function handle(WebPushReminderService $reminderService)
    {
        ini_set('memory_limit', -1);

        $forceIgnoreCache = (bool)$this->option('force');
        if ($forceIgnoreCache) {
            $this->warn('已启用 --force：忽略去重缓存重新推送。');
        }

        $stats = $reminderService->processAll($forceIgnoreCache);

        if (!$stats['configured']) {
            $this->warn('Web Push 未配置，已跳过。');
            return 0;
        }

        $this->info(sprintf(
            '到期提醒：检查 %d 人，成功推送 %d 人；流量提醒：检查 %d 人，成功推送 %d 人',
            $stats['expire_checked'],
            $stats['expire_sent_users'],
            $stats['traffic_checked'],
            $stats['traffic_sent_users']
        ));

        Log::info('Web push reminders processed', $stats);
        return 0;
    }
}
