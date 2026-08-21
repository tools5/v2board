<?php

// 注入「客户端广告」（AdMob）后台页：菜单项 + 路由 + v2bAdmobSettings 页面模块。
// 幂等：重复执行不会重复插入；模块文本变更时会原位替换更新。

$bundlePath = __DIR__ . '/../public/assets/admin/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}

// 菜单锚点：nav 数组「设置」组末尾（主题配置之后）紧跟「服务器」分组标题。
// 选 heading 而非 /config/theme 菜单项作锚点，是因为 title:"服务器" + type:"heading"
// 的组合（含 20/24 空格缩进）在整个 bundle 中唯一，且新菜单项应插在设置组末尾、服务器组之前。
$menuAnchor = <<<'JS'
                    }, {
                        title: "\u670d\u52a1\u5668",
                        type: "heading"
JS;
if (strpos($bundle, 'href: "/config/admob"') === false) {
    // title「客户端广告」使用 \uXXXX 转义，与 bundle 内既有菜单项风格一致
    $menuItem = <<<'JS'
                    }, {
                        title: "\u5ba2\u6237\u7aef\u5e7f\u544a",
                        type: "item",
                        href: "/config/admob",
                        icon: o.a.createElement("i", {
                            className: "nav-main-link-icon si si-screen-smartphone"
                        })
JS;
    if (strpos($bundle, $menuAnchor) === false) {
        fwrite(STDERR, "Admin admob menu anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($menuAnchor, $menuItem . "\n" . $menuAnchor, $bundle);
}

// 路由锚点：路由表中 /config/theme 条目（component: n("8drl")）在 bundle 中唯一，
// 新路由插在它前面，与其它 /config/* 路由同组。
$routeAnchor = <<<'JS'
        }, {
            path: "/config/theme",
            exact: !0,
            component: n("8drl").default
JS;
if (strpos($bundle, 'path: "/config/admob"') === false) {
    $routeItem = <<<'JS'
        }, {
            path: "/config/admob",
            exact: !0,
            component: n("v2bAdmobSettings").default
JS;
    if (strpos($bundle, $routeAnchor) === false) {
        fwrite(STDERR, "Admin admob route anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($routeAnchor, $routeItem . "\n" . $routeAnchor, $bundle);
}

// 页面模块：插入 webpack 模块映射。
$modulePath = __DIR__ . '/admin-admob-module.js';
$moduleSource = file_get_contents($modulePath);
if ($moduleSource === false || strpos($moduleSource, 'v2bAdmobSettings: function') === false) {
    fwrite(STDERR, "Admin admob module source is invalid.\n");
    exit(1);
}
$moduleSource = trim($moduleSource);

// 模块锚点：v2bWebPushManage 是模块映射中的既有自定义模块键（4 空格缩进），全 bundle 唯一；
// 新模块插到它前面（即紧跟 v2bLoginSettings 之后）。
$moduleAnchor = "\n    v2bWebPushManage: function(e, t, n) {";
$anchorPos = strpos($bundle, $moduleAnchor);
if ($anchorPos === false) {
    fwrite(STDERR, "Admin module boundary (v2bWebPushManage) not found.\n");
    exit(1);
}
$moduleStart = strpos($bundle, "    v2bAdmobSettings: function(e, t, n) {");
if ($moduleStart !== false && $moduleStart < $anchorPos) {
    // 已注入：以旧模块起点至 v2bWebPushManage 前换行为界原位替换（支持更新模块内容）
    $bundle = substr_replace($bundle, "    " . $moduleSource . ",", $moduleStart, $anchorPos - $moduleStart);
} else {
    // 未注入：插到 v2bWebPushManage 之前
    $bundle = substr_replace($bundle, "\n    " . $moduleSource . ",", $anchorPos, 0);
}

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write admin bundle.\n");
    exit(1);
}
fwrite(STDOUT, "Admin admob settings page patched.\n");
