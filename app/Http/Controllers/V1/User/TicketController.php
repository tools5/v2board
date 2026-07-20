<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TelegramService;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    private const MAX_TICKETS = 200;
    private const MAX_MESSAGES = 1000;

    public function fetch(Request $request)
    {
        $userId = (int)$request->user['id'];
        $ticketId = $request->input('id');

        if ($ticketId !== null && $ticketId !== '') {
            $payload = $request->validate([
                'id' => 'required|integer|min:1'
            ]);
            $ticket = Ticket::where('id', $payload['id'])
                ->where('user_id', $userId)
                ->first();
            if (!$ticket) {
                abort(404, __('Ticket does not exist'));
            }

            $messages = TicketMessage::where('ticket_id', $ticket->id)
                ->orderBy('id', 'DESC')
                ->limit(self::MAX_MESSAGES)
                ->get()
                ->reverse()
                ->values()
                ->map(function ($message) use ($ticket) {
                    $message->setAttribute('is_me', (int)$message->user_id === (int)$ticket->user_id);
                    return $message;
                });
            $ticket->setAttribute('message', $messages);

            return response(['data' => $ticket]);
        }

        $tickets = Ticket::where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit(self::MAX_TICKETS)
            ->get();

        return response([
            'data' => $tickets
        ]);
    }

    public function save(TicketSave $request)
    {
        $userId = (int)$request->user['id'];
        $ticket = DB::transaction(function () use ($request, $userId) {
            $user = User::where('id', $userId)->lockForUpdate()->first();
            if (!$user) {
                abort(401, __('Unauthorized'));
            }

            if (Ticket::where('status', 0)->where('user_id', $userId)->exists()) {
                abort(422, __('There are other unresolved tickets'));
            }

            $ticketStatus = (int)config('v2board.ticket_status', 0);
            if ($ticketStatus === 1) {
                $hasOrder = Order::where('user_id', $userId)
                    ->whereIn('status', [3, 4])
                    ->exists();
                if (!$hasOrder) {
                    abort(422, __('请先购买套餐'));
                }
            } elseif ($ticketStatus === 2) {
                abort(422, __('当前套餐不允许发起工单'));
            } elseif ($ticketStatus !== 0) {
                throw new \RuntimeException('Invalid ticket_status configuration');
            }

            $ticket = Ticket::create([
                'subject' => $request->input('subject'),
                'level' => $request->input('level'),
                'user_id' => $userId
            ]);
            TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $request->input('message')
            ]);

            return $ticket;
        }, 3);

        $this->sendNotifySafely(
            $ticket,
            $request->input('message'),
            $userId,
            $request->ip()
        );

        return response([
            'data' => true
        ]);
    }

    public function reply(Request $request)
    {
        $payload = $request->validate([
            'id' => 'required|integer|min:1',
            'message' => 'required|string|max:20000'
        ]);
        $userId = (int)$request->user['id'];

        $ticket = DB::transaction(function () use ($payload, $userId) {
            $ticket = Ticket::where('id', $payload['id'])
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            if (!$ticket) {
                abort(404, __('Ticket does not exist'));
            }
            if ((int)$ticket->status !== 0) {
                abort(422, __('The ticket is closed and cannot be replied'));
            }

            $lastMessage = TicketMessage::where('ticket_id', $ticket->id)
                ->orderBy('id', 'DESC')
                ->first();
            if ($lastMessage && (int)$lastMessage->user_id === $userId) {
                abort(422, __('Please wait for the technical enginneer to reply'));
            }

            TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $payload['message']
            ]);
            $ticket->reply_status = 0;
            $ticket->save();

            return $ticket;
        }, 3);

        $this->sendNotifySafely($ticket, $payload['message'], $userId, $request->ip());

        return response([
            'data' => true
        ]);
    }

    public function close(Request $request)
    {
        $payload = $request->validate([
            'id' => 'required|integer|min:1'
        ]);
        $userId = (int)$request->user['id'];

        DB::transaction(function () use ($payload, $userId) {
            $ticket = Ticket::where('id', $payload['id'])
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            if (!$ticket) {
                abort(404, __('Ticket does not exist'));
            }
            if ((int)$ticket->status !== 1) {
                $ticket->status = 1;
                $ticket->save();
            }
        }, 3);

        return response([
            'data' => true
        ]);
    }

    public function withdraw(TicketWithdraw $request)
    {
        if ((int)config('v2board.withdraw_close_enable', 0) !== 0) {
            abort(422, 'user.ticket.withdraw.not_support_withdraw');
        }

        $withdrawMethods = config(
            'v2board.commission_withdraw_method',
            Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT
        );
        if (!is_array($withdrawMethods)
            || !in_array($request->input('withdraw_method'), $withdrawMethods, true)
        ) {
            abort(422, __('Unsupported withdrawal method'));
        }

        $userId = (int)$request->user['id'];
        list($ticket, $message) = DB::transaction(function () use ($request, $userId) {
            $user = User::where('id', $userId)->lockForUpdate()->first();
            if (!$user) {
                abort(401, __('Unauthorized'));
            }

            $limitInCents = (int)round(max(0, (float)config('v2board.commission_withdraw_limit', 100)) * 100);
            if ((int)$user->commission_balance < $limitInCents) {
                abort(422, __('The current required minimum withdrawal commission is :limit', [
                    'limit' => $limitInCents / 100
                ]));
            }
            if (Ticket::where('status', 0)->where('user_id', $userId)->exists()) {
                abort(422, __('There are other unresolved tickets'));
            }

            $ticket = Ticket::create([
                'subject' => __('[Commission Withdrawal Request] This ticket is opened by the system'),
                'level' => 2,
                'user_id' => $userId
            ]);
            $message = sprintf(
                "%s\r\n%s\r\n%s",
                __('Withdrawal method') . '：' . $request->input('withdraw_method'),
                __('Withdrawal account') . '：' . $request->input('withdraw_account'),
                __('Withdrawal amount') . '：' . number_format((int)$user->commission_balance / 100, 2, '.', '')
            );
            TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);

            return [$ticket, $message];
        }, 3);

        $this->sendNotifySafely($ticket, $message, $userId, $request->ip());

        return response([
            'data' => true
        ]);
    }

    private function sendNotifySafely(Ticket $ticket, string $message, $userId = null, $ipAddress = null)
    {
        try {
            $this->sendNotify($ticket, $message, $userId, $ipAddress);
        } catch (\Throwable $error) {
            try {
                report($error);
            } catch (\Throwable $ignored) {
                // Notification failures must not turn a committed ticket request into a 500 response.
            }
        }
    }

    private function sendNotify(Ticket $ticket, string $message, $userId = null, $ipAddress = null)
    {
        $telegramService = new TelegramService();
        if (empty($userId)) {
            $telegramService->sendMessageWithAdmin(
                "工单提醒 #{$ticket->id}\n主题：" . $this->telegramText($ticket->subject, 255)
                . "\n内容：" . $this->telegramText($message, 1800),
                true
            );
            return;
        }

        $user = User::find($userId);
        if (!$user) {
            return;
        }

        $plan = Plan::find($user->plan_id);
        $planName = $plan ? $plan->name : '未找到套餐信息';
        $transferEnable = $this->getFlowData($user->transfer_enable);
        $remainingTraffic = $this->getFlowData($user->transfer_enable - $user->u - $user->d);
        $uploaded = $this->getFlowData($user->u);
        $downloaded = $this->getFlowData($user->d);
        $expiredAt = (int)$user->expired_at > 0
            ? date('Y-m-d H:i:s', (int)$user->expired_at)
            : '未设置';
        $ipAddress = filter_var($ipAddress, FILTER_VALIDATE_IP) !== false
            ? $ipAddress
            : '未知';

        $notification = "工单提醒 #{$ticket->id}"
            . "\n邮箱：" . $this->telegramText($user->email, 128)
            . "\nIP：" . $this->telegramText($ipAddress, 64)
            . "\n套餐与流量：" . $this->telegramText($planName, 128) . " {$transferEnable}/{$remainingTraffic}"
            . "\n上传/下载：{$uploaded}/{$downloaded}"
            . "\n到期时间：{$expiredAt}"
            . "\n余额/佣金余额：" . number_format((int)$user->balance / 100, 2, '.', '')
            . '/' . number_format((int)$user->commission_balance / 100, 2, '.', '')
            . "\n主题：" . $this->telegramText($ticket->subject, 255)
            . "\n内容：" . $this->telegramText($message, 1800);

        $telegramService->sendMessageWithAdmin($notification, true);
    }

    private function telegramText($value, $limit)
    {
        $value = (string)$value;
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, $limit, 'UTF-8');
        } else {
            $value = substr($value, 0, $limit);
        }

        return str_replace(
            ['\\', '`', '*', '[', ']'],
            ['\\\\', '\\`', '\\*', '\\[', '\\]'],
            $value
        );
    }

    private function getFlowData($bytes)
    {
        $bytes = max(0, (float)$bytes);
        $gigabytes = $bytes / (1024 * 1024 * 1024);
        if ($gigabytes >= 1) {
            return round($gigabytes, 2) . 'GB';
        }

        return round($bytes / (1024 * 1024), 2) . 'MB';
    }
}
