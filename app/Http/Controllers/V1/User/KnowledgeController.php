<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Knowledge;
use App\Models\User;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $knowledge = Knowledge::where('id', $request->input('id'))
                ->where('show', 1)
                ->first();
            if (!$knowledge) abort(500, __('Article does not exist'));
            $knowledge = $knowledge->toArray();
            $user = User::find($request->user['id']);
            if (!$user) abort(500, __('The user does not exist'));
            $userService = new UserService();
            if (!$userService->isAvailable($user)) {
                $this->formatAccessData($knowledge['body']);
            }
            $subscribeUrl = Helper::getSubscribeUrl($user['token']);
            $knowledge['body'] = str_replace('{{siteName}}', config('v2board.app_name', 'V2Board'), $knowledge['body']);
            $knowledge['body'] = str_replace('{{subscribeUrl}}', $subscribeUrl, $knowledge['body']);
            $knowledge['body'] = str_replace('{{urlEncodeSubscribeUrl}}', urlencode($subscribeUrl), $knowledge['body']);
            $knowledge['body'] = str_replace(
                '{{safeBase64SubscribeUrl}}',
                str_replace(
                    array('+', '/', '='),
                    array('-', '_', ''),
                    base64_encode($subscribeUrl)
                ),
                $knowledge['body']
            );
            $knowledge['body'] = str_replace('{{subscribeToken}}', $user['token'], $knowledge['body']);
            return response([
                'data' => $knowledge
            ]);
        }
        $builder = Knowledge::select(['id', 'category', 'title', 'updated_at'])
            ->where('language', $request->input('language'))
            ->where('show', 1)
            ->orderBy('sort', 'ASC');
        $keyword = $request->input('keyword');
        if ($keyword) {
            $builder = $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('body', 'LIKE', "%{$keyword}%");
            });
        }

        $knowledges = $builder->get()
            ->groupBy('category');
        return response([
            'data' => $knowledges
        ]);
    }

    public function getCategory(Request $request)
    {
        $builder = Knowledge::query()
            ->where('show', 1)
            ->whereNotNull('category')
            ->where('category', '<>', '');

        if ($request->filled('language')) {
            $builder->where('language', $request->input('language'));
        }

        return response([
            'data' => $builder->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->values(),
        ]);
    }

    private function formatAccessData(&$body)
    {
        $startMarker = '<!--access start-->';
        $endMarker = '<!--access end-->';
        $replacement = '<div class="v2board-no-access">'
            . __('You must have a valid subscription to view content in this area')
            . '</div>';

        while (($start = strpos($body, $startMarker)) !== false) {
            $end = strpos($body, $endMarker, $start + strlen($startMarker));
            if ($end === false) {
                // A malformed restricted block protects everything after its start marker.
                $body = substr($body, 0, $start) . $replacement;
                break;
            }

            $body = substr($body, 0, $start)
                . $replacement
                . substr($body, $end + strlen($endMarker));
        }
    }
}
