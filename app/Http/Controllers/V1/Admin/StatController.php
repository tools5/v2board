<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\ServerHysteria;
use App\Models\ServerTuic;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerVmess;
use App\Models\ServerVless;
use App\Models\ServerAnytls;
use App\Models\ServerV2node;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use App\Services\StatisticalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatController extends Controller
{
    public function getStat(Request $request)
    {
        [$startAt, $endAt] = $this->validateRange($request);

        if ($startAt === null) {
            return [
                'data' => (new StatisticalService())->generateStatData()
            ];
        }

        $stats = Stat::where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->get()
            ->makeHidden(['record_at', 'created_at', 'updated_at', 'id', 'record_type'])
            ->toArray();

        $stats = array_reduce($stats, function (array $carry, array $item) {
            foreach ($item as $key => $value) {
                $carry[$key] = ($carry[$key] ?? 0) + $value;
            }
            return $carry;
        }, []);

        return [
            'data' => $stats
        ];
    }

    public function getStatRecord(Request $request)
    {
        $request->validate([
            'type' => 'required|in:paid_total,commission_total,register_count'
        ]);
        [$startAt, $endAt] = $this->validateRange($request, true);

        $statisticalService = new StatisticalService();
        $statisticalService->setStartAt($startAt);
        $statisticalService->setEndAt($endAt);

        return [
            'data' => $statisticalService->getStatRecord($request->input('type'))
        ];
    }

    public function getRanking(Request $request)
    {
        $params = $request->validate([
            'type' => 'required|in:server_traffic_rank,user_consumption_rank,invite_rank',
            'limit' => 'nullable|integer|between:1,100'
        ]);
        [$startAt, $endAt] = $this->validateRange($request, true);

        $statisticalService = new StatisticalService();
        $statisticalService->setStartAt($startAt);
        $statisticalService->setEndAt($endAt);

        return [
            'data' => $statisticalService->getRanking(
                $params['type'],
                isset($params['limit']) ? (int)$params['limit'] : 20
            )
        ];
    }

    private function validateRange(Request $request, bool $useTodayByDefault = false): array
    {
        $params = $request->validate([
            'start_at' => 'nullable|integer|min:0|required_with:end_at',
            'end_at' => 'nullable|integer|min:1|required_with:start_at|gt:start_at'
        ]);

        if (!isset($params['start_at'], $params['end_at'])) {
            if (!$useTodayByDefault) {
                return [null, null];
            }

            $startAt = strtotime(date('Y-m-d'));
            return [$startAt, strtotime('+1 day', $startAt)];
        }

        return [(int)$params['start_at'], (int)$params['end_at']];
    }

    public function getOverride(Request $request)
    {
        return [
            'data' => [
                'online_user' => User::where('t','>=', time() - 600)
                    ->count(),
                'month_income' => Order::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'month_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->count(),
                'day_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->count(),
                'ticket_pending_total' => Ticket::where('status', 0)
                    ->where('reply_status', 0)
                    ->count(),
                'commission_pending_total' => Order::where('commission_status', 0)
                    ->where('invite_user_id', '!=', NULL)
                    ->whereNotIn('status', [0, 2])
                    ->where('commission_balance', '>', 0)
                    ->count(),
                'day_income' => Order::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'last_month_income' => Order::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'commission_month_payout' => CommissionLog::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->sum('get_amount'),
                'commission_last_month_payout' => CommissionLog::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->sum('get_amount'),
            ]
        ];
    }

    public function getOrder(Request $request)
    {
        $statistics = Stat::where('record_type', 'd')
            ->limit(31)
            ->orderBy('record_at', 'DESC')
            ->get()
            ->toArray();
        $result = [];
        foreach ($statistics as $statistic) {
            $date = date('m-d', $statistic['record_at']);
            $result[] = [
                'type' => '注册人数',
                'date' => $date,
                'value' => $statistic['register_count']
            ];
            $result[] = [
                'type' => '收款金额',
                'date' => $date,
                'value' => $statistic['paid_total'] / 100
            ];
            $result[] = [
                'type' => '收款笔数',
                'date' => $date,
                'value' => $statistic['paid_count']
            ];
            $result[] = [
                'type' => '佣金金额(已发放)',
                'date' => $date,
                'value' => $statistic['commission_total'] / 100
            ];
            $result[] = [
                'type' => '佣金笔数(已发放)',
                'date' => $date,
                'value' => $statistic['commission_count']
            ];
        }
        $result = array_reverse($result);
        return [
            'data' => $result
        ];
    }

    public function getServerLastRank()
    {
        $servers = [
            'shadowsocks' => ServerShadowsocks::where('parent_id', null)->get()->toArray(),
            'v2ray' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'trojan' => ServerTrojan::where('parent_id', null)->get()->toArray(),
            'vmess' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'vless' => ServerVless::where('parent_id', null)->get()->toArray(),
            'tuic' => ServerTuic::where('parent_id', null)->get()->toArray(),
            'hysteria'=> ServerHysteria::where('parent_id', null)->get()->toArray(),
            'anytls' => ServerAnytls::where('parent_id', null)->get()->toArray(),
            'v2node' => ServerV2node::where('parent_id', null)->get()->toArray()
        ];
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        $statistics = StatServer::select([
            'server_id',
            'server_type',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(15)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        foreach ($statistics as $k => $v) {
            foreach ($servers[$v['server_type']] as $server) {
                if ($server['id'] === $v['server_id']) {
                    $statistics[$k]['server_name'] = $server['name'];
                }
            }
            $statistics[$k]['total'] = $statistics[$k]['total'] / 1073741824;
        }
        array_multisort(array_column($statistics, 'total'), SORT_DESC, $statistics);
        return [
            'data' => $statistics
        ];
    }

    public function getServerTodayRank()
    {
        $servers = [
            'shadowsocks' => ServerShadowsocks::where('parent_id', null)->get()->toArray(),
            'v2ray' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'trojan' => ServerTrojan::where('parent_id', null)->get()->toArray(),
            'vmess' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'vless' => ServerVless::where('parent_id', null)->get()->toArray(),
            'tuic' => ServerTuic::where('parent_id', null)->get()->toArray(),
            'hysteria'=> ServerHysteria::where('parent_id', null)->get()->toArray(),
            'anytls' => ServerAnytls::where('parent_id', null)->get()->toArray(),
            'v2node' => ServerV2node::where('parent_id', null)->get()->toArray()
        ];
        $startAt = strtotime(date('Y-m-d'));
        $endAt = time();
        $statistics = StatServer::select([
            'server_id',
            'server_type',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(15)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        foreach ($statistics as $k => $v) {
            foreach ($servers[$v['server_type']] as $server) {
                if ($server['id'] === $v['server_id']) {
                    $statistics[$k]['server_name'] = $server['name'];
                }
            }
            $statistics[$k]['total'] = $statistics[$k]['total'] / 1073741824;
        }
        array_multisort(array_column($statistics, 'total'), SORT_DESC, $statistics);
        return [
            'data' => $statistics
        ];
    }

    public function getUserTodayRank()
    {
        $startAt = strtotime(date('Y-m-d'));
        $endAt = time();
        return ['data' => $this->getUserTrafficRank($startAt, $endAt)];
    }

    public function getUserLastRank()
    {
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        return ['data' => $this->getUserTrafficRank($startAt, $endAt)];
    }

    public function getStatUser(Request $request)
    {
        $payload = $request->validate([
            'user_id' => 'required|integer|min:1',
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1'
        ]);
        $current = max(1, (int)($payload['current'] ?? 1));
        $pageSize = min(100, max(10, (int)($payload['pageSize'] ?? 10)));
        $builder = StatUser::orderBy('record_at', 'DESC')->where('user_id', $payload['user_id']);

        $total = $builder->count();
        $records = $builder->forPage($current, $pageSize)
            ->get();
        return [
            'data' => $records,
            'total' => $total
        ];
    }

    private function getUserTrafficRank($startAt, $endAt)
    {
        return StatUser::query()
            ->leftJoin('v2_user as rank_user', 'v2_stat_user.user_id', '=', 'rank_user.id')
            ->select([
                'v2_stat_user.user_id',
                DB::raw("COALESCE(rank_user.email, 'null') as email"),
                DB::raw('SUM((v2_stat_user.u + v2_stat_user.d) * v2_stat_user.server_rate) / 1073741824 as total')
            ])
            ->where('v2_stat_user.record_at', '>=', $startAt)
            ->where('v2_stat_user.record_at', '<', $endAt)
            ->where('v2_stat_user.record_type', 'd')
            ->groupBy('v2_stat_user.user_id', 'rank_user.email')
            ->orderBy('total', 'DESC')
            ->limit(15)
            ->get();
    }

}
