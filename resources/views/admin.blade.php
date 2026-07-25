<!doctype html>
<html lang="zh-CN">
<head>
    @php
        $builtIndexPath = public_path('assets/admin-next/index.html');
        $builtIndex = is_file($builtIndexPath) ? file_get_contents($builtIndexPath) : '';
        preg_match_all('/<script\b[^>]*\bsrc="([^"]+)"/i', $builtIndex, $scriptMatches);
        preg_match_all('/<link\b[^>]*\bhref="([^"]+)"[^>]*\brel="stylesheet"/i', $builtIndex, $styleMatches);
        $assetFilter = function ($asset) {
            return is_string($asset) && strpos($asset, '/assets/admin-next/') === 0;
        };
        $scriptAssets = array_values(array_filter($scriptMatches[1] ?? [], $assetFilter));
        $styleAssets = array_values(array_filter($styleMatches[1] ?? [], $assetFilter));
        if (!$scriptAssets) {
            // 兜底：index.html 缺失/解析失败时按目录实际文件构造。
            // 不能写死 chunk 名——共享 chunk 的编号（948.js/698.js…）每次构建都会漂移，
            // 写死的列表在下一次构建后就是坏的。lib-react 排最前、入口 index 排最后，
            // 其余 chunk 居中（都是 defer，webpack 运行时会等依赖就位，顺序仅求稳妥）。
            $jsFiles = glob(public_path('assets/admin-next/static/js/*.js')) ?: [];
            $scriptAssets = array_map(function ($file) {
                return '/assets/admin-next/static/js/' . basename($file);
            }, $jsFiles);
            usort($scriptAssets, function ($a, $b) {
                $rank = function ($f) {
                    if (strpos($f, 'lib-react') !== false) return 0;
                    if (strpos($f, 'index') !== false) return 2;
                    return 1;
                };
                return $rank($a) <=> $rank($b);
            });
        }
        if (!$styleAssets) {
            $styleAssets = ['/assets/admin-next/static/css/index.css'];
        }
        $assetFiles = array_merge($scriptAssets, $styleAssets);
        $assetTimes = array_map(function ($asset) {
            return (int) @filemtime(public_path(ltrim($asset, '/')));
        }, $assetFiles);
        $assetVersion = $version . '.' . max(array_merge([0], $assetTimes));
        $settings = [
            'title' => $title,
            'theme' => [
                'sidebar' => $theme_sidebar,
                'header' => $theme_header,
                'color' => $theme_color,
            ],
            'version' => $version,
            'background_url' => $background_url,
            'logo' => $logo,
            'secure_path' => $secure_path,
        ];
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <meta name="google" content="notranslate">
    <title>{{$title}}</title>
    <script>
        (function () {
            try {
                var match = document.cookie.match(/(?:^|;)\s*vite-ui-theme\s*=\s*([^;]+)/);
                var theme = match ? decodeURIComponent(match[1]) : 'system';
                var dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.add(dark ? 'dark' : 'light');
            } catch (e) {}
        })();
        window.settings = @json($settings);
    </script>
    @foreach ($styleAssets as $asset)
        <link rel="stylesheet" href="{{$asset}}?v={{$assetVersion}}">
    @endforeach
    @foreach ($scriptAssets as $asset)
        <script defer src="{{$asset}}?v={{$assetVersion}}"></script>
    @endforeach
</head>
<body>
<script>
    (function () {
        try {
            var cookie = function (key) {
                // 注意双反斜杠：字符串字面量里 '\s' 会退化成 's'，
                // 导致 cookie 前有空格（"; theme_preset=…"）时永远匹配不到
                var match = document.cookie.match('(?:^|;)\\s*' + key + '\\s*=\\s*([^;]+)');
                return match ? decodeURIComponent(match[1]) : '';
            };
            var body = document.body;
            var preset = cookie('theme_preset') || 'default';
            var font = cookie('theme_font') || 'default';
            if (font === 'default') font = preset === 'anthropic' ? 'serif' : 'sans';
            body.setAttribute('data-theme-preset', preset);
            body.setAttribute('data-theme-radius', cookie('theme_radius') || 'default');
            body.setAttribute('data-theme-scale', cookie('theme_scale') || 'default');
            body.setAttribute('data-theme-font', font);
            body.setAttribute('data-theme-content-layout', cookie('theme_content_layout') || 'full');
        } catch (e) {}
    })();
</script>
<div id="root" translate="no" class="notranslate"></div>
</body>
</html>
