<?php

// 用重排版的 v2bOauthManage 模块原位替换后台 bundle 中的现有模块，
// 修复 OAuth 管理页表格排版挤压（表头 CJK 竖排、操作按钮列拥挤）：
// - 表格容器横向滚动（overflow-x:auto），表格 min-width，不再挤压
// - 表头与套餐/流量、在线/设备、状态等关键单元格 white-space:nowrap
// - 各列设置最小宽度；操作列改为紧凑小按钮 flex 流式换行（约两行）
// - 样式由模块内 ensureStyles 注入（style#v2b-oauth-manage-style），不动 custom.css
// 数据与操作逻辑零变化，仅布局/样式/列宽调整。
// 幂等：新模块含 v2b-oauth-manage-style 样式标识，旧模块没有，以此判断是否已打过补丁。

$bundlePath = __DIR__ . '/../public/assets/admin/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}

if (strpos($bundle, 'v2b-oauth-manage-style') !== false) {
    fwrite(STDOUT, "Admin oauth manage layout already patched.\n");
    exit(0);
}

$modulePath = __DIR__ . '/admin-oauth-manage-module.js';
$moduleSource = file_get_contents($modulePath);
if ($moduleSource === false
    || strpos($moduleSource, 'v2bOauthManage: function') === false
    || strpos($moduleSource, 'v2b-oauth-manage-style') === false) {
    fwrite(STDERR, "Admin oauth manage module source is invalid.\n");
    exit(1);
}
$moduleSource = trim($moduleSource);

// 起点锚点：webpack 模块映射的键唯一，4 空格缩进的模块头在 bundle 中只出现一次。
$startMarker = "    v2bOauthManage: function(e, t, n) {";
$start = strpos($bundle, $startMarker);
if ($start === false) {
    fwrite(STDERR, "Admin oauth manage module start marker not found.\n");
    exit(1);
}

// 终点锚点：当前 v2bOauthManage 的下一个模块键是 "1j5w"；
// 若以后有补丁在二者之间插入新模块，需把对应模块键补进候选列表，
// 取多个候选中更靠前者，避免把中间的模块一并覆盖掉
// （双候选处理方式同 patch-admin-login-settings.php）。
$endCandidates = array(
    "\n    \"1j5w\": function(e, t, n) {",
);
$end = false;
foreach ($endCandidates as $marker) {
    $pos = strpos($bundle, $marker, $start);
    if ($pos !== false && ($end === false || $pos < $end)) {
        $end = $pos;
    }
}
if ($end === false || $end <= $start) {
    fwrite(STDERR, "Admin oauth manage module end marker not found.\n");
    exit(1);
}

// 区间 [start, end) 覆盖原模块全文（含结尾的“    },”，不含终点锚点前的换行）
$bundle = substr_replace($bundle, "    " . $moduleSource . ",", $start, $end - $start);

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write admin bundle.\n");
    exit(1);
}
fwrite(STDOUT, "Admin oauth manage layout patched.\n");
