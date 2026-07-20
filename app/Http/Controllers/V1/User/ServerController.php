<?php

namespace App\Http\Controllers\V1\User;

use App\Support\EtagMatcher;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ServerController extends Controller
{
    public function fetch(Request $request)
    {
        $user = User::find($request->user['id']);
        $servers = [];
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
        }
        $eTag = sha1(json_encode(array_column($servers, 'cache_key')));
        if (EtagMatcher::matches($request->header('If-None-Match'), $eTag)) {
            return response('', 304)->header('ETag', "\"{$eTag}\"");
        }

        return response([
            'data' => $servers
        ])->header('ETag', "\"{$eTag}\"");
    }
}
