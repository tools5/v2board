<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\ServerService;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('group_id')) {
            return response([
                'data' => [ServerGroup::find($request->input('group_id'))]
            ]);
        }
        $serverGroups = ServerGroup::get();
        $serverService = new ServerService();
        $servers = $serverService->getAllServers();
        foreach ($serverGroups as $k => $v) {
            $serverGroups[$k]['user_count'] = User::where('group_id', $v['id'])->count();
            $serverGroups[$k]['server_count'] = 0;
            foreach ($servers as $server) {
                if (in_array((int) $v['id'], $this->normalizeGroupIds($server['group_id'] ?? null), true)) {
                    $serverGroups[$k]['server_count'] = $serverGroups[$k]['server_count']+1;
                }
            }
        }
        return response([
            'data' => $serverGroups
        ]);
    }

    public function save(Request $request)
    {
        if (empty($request->input('name'))) {
            abort(500, '组名不能为空');
        }

        if ($request->input('id')) {
            $serverGroup = ServerGroup::find($request->input('id'));
        } else {
            $serverGroup = new ServerGroup();
        }

        $serverGroup->name = $request->input('name');
        return response([
            'data' => $serverGroup->save()
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $serverGroup = ServerGroup::find($request->input('id'));
            if (!$serverGroup) {
                abort(500, '组不存在');
            }
        }

        $groupId = (int) $request->input('id');
        $servers = (new ServerService())->getAllServers();
        foreach ($servers as $server) {
            if (in_array($groupId, $this->normalizeGroupIds($server['group_id'] ?? null), true)) {
                abort(500, '该组已被节点所使用，无法删除');
            }
        }

        if (Plan::where('group_id', $request->input('id'))->first()) {
            abort(500, '该组已被订阅所使用，无法删除');
        }
        if (User::where('group_id', $request->input('id'))->first()) {
            abort(500, '该组已被用户所使用，无法删除');
        }
        return response([
            'data' => $serverGroup->delete()
        ]);
    }

    private function normalizeGroupIds($groupIds): array
    {
        if (is_string($groupIds)) {
            $decoded = json_decode($groupIds, true);
            $groupIds = is_array($decoded) ? $decoded : explode(',', $groupIds);
        } elseif (!is_array($groupIds)) {
            $groupIds = $groupIds === null ? [] : [$groupIds];
        }

        return array_values(array_unique(array_map(
            'intval',
            array_filter($groupIds, static fn ($groupId) => is_numeric($groupId))
        )));
    }
}
