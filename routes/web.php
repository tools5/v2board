<?php

use App\Services\ThemeService;
use App\Support\ConfiguredUrl;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

$frontendTheme = trim((string)config('v2board.frontend_theme', 'default'));
if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $frontendTheme)
    || !is_dir(public_path('theme') . DIRECTORY_SEPARATOR . $frontendTheme)) {
    $frontendTheme = 'default';
}

$legacySecurePath = trim((string)config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
$securePath = trim((string)config('v2board.secure_path', $legacySecurePath));
if (!preg_match('/\A[A-Za-z0-9_-]{8,}\z/', $securePath)) {
    $securePath = preg_match('/\A[A-Za-z0-9_-]{8,}\z/', $legacySecurePath)
        ? $legacySecurePath
        : hash('crc32b', config('app.key'));
}

$subscribePath = trim((string)config('v2board.subscribe_path', ''));
if ($subscribePath !== '' && ConfiguredUrl::applicationPathUrl($subscribePath) === '') {
    $subscribePath = '';
}

Route::get('/', function (Request $request) use ($frontendTheme) {
    if (config('v2board.app_url') && config('v2board.safe_mode_enable', 0)) {
        $configuredHost = ConfiguredUrl::applicationHost();
        if ($configuredHost === '' || strtolower(rtrim($request->getHost(), '.')) !== $configuredHost) {
            abort(403);
        }
    }
    $renderParams = [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme' => $frontendTheme,
        'version' => config('app.version'),
        'description' => config('v2board.app_description', 'V2Board is best'),
        'logo' => ConfiguredUrl::normalizeExternalHttpUrl(config('v2board.logo'))
    ];

    if (!config("theme.{$renderParams['theme']}")) {
        $themeService = new ThemeService($renderParams['theme']);
        $themeService->init();
    }

    $themeConfig = config('theme.' . $frontendTheme);
    if (!is_array($themeConfig)) {
        $themeConfig = [];
    }
    $themeConfig['background_url'] = ConfiguredUrl::normalizeExternalHttpUrl($themeConfig['background_url'] ?? '');
    $renderParams['theme_config'] = $themeConfig;

    return view('theme::' . $frontendTheme . '.dashboard', $renderParams);
});

//TODO:: 兼容
Route::get('/' . $securePath, function () use ($securePath) {
    return view('admin', [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme_sidebar' => config('v2board.frontend_theme_sidebar', 'light'),
        'theme_header' => config('v2board.frontend_theme_header', 'dark'),
        'theme_color' => config('v2board.frontend_theme_color', 'default'),
        'background_url' => ConfiguredUrl::normalizeExternalHttpUrl(config('v2board.frontend_background_url')),
        'version' => config('app.version'),
        'logo' => ConfiguredUrl::normalizeExternalHttpUrl(config('v2board.logo')),
        'secure_path' => $securePath
    ]);
});

if ($subscribePath !== '') {
    Route::get($subscribePath, 'V1\\Client\\ClientController@subscribe')->middleware('client');
}

// Root-scoped service worker for browser Web Push
Route::get('/web-push-sw.js', function () {
    $path = public_path('web-push-sw.js');
    if (!is_file($path)) {
        abort(404);
    }

    return response()->file($path, [
        'Content-Type' => 'application/javascript; charset=UTF-8',
        'Service-Worker-Allowed' => '/',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
});