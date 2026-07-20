<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\ConfiguredUrl;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function getPaymentMethods()
    {
        $methods = [];
        foreach (glob(base_path('app/Payments') . '/*.php') as $file) {
            $method = pathinfo($file, PATHINFO_FILENAME);
            if (PaymentService::isMethodAvailable($method)) {
                $methods[] = $method;
            }
        }
        natcasesort($methods);

        return response([
            'data' => array_values($methods)
        ]);
    }

    public function fetch()
    {
        $payments = Payment::orderBy('sort', 'ASC')->get();
        $applicationUrl = ConfiguredUrl::applicationUrl();
        foreach ($payments as $key => $payment) {
            $notifyUrl = '';
            if ($applicationUrl !== '') {
                $notifyPath = '/api/v1/guest/payment/notify/'
                    . rawurlencode((string)$payment->payment)
                    . '/'
                    . rawurlencode((string)$payment->uuid);
                $notifyDomain = ConfiguredUrl::normalizeHttpUrl($payment->notify_domain);
                $notifyUrl = rtrim($notifyDomain !== '' ? $notifyDomain : $applicationUrl, '/') . $notifyPath;
            }
            $payments[$key]['notify_url'] = $notifyUrl;
        }

        return response([
            'data' => $payments
        ]);
    }

    public function getPaymentForm(Request $request)
    {
        $params = $request->validate([
            'payment' => ['required', 'string', 'regex:/\A[A-Za-z][A-Za-z0-9_]*\z/'],
            'id' => 'nullable|integer'
        ]);
        if (!PaymentService::isMethodAvailable($params['payment'])) {
            abort(500, '支付方式不可用或缺少依赖');
        }

        $paymentService = new PaymentService($params['payment'], $params['id'] ?? null);
        return response([
            'data' => $paymentService->form()
        ]);
    }

    public function show(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        DB::transaction(function () use ($request) {
            $payment = Payment::where('id', $request->input('id'))->lockForUpdate()->first();
            if (!$payment) {
                abort(500, '支付方式不存在');
            }

            $payment->enable = (int) !(int) $payment->enable;
            if (!$payment->save()) {
                throw new \RuntimeException('保存失败');
            }
        }, 3);

        return response([
            'data' => true
        ]);
    }

    public function save(Request $request)
    {
        if (ConfiguredUrl::applicationUrl() === '') {
            abort(500, '请在站点配置中配置站点地址');
        }
        $params = $request->validate([
            'name' => 'required|string|max:255',
            'icon' => 'nullable|string|max:2048',
            'payment' => ['required', 'string', 'regex:/\A[A-Za-z][A-Za-z0-9_]*\z/'],
            'config' => 'required|array',
            'notify_domain' => 'nullable|url|max:255',
            'handling_fee_fixed' => 'nullable|integer|min:0',
            'handling_fee_percent' => 'nullable|numeric|between:0,100'
        ], [
            'name.required' => '显示名称不能为空',
            'payment.required' => '网关参数不能为空',
            'config.required' => '配置参数不能为空',
            'config.array' => '配置参数格式有误',
            'notify_domain.url' => '自定义通知域名格式有误',
            'handling_fee_fixed.integer' => '固定手续费格式有误',
            'handling_fee_fixed.min' => '固定手续费不能小于0',
            'handling_fee_percent.between' => '百分比手续费范围须在0-100之间'
        ]);
        if (!PaymentService::isMethodAvailable($params['payment'])) {
            abort(500, '支付方式不可用或缺少依赖');
        }

        $notifyDomain = empty($params['notify_domain'])
            ? ''
            : ConfiguredUrl::normalizeHttpUrl($params['notify_domain']);
        if (!empty($params['notify_domain']) && $notifyDomain === '') {
            abort(422, '自定义通知域名必须是无认证信息的 HTTP(S) 地址');
        }
        $params['notify_domain'] = $notifyDomain === '' ? null : $notifyDomain;
        $id = $request->input('id');
        if ($id) {
            DB::transaction(function () use ($id, $params) {
                $payment = Payment::where('id', $id)->lockForUpdate()->first();
                if (!$payment) {
                    abort(500, '支付方式不存在');
                }

                $payment->fill($params);
                $hasActiveOrders = Order::where('payment_id', $payment->id)
                    ->whereIn('status', [
                        OrderService::STATUS_PENDING,
                        OrderService::STATUS_PROCESSING
                    ])
                    ->exists();
                if ($hasActiveOrders && $payment->isDirty(['payment', 'config', 'notify_domain'])) {
                    abort(500, '该支付方式存在待支付或处理中订单，暂不能修改网关类型、配置或通知域名');
                }
                if (!$payment->save()) {
                    throw new \RuntimeException('保存失败');
                }
            }, 3);

            return response(['data' => true]);
        }

        $params['uuid'] = Helper::randomChar(8);
        if (!Payment::create($params)) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        DB::transaction(function () use ($request) {
            $payment = Payment::where('id', $request->input('id'))->lockForUpdate()->first();
            if (!$payment) {
                abort(500, '支付方式不存在');
            }
            if (Order::where('payment_id', $payment->id)->exists()) {
                abort(500, '该支付方式已被订单引用，只能禁用，不能删除');
            }
            if (!$payment->delete()) {
                throw new \RuntimeException('删除失败');
            }
        }, 3);

        return response([
            'data' => true
        ]);
    }

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|distinct'
        ], [
            'ids.required' => '参数有误',
            'ids.array' => '参数有误',
            'ids.min' => '参数有误',
            'ids.*.integer' => '参数有误',
            'ids.*.distinct' => '参数有误'
        ]);

        DB::transaction(function () use ($params) {
            $payments = Payment::whereIn('id', $params['ids'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($payments->count() !== count($params['ids'])) {
                abort(500, '支付方式不存在');
            }

            foreach ($params['ids'] as $index => $id) {
                $payment = $payments->get($id);
                $payment->sort = $index + 1;
                if (!$payment->save()) {
                    throw new \RuntimeException('保存失败');
                }
            }
        }, 3);

        return response([
            'data' => true
        ]);
    }
}
