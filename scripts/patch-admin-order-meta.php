<?php

// 订单管理页显示补全（二）：下单元数据（下单IP / 地区 / 客户端）。
// 面板侧数据契约：
// - 列表 order/fetch 每行是完整 Order 模型，含 created_ip（可能 null）、
//   created_user_agent（下单 User-Agent，最长 512，可能 null）
// - 详情 order/detail 额外返回 created_ip_location（IP 归属地，实时计算，可能为空）
// 改动：
// 1) 订单列表在「创建时间」列前插入「下单IP」列（nowrap，空显示 "-"；
//    地区与 UA 不进列表，避免表格过宽）
// 2) 订单详情弹窗在「更新时间」行后追加「下单IP」「地区」「客户端」三行
//    （空显示 "-"；客户端 wordBreak:break-all 换行，不撑破弹窗）
// 幂等：两处替换各自独立判断，重复执行不会重复插入。
// 锚点均取自原版 bundle 文本，与 patch-admin-order-email.php 互不依赖、顺序无关。

$bundlePath = __DIR__ . '/../public/assets/admin/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}

// —— 1) 列表「下单IP」列 ——
// 锚点：订单列表「创建时间」列整块（含收尾 }];，即 columns 数组末尾），在 bundle 中唯一。
$listAnchor = <<<'JS'
                }, {
                    title: "\u521b\u5efa\u65f6\u95f4",
                    dataIndex: "created_at",
                    key: "created_at",
                    align: "right",
                    render: e=>{
                        return w()(1e3 * e).format("YYYY/MM/DD HH:mm")
                    }
                }];
JS;
if (strpos($bundle, 'dataIndex: "created_ip"') === false) {
    if (strpos($bundle, $listAnchor) === false) {
        fwrite(STDERR, "Admin order created_at column anchor not found.\n");
        exit(1);
    }
    $listItem = <<<'JS'
                }, {
                    title: "\u4e0b\u5355IP",
                    dataIndex: "created_ip",
                    key: "created_ip",
                    render: e=>{
                        return g.a.createElement("span", {
                            style: {
                                whiteSpace: "nowrap"
                            }
                        }, e || "-")
                    }
                }, {
                    title: "\u521b\u5efa\u65f6\u95f4",
                    dataIndex: "created_at",
                    key: "created_at",
                    align: "right",
                    render: e=>{
                        return w()(1e3 * e).format("YYYY/MM/DD HH:mm")
                    }
                }];
JS;
    $bundle = str_replace($listAnchor, $listItem, $bundle);
}

// —— 2) 详情弹窗「下单IP / 地区 / 客户端」三行 ——
// 锚点：详情弹窗「更新时间」行收尾 + 邀请人条件渲染开头，在 bundle 中唯一。
$detailAnchor = '}, w()(1e3 * this.state.order.updated_at).format("YYYY-MM-DD HH:mm:ss"))), this.state.order.invite_user_id';
if (strpos($bundle, 'created_ip_location') === false) {
    if (strpos($bundle, $detailAnchor) === false) {
        fwrite(STDERR, "Admin order detail updated_at anchor not found.\n");
        exit(1);
    }
    $detailItem = <<<'JS'
}, w()(1e3 * this.state.order.updated_at).format("YYYY-MM-DD HH:mm:ss"))), g.a.createElement(E["a"], {
                    gutter: [16, 16],
                    style: n
                }, g.a.createElement(S["a"], {
                    span: 6
                }, "\u4e0b\u5355IP"), g.a.createElement(S["a"], {
                    span: 18
                }, this.state.order.created_ip || "-")), g.a.createElement(E["a"], {
                    gutter: [16, 16],
                    style: n
                }, g.a.createElement(S["a"], {
                    span: 6
                }, "\u5730\u533a"), g.a.createElement(S["a"], {
                    span: 18
                }, this.state.order.created_ip_location || "-")), g.a.createElement(E["a"], {
                    gutter: [16, 16],
                    style: n
                }, g.a.createElement(S["a"], {
                    span: 6
                }, "\u5ba2\u6237\u7aef"), g.a.createElement(S["a"], {
                    span: 18,
                    style: {
                        wordBreak: "break-all"
                    }
                }, this.state.order.created_user_agent || "-")), this.state.order.invite_user_id
JS;
    $bundle = str_replace($detailAnchor, $detailItem, $bundle);
}

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write admin bundle.\n");
    exit(1);
}
fwrite(STDOUT, "Admin order meta (ip/location/user-agent) patched.\n");
