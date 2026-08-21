v2bRiskTrace: function(e, t, n) {
        "use strict";
        n.r(t);
        // 手工补丁：订阅溯源页（自 kexue 风控套件移植；本站为单订阅制，token 一律挂在
        // 用户身上，原「所属订阅」展示已移除）。列出留下过订阅拉取记录的用户，并支持按 token 反查归属
        // （含已被重置的 token）。历史 token 在加库之前完全不可恢复，所以本页的历史只能
        // 从部署那一刻起累积 —— 全部相关文案都由后端组装，前端不留第二份判断。
        // 唯一需要 dispatch 的地方是「在用户管理中打开」，走 d1ca 已有的 user/addFilter
        // 播种模式，所以只 connect 取 dispatch，不注册任何 model。
        var r = n("jehZ")
          , i = n.n(r)
          , o = (n("g9YV"),
        n("wCAj"))
          , a = (n("+L6B"),
        n("2/Rp"))
          , s = (n("5NDa"),
        n("5rEg"))
          , l = (n("Pwec"),
        n("CtXQ"))
          , c = (n("2qtc"),
        n("kLXV"))
          , f = (n("/zsF"),
        n("PArb"))
          , y = (n("+BJd"),
        n("mr32"))
          , d = n("q1tI")
          , p = n.n(d)
          , m = n("Bl7J")
          , g = n("v32e")
          , w = n("wd/R")
          , k = n("/MKj");
        function traceUrl(path) {
            return "/" + window.settings.secure_path + path
        }
        function traceGet(path, params) {
            return Object(n("t3Un")["a"])(traceUrl(path), params)
        }
        // lookup 与 reveal 一律走 POST：GET 会把 token 拼进 query string，落进 nginx
        // 访问日志、浏览器历史与后续导航的 Referer。不要改回 GET。
        function tracePost(path, params) {
            return Object(n("t3Un")["b"])(traceUrl(path), params)
        }
        // MIN/MAX 聚合的别名不走模型 cast，回来是原始值，先 Number 再格式化。
        function traceTime(value) {
            var num = Number(value);
            return num > 0 ? w(1e3 * num).format("YYYY-MM-DD HH:mm:ss") : "-"
        }
        function traceReasonText(reasons, code) {
            return code ? (reasons && reasons[code] ? reasons[code] : code) : "-"
        }
        class RiskTracePage extends p.a.Component {
            constructor(props) {
                super(props),
                this.state = {
                    rows: [],
                    total: 0,
                    pagination: {
                        pageSize: 20,
                        current: 1
                    },
                    sort: {
                        sort: "last_requested_at",
                        sort_type: "DESC"
                    },
                    keyword: "",
                    fetchLoading: !0,
                    meta: {
                        available: {},
                        retention_days: 0,
                        token_history_started_at: null,
                        reasons: {},
                        subscribe_method: 0
                    },
                    keywordTruncated: !1,
                    lookupValue: "",
                    lookupLoading: !1,
                    lookupResult: null,
                    expandedRowKeys: [],
                    history: {},
                    historyLoading: {}
                },
                this.inputDelayTimer = null
            }
            componentDidMount() {
                this.fetch()
            }
            componentWillUnmount() {
                // 原版 d1ca 的防抖没有清理定时器，会在卸载后 setState。
                this.inputDelayTimer && clearTimeout(this.inputDelayTimer),
                this.inputDelayTimer = null,
                this.unmounted = !0
            }
            fetch() {
                var state = this.state;
                this.setState({
                    fetchLoading: !0
                }),
                traceGet("/risk/trace/fetch", i()({
                    keyword: state.keyword
                }, state.pagination, state.sort)).then(res=>{
                    // 非 200 已由请求助手弹出带服务端消息的提示，这里只收 loading。
                    if (this.unmounted)
                        return;
                    if (200 !== res.code)
                        return void this.setState({
                            fetchLoading: !1
                        });
                    this.setState({
                        rows: res.data || [],
                        total: res.total || 0,
                        keywordTruncated: !0 === res.keyword_truncated,
                        meta: {
                            available: res.available || {},
                            retention_days: Number(res.retention_days || 0),
                            token_history_started_at: res.token_history_started_at || null,
                            reasons: res.reasons || {},
                            subscribe_method: Number(res.subscribe_method || 0)
                        },
                        pagination: i()({}, this.state.pagination, {
                            total: res.total || 0
                        }),
                        fetchLoading: !1
                    })
                }
                ).catch(()=>this.setState({
                    fetchLoading: !1
                }))
            }
            tableOnChange(pagination, sorter) {
                var nextSort = this.state.sort;
                sorter && sorter.columnKey && (nextSort = {
                    sort: sorter.columnKey,
                    sort_type: "ascend" === sorter.order ? "ASC" : "DESC"
                }),
                this.setState({
                    pagination: i()({}, this.state.pagination, pagination),
                    sort: nextSort
                }, ()=>this.fetch())
            }
            searchOnChange(value) {
                this.inputDelayTimer && clearTimeout(this.inputDelayTimer),
                this.inputDelayTimer = setTimeout(()=>{
                    this.inputDelayTimer = null,
                    this.setState({
                        keyword: value,
                        pagination: i()({}, this.state.pagination, {
                            current: 1
                        })
                    }, ()=>this.fetch())
                }, 400)
            }
            // 显式提交，不做逐键自动查：每次反查都会写一条审计日志，逐键触发等于刷日志
            // 并把半截 token 发给服务端。
            doLookup() {
                var value = (this.state.lookupValue || "").trim();
                if (!value)
                    return;
                this.setState({
                    lookupLoading: !0
                }),
                tracePost("/risk/trace/token/lookup", {
                    token: value
                }).then(res=>{
                    if (this.unmounted)
                        return;
                    if (200 !== res.code)
                        return void this.setState({
                            lookupLoading: !1
                        });
                    this.setState({
                        lookupResult: res.data || null,
                        lookupLoading: !1
                    })
                }
                ).catch(()=>this.setState({
                    lookupLoading: !1
                }))
            }
            // 跨页跳转复用 d1ca.orderFilter 的 filter 播种模式：user 命名空间在启动时就全局
            // 注册了，任何页面都能 dispatch。这样不必提取或复制那个审计弹窗。
            openInUserManage(email) {
                email && (this.props.dispatch({
                    type: "user/addFilter",
                    key: "email",
                    condition: "=",
                    value: email,
                    clear: !0
                }),
                n("3a4m").a.push("/user"))
            }
            onExpand(expanded, record) {
                var keys = expanded ? this.state.expandedRowKeys.concat([record.user_id]) : this.state.expandedRowKeys.filter(item=>item !== record.user_id);
                this.setState({
                    expandedRowKeys: keys
                }),
                // 一行一次请求且缓存：重复展开不再打服务端。列表本身只有一次请求，与
                // 「列表不带风险状态列」同一个理由。
                expanded && !this.state.history[record.user_id] && this.loadHistory(record.user_id)
            }
            loadHistory(userId) {
                this.setState({
                    historyLoading: i()({}, this.state.historyLoading, {
                        [userId]: !0
                    })
                }),
                traceGet("/risk/trace/history", {
                    user_id: userId
                }).then(res=>{
                    if (this.unmounted)
                        return;
                    var loading = i()({}, this.state.historyLoading);
                    delete loading[userId];
                    if (200 !== res.code)
                        return void this.setState({
                            historyLoading: loading
                        });
                    this.setState({
                        history: i()({}, this.state.history, {
                            [userId]: res.data || {}
                        }),
                        historyLoading: loading
                    })
                }
                ).catch(()=>{
                    var loading = i()({}, this.state.historyLoading);
                    delete loading[userId],
                    this.setState({
                        historyLoading: loading
                    })
                })
            }
            // 默认只显示掩码，原值要显式点一次才解密，避免整页凭证被截图；后端为每次
            // reveal 单独记审计日志。
            revealToken(id) {
                tracePost("/risk/trace/token/reveal", {
                    id: id
                }).then(res=>{
                    if (200 !== res.code || !res.data || !res.data.token)
                        return;
                    c["a"].info({
                        title: "历史 Token 原值",
                        width: 520,
                        content: p.a.createElement("div", null, p.a.createElement("p", {
                            className: "text-muted",
                            style: {
                                marginBottom: 8
                            }
                        }, "请勿转发或截图外传。"), p.a.createElement("code", {
                            style: {
                                wordBreak: "break-all",
                                userSelect: "all"
                            }
                        }, res.data.token))
                    })
                }
                ).catch(()=>{})
            }
            renderLookup() {
                var state = this.state
                  , result = state.lookupResult;
                return p.a.createElement("div", {
                    className: "block block-rounded"
                }, p.a.createElement("div", {
                    className: "bg-white",
                    style: {
                        padding: 15
                    }
                }, p.a.createElement("div", {
                    className: "d-flex",
                    style: {
                        marginBottom: 10
                    }
                }, p.a.createElement(s["a"], {
                    placeholder: "粘贴订阅链接或 token",
                    value: state.lookupValue,
                    onChange: event=>this.setState({
                        lookupValue: event.target.value
                    }),
                    onPressEnter: ()=>this.doLookup(),
                    style: {
                        marginRight: 8
                    }
                }), p.a.createElement(a["a"], {
                    type: "primary",
                    loading: state.lookupLoading,
                    onClick: ()=>this.doLookup()
                }, p.a.createElement(l["a"], {
                    type: "search"
                }), " 反查归属")), result ? result.found ? this.renderHit(result) : this.renderMiss(result) : p.a.createElement("p", {
                    className: "text-muted mb-0"
                }, "输入一个 token 或整条订阅链接，查出它属于哪个用户 —— 包括已经被重置掉的 token。")))
            }
            renderHit(result) {
                var reasons = this.state.meta.reasons
                  , rows = [["用户 UID", String(result.user.id || "-")], ["邮箱", result.user.deleted ? "已删除用户（UID 仍保留在审计记录中）" : result.user.email || "-"], ["Token", (result.token_masked || "-") + (result.token_certain ? "" : "（无法确定具体是哪一个 token）")], ["状态", "retired" === result.token_status ? "已停用" : "active" === result.token_status ? "使用中" : "未知"], ["签发时间", result.issued_at ? traceTime(result.issued_at) + (result.issued_at_exact ? "" : "（推断，不早于此时间）") : "-"], ["停用时间", result.retired_at ? traceTime(result.retired_at) : "-"], ["停用原因", traceReasonText(reasons, result.retired_reason)]];
                return p.a.createElement("div", null, p.a.createElement("table", {
                    className: "table table-sm table-borderless mb-2"
                }, p.a.createElement("tbody", null, rows.map((row, index)=>p.a.createElement("tr", {
                    key: index
                }, p.a.createElement("td", {
                    style: {
                        width: 120
                    },
                    className: "text-muted"
                }, row[0]), p.a.createElement("td", null, row[1]))))), result.token_certain ? null : p.a.createElement("div", {
                    className: "alert alert-warning",
                    style: {
                        padding: "8px 12px"
                    }
                }, p.a.createElement("p", {
                    className: "mb-0"
                }, "该 token 是动态签名，只能确定所属用户，无法确定具体是哪一个 token。")), p.a.createElement("div", null, p.a.createElement(a["a"], {
                    size: "small",
                    onClick: ()=>this.openInUserManage(result.user.email)
                }, "在用户管理中打开"), result.history_id ? p.a.createElement(a["a"], {
                    size: "small",
                    type: "dashed",
                    style: {
                        marginLeft: 8
                    },
                    onClick: ()=>this.revealToken(result.history_id)
                }, "显示完整 token") : null, result.has_audit_records ? p.a.createElement(a["a"], {
                    size: "small",
                    style: {
                        marginLeft: 8
                    },
                    onClick: ()=>this.jumpToUser(result.user.id)
                }, "查看该用户 Token 历史") : p.a.createElement("span", {
                    className: "text-muted",
                    style: {
                        marginLeft: 8
                    }
                }, "该用户在保留期内没有订阅拉取记录")))
            }
            // 把该 UID 灌进搜索并展开对应行，这样即使他不在当前页也能直接下钻。
            jumpToUser(userId) {
                this.setState({
                    keyword: String(userId),
                    lookupValue: this.state.lookupValue,
                    pagination: i()({}, this.state.pagination, {
                        current: 1
                    }),
                    expandedRowKeys: [userId]
                }, ()=>{
                    this.fetch(),
                    this.state.history[userId] || this.loadHistory(userId)
                })
            }
            renderMiss(result) {
                return p.a.createElement("div", {
                    className: "alert alert-warning mb-0"
                }, (result.notes || []).map((note, index)=>p.a.createElement("p", {
                    key: index,
                    className: index === (result.notes || []).length - 1 ? "mb-0" : "mb-1"
                }, note)))
            }
            renderExpanded(record) {
                var payload = this.state.history[record.user_id]
                  , reasons = this.state.meta.reasons;
                if (this.state.historyLoading[record.user_id])
                    return p.a.createElement("p", {
                        className: "text-muted mb-0"
                    }, "正在加载…");
                if (!payload)
                    return p.a.createElement("p", {
                        className: "text-muted mb-0"
                    }, "-");
                if (payload.available && !1 === payload.available.token_history)
                    return p.a.createElement("p", {
                        className: "text-muted mb-0"
                    }, "Token 历史表尚未创建（尚未记录过 token 签发）。");
                var audit = payload.audit || {}
                  , tokenColumns = [{
                    title: "Token",
                    dataIndex: "token_masked",
                    render: (value, row)=>p.a.createElement("span", null, value || "-", p.a.createElement("a", {
                        href: "javascript:void(0);",
                        style: {
                            marginLeft: 8
                        },
                        onClick: ()=>this.revealToken(row.id)
                    }, "显示"))
                }, {
                    title: "签发",
                    dataIndex: "issued_at",
                    render: (value, row)=>p.a.createElement("span", null, traceTime(value), row.issued_at_exact ? null : p.a.createElement(y["a"], {
                        color: "orange",
                        style: {
                            marginLeft: 6
                        }
                    }, "推断"))
                }, {
                    title: "停用",
                    dataIndex: "retired_at",
                    render: value=>traceTime(value)
                }, {
                    title: "原因",
                    dataIndex: "retired_reason",
                    render: value=>traceReasonText(reasons, value)
                }, {
                    title: "状态",
                    dataIndex: "active",
                    render: value=>p.a.createElement(y["a"], {
                        color: value ? "green" : void 0
                    }, value ? "使用中" : "已停用")
                }];
                return p.a.createElement("div", null, p.a.createElement("p", {
                    className: "text-muted",
                    style: {
                        marginBottom: 8
                    }
                }, "拉取 " + (audit.request_count || 0) + " 次 · " + (audit.user_agent_count || 0) + " 种 UA · " + traceTime(audit.first_requested_at) + " ~ " + traceTime(audit.last_requested_at)), p.a.createElement(o["a"], {
                    size: "small",
                    rowKey: row=>row.id,
                    dataSource: payload.tokens || [],
                    columns: tokenColumns,
                    pagination: !1,
                    locale: {
                        emptyText: "该用户没有 Token 历史记录"
                    }
                }))
            }
            renderNotice() {
                var meta = this.state.meta
                  , notes = [];
                if (!1 === meta.available.subscribe_request_log)
                    notes.push("订阅拉取审计表尚未创建（尚无订阅拉取记录），本页暂时没有数据。");
                else if (meta.retention_days > 0)
                    notes.push("本页只列出保留期内仍有订阅拉取记录的用户。拉取记录默认保留 " + meta.retention_days + " 天并每日清理，管理员也可以随时清空某个用户的记录，因此「没有记录」不等于「没有拉取过」，「首次拉取」也不是该用户真正的第一次拉取。");
                else
                    notes.push("本页只列出有订阅拉取记录的用户。拉取记录当前不自动清理（保留期设为 0），但管理员可以随时清空某个用户的记录，因此「没有记录」不等于「没有拉取过」。");
                if (!1 === meta.available.token_history)
                    notes.push("Token 历史表尚未创建（尚未记录过 token 签发），当前只能反查仍在使用的 token，已被重置的 token 查不到。");
                else
                    notes.push("Token 历史自 " + (meta.token_history_started_at ? w(1e3 * Number(meta.token_history_started_at)).format("YYYY-MM-DD") : "安装时") + " 起记录。此前发生的重置没有留下任何痕迹，无法回填；标注「推断」的签发时间不是真实签发时间。「停用」指该 token 不再作为凭证列中的值，不等于它立刻失效。");
                return p.a.createElement("div", {
                    className: "alert alert-info",
                    style: {
                        marginBottom: 15
                    }
                }, notes.map((note, index)=>p.a.createElement("p", {
                    key: index,
                    className: index === notes.length - 1 ? "mb-0" : "mb-1"
                }, note)))
            }
            render() {
                var state = this.state
                  , columns = [{
                    title: "UID",
                    dataIndex: "user_id",
                    key: "user_id",
                    sorter: !0,
                    width: 100
                }, {
                    title: "邮箱",
                    dataIndex: "email",
                    // 邮箱不在聚合里，ONLY_FULL_GROUP_BY 下服务端排不了 —— 点了没反应的
                    // 排序箭头比没有更糟，所以刻意不给 sorter。
                    render: (value, row)=>row.deleted ? p.a.createElement("span", {
                        className: "text-muted"
                    }, "已删除用户") : p.a.createElement("span", null, value || "-", row.banned ? p.a.createElement(y["a"], {
                        color: "red",
                        style: {
                            marginLeft: 6
                        }
                    }, "已封禁") : null)
                }, {
                    title: "首次拉取",
                    dataIndex: "first_requested_at",
                    key: "first_requested_at",
                    sorter: !0,
                    render: value=>traceTime(value)
                }, {
                    title: "最近拉取",
                    dataIndex: "last_requested_at",
                    key: "last_requested_at",
                    sorter: !0,
                    defaultSortOrder: "descend",
                    render: value=>traceTime(value)
                }];
                // 必须展开路由 props，否则侧边栏会在 location.pathname 上崩。
                return p.a.createElement(m["a"], i()({}, this.props, {
                    title: "订阅溯源"
                }), p.a.createElement(g["a"], {
                    loading: state.fetchLoading
                }, this.renderLookup(), p.a.createElement(f["a"], null), this.renderNotice(), p.a.createElement("div", {
                    className: "block block-rounded"
                }, p.a.createElement("div", {
                    className: "bg-white"
                }, p.a.createElement("div", {
                    style: {
                        padding: 15
                    }
                }, p.a.createElement(s["a"], {
                    placeholder: "搜索 UID 或邮箱",
                    allowClear: !0,
                    defaultValue: state.keyword,
                    onChange: event=>this.searchOnChange(event.target.value),
                    style: {
                        maxWidth: 320
                    }
                }), state.keywordTruncated ? p.a.createElement("span", {
                    className: "text-muted",
                    style: {
                        marginLeft: 10
                    }
                }, "匹配到的邮箱过多，仅显示前 200 个用户的记录。") : null), p.a.createElement("div", {
                    style: {
                        padding: "0 15px 15px"
                    }
                }, p.a.createElement(o["a"], {
                    tableLayout: "auto",
                    rowKey: row=>row.user_id,
                    dataSource: state.rows,
                    columns: columns,
                    expandedRowKeys: state.expandedRowKeys,
                    onExpand: (expanded, record)=>this.onExpand(expanded, record),
                    expandedRowRender: record=>this.renderExpanded(record),
                    pagination: i()({}, state.pagination, {
                        size: "small",
                        showSizeChanger: !0,
                        // 后端 pageSize 上限 100（clamp），选项不得超过它：给 150 会让
                        // 页数按 150 虚算、第 100 名以后的行任何页都看不到。与共享 IP 页一致。
                        pageSizeOptions: ["10", "50", "100"]
                    }),
                    onChange: (pagination, filters, sorter)=>this.tableOnChange(pagination, sorter),
                    locale: {
                        emptyText: "暂无订阅拉取记录"
                    },
                    scroll: {
                        x: 800
                    }
                }))))))
            }
        }
        t["default"] = Object(k["c"])()(RiskTracePage)
    }
