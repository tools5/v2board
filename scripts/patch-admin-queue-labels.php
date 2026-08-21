<?php

// 修复后台「队列监控」页空白行：面板 Horizon 多了 send_web_push 队列（见 config/horizon.php），
// 原版队列名称映射表没有这一项，render 返回 undefined 导致整行队列名空白。
// 补上 send_web_push（浏览器推送队列）映射，并给 render 加兜底：
// 未知队列直接显示原始队列名，以后再加队列也不会出现空白行。
// 幂等：补丁后 bundle 含 send_web_push，原版没有，以此判断是否已打过补丁。

$bundlePath = __DIR__ . '/../public/assets/admin/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}

if (strpos($bundle, 'send_web_push') !== false) {
    fwrite(STDOUT, "Admin queue labels already patched.\n");
    exit(0);
}

// 锚点：队列名称映射表最后一项 traffic_fetch（流量消费队列）+ 闭合 + return，
// traffic_fetch 在整个 bundle 中唯一（32 空格缩进与上下文一致）。
$anchor = <<<'JS'
                                traffic_fetch: "\u6d41\u91cf\u6d88\u8d39\u961f\u5217"
                            };
                            return t[e]
JS;
if (strpos($bundle, $anchor) === false) {
    fwrite(STDERR, "Admin queue labels anchor not found.\n");
    exit(1);
}

// 新条目「浏览器推送队列」使用 \uXXXX 转义，与映射表既有条目风格一致
$replacement = <<<'JS'
                                traffic_fetch: "\u6d41\u91cf\u6d88\u8d39\u961f\u5217",
                                send_web_push: "\u6d4f\u89c8\u5668\u63a8\u9001\u961f\u5217"
                            };
                            return t[e] || e
JS;
$bundle = str_replace($anchor, $replacement, $bundle);

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write admin bundle.\n");
    exit(1);
}
fwrite(STDOUT, "Admin queue labels patched.\n");
