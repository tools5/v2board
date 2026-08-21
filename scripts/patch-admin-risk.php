<?php

// 注入「风控」后台三页：菜单分组（风控 heading + 风控规则 / 订阅溯源 / 多账号同 IP 三项）
// + 3 条路由 + 3 个页面模块（v2bRiskRule / v2bRiskTrace / v2bRiskSharedIp）。
// 页面自 kexue 风控套件移植，已适配本站单订阅制（user 维度）的展示口径。
// 幂等：重复执行不会重复插入；模块文本变更时会原位替换更新。

$bundlePath = __DIR__ . '/../public/assets/admin/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}

// ---------- 菜单 ----------
// 菜单锚点：nav 数组「用户」组末尾（知识库管理之后）紧跟「指标」分组标题。
// 风控组插在「用户」组之后、「指标」组之前，贴近 kexue 原版位置；
// title:"指标" + type:"heading" 的组合（含 20/24 空格缩进）在整个 bundle 中唯一。
$menuAnchor = <<<'JS'
                    }, {
                        title: "\u6307\u6807",
                        type: "heading"
JS;
if (strpos($bundle, 'href: "/risk/rule"') === false) {
    // 菜单标题使用 \uXXXX 转义，与 bundle 内既有菜单项风格一致：
    // 风控 / 风控规则 / 订阅溯源 / 多账号同 IP
    $menuItem = <<<'JS'
                    }, {
                        title: "\u98ce\u63a7",
                        type: "heading"
                    }, {
                        title: "\u98ce\u63a7\u89c4\u5219",
                        type: "item",
                        href: "/risk/rule",
                        icon: o.a.createElement("i", {
                            className: "nav-main-link-icon si si-shield"
                        })
                    }, {
                        title: "\u8ba2\u9605\u6eaf\u6e90",
                        type: "item",
                        href: "/risk/trace",
                        icon: o.a.createElement("i", {
                            className: "nav-main-link-icon si si-magnifier"
                        })
                    }, {
                        title: "\u591a\u8d26\u53f7\u540c IP",
                        type: "item",
                        href: "/risk/shared-ip",
                        icon: o.a.createElement("i", {
                            className: "nav-main-link-icon si si-share-alt"
                        })
JS;
    if (strpos($bundle, $menuAnchor) === false) {
        fwrite(STDERR, "Admin risk menu anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($menuAnchor, $menuItem . "\n" . $menuAnchor, $bundle);
}

// ---------- 路由 ----------
// 路由锚点：路由表末尾的 /user 条目（component: n("d1ca")）加收尾的 "}];"，
// 该组合在 bundle 中唯一；三条风控路由插在它前面（数组内位置不影响 exact 匹配）。
$routeAnchor = <<<'JS'
        }, {
            path: "/user",
            exact: !0,
            component: n("d1ca").default
        }];
JS;
if (strpos($bundle, 'path: "/risk/rule"') === false) {
    $routeItem = <<<'JS'
        }, {
            path: "/risk/rule",
            exact: !0,
            component: n("v2bRiskRule").default
        }, {
            path: "/risk/trace",
            exact: !0,
            component: n("v2bRiskTrace").default
        }, {
            path: "/risk/shared-ip",
            exact: !0,
            component: n("v2bRiskSharedIp").default
JS;
    if (strpos($bundle, $routeAnchor) === false) {
        fwrite(STDERR, "Admin risk route anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($routeAnchor, $routeItem . "\n" . $routeAnchor, $bundle);
}

// ---------- 页面模块 ----------
// 模块插入点选在 v2bLoginSettings 之前（而不是既有补丁常用的 v2bWebPushManage 之前）：
// patch-admin-login-settings.php 与 patch-admin-admob.php 的「原位替换」区间分别是
// [v2bLoginSettings, v2bAdmobSettings|v2bWebPushManage) 与 [v2bAdmobSettings, v2bWebPushManage)，
// 若把风控模块夹进这些区间，重跑那两个补丁会把风控模块整段吞掉；放在 v2bLoginSettings
// 之前则不落入任何既有补丁的替换区间。
// 每个模块的原位替换终点取它后面第一个出现的已知模块键（三个风控模块按下列顺序相邻，
// 最后以 v2bLoginSettings 收尾），多候选取更靠前者，处理方式同 patch-admin-login-settings.php。
$moduleSpecs = array(
    array(
        'key' => 'v2bRiskRule',
        'file' => __DIR__ . '/admin-risk-rule-module.js',
        'next' => array('v2bRiskTrace', 'v2bRiskSharedIp', 'v2bLoginSettings'),
    ),
    array(
        'key' => 'v2bRiskTrace',
        'file' => __DIR__ . '/admin-risk-trace-module.js',
        'next' => array('v2bRiskSharedIp', 'v2bLoginSettings'),
    ),
    array(
        'key' => 'v2bRiskSharedIp',
        'file' => __DIR__ . '/admin-risk-shared-ip-module.js',
        'next' => array('v2bLoginSettings'),
    ),
);
foreach ($moduleSpecs as $spec) {
    $moduleSource = file_get_contents($spec['file']);
    // 模块源文件必须以模块键开头（无 BOM、无前置空白），防止拿错文件整段注入。
    if ($moduleSource === false || strpos($moduleSource, $spec['key'] . ': function(e, t, n) {') !== 0) {
        fwrite(STDERR, "Admin risk module source is invalid: " . basename($spec['file']) . "\n");
        exit(1);
    }
    $moduleSource = trim($moduleSource);

    // 起点锚点：webpack 模块映射的键唯一，4 空格缩进的模块头在 bundle 中只出现一次。
    $startMarker = '    ' . $spec['key'] . ': function(e, t, n) {';
    $start = strpos($bundle, $startMarker);
    // 终点锚点：start 之后第一个出现的已知后继模块键（换行 + 4 空格缩进的模块头）。
    $end = false;
    foreach ($spec['next'] as $nextKey) {
        $pos = strpos($bundle, "\n    " . $nextKey . ': function(e, t, n) {', $start === false ? 0 : $start);
        if ($pos !== false && ($end === false || $pos < $end)) {
            $end = $pos;
        }
    }
    if ($end === false) {
        fwrite(STDERR, "Admin risk module boundary not found for " . $spec['key'] . ".\n");
        exit(1);
    }
    if ($start !== false) {
        if ($start >= $end) {
            fwrite(STDERR, "Admin risk module layout unexpected for " . $spec['key'] . ".\n");
            exit(1);
        }
        // 已注入：以旧模块起点至后继模块键前换行为界原位替换（支持更新模块内容）
        $bundle = substr_replace($bundle, '    ' . $moduleSource . ',', $start, $end - $start);
    } else {
        // 未注入：插到后继模块键之前（其前导换行之前）
        $bundle = substr_replace($bundle, "\n    " . $moduleSource . ',', $end, 0);
    }
}

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write admin bundle.\n");
    exit(1);
}
fwrite(STDOUT, "Admin risk pages patched.\n");
