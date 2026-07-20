<?php

namespace App\Http\Controllers\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Staff\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Support\ConfiguredUrl;
use Illuminate\Http\Request;

class UserController extends Controller
{
    private const FILTERABLE_FIELDS = [
        'id',
        'email',
        'transfer_enable',
        'device_limit',
        'u',
        'd',
        'expired_at',
        'uuid',
        'token',
        'invite_by_email',
        'invite_user_id',
        'plan_id',
        'banned',
        'created_at',
        'balance',
        'commission_balance',
    ];

    private const FILTERABLE_CONDITIONS = ['>', '<', '=', '>=', '<=', '!=', '模糊'];

    private const SORTABLE_FIELDS = [
        'id',
        'email',
        'transfer_enable',
        'device_limit',
        'u',
        'd',
        'expired_at',
        'plan_id',
        'banned',
        'created_at',
        'updated_at',
        'balance',
        'commission_balance',
    ];

    public function getUserInfoById(Request $request)
    {
        $id = filter_var($request->input('id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!$id) {
            abort(500, '参数错误');
        }

        $user = $this->ordinaryUsers()
            ->where('id', $id)
            ->first();
        if (!$user) abort(500, '用户不存在');

        $user->makeHidden(['password', 'password_algo', 'password_salt']);
        return response([
            'data' => $user
        ]);
    }

    public function update(UserUpdate $request)
    {
        $params = $request->validated();
        $user = $this->ordinaryUsers()->where('id', $params['id'])->first();
        if (!$user) {
            abort(500, '用户不存在');
        }
        if (User::where('email', $params['email'])->where('id', '<>', $user->id)->exists()) {
            abort(500, '邮箱已被使用');
        }

        unset($params['id']);
        $passwordChanged = isset($params['password']) && $params['password'] !== '';
        if ($passwordChanged) {
            $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            $params['password_algo'] = null;
            $params['password_salt'] = null;
        } else {
            unset($params['password']);
        }

        if (array_key_exists('plan_id', $params) && $params['plan_id'] !== null) {
            $plan = Plan::find($params['plan_id']);
            if (!$plan) {
                abort(500, '订阅计划不存在');
            }
            $params['group_id'] = $plan->group_id;
        } elseif (array_key_exists('plan_id', $params)) {
            $params['group_id'] = null;
        }

        try {
            $user->update($params);
            if ($passwordChanged || (isset($params['banned']) && (int)$params['banned'] === 1)) {
                (new AuthService($user))->removeAllSession();
            }
        } catch (\Throwable $e) {
            abort(500, '保存失败');
        }
        return response([
            'data' => true
        ]);
    }

    public function sendMail(UserSendMail $request)
    {
        $sortType = $this->sortDirection($request);
        $sort = $this->sortColumn($request);
        $builder = $this->ordinaryUsers()->orderBy($sort, $sortType);
        $this->filter($request, $builder);
        foreach ($builder->cursor() as $user) {
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => $request->input('subject'),
                'template_name' => 'notify',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => ConfiguredUrl::applicationUrl(),
                    'content' => $request->input('content')
                ]
            ], 'send_email_mass');
        }

        return response([
            'data' => true
        ]);
    }

    public function ban(Request $request)
    {
        $sortType = $this->sortDirection($request);
        $sort = $this->sortColumn($request);
        $builder = $this->ordinaryUsers()->orderBy($sort, $sortType);
        $this->filter($request, $builder);
        try {
            foreach ((clone $builder)->cursor() as $user) {
                (new AuthService($user))->removeAllSession();
            }
            $builder->update([
                'banned' => 1
            ]);
        } catch (\Throwable $e) {
            abort(500, '处理失败');
        }

        return response([
            'data' => true
        ]);
    }

    private function ordinaryUsers()
    {
        return User::query()
            ->where('is_admin', 0)
            ->where('is_staff', 0);
    }

    private function filter(Request $request, $builder)
    {
        // Defense in depth: callers cannot remove the ordinary-user boundary.
        $builder->where('is_admin', 0)->where('is_staff', 0);
        $filters = $request->input('filter', []);
        if ($filters === null || $filters === '') {
            return;
        }
        if (!is_array($filters)) {
            abort(422, '过滤参数有误');
        }

        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                abort(422, '过滤参数有误');
            }

            $key = isset($filter['key']) ? (string)$filter['key'] : '';
            $condition = isset($filter['condition']) ? (string)$filter['condition'] : '';
            if (!in_array($key, self::FILTERABLE_FIELDS, true)
                || !in_array($condition, self::FILTERABLE_CONDITIONS, true)
                || !array_key_exists('value', $filter)) {
                abort(422, '过滤参数有误');
            }

            $value = $filter['value'];
            if ($condition === '模糊') {
                if (!in_array($key, ['email', 'uuid', 'token', 'invite_by_email'], true)) {
                    abort(422, '该字段不支持模糊查询');
                }
                $condition = 'like';
                $value = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$value) . '%';
            }

            if (in_array($key, ['u', 'd', 'transfer_enable'], true)) {
                if (!is_numeric($value) || (float)$value < 0) {
                    abort(422, '流量过滤参数有误');
                }
                $value = (float)$value * 1073741824;
            }

            if ($key === 'invite_by_email') {
                $inviteUserId = User::where('email', $condition, $value)->value('id');
                $builder->where('invite_user_id', $inviteUserId ?: 0);
                continue;
            }

            if ($key === 'plan_id' && $value === 'null') {
                if ($condition === '=') {
                    $builder->whereNull('plan_id');
                } elseif ($condition === '!=') {
                    $builder->whereNotNull('plan_id');
                } else {
                    abort(422, '套餐过滤参数有误');
                }
                continue;
            }

            $builder->where($key, $condition, $value);
        }
    }

    private function sortColumn(Request $request)
    {
        $sort = (string)$request->input('sort', 'created_at');
        return in_array($sort, self::SORTABLE_FIELDS, true) ? $sort : 'created_at';
    }

    private function sortDirection(Request $request)
    {
        $direction = strtoupper((string)$request->input('sort_type', 'DESC'));
        return in_array($direction, ['ASC', 'DESC'], true) ? $direction : 'DESC';
    }
}
