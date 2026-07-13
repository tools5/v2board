<?php

namespace App\Jobs;

use App\Models\Notice;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendNoticeWebPushJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $noticeId;
    public $tries = 3;
    public $timeout = 180;
    public $uniqueFor = 300;

    public function __construct($noticeId)
    {
        $this->onQueue('send_web_push');
        $this->noticeId = (int)$noticeId;
    }

    public function uniqueId()
    {
        return (string)$this->noticeId;
    }

    public function backoff()
    {
        return [15, 60];
    }

    public function handle(WebPushService $webPushService)
    {
        $notice = Notice::find($this->noticeId);
        if (!$notice || !$notice->show || $notice->web_push_sent_at) {
            return;
        }

        if (!$webPushService->isConfigured()) {
            Log::info('Notice web push skipped: not configured', [
                'notice_id' => $this->noticeId,
            ]);
            return;
        }

        $stats = $webPushService->sendNotice($notice);

        // 没有订阅时不算失败，直接标记已处理，避免任务反复重试
        if ($stats['sent'] === 0 && $stats['failed'] === 0) {
            $notice->web_push_sent_at = time();
            $notice->save();
            Log::info('Notice web push processed with zero subscriptions', [
                'notice_id' => $notice->id,
            ]);
            return;
        }

        if ($stats['failed'] > 0 && $stats['sent'] === 0) {
            throw new \RuntimeException('公告浏览器推送全部投递失败');
        }

        $notice->web_push_sent_at = time();
        $notice->save();

        Log::info('Notice web push processed', [
            'notice_id' => $notice->id,
            'sent' => $stats['sent'],
            'failed' => $stats['failed'],
            'expired' => $stats['expired'],
        ]);
    }
}
