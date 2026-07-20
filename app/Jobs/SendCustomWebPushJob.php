<?php

namespace App\Jobs;

use App\Models\WebPushMessage;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCustomWebPushJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $messageId;
    // Push providers cannot guarantee idempotency after a partial delivery.
    public $tries = 1;
    public $timeout = 300;
    public $uniqueFor = 600;

    public function __construct($messageId)
    {
        $this->onQueue('send_web_push');
        $this->messageId = (int)$messageId;
    }

    public function uniqueId()
    {
        return (string)$this->messageId;
    }

    public function handle(WebPushService $webPushService)
    {
        $claimed = WebPushMessage::query()
            ->where('id', $this->messageId)
            ->where('status', 'queued')
            ->update([
                'status' => 'sending',
                'updated_at' => time(),
            ]);
        if ($claimed !== 1) {
            return;
        }

        $message = WebPushMessage::find($this->messageId);
        if (!$message) {
            return;
        }

        if (!$webPushService->isConfigured()) {
            $message->status = 'failed';
            $message->error_message = 'Web Push 未配置';
            $message->save();
            return;
        }

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
            WebPushMessage::query()
                ->where('id', $this->messageId)
                ->where('status', 'sending')
                ->update([
                    'status' => 'failed',
                    'error_message' => '推送任务执行失败，请检查服务器日志',
                    'updated_at' => time(),
                ]);
            Log::error('Custom web push job failed', [
                'message_id' => $this->messageId,
                'exception' => $error,
            ]);
            throw $error;
        }
    }

    public function failed(\Throwable $error)
    {
        $updated = WebPushMessage::query()
            ->where('id', $this->messageId)
            ->where('status', 'sending')
            ->update([
                'status' => 'failed',
                'error_message' => '推送任务执行失败，请检查服务器日志',
                'updated_at' => time(),
            ]);

        if ($updated === 1) {
            Log::error('Custom web push job failed', [
                'message_id' => $this->messageId,
                'exception' => $error,
            ]);
        }
    }
}
