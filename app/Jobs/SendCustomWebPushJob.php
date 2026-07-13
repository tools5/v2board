<?php

namespace App\Jobs;

use App\Models\WebPushMessage;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCustomWebPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $messageId;
    public $tries = 3;
    public $timeout = 300;

    public function __construct($messageId)
    {
        $this->onQueue('send_web_push');
        $this->messageId = (int)$messageId;
    }

    public function backoff()
    {
        return [15, 60];
    }

    public function handle(WebPushService $webPushService)
    {
        $message = WebPushMessage::find($this->messageId);
        if (!$message || $message->status === 'sent') {
            return;
        }

        if (!$webPushService->isConfigured()) {
            $message->status = 'failed';
            $message->error_message = 'Web Push 未配置';
            $message->save();
            return;
        }

        $message->status = 'sending';
        $message->save();

        try {
            $payload = $webPushService->normalizePayload([
                'title' => $message->title,
                'body' => $message->body,
                'icon' => $message->icon,
                'image' => $message->image,
                'url' => $message->url,
                'tag' => $message->tag,
                'actions' => $message->actions ?: [],
            ]);

            $target = [
                'type' => $message->target_type ?: 'all',
                'user_id' => $message->target_user_id,
            ];
            if (is_array($message->target_filter)) {
                $target = array_merge($target, $message->target_filter);
                if (empty($target['type'])) {
                    $target['type'] = $message->target_type ?: 'all';
                }
            }

            $query = $webPushService->buildTargetQuery($target);
            $stats = $webPushService->sendToQuery($query, $payload);

            $message->total = (int)$stats['total'];
            $message->sent = (int)$stats['sent'];
            $message->failed = (int)$stats['failed'];
            $message->expired = (int)$stats['expired'];
            $message->status = ($stats['sent'] > 0 || $stats['total'] === 0) ? 'sent' : 'failed';
            if ($stats['total'] === 0) {
                $message->error_message = '没有可推送的订阅设备';
            } elseif ($stats['sent'] === 0 && $stats['failed'] > 0) {
                $message->error_message = '全部投递失败';
            } else {
                $message->error_message = null;
            }
            $message->save();

            Log::info('Custom web push processed', [
                'message_id' => $message->id,
                'sent' => $stats['sent'],
                'failed' => $stats['failed'],
                'expired' => $stats['expired'],
                'total' => $stats['total'],
            ]);
        } catch (\Throwable $error) {
            $message->status = 'failed';
            $message->error_message = $error->getMessage();
            $message->save();
            throw $error;
        }
    }
}
