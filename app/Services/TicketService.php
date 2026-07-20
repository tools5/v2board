<?php
namespace App\Services;


use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\ConfiguredUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TicketService {
    public function reply($ticket, $message, $userId)
    {
        try {
            return DB::transaction(function () use ($ticket, $message, $userId) {
                $lockedTicket = Ticket::where('id', $ticket->id)->lockForUpdate()->first();
                if (!$lockedTicket || (int)$lockedTicket->status !== 0) {
                    return false;
                }

                $lastMessage = TicketMessage::where('ticket_id', $lockedTicket->id)
                    ->orderBy('id', 'DESC')
                    ->first();
                if ($lastMessage && (int)$lastMessage->user_id === (int)$userId) {
                    return false;
                }

                $ticketMessage = TicketMessage::create([
                    'user_id' => $userId,
                    'ticket_id' => $lockedTicket->id,
                    'message' => $message
                ]);
                $lockedTicket->reply_status = (int)$userId !== (int)$lockedTicket->user_id ? 1 : 0;
                $lockedTicket->save();

                return $ticketMessage;
            }, 3);
        } catch (\Throwable $error) {
            report($error);
            return false;
        }
    }

    public function replyByAdmin($ticketId, $message, $userId):void
    {
        list($ticket, $ticketMessage) = DB::transaction(function () use ($ticketId, $message, $userId) {
            $ticket = Ticket::where('id', $ticketId)->lockForUpdate()->first();
            if (!$ticket) {
                abort(404, '工单不存在');
            }

            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            $ticket->status = 0;
            $ticket->reply_status = (int)$userId !== (int)$ticket->user_id ? 1 : 0;
            $ticket->save();

            return [$ticket, $ticketMessage];
        }, 3);

        try {
            $this->sendEmailNotify($ticket, $ticketMessage);
        } catch (\Throwable $error) {
            try {
                report($error);
            } catch (\Throwable $ignored) {
                // The reply is already committed; notification failures are non-fatal.
            }
        }
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        $user = User::find($ticket->user_id);
        if (!$user) {
            return;
        }

        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (Cache::add($cacheKey, 1, 1800)) {
            try {
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => '您在' . config('v2board.app_name', 'V2Board') . '的工单得到了回复',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => ConfiguredUrl::applicationUrl(),
                    'content' => "主题：{$ticket->subject}\r\n回复内容：{$ticketMessage->message}"
                ]
            ]);
            } catch (\Throwable $error) {
                Cache::forget($cacheKey);
                throw $error;
            }
        }
    }
}
