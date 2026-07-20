<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;

class ThemeController extends Controller
{
    private $themes;
    private $path;

    public function __construct()
    {
        $this->path = public_path('theme') . DIRECTORY_SEPARATOR;
        $themeDirectories = glob($this->path . '*', GLOB_ONLYDIR) ?: [];
        $this->themes = array_values(array_filter(array_map(function ($item) {
            return basename($item);
        }, $themeDirectories), function ($theme) {
            return strlen($theme) <= 64 && preg_match('/^[A-Za-z0-9_-]+$/D', $theme) === 1;
        }));
    }

    public function getThemes()
    {
        $themeConfigs = [];
        foreach ($this->themes as $theme) {
            $themeConfigFile = $this->path . "{$theme}/config.json";
            if (!File::exists($themeConfigFile)) continue;
            $themeConfig = json_decode(File::get($themeConfigFile), true, 64);
            if (json_last_error() !== JSON_ERROR_NONE
                || !is_array($themeConfig)
                || !isset($themeConfig['configs'])
                || !is_array($themeConfig['configs'])
                || count($themeConfig['configs']) > 256
            ) continue;
            $themeConfigs[$theme] = $themeConfig;
            if (config("theme.{$theme}")) continue;
            $themeService = new ThemeService($theme);
            $themeService->init();
        }
        return response([
            'data' => [
                'themes' => $themeConfigs,
                'active' => config('v2board.frontend_theme', 'v2board')
            ]
        ]);
    }

    public function getThemeConfig(Request $request)
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::in($this->themes)]
        ]);
        return response([
            'data' => config("theme.{$payload['name']}")
        ]);
    }

    public function saveThemeConfig(Request $request)
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::in($this->themes)],
            'config' => 'required|string|max:2097152'
        ]);
        $decoded = base64_decode($payload['config'], true);
        $payload['config'] = is_string($decoded) ? json_decode($decoded, true, 64) : null;
        if (!is_array($payload['config']) || json_last_error() !== JSON_ERROR_NONE) {
            abort(422, '参数有误');
        }

        try {
            $themeService = new ThemeService($payload['name']);
            $config = $themeService->save($payload['config']);
        } catch (\InvalidArgumentException $error) {
            abort(422, $error->getMessage());
        } catch (\Throwable $error) {
            report($error);
            abort(500, '保存失败');
        }

        return response([
            'data' => $config
        ]);
    }
}
