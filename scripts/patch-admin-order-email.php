<?php

// 订单管理页显示补全（面板扩展了 admin order/fetch 的返回字段，原版前端没跟上）：
// 1) 新增「用户邮箱」列：面板每行多返回 user_email（见 V1/Admin/OrderController@fetch），
//    插在「订单号」列之后；缺失显示 "-"，nowrap 防止折行。
// 2) 「周期」列与订单详情弹窗补 deposit（充值订单）兜底：面板充值订单 period 为
//    deposit（见 app/Services/OrderService.php setOrderType），原版 periodText 映射
//    没有该键，充值订单的周期显示为空白。注意不能直接往共享的 periodText 映射表加键——
//    套餐编辑等页面用 Object.keys(periodText) 枚举价格字段，加键会多出一个价格输入框，
//    因此只在订单页两处渲染点就地兜底。
// 幂等：三处替换各自独立判断，重复执行不会重复插入。

$bundlePath = __DIR__ . '/../public/assets/admin/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}

// —— 1) 用户邮箱列 ——
// 锚点：订单号列 render 的收尾。e.substr(0, 3), "...", e.substr(-3) 在整个 bundle 中唯一，
// 带上后续两行闭合与下一列的开头，新列恰好插在「订单号」与「类型」两列之间。
// 「用户邮箱」使用 \uXXXX 转义，与 bundle 内既有列标题风格一致。
$columnAnchor = <<<'JS'
                        }, e.substr(0, 3), "...", e.substr(-3)))
                    }
                }, {
JS;
if (strpos($bundle, 'dataIndex: "user_email"') === false) {
    if (strpos($bundle, $columnAnchor) === false) {
        fwrite(STDERR, "Admin order email column anchor not found.\n");
        exit(1);
    }
    $columnItem = <<<'JS'
                        }, e.substr(0, 3), "...", e.substr(-3)))
                    }
                }, {
                    title: "\u7528\u6237\u90ae\u7bb1",
                    dataIndex: "user_email",
                    key: "user_email",
                    render: e=>{
                        return g.a.createElement("span", {
                            style: {
                                whiteSpace: "nowrap"
                            }
                        }, e || "-")
                    }
                }, {
JS;
    $bundle = str_replace($columnAnchor, $columnItem, $bundle);
}

// —— 2) 列表「周期」列 deposit 兜底 ——
// 锚点：周期列 render 整行，在 bundle 中唯一。
$periodListAnchor = '                        return g.a.createElement(p["a"], null, y["a"].periodText[t.period])';
if (strpos($bundle, 'periodText[t.period] ||') === false) {
    if (strpos($bundle, $periodListAnchor) === false) {
        fwrite(STDERR, "Admin order period column anchor not found.\n");
        exit(1);
    }
    $periodListItem = '                        return g.a.createElement(p["a"], null, y["a"].periodText[t.period] || ("deposit" === t.period ? "\u5145\u503c" : t.period))';
    $bundle = str_replace($periodListAnchor, $periodListItem, $bundle);
}

// —— 3) 订单详情弹窗「订单周期」deposit 兜底 ——
// 锚点：详情弹窗周期渲染行，在 bundle 中唯一。
$periodDetailAnchor = '                }, y["a"].periodText[this.state.order.period])), g.a.createElement(E["a"], {';
if (strpos($bundle, 'periodText[this.state.order.period] ||') === false) {
    if (strpos($bundle, $periodDetailAnchor) === false) {
        fwrite(STDERR, "Admin order detail period anchor not found.\n");
        exit(1);
    }
    $periodDetailItem = '                }, y["a"].periodText[this.state.order.period] || ("deposit" === this.state.order.period ? "\u5145\u503c" : this.state.order.period))), g.a.createElement(E["a"], {';
    $bundle = str_replace($periodDetailAnchor, $periodDetailItem, $bundle);
}

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write admin bundle.\n");
    exit(1);
}
fwrite(STDOUT, "Admin order email/period patched.\n");
