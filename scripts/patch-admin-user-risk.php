<?php

// 注入用户管理页（stock 模块 d1ca）的风控 UI 四处 hunk（自 kexue 风控套件移植，
// 已适配本站单订阅制与后端实际契约）：
//   A. 订阅审计方法组：clearSubscribeAudit / recomputeUserRisk / subscribeRequests /
//      loadSubscribeAuditPage（弹窗调 GET /user/subscribe-requests 服务端分页 +
//      GET /user/risk 周期账本 + POST /user/subscribe-audit/clear）
//   B. 用户表「风险」徽标列（读 user/fetch 每行下发的 risk 摘要）
//   C. 行操作下拉「订阅审计」入口
//   D. 筛选抽屉「风险」筛选项（后端 UserController::applyRiskFilter，条件仅 =）
// 幂等：B/C/D 靠特征串守卫跳过；A 以 [clearSubscribeAudit 起点, delUser 前) 区间
// 原位替换，支持更新方法内容。锚点未命中一律 stderr + exit 1。
// 区间安全：本补丁所有锚点都在 stock 模块 d1ca 内部，不落入任何既有补丁
// （admob / login-settings / oauth-manage / order-email / order-meta / 风控三页）
// 的原位替换区间；反之那些补丁的区间也不覆盖 d1ca。

$bundlePath = __DIR__ . '/../public/assets/admin/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}

// ---------- A. 订阅审计方法组 ----------
// 起点锚点：方法头（12 空格缩进）在 bundle 中唯一；终点锚点：紧随其后的 stock 方法
// delUser（bundle 中唯一）。源文件必须以起点锚点开头（无 BOM、无前置注释），否则
// 原位替换区间会漏掉开头部分造成残留。
$methodsPath = __DIR__ . '/admin-user-risk-methods.js';
$methodsMarker = '            clearSubscribeAudit(user, auditModal) {';
$methodsSource = file_get_contents($methodsPath);
if ($methodsSource === false || strpos($methodsSource, $methodsMarker) !== 0) {
    fwrite(STDERR, "Admin user risk methods source is invalid.\n");
    exit(1);
}
// 只修剪尾部空白：首行以 12 空格缩进开头，trim() 会把它剥掉。
$methodsSource = rtrim($methodsSource);

$methodsEndAnchor = "\n            delUser(e) {";
$start = strpos($bundle, $methodsMarker);
$end = strpos($bundle, $methodsEndAnchor, $start === false ? 0 : $start);
if ($end === false) {
    fwrite(STDERR, "Admin user risk methods boundary (delUser) not found.\n");
    exit(1);
}
if ($start !== false) {
    if ($start >= $end) {
        fwrite(STDERR, "Admin user risk methods layout unexpected.\n");
        exit(1);
    }
    // 已注入：以方法组起点至 delUser 前换行为界原位替换（支持更新方法内容）
    $bundle = substr_replace($bundle, $methodsSource, $start, $end - $start);
} else {
    // 未注入：插到 delUser 之前（其前导换行之前）
    $bundle = substr_replace($bundle, "\n" . $methodsSource, $end, 0);
}

// ---------- B. 用户表「风险」列 ----------
// 锚点：状态（banned）列 render 尾行 + 紧随其后的「订阅」列头。「订阅」列头单独出现
// 2 次，加上 banned render 尾行（全 bundle 唯一）后组合唯一。风险列插在两者之间。
$columnGuard = 'dataIndex: "risk"';
$columnAnchor = <<<'JS'
                        }, e ? "\u5c01\u7981" : "\u6b63\u5e38")
                    }
                }, {
                    title: "\u8ba2\u9605",
JS;
$columnReplacement = <<<'JS'
                        }, e ? "\u5c01\u7981" : "\u6b63\u5e38")
                    }
                }, {
                    title: "风险",
                    dataIndex: "risk",
                    key: "risk",
                    render: e=>{
                        // 手工补丁（风控套件）：读 user/fetch 每行下发的 risk 摘要
                        // （summaryForUser：status 三态 + reasons），悬浮显示命中理由。
                        var t = e && e.status
                          , n = "suspicious" === t ? "red" : "normal" === t ? "green" : "orange"
                          , r = "suspicious" === t ? "疑似内鬼" : "normal" === t ? "正常" : "待观察";
                        return g.a.createElement(h["a"], {
                            color: n,
                            title: e && e.reasons && e.reasons.length ? e.reasons.join("；") : ""
                        }, r)
                    }
                }, {
                    title: "\u8ba2\u9605",
JS;
if (strpos($bundle, $columnGuard) === false) {
    if (strpos($bundle, $columnAnchor) === false) {
        fwrite(STDERR, "Admin user risk column anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($columnAnchor, $columnReplacement, $bundle);
}

// ---------- C. 行操作下拉「订阅审计」入口 ----------
// 锚点：行操作下拉里「编辑」项的收尾 + 下一个 Menu.Item 的开头（单行，bundle 中唯一；
// 右键菜单的「编辑」是 li 结构，不会误中）。新项插在「编辑」与「分配订单」之间。
$dropdownGuard = 'this.subscribeRequests(t)';
$dropdownAnchor = '}), " \u7f16\u8f91"))), g.a.createElement(c["a"].Item, {';
$dropdownItem = <<<'JS'
                                onClick: ()=>this.subscribeRequests(t),
                                onContextMenu: e=>{
                                    e.stopPropagation()
                                }
                            }, g.a.createElement("a", null, g.a.createElement(u["a"], {
                                type: "history"
                            }), " 订阅审计")), g.a.createElement(c["a"].Item, {
JS;
if (strpos($bundle, $dropdownGuard) === false) {
    if (strpos($bundle, $dropdownAnchor) === false) {
        fwrite(STDERR, "Admin user risk dropdown anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($dropdownAnchor, $dropdownAnchor . "\n" . $dropdownItem, $bundle);
}

// ---------- D. 筛选抽屉「风险」筛选项 ----------
// 锚点：账号状态（banned）筛选的「封禁」选项收尾 + 紧随其后的 invite_by_email 项
// （组合在 bundle 中唯一）。风险筛选插在两者之间；选项值与 summaryForUser 的三态
// 一致，条件只有 =（后端 applyRiskFilter 对其他条件 422）。
// 守卫必须带 24 空格缩进：裸 'key: "risk"' 会被 hunk B 风险列的 20 空格
// key: "risk" 误命中（B 先执行），导致本 hunk 永远被跳过。
$filterGuard = '                        key: "risk",';
$filterAnchor = <<<'JS'
                            key: "\u5c01\u7981",
                            value: 1
                        }]
                    }, {
                        key: "invite_by_email",
JS;
$filterReplacement = <<<'JS'
                            key: "\u5c01\u7981",
                            value: 1
                        }]
                    }, {
                        // 手工补丁（风控套件）：风险徽标过滤。选项值与 summaryForUser 的
                        // 三态一致，后端在 UserController::applyRiskFilter 里翻成同语义查询。
                        key: "risk",
                        title: "风险",
                        condition: ["="],
                        type: "select",
                        options: [{
                            key: "疑似内鬼",
                            value: "suspicious"
                        }, {
                            key: "待观察",
                            value: "pending"
                        }, {
                            key: "正常",
                            value: "normal"
                        }]
                    }, {
                        key: "invite_by_email",
JS;
if (strpos($bundle, $filterGuard) === false) {
    if (strpos($bundle, $filterAnchor) === false) {
        fwrite(STDERR, "Admin user risk filter anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($filterAnchor, $filterReplacement, $bundle);
}

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write admin bundle.\n");
    exit(1);
}
fwrite(STDOUT, "Admin user risk hunks patched.\n");
