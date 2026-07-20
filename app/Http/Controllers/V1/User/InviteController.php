<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InviteController extends Controller
{
    private const MAX_PAGE_SIZE = 100;

    public function save(Request $request)
    {
        $inviteCode = DB::transaction(function () use ($request) {
            $user = User::where('id', $request->user['id'])->lockForUpdate()->first();
            if (!$user) {
                abort(404, __('The user does not exist'));
            }

            $limit = max(0, (int)config('v2board.invite_gen_limit', 5));
            $activeCount = InviteCode::where('user_id', $user->id)->where('status', 0)->count();
            if ($activeCount >= $limit) {
                abort(422, __('The maximum number of creations has been reached'));
            }

            do {
                $code = Helper::randomChar(12);
            } while (InviteCode::where('code', $code)->exists());

            $inviteCode = new InviteCode();
            $inviteCode->user_id = $user->id;
            $inviteCode->code = $code;
            if (!$inviteCode->save()) {
                throw new \RuntimeException('邀请码保存失败');
            }

            return $inviteCode;
        });

        return response([
            'data' => (bool)$inviteCode->exists
        ]);
    }

    public function details(Request $request)
    {
        $payload = $request->validate([
            'current' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|min:1'
        ]);
        $current = max(1, (int)($payload['current'] ?? 1));
        $pageSize = min(self::MAX_PAGE_SIZE, max(10, (int)($payload['page_size'] ?? 10)));
        $builder = CommissionLog::where('invite_user_id', $request->user['id'])
            ->where('get_amount', '>', 0)
            ->select([
                'id',
                'trade_no',
                'order_amount',
                'get_amount',
                'created_at'
            ])
            ->orderBy('created_at', 'DESC');
        $total = $builder->count();
        $details = $builder->forPage($current, $pageSize)
            ->get();
        return response([
            'data' => $details,
            'total' => $total
        ]);
    }

    public function fetch(Request $request)
    {
        $codes = InviteCode::where('user_id', $request->user['id'])
            ->where('status', 0)
            ->get();
        $commission_rate = config('v2board.invite_commission', 10);
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(404, __('The user does not exist'));
        }
        if ($user->commission_rate) {
            $commission_rate = $user->commission_rate;
        }
        $uncheck_commission_balance = (int)Order::where('status', 3)
            ->where('commission_status', 0)
            ->where('invite_user_id', $request->user['id'])
            ->sum('commission_balance');
        if (config('v2board.commission_distribution_enable', 0)) {
            $uncheck_commission_balance = $uncheck_commission_balance * (config('v2board.commission_distribution_l1') / 100);
        }
        $stat = [
            //已注册用户数
            (int)User::where('invite_user_id', $request->user['id'])->count(),
            //有效的佣金
            (int)CommissionLog::where('invite_user_id', $request->user['id'])
                ->sum('get_amount'),
            //确认中的佣金
            $uncheck_commission_balance,
            //佣金比例
            (int)$commission_rate,
            //可用佣金
            (int)$user->commission_balance
        ];
        return response([
            'data' => [
                'codes' => $codes,
                'stat' => $stat
            ]
        ]);
    }
}
