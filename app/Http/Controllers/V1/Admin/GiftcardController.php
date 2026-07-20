<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GiftcardGenerate;
use App\Models\Giftcard;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GiftcardController extends Controller
{
    private const MAX_PAGE_SIZE = 100;

    public function fetch(Request $request)
    {
        $payload = $request->validate([
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1',
            'sort_type' => 'nullable|string|in:ASC,DESC,asc,desc',
            'sort' => 'nullable|string|in:id,code,name,type,value,plan_id,limit_use,started_at,ended_at,created_at,updated_at'
        ]);
        $current = max(1, (int)($payload['current'] ?? 1));
        $pageSize = min(self::MAX_PAGE_SIZE, max(10, (int)($payload['pageSize'] ?? 10)));
        $sortType = strtoupper($payload['sort_type'] ?? 'DESC');
        $sort = $payload['sort'] ?? 'id';

        $builder = Giftcard::orderBy($sort, $sortType);
        $total = $builder->count();
        $giftcards = $builder->forPage($current, $pageSize)->get();

        return response([
            'data' => $giftcards,
            'total' => $total
        ]);
    }

    public function generate(GiftcardGenerate $request)
    {
        if ($request->filled('generate_count')) {
            return $this->multiGenerate($request);
        }

        $params = $request->validated();
        unset($params['id'], $params['generate_count']);
        $this->normalizeTypeSpecificFields($params);
        if (!$request->input('id')) {
            if (empty($params['code'])) {
                $params['code'] = Helper::randomChar(16);
            }
            if (!Giftcard::create($params)) {
                abort(500, '礼品卡创建失败');
            }
        } else {
            $giftcard = Giftcard::find($request->input('id'));
            if (!$giftcard) {
                abort(404, '礼品卡不存在');
            }
            try {
                $giftcard->update($params);
            } catch (\Throwable $e) {
                report($e);
                abort(500, '礼品卡保存失败');
            }
        }

        return response([
            'data' => true
        ]);
    }

    private function multiGenerate(GiftcardGenerate $request)
    {
        $giftcards = [];
        $giftcard = $request->validated();
        $giftcard['created_at'] = $giftcard['updated_at'] = time();
        unset($giftcard['id'], $giftcard['generate_count'], $giftcard['code']);
        $this->normalizeTypeSpecificFields($giftcard);
        $generatedCodes = [];

        for ($i = 0; $i < $request->input('generate_count'); $i++) {
            do {
                $giftcard['code'] = Helper::randomChar(16);
            } while (isset($generatedCodes[$giftcard['code']]));
            $generatedCodes[$giftcard['code']] = true;
            $giftcards[] = $giftcard;
        }

        try {
            DB::transaction(function () use ($giftcards) {
                if (!Giftcard::insert($giftcards)) {
                    throw new \RuntimeException('礼品卡批量写入失败');
                }
            });
        } catch (\Throwable $error) {
            report($error);
            abort(500, '礼品卡批量生成失败');
        }

        $csvRows = [['名称', '类型', '数值', '开始时间', '结束时间', '可用次数', '礼品卡卡密', '生成时间']];
        foreach ($giftcards as $giftcard) {
            $type = ['', '金额', '时长', '流量', '重置', '套餐'][$giftcard['type']];
            $giftcardValue = $giftcard['value'] ?? 0;
            $value = ['', round($giftcardValue / 100, 2), $giftcardValue . '天', $giftcardValue . 'GB', '-', $giftcardValue . '天'][$giftcard['type']];
            $startTime = date('Y-m-d H:i:s', $giftcard['started_at']);
            $endTime = date('Y-m-d H:i:s', $giftcard['ended_at']);
            $limitUse = $giftcard['limit_use'] ?? '不限制';
            $createTime = date('Y-m-d H:i:s', $giftcard['created_at']);
            $csvRows[] = [$giftcard['name'], $type, $value, $startTime, $endTime, $limitUse, $giftcard['code'], $createTime];
        }

        return $this->csvResponse($csvRows, 'giftcards.csv');
    }

    public function drop(Request $request)
    {
        $payload = $request->validate(['id' => 'required|integer|min:1']);
        $giftcardId = $payload['id'];

        $giftcard = Giftcard::find($giftcardId);
        if (!$giftcard) {
            abort(404, '礼品卡不存在');
        }

        if (!$giftcard->delete()) {
            abort(500, '删除失败');
        }

        return response([
            'data' => true
        ]);
    }

    private function normalizeTypeSpecificFields(array &$giftcard)
    {
        if ((int)$giftcard['type'] !== 5) {
            $giftcard['plan_id'] = null;
        }
        if ((int)$giftcard['type'] === 4) {
            $giftcard['value'] = null;
        }
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
