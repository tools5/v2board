<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponGenerate;
use App\Models\Coupon;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    private const MAX_PAGE_SIZE = 100;

    public function fetch(Request $request)
    {
        $payload = $request->validate([
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1',
            'sort_type' => 'nullable|string|in:ASC,DESC,asc,desc',
            'sort' => 'nullable|string|in:id,code,name,type,value,show,limit_use,started_at,ended_at,created_at,updated_at'
        ]);
        $current = max(1, (int)($payload['current'] ?? 1));
        $pageSize = min(self::MAX_PAGE_SIZE, max(10, (int)($payload['pageSize'] ?? 10)));
        $sortType = strtoupper($payload['sort_type'] ?? 'DESC');
        $sort = $payload['sort'] ?? 'id';
        $builder = Coupon::orderBy($sort, $sortType);
        $total = $builder->count();
        $coupons = $builder->forPage($current, $pageSize)
            ->get();
        return response([
            'data' => $coupons,
            'total' => $total
        ]);
    }

    public function show(Request $request)
    {
        $payload = $request->validate(['id' => 'required|integer|min:1']);
        $coupon = Coupon::find($payload['id']);
        if (!$coupon) {
            abort(404, '优惠券不存在');
        }
        $coupon->show = $coupon->show ? 0 : 1;
        if (!$coupon->save()) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function generate(CouponGenerate $request)
    {
        if ($request->filled('generate_count')) {
            return $this->multiGenerate($request);
        }

        $params = $request->validated();
        unset($params['id'], $params['generate_count']);
        if (!$request->input('id')) {
            if (empty($params['code'])) {
                $params['code'] = Helper::randomChar(8);
            }
            if (!Coupon::create($params)) {
                abort(500, '创建失败');
            }
        } else {
            $coupon = Coupon::find($request->input('id'));
            if (!$coupon) {
                abort(404, '优惠券不存在');
            }
            try {
                $coupon->update($params);
            } catch (\Throwable $e) {
                report($e);
                abort(500, '保存失败');
            }
        }

        return response([
            'data' => true
        ]);
    }

    private function multiGenerate(CouponGenerate $request)
    {
        $coupons = [];
        $coupon = $request->validated();
        $coupon['created_at'] = $coupon['updated_at'] = time();
        $coupon['show'] = 1;
        unset($coupon['id'], $coupon['generate_count'], $coupon['code']);
        $generatedCodes = [];
        for ($i = 0; $i < (int)$request->input('generate_count'); $i++) {
            do {
                $code = Helper::randomChar(12);
            } while (isset($generatedCodes[$code]));
            $generatedCodes[$code] = true;
            $coupon['code'] = $code;
            $coupons[] = $coupon;
        }

        $rows = array_map(function ($item) {
            if (isset($item['limit_plan_ids']) && is_array($item['limit_plan_ids'])) {
                $item['limit_plan_ids'] = json_encode($item['limit_plan_ids']);
            }
            if (isset($item['limit_period']) && is_array($item['limit_period'])) {
                $item['limit_period'] = json_encode($item['limit_period']);
            }
            return $item;
        }, $coupons);

        try {
            DB::transaction(function () use ($rows) {
                if (!Coupon::insert($rows)) {
                    throw new \RuntimeException('优惠券批量写入失败');
                }
            });
        } catch (\Throwable $error) {
            report($error);
            abort(500, '生成失败');
        }

        $csvRows = [['名称', '类型', '金额或比例', '开始时间', '结束时间', '可用次数', '可用于订阅', '券码', '生成时间']];
        foreach ($coupons as $coupon) {
            $type = ['', '金额', '比例'][$coupon['type']];
            $value = ['', ($coupon['value'] / 100), $coupon['value']][$coupon['type']];
            $startTime = date('Y-m-d H:i:s', $coupon['started_at']);
            $endTime = date('Y-m-d H:i:s', $coupon['ended_at']);
            $limitUse = $coupon['limit_use'] ?? '不限制';
            $createTime = date('Y-m-d H:i:s', $coupon['created_at']);
            $limitPlanIds = isset($coupon['limit_plan_ids']) ? implode("/", $coupon['limit_plan_ids']) : '不限制';
            $csvRows[] = [$coupon['name'], $type, $value, $startTime, $endTime, $limitUse, $limitPlanIds, $coupon['code'], $createTime];
        }

        return $this->csvResponse($csvRows, 'coupons.csv');
    }

    public function drop(Request $request)
    {
        $payload = $request->validate(['id' => 'required|integer|min:1']);
        $coupon = Coupon::find($payload['id']);
        if (!$coupon) {
            abort(404, '优惠券不存在');
        }
        if (!$coupon->delete()) {
            abort(500, '删除失败');
        }

        return response([
            'data' => true
        ]);
    }

    private function csvResponse(array $rows, $filename)
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            abort(500, '生成导出文件失败');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            $row = array_map([$this, 'escapeSpreadsheetCell'], $row);
            fputcsv($stream, $row);
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return response($contents, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Content-Type-Options' => 'nosniff'
        ]);
    }

    private function escapeSpreadsheetCell($value)
    {
        $value = (string)$value;
        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'" . $value : $value;
    }
}
