<?php

namespace App\Http\Controllers\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\TicketService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    private const MAX_PAGE_SIZE = 100;
    private const MAX_MESSAGES = 1000;

    public function fetch(Request $request)
    {
        if ($request->input('id') !== null && $request->input('id') !== '') {
            $payload = $request->validate([
                'id' => 'required|integer|min:1'
            ]);
            $ticket = Ticket::where('id', $payload['id'])->first();
            if (!$ticket) {
                abort(404, '工单不存在');
            }

            $messages = TicketMessage::where('ticket_id', $ticket->id)
                ->orderBy('id', 'DESC')
                ->limit(self::MAX_MESSAGES)
                ->get()
                ->reverse()
                ->values()
                ->map(function ($message) use ($ticket) {
                    $message->setAttribute('is_me', (int)$message->user_id !== (int)$ticket->user_id);
                    return $message;
                });
            $ticket->setAttribute('message', $messages);

            return response([
                'data' => $ticket
            ]);
        }

        $payload = $request->validate([
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1',
            'status' => 'nullable|in:0,1'
        ]);
        $current = min((int)($payload['current'] ?? 1), 1000000);
        $pageSize = min(max((int)($payload['pageSize'] ?? 10), 10), self::MAX_PAGE_SIZE);
        $model = Ticket::orderBy('created_at', 'DESC');
        if (array_key_exists('status', $payload) && $payload['status'] !== null) {
            $model->where('status', $payload['status']);
        }
        $total = $model->count();
        $res = $model->forPage($current, $pageSize)
            ->get();
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function reply(Request $request)
    {
        $payload = $request->validate([
            'id' => 'required|integer|min:1',
            'message' => 'required|string|max:20000'
        ]);
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $payload['id'],
            $payload['message'],
            $request->user['id']
        );
        return response([
            'data' => true
        ]);
    }

    public function close(Request $request)
    {
        $payload = $request->validate([
            'id' => 'required|integer|min:1'
        ]);
        $ticket = Ticket::where('id', $payload['id'])->first();
        if (!$ticket) {
            abort(404, '工单不存在');
        }
        if ((int)$ticket->status !== 1) {
            $ticket->status = 1;
            $ticket->save();
        }

        return response([
            'data' => true
        ]);
    }
}
