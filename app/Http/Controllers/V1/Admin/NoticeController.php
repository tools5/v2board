<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NoticeSave;
use App\Jobs\SendNoticeWebPushJob;
use App\Models\Notice;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        return response([
            'data' => Notice::orderBy('id', 'DESC')->get()
        ]);
    }

    public function save(NoticeSave $request)
    {
        $noticeId = null;
        if ($request->filled('id')) {
            $params = $request->validate([
                'id' => 'required|integer|min:1'
            ]);
            $noticeId = (int)$params['id'];
        }

        return $this->persistNotice($request, $noticeId);
    }

    public function update(NoticeSave $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|min:1'
        ]);

        return $this->persistNotice($request, (int)$params['id']);
    }

    private function persistNotice(NoticeSave $request, ?int $noticeId)
    {
        $data = $request->only([
            'title',
            'content',
            'img_url',
            'tags'
        ]);

        $notice = $noticeId ? Notice::find($noticeId) : new Notice();
        if (!$notice) {
            abort(404, '公告不存在');
        }

        try {
            $saved = $notice->fill($data)->save();
        } catch (\Throwable $e) {
            report($e);
            abort(500, '保存失败');
        }
        if (!$saved) {
            abort(500, '保存失败');
        }

        $this->dispatchWebPushIfNeeded($notice->fresh());

        return response([
            'data' => true
        ]);
    }

    public function show(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数有误');
        }
        $notice = Notice::find($request->input('id'));
        if (!$notice) {
            abort(500, '公告不存在');
        }
        $notice->show = $notice->show ? 0 : 1;
        if (!$notice->save()) {
            abort(500, '保存失败');
        }

        $this->dispatchWebPushIfNeeded($notice);

        return response([
            'data' => true
        ]);
    }

    private function dispatchWebPushIfNeeded(Notice $notice)
    {
        if (!$notice || !$notice->show || $notice->web_push_sent_at) {
            return;
        }

        SendNoticeWebPushJob::dispatch($notice->id);
    }


    public function drop(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        $notice = Notice::find($request->input('id'));
        if (!$notice) {
            abort(500, '公告不存在');
        }
        if (!$notice->delete()) {
            abort(500, '删除失败');
        }
        return response([
            'data' => true
        ]);
    }
}
