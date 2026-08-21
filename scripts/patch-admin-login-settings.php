<?php

// 用扩展版 v2bLoginSettings 模块（新增「应用回调 scheme」与「Cap 自托管验证码」配置）
// 原位替换后台 bundle 中的原版模块。路由与菜单项原版已有（/config/login），无需注入。
// 幂等：扩展版包含 oauth_app_scheme 字符串，原版模块没有，以此判断是否已打过补丁。

$bundlePath = __DIR__ . '/../public/assets/admin/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}

if (strpos($bundle, 'oauth_app_scheme') !== false) {
    fwrite(STDOUT, "Admin login settings already patched.\n");
    exit(0);
}

$modulePath = __DIR__ . '/admin-login-settings-module.js';
$moduleSource = file_get_contents($modulePath);
if ($moduleSource === false
    || strpos($moduleSource, 'v2bLoginSettings: function') === false
    || strpos($moduleSource, 'oauth_app_scheme') === false) {
    fwrite(STDERR, "Admin login settings module source is invalid.\n");
    exit(1);
}
$moduleSource = trim($moduleSource);

// 起点锚点：webpack 模块映射的键唯一，4 空格缩进的模块头在 bundle 中只出现一次。
$startMarker = "    v2bLoginSettings: function(e, t, n) {";
$start = strpos($bundle, $startMarker);
if ($start === false) {
    fwrite(STDERR, "Admin login settings module start marker not found.\n");
    exit(1);
}

// 终点锚点：原版中 v2bLoginSettings 的下一个模块键是 v2bWebPushManage；
// 若先运行过 patch-admin-admob.php，v2bAdmobSettings 会插在二者之间，
// 因此取两个候选锚点中更靠前者，避免把 admob 模块一并覆盖掉。
$endCandidates = array(
    "\n    v2bAdmobSettings: function(e, t, n) {",
    "\n    v2bWebPushManage: function(e, t, n) {",
);
$end = false;
foreach ($endCandidates as $marker) {
    $pos = strpos($bundle, $marker, $start);
    if ($pos !== false && ($end === false || $pos < $end)) {
        $end = $pos;
    }
}
if ($end === false || $end <= $start) {
    fwrite(STDERR, "Admin login settings module end marker not found.\n");
    exit(1);
}

// 区间 [start, end) 覆盖原模块全文（含结尾的“    },”，不含终点锚点前的换行）
$bundle = substr_replace($bundle, "    " . $moduleSource . ",", $start, $end - $start);

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write admin bundle.\n");
    exit(1);
}
fwrite(STDOUT, "Admin login settings page patched.\n");
