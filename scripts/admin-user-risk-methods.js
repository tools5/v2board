            clearSubscribeAudit(user, auditModal) {
                // 手工补丁（风控套件）：清空该用户的全部审计证据与风险判定。
                // 后端 purgeUser 连 IP 关联累积行和手动评估判定一起删：留着引用了具体
                // IP 的判定、下面证据却已清空，等于一条无法核实的指控。
                var t = this;
                p["a"].confirm({
                    title: "清空订阅审计记录",
                    content: "确定要清空 " + user.email + " 的订阅拉取记录、节点连接记录和 IP 关联记录吗？删除后无法恢复，该用户的风险判定结果会一并重置。",
                    okText: "确定清空",
                    okType: "danger",
                    cancelText: "取消",
                    onOk() {
                        // 返回 Promise，让确定按钮保持 loading 直到请求结束。
                        return Object(n("t3Un")["b"])("/" + window.settings.secure_path + "/user/subscribe-audit/clear", {
                            user_id: user.id
                        }).then(function(res) {
                            if (200 !== res.code)
                                return;
                            var counts = res.data || {}
                              , riskCleared = (counts.subscription_risk_cycle || 0) + (counts.subscription_risk_manual || 0) + (counts.subscription_risk_manual_stage || 0);
                            // 用户列表有「风险」列，不刷新会留着已被重置的旧徽章。
                            t.props.dispatch({
                                type: "user/fetch"
                            }),
                            auditModal && auditModal.destroy(),
                            p["a"].success({
                                title: "已清空",
                                content: "订阅拉取 " + (counts.subscribe_request_log || 0) + " 条，节点连接 " + (counts.node_connection_log || 0) + " 条，IP 关联 " + (counts.ip_account_link || 0) + " 条，风险判定 " + riskCleared + " 条。"
                            }),
                            t.subscribeRequests(user)
                        }).catch(function() {
                            p["a"].error({
                                title: "请求失败",
                                content: "清空审计记录失败，请稍后重试"
                            })
                        })
                    }
                })
            }
            recomputeUserRisk(user, auditModal) {
                // 手工补丁（风控套件）：单用户重算。调完阈值先拿一个用户验证，比全站刷一遍
                // 安全得多（爆炸半径小、一秒完成）。后端对单用户是同步跑完的（直接返回
                // done:true），所以这里没有风控规则页那种游标循环，也不能带 restart。
                // 只改写 30 天周期账本；驱动「风险」列的手动评估结果不受影响。
                var t = this
                  , warning = ["重算会用当前规则重新判定该用户所有已完成周期，覆盖此前的判定结果。", "若审计证据已被保留期清理，重算结果可能低于当初的真实值，原本「疑似内鬼」的周期可能被改为「正常」。", "节点连接记录按 last_seen_at 清理，历史周期的连接指标尤其容易失真。", "此操作不可撤销。"];
                p["a"].confirm({
                    title: "重算 " + user.email + " 的历史周期",
                    width: 560,
                    content: g.a.createElement("div", null, warning.map(function(line, index) {
                        return g.a.createElement("p", {
                            key: index,
                            className: index === warning.length - 1 ? "mb-0 font-w600" : "mb-2"
                        }, line)
                    })),
                    okText: "开始重算",
                    okType: "danger",
                    cancelText: "取消",
                    onOk() {
                        // 返回 Promise，让确定按钮保持 loading 直到请求结束。
                        return Object(n("t3Un")["b"])("/" + window.settings.secure_path + "/risk/rule/recompute", {
                            user_id: user.id
                        }).then(function(res) {
                            if (200 !== res.code)
                                return;
                            var counts = res.data || {};
                            t.props.dispatch({
                                type: "user/fetch"
                            }),
                            auditModal && auditModal.destroy(),
                            p["a"].success({
                                title: "重算完成",
                                content: "已重算 " + (counts.cycles || 0) + " 个周期。"
                            }),
                            t.subscribeRequests(user)
                        }).catch(function() {
                            p["a"].error({
                                title: "请求失败",
                                content: "重算失败，请稍后重试"
                            })
                        })
                    }
                })
            }
            subscribeRequests(e) {
                // 手工补丁（风控套件）：订阅审计弹窗入口。先取 30 天周期账本（GET /user/risk
                // 会顺带补算已完成而未评估的周期），再取第一页审计数据；账本在弹窗生命周期
                // 内只取一次，翻页只刷新拉取记录。holder 让翻页回调拿到弹窗实例做原地更新。
                var t = this
                  , holder = {
                    modal: null,
                    cycles: []
                };
                Object(n("t3Un")["a"])("/" + window.settings.secure_path + "/user/risk", {
                    user_id: e.id
                }).then(function(res) {
                    200 === res.code && res.data && (holder.cycles = res.data.cycles || [])
                }).catch(function() {}).then(function() {
                    t.loadSubscribeAuditPage(e, holder, 1, 20)
                })
            }
            loadSubscribeAuditPage(e, holder, page, pageSize) {
                // 拉取记录走服务端分页（后端 pageSize 上限 100，选项不得超过它）；UA 汇总与
                // 节点连接由后端一次性下发（前者最多前 100 种、后者最近 200 条），只做前端
                // 分页。弹窗用 Modal.info + update：翻页原地刷新内容，不重开弹窗。
                var t = this;
                Object(n("t3Un")["a"])("/" + window.settings.secure_path + "/user/subscribe-requests", {
                    user_id: e.id,
                    page: page,
                    pageSize: pageSize
                }).then(function(r) {
                    if (200 !== r.code)
                        return;
                    // 注意：这个回调里模块级的 antd 别名会被下面的局部变量遮蔽（u/h 成了
                    // 格式化函数），所以 Table/Button/Tag 必须另起可读的名字就地取。
                    var i = n("wCAj")["a"]
                      , auditButton = n("2/Rp")["a"]
                      , auditTag = n("mr32")["a"]
                      , o = r.data || []
                      , a = r.connections || []
                      , s = r.risk || {}
                      , l = r.summary || {}
                      , uaSummary = l.user_agents || []
                      , total = r.total || 0
                      , c = "suspicious" === s.status ? "疑似内鬼" : "normal" === s.status ? "正常" : "待观察";
                    // 归属地/运营商/IDC 三列在几张表里含义一致，共用格式化逻辑。
                    var u = function(e) {
                        var t = e || {};
                        return [t.country_name || t.country_code, t.province || t.region, t.city, t.district].filter(Boolean).join(" / ") || "未知"
                    }
                      , h = function(e) {
                        var t = e || {};
                        // is_idc 由后端给出三态：命中 IDC 库为 true，命中普通库为 false，
                        // 完全查不到为 null。查到且非 IDC 才能写「否」，查不到只能写未知。
                        return !0 === t.is_idc ? t.idc_vendor || "是" : !1 === t.is_idc ? "否" : "未知"
                    }
                      , f = function(e) {
                        return e ? w()(1e3 * e).format("YYYY-MM-DD HH:mm:ss") : "-"
                    }
                      , day = function(e) {
                        return e ? w()(1e3 * e).format("YYYY-MM-DD") : "-"
                    }
                      , d = function(e, t) {
                        return g.a.createElement("div", {
                            style: {
                                marginBottom: 8,
                                fontWeight: 600
                            }
                        }, e, g.a.createElement("span", {
                            style: {
                                marginLeft: 8,
                                fontWeight: 400,
                                color: "#8c8c8c"
                            }
                        }, t))
                    }
                      , breakAll = function(e) {
                        return g.a.createElement("span", {
                            style: {
                                wordBreak: "break-all"
                            }
                        }, e || "-")
                    }
                      , count = function(e) {
                        return e || 0
                    }
                      , spacer = function(key) {
                        return g.a.createElement("div", {
                            key: key,
                            style: {
                                height: 20
                            }
                        })
                    }
                      , cycleReasons = function(e) {
                        // risk_reasons 是 TEXT 里的 JSON 数组，坏数据退化为空理由而不是白屏。
                        try {
                            var t = JSON.parse(e || "[]");
                            return Array.isArray(t) ? t : []
                        } catch (err) {
                            return []
                        }
                    };
                    var content = g.a.createElement("div", {
                        style: {
                            maxHeight: "62vh",
                            overflowY: "auto"
                        }
                    },
                        g.a.createElement("div", {
                            style: {
                                marginBottom: 12,
                                textAlign: "right"
                            }
                        }, g.a.createElement(auditButton, {
                            type: "danger",
                            size: "small",
                            icon: "delete",
                            onClick: function() {
                                t.clearSubscribeAudit(e, holder.modal)
                            }
                        }, "清空该用户审计记录"), g.a.createElement(auditButton, {
                            // 重算只改写判定、不删证据，比清空轻一档。用 dashed 和实心
                            // danger 拉开视觉差，两个按钮不会被误点。
                            type: "dashed",
                            size: "small",
                            icon: "reload",
                            style: {
                                marginLeft: 8
                            },
                            onClick: function() {
                                t.recomputeUserRisk(e, holder.modal)
                            }
                        }, "重算该用户历史周期")),
                        d("订阅拉取 IP", "客户端下载订阅配置的来源；请求 " + count(l.request_count) + " 次，UA " + count(l.user_agent_count) + " 种，IP " + count(l.distinct_ip_count) + " 个，地区 " + count(s.region_count) + "，国家 " + count(s.country_count)),
                        g.a.createElement(i, {
                            size: "small",
                            tableLayout: "auto",
                            rowKey: function(e, t) {
                                return e.id || "pull-" + t
                            },
                            dataSource: o,
                            pagination: {
                                current: page,
                                pageSize: pageSize,
                                total: total,
                                size: "small",
                                showSizeChanger: !0,
                                pageSizeOptions: ["10", "20", "50", "100"],
                                onChange: function(current, size) {
                                    t.loadSubscribeAuditPage(e, holder, current, size || pageSize)
                                },
                                onShowSizeChange: function(current, size) {
                                    t.loadSubscribeAuditPage(e, holder, 1, size)
                                }
                            },
                            locale: {
                                emptyText: "暂无订阅拉取记录"
                            },
                            columns: [{
                                title: "User-Agent",
                                dataIndex: "user_agent",
                                render: breakAll
                            }, {
                                title: "拉取 IP",
                                dataIndex: "request_ip"
                            }, {
                                // ip_count 是该 IP 在此用户全部记录里的累计次数，不随分页变化。
                                title: "IP 累计次数",
                                dataIndex: "ip_count",
                                align: "right",
                                render: count
                            }, {
                                title: "归属地",
                                render: function(e, t) {
                                    return u(t.ip_location)
                                }
                            }, {
                                title: "运营商",
                                render: function(e, t) {
                                    return (t.ip_location || {}).isp || "-"
                                }
                            }, {
                                title: "IDC/云厂商",
                                render: function(e, t) {
                                    return h(t.ip_location)
                                }
                            }, {
                                title: "请求时间",
                                dataIndex: "requested_at",
                                render: f
                            }]
                        }),
                        spacer("sp-ua"),
                        d("User-Agent 汇总", "按 UA 聚合的全部拉取记录（最多前 100 种，不随上方分页变化）"),
                        g.a.createElement(i, {
                            size: "small",
                            tableLayout: "auto",
                            rowKey: function(e, t) {
                                return e.ua_hash || "ua-" + t
                            },
                            dataSource: uaSummary,
                            pagination: {
                                pageSize: 10,
                                size: "small"
                            },
                            locale: {
                                emptyText: "暂无 User-Agent 记录"
                            },
                            columns: [{
                                title: "User-Agent",
                                dataIndex: "user_agent",
                                render: breakAll
                            }, {
                                title: "次数",
                                dataIndex: "request_count",
                                align: "right",
                                render: count
                            }, {
                                title: "首次拉取",
                                dataIndex: "first_requested_at",
                                render: f
                            }, {
                                title: "最近拉取",
                                dataIndex: "last_requested_at",
                                render: f
                            }]
                        }),
                        spacer("sp-conn"),
                        d("节点连接 IP", "节点上报的实际使用来源；共 " + count(l.connection_ip_count) + " 个 IP（最多显示最近 200 条）"),
                        g.a.createElement(i, {
                            size: "small",
                            tableLayout: "auto",
                            rowKey: function(e, t) {
                                return e.id || "conn-" + t
                            },
                            dataSource: a,
                            pagination: !1,
                            locale: {
                                emptyText: "暂无节点连接记录（该功能自风控套件部署后开始累积）"
                            },
                            columns: [{
                                title: "节点",
                                dataIndex: "node_name",
                                render: function(e, t) {
                                    return e || t.node_type + " #" + t.node_id
                                }
                            }, {
                                title: "连接 IP",
                                dataIndex: "ip"
                            }, {
                                title: "上报次数",
                                dataIndex: "report_count",
                                align: "right",
                                render: count
                            }, {
                                title: "归属地",
                                render: function(e, t) {
                                    return u(t.ip_location)
                                }
                            }, {
                                title: "运营商",
                                render: function(e, t) {
                                    return (t.ip_location || {}).isp || "-"
                                }
                            }, {
                                title: "IDC/云厂商",
                                render: function(e, t) {
                                    return h(t.ip_location)
                                }
                            }, {
                                title: "首次出现",
                                dataIndex: "first_seen_at",
                                render: f
                            }, {
                                title: "最近出现",
                                dataIndex: "last_seen_at",
                                render: f
                            }]
                        }),
                        spacer("sp-cycle"),
                        d("30 天风险周期账本", "按账号创建时间铺 30 天网格逐周期判定；「风险」列由手动评估驱动，此账本供历史追溯"),
                        g.a.createElement(i, {
                            size: "small",
                            tableLayout: "auto",
                            rowKey: function(e, t) {
                                return e.id || "cycle-" + t
                            },
                            dataSource: holder.cycles,
                            pagination: {
                                pageSize: 10,
                                size: "small"
                            },
                            locale: {
                                emptyText: "暂无已完成的风险周期（首个 30 天周期尚未走完，或审计表未建）"
                            },
                            columns: [{
                                title: "周期",
                                render: function(e, t) {
                                    return day(t.cycle_start) + " ~ " + day(t.cycle_end)
                                }
                            }, {
                                title: "状态",
                                dataIndex: "status",
                                render: function(e, t) {
                                    var reasons = cycleReasons(t.risk_reasons);
                                    return g.a.createElement("span", {
                                        title: reasons.length ? reasons.join("；") : ""
                                    }, g.a.createElement(auditTag, {
                                        color: "suspicious" === e ? "red" : "normal" === e ? "green" : "orange"
                                    }, "suspicious" === e ? "疑似内鬼" : "normal" === e ? "正常" : "待观察"))
                                }
                            }, {
                                title: "UA 种类",
                                dataIndex: "user_agent_count",
                                align: "right",
                                render: count
                            }, {
                                title: "IP 数",
                                dataIndex: "distinct_ip_count",
                                align: "right",
                                render: count
                            }, {
                                title: "城市",
                                dataIndex: "city_count",
                                align: "right",
                                render: count
                            }, {
                                title: "省/州",
                                dataIndex: "region_count",
                                align: "right",
                                render: count
                            }, {
                                title: "国家",
                                dataIndex: "country_count",
                                align: "right",
                                render: count
                            }, {
                                // used_ratio 是 decimal 字符串（如 "0.40000000"），先 Number 再化百分比。
                                title: "流量使用率",
                                dataIndex: "used_ratio",
                                align: "right",
                                render: function(e) {
                                    return null === e || void 0 === e || "" === e ? "-" : Math.round(1e4 * Number(e)) / 100 + "%"
                                }
                            }, {
                                title: "评估时间",
                                dataIndex: "evaluated_at",
                                render: f
                            }]
                        })
                    );
                    var title = "订阅审计 - " + e.email + "（风险：" + c + "）";
                    holder.modal ? holder.modal.update({
                        title: title,
                        content: content
                    }) : holder.modal = p["a"].info({
                        title: title,
                        width: 1180,
                        content: content
                    })
                }).catch(function() {
                    p["a"].error({
                        title: "请求失败",
                        content: "订阅审计数据加载失败，请稍后重试"
                    })
                })
            }
