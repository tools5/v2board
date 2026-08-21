v2bRiskSharedIp: function(e, t, n) {
        "use strict";
        n.r(t);
        // 手工补丁：风控板块「多账号同 IP」面板（组件 v2boardSharedIpPanel，自 kexue
        // 风控套件移植，账号即用户，本页本就以 user 维度聚合，无订阅口径）。数据来自累积
        // 表 v2_ip_account_link，由计划任务 audit:ip-link 每小时离线聚合；本页两个端点都是
        // 只读的，唯一副作用是 IP 归属查询会填充 v2_ip_location_cache（与 d1ca 的订阅审计
        // 弹窗同款）。与 v2bRiskRule / v2bRiskTrace 同一路数：不建 dva model，数据访问直接
        // 走 t3Un 请求助手；唯一需要 dispatch 的地方是「在用户管理中打开」，沿用 d1ca 已有的
        // user/addFilter 播种模式，所以只 connect 取 dispatch、不注册任何 model。
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
          , y = (n("+BJd"),
        n("mr32"))
          , D = (n("iQDF"),
        n("+eQT"))
          , d = n("q1tI")
          , p = n.n(d)
          , m = n("Bl7J")
          , g = n("v32e")
          , w = n("wd/R")
          , k = n("/MKj");
        function v2boardSharedIpUrl(path) {
            return "/" + window.settings.secure_path + path
        }
        function v2boardSharedIpGet(path, params) {
            return Object(n("t3Un")["a"])(v2boardSharedIpUrl(path), params)
        }
        // 时间与次数字段全是 int（unix 秒 / 计数），后端已经处理好，这里只格式化。
        function v2boardSharedIpTime(value) {
            var num = Number(value);
            return num > 0 ? w(1e3 * num).format("YYYY-MM-DD HH:mm:ss") : "-"
        }
        function v2boardSharedIpDay(value) {
            var num = Number(value);
            return num > 0 ? w(1e3 * num).format("YYYY-MM-DD HH:mm") : "-"
        }
        // 归属地 / 运营商 / IDC 三态的口径与 d1ca 的订阅审计弹窗逐字一致（ip_location 同结构）。
        function v2boardSharedIpLocation(location) {
            var loc = location || {};
            return [loc.country_name || loc.country_code, loc.province || loc.region, loc.city, loc.district].filter(Boolean).join(" / ") || "未知"
        }
        function v2boardSharedIpIdc(location) {
            var loc = location || {};
            // is_idc 是三态：命中 IDC 库为 true，命中普通库为 false，完全查不到为 null。
            return !0 === loc.is_idc ? loc.idc_vendor || "是" : !1 === loc.is_idc ? "否" : "未知"
        }
        // request_ip 可能是字面量 "unknown"（当时服务端解析不出地址），所以不做 IP 正则强
        // 校验，原值照样回传给明细接口。
        function v2boardSharedIpText(value) {
            return "unknown" === value ? "未知来源" : value || "-"
        }
        // 聚合每小时一次，落后两个周期以上才提示，避免整点前后一直挂着黄条。
        var V2BOARD_SHARED_IP_LAG_SECONDS = 7200;
        // 默认排序。第三次点同一列表头是 antd 的「取消排序」：sorter.columnKey 还在、
        // sorter.order 变成 undefined。把 order 缺失当成该列 DESC 会出现「表头箭头清空了
        // 但数据没变」，所以那种情况回到这里定义的默认排序。
        var V2BOARD_SHARED_IP_SORT = {
            sort: "account_count",
            sort_type: "DESC"
        };
        var V2BOARD_SHARED_IP_DETAIL_SORT = {
            sort: "request_count",
            sort_type: "DESC"
        };
        // antd 的 onChange 回传的是整个 pagination 配置（total / size / showSizeChanger /
        // pageSizeOptions 全在里面）。整份并进 state 再展开进 query string，翻页请求里就会
        // 多出 size=small&showSizeChanger=true&pageSizeOptions[0]=10 这类无用参数，
        // 所以只取真正属于分页的两项。
        function v2boardSharedIpPage(pagination) {
            var page = pagination || {}
              , current = Number(page.current)
              , pageSize = Number(page.pageSize);
            return {
                current: current > 0 ? current : 1,
                pageSize: pageSize > 0 ? pageSize : 20
            }
        }
        // sorter → 后端的 sort / sort_type。只在 sorter.columnKey 存在时调用（没有列信息
        // 就该保持当前排序不动）；order 缺失即「取消排序」，回落到 fallback。
        function v2boardSharedIpSort(sorter, fallback) {
            return sorter.order ? {
                sort: sorter.columnKey,
                sort_type: "ascend" === sorter.order ? "ASC" : "DESC"
            } : fallback
        }
        class v2boardSharedIpPanel extends p.a.Component {
            constructor(props) {
                super(props),
                this.state = {
                    rows: [],
                    total: 0,
                    totalCapped: !1,
                    pagination: {
                        pageSize: 20,
                        current: 1
                    },
                    sort: i()({}, V2BOARD_SHARED_IP_SORT),
                    minAccounts: 2,
                    ipKeyword: "",
                    emailKeyword: "",
                    range: [null, null],
                    fetchLoading: !0,
                    meta: {
                        available: {},
                        retention_days: 0,
                        aggregation: {},
                        window: {},
                        min_accounts: 2,
                        email_truncated: !1,
                        scope_truncated: !1,
                        non_routable_rows: 0
                    },
                    detail: this.emptyDetail()
                },
                // 每个筛选框各自一个防抖定时器：共用一个的话，在 IP 框打完立刻去点邮箱框会把
                // 还没落地的 IP 改动一起取消掉。
                this.filterTimers = {}
            }
            emptyDetail() {
                return {
                    visible: !1,
                    ip: "",
                    rows: [],
                    total: 0,
                    summary: null,
                    loading: !1,
                    pagination: {
                        pageSize: 20,
                        current: 1
                    },
                    sort: i()({}, V2BOARD_SHARED_IP_DETAIL_SORT),
                    expandedRowKeys: []
                }
            }
            componentDidMount() {
                this.fetch()
            }
            componentWillUnmount() {
                // 原版 d1ca 的防抖没有清理定时器，会在卸载后 setState。
                Object.keys(this.filterTimers).forEach(key=>{
                    this.filterTimers[key] && clearTimeout(this.filterTimers[key])
                }
                ),
                this.filterTimers = {},
                this.unmounted = !0
            }
            // 时间窗只在两端都选了才下发；否则交给后端默认值（最近 365 天，上限 1095 天）。
            // 前端不自己算默认窗口 —— 窗口口径的唯一事实源是后端返回的 window。
            windowParams() {
                var range = this.state.range;
                return range && range[0] && range[1] ? {
                    start_at: range[0].clone().startOf("day").unix(),
                    end_at: range[1].clone().endOf("day").unix()
                } : {}
            }
            fetch() {
                var state = this.state;
                this.setState({
                    fetchLoading: !0
                }),
                v2boardSharedIpGet("/risk/shared-ip/fetch", i()({
                    min_accounts: state.minAccounts || 2,
                    ip: state.ipKeyword,
                    email: state.emailKeyword
                }, this.windowParams(), v2boardSharedIpPage(state.pagination), state.sort)).then(res=>{
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
                        totalCapped: !0 === res.total_capped,
                        meta: {
                            available: res.available || {},
                            retention_days: Number(res.retention_days || 0),
                            aggregation: res.aggregation || {},
                            window: res.window || {},
                            min_accounts: Number(res.min_accounts || 0),
                            email_truncated: !0 === res.email_truncated,
                            scope_truncated: !0 === res.scope_truncated,
                            non_routable_rows: Number(res.non_routable_rows || 0)
                        },
                        pagination: i()({}, this.state.pagination, {
                            total: res.total || 0
                        }),
                        fetchLoading: !1
                    })
                }
                // 请求在离开本页之后失败时不能再 setState，否则 React 会对已卸载组件告警
                // （.then 分支已经有这道卫语句，catch 分支同样需要）。
                ).catch(()=>{
                    this.unmounted || this.setState({
                        fetchLoading: !1
                    })
                }
                )
            }
            tableOnChange(pagination, sorter) {
                var nextSort = this.state.sort;
                sorter && sorter.columnKey && (nextSort = v2boardSharedIpSort(sorter, V2BOARD_SHARED_IP_SORT)),
                this.setState({
                    // 只并进 current / pageSize：antd 回传的整份配置不该进 query string。
                    pagination: i()({}, this.state.pagination, v2boardSharedIpPage(pagination)),
                    sort: nextSort
                }, ()=>this.fetch())
            }
            // 任何筛选变化都要回到第 1 页，否则会停在一个可能已经不存在的页码上。
            resetPage(patch) {
                this.setState(i()({}, patch, {
                    pagination: i()({}, this.state.pagination, {
                        current: 1
                    })
                }), ()=>this.fetch())
            }
            filterOnChange(field, value) {
                this.filterTimers[field] && clearTimeout(this.filterTimers[field]),
                this.filterTimers[field] = setTimeout(()=>{
                    this.filterTimers[field] = null;
                    var patch = {};
                    patch[field] = value,
                    this.resetPage(patch)
                }, 400)
            }
            openDetail(record) {
                this.setState({
                    detail: i()({}, this.emptyDetail(), {
                        visible: !0,
                        ip: record.request_ip
                    })
                }, ()=>this.fetchDetail())
            }
            closeDetail() {
                this.setState({
                    detail: this.emptyDetail()
                })
            }
            fetchDetail() {
                var detail = this.state.detail;
                if (!detail.ip)
                    return;
                this.setState({
                    detail: i()({}, detail, {
                        loading: !0
                    })
                }),
                v2boardSharedIpGet("/risk/shared-ip/detail", i()({
                    ip: detail.ip
                }, this.windowParams(), v2boardSharedIpPage(detail.pagination), detail.sort)).then(res=>{
                    if (this.unmounted)
                        return;
                    var current = this.state.detail;
                    // 弹层已经关掉、或者换成了另一个 IP，就丢弃在飞的这次响应。
                    if (!current.visible || current.ip !== detail.ip)
                        return;
                    if (200 !== res.code)
                        return void this.setState({
                            detail: i()({}, current, {
                                loading: !1
                            })
                        });
                    var rows = res.data || [];
                    this.setState({
                        detail: i()({}, current, {
                            rows: rows,
                            total: res.total || 0,
                            summary: res.ip || null,
                            loading: !1,
                            // UA 全文是这个弹层的重点，默认把本页每个账号都展开。
                            expandedRowKeys: rows.map(row=>row.user_id),
                            pagination: i()({}, current.pagination, {
                                total: res.total || 0
                            })
                        })
                    })
                }
                // 同 fetch()：catch 分支也要有卸载卫语句。
                ).catch(()=>{
                    this.unmounted || this.setState({
                        detail: i()({}, this.state.detail, {
                            loading: !1
                        })
                    })
                }
                )
            }
            detailOnChange(pagination, sorter) {
                var detail = this.state.detail
                  , nextSort = detail.sort;
                sorter && sorter.columnKey && (nextSort = v2boardSharedIpSort(sorter, V2BOARD_SHARED_IP_DETAIL_SORT)),
                this.setState({
                    detail: i()({}, detail, {
                        pagination: i()({}, detail.pagination, v2boardSharedIpPage(pagination)),
                        sort: nextSort
                    })
                }, ()=>this.fetchDetail())
            }
            detailOnExpand(expanded, record) {
                var detail = this.state.detail
                  , keys = expanded ? detail.expandedRowKeys.concat([record.user_id]) : detail.expandedRowKeys.filter(item=>item !== record.user_id);
                this.setState({
                    detail: i()({}, detail, {
                        expandedRowKeys: keys
                    })
                })
            }
            // 跨页跳转沿用 d1ca.orderFilter 的 filter 播种模式：user 命名空间在启动时就全局
            // 注册了，任何页面都能 dispatch，这样不必提取或复制那个审计弹窗。
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
            renderNotice() {
                var meta = this.state.meta
                  , available = meta.available || {}
                  , aggregation = meta.aggregation || {}
                  , win = meta.window || {}
                  , warnings = []
                  , notes = [];
                // 表缺失、聚合落后、窗口被收紧、总数封顶、筛选被截断：五种「数字不可全信」的
                // 状态各有各的处置办法，文案必须分开，不能混成一句。
                // --full 只在表还是空的时候是安全的（它会清表重扫，超出保留期的历史拿不回来），
                // 所以这句提示必须写明「仅首次」，别让人在有数据之后照抄。
                !1 === available.ip_account_link && warnings.push("IP 关联累积表尚未创建，本页暂时没有数据。计划任务 audit:ip-link 每小时聚合一次并会自动建表；首次启用可执行 php artisan audit:ip-link --full 回填一次历史日志（--full 会清空累积表，仅限首次启用时使用；此后只需 php artisan audit:ip-link 增量聚合）。"),
                !1 === available.subscribe_request_log && warnings.push("订阅拉取审计表尚未创建（尚无订阅拉取记录），聚合任务没有数据来源。");
                var pending = Number(aggregation.pending_since || 0);
                pending > 0 && Math.floor(Date.now() / 1e3) - pending > V2BOARD_SHARED_IP_LAG_SECONDS && warnings.push("聚合尚未追平：最早一条还没进入统计的拉取记录发生在 " + v2boardSharedIpTime(pending) + "，本页数字可能不是最新的。聚合由计划任务 audit:ip-link 每小时执行一次。"),
                win.clamped && warnings.push("所选时间范围超过 1095 天上限，已自动收紧为 " + v2boardSharedIpDay(win.start_at) + " ~ " + v2boardSharedIpDay(win.end_at) + "。"),
                this.state.totalCapped && warnings.push("满足条件的 IP 超过 10000 组，总数按 10000 显示。请缩小时间范围或提高「最少账号数」。"),
                meta.email_truncated && warnings.push("匹配到的邮箱过多，只解析了前 200 个账号，可能有遗漏。"),
                meta.scope_truncated && warnings.push("按账号筛选命中的 IP 超过 1000 个，只统计了其中一部分，可能有遗漏。"),
                // 当页出现内网/回环地址，几乎总是反向代理没配对：那种情况下全站每个账号的
                // 拉取 IP 都会是同一个地址，聚合出来是一条「所有账号共用一个 IP」的假结论。
                Number(meta.non_routable_rows || 0) > 0 && warnings.push("本页有 " + meta.non_routable_rows + " 行不是公网地址（内网或回环地址）。这通常说明站点经反向代理接入但可信代理没配好（config/v2board.php 的 trusted_proxies 配置项），记录下来的是代理自己的地址而不是客户端地址 —— 这类行上的「关联账号数」没有分析意义，请先修正代理配置。"),
                notes.push("本页按订阅拉取 IP 分组，列出被 " + (meta.min_accounts || 2) + " 个及以上不同账号共用的 IP。每行的「关联账号数」始终是该 IP 的完整账号数，不会因为筛选条件而变少；解析不出客户端地址的记录（占位值 unknown）不参与本页统计。"),
                // 首帧 meta.window 还是 {}，格式化出来是「- ~ -（0 天）」，比不显示更糟。
                Number(win.start_at) > 0 && notes.push("统计窗口：" + v2boardSharedIpDay(win.start_at) + " ~ " + v2boardSharedIpDay(win.end_at) + "（" + (win.days || 0) + " 天），按「最近出现」落在窗口内筛选；「首次出现」与「请求次数」是该 IP 的终身累计值，可能早于窗口起点、也可能包含窗口之外的次数。"),
                meta.retention_days > 0 ? notes.push("原始拉取日志默认保留 " + meta.retention_days + " 天并每日清理，但本页的累积记录不随保留期消失，所以这里的「首次出现」可能早于原始日志里还能查到的最早记录。") : notes.push("原始拉取日志当前不自动清理（保留期设为 0）；本页的累积记录同样不随保留期消失。"),
                notes.push("同一个 IP 下有多个账号不等于共享账号：家庭或公司出口、运营商 NAT、同一个人的多个账号都会这样。请结合 User-Agent、归属地与拉取次数一起判断。");
                return p.a.createElement("div", null, warnings.length ? p.a.createElement("div", {
                    className: "alert alert-warning",
                    role: "alert",
                    style: {
                        marginBottom: 15
                    }
                }, warnings.map((note, index)=>p.a.createElement("p", {
                    key: index,
                    className: index === warnings.length - 1 ? "mb-0" : "mb-1"
                }, note))) : null, p.a.createElement("div", {
                    className: "alert alert-info",
                    style: {
                        marginBottom: 15
                    }
                }, notes.map((note, index)=>p.a.createElement("p", {
                    key: index,
                    className: index === notes.length - 1 ? "mb-0" : "mb-1"
                }, note))))
            }
            // 列表里只给前 3 个邮箱 + 「等 N 个」，完整名单在明细弹层里；后端每行最多下发 5 条
            // 摘要，所以这里的「等 N 个」按 account_count 算而不是按数组长度算。
            renderAccounts(record) {
                var accounts = record.accounts || []
                  , shown = accounts.slice(0, 3)
                  , rest = (record.account_count || 0) - shown.length;
                return shown.length ? p.a.createElement("span", null, shown.map((account, index)=>p.a.createElement("span", {
                    key: account.user_id,
                    style: {
                        marginRight: 6
                    }
                }, account.deleted ? p.a.createElement("span", {
                    className: "text-muted"
                }, "已删除用户 #" + account.user_id) : p.a.createElement("span", null, account.email || "#" + account.user_id, account.banned ? p.a.createElement(y["a"], {
                    color: "red",
                    style: {
                        marginLeft: 4
                    }
                }, "已封禁") : null), index === shown.length - 1 ? null : "、")), rest > 0 ? p.a.createElement("span", {
                    className: "text-muted"
                }, "等 " + rest + " 个") : null) : "-"
            }
            renderDetailUserAgents(record) {
                var agents = record.user_agents || [];
                if (!agents.length)
                    return p.a.createElement("p", {
                        className: "text-muted mb-0"
                    }, "该账号在这个 IP 上没有 User-Agent 记录。");
                var columns = [{
                    title: "User-Agent",
                    dataIndex: "user_agent",
                    // 长 UA 折行显示，另挂原生 title 便于悬浮看全文（与 d1ca 订阅审计弹窗
                    // 的 wordBreak 处理同款，不引入额外组件）。
                    render: value=>p.a.createElement("span", {
                        style: {
                            wordBreak: "break-all"
                        },
                        title: value || ""
                    }, value || "-")
                }, {
                    title: "次数",
                    dataIndex: "request_count",
                    align: "right",
                    width: 90,
                    render: value=>value || 0
                }, {
                    title: "首次出现",
                    dataIndex: "first_seen_at",
                    width: 180,
                    render: value=>v2boardSharedIpTime(value)
                }, {
                    title: "最近出现",
                    dataIndex: "last_seen_at",
                    width: 180,
                    render: value=>v2boardSharedIpTime(value)
                }];
                return p.a.createElement("div", null, p.a.createElement(o["a"], {
                    size: "small",
                    tableLayout: "auto",
                    rowKey: row=>row.ua_hash,
                    dataSource: agents,
                    columns: columns,
                    pagination: !1,
                    locale: {
                        emptyText: "暂无 User-Agent 记录"
                    }
                }), record.user_agents_truncated ? p.a.createElement("p", {
                    className: "text-muted mb-0",
                    style: {
                        marginTop: 6
                    }
                }, "该账号在这个 IP 上共有 " + (record.ua_count || 0) + " 种 User-Agent，这里只显示次数最多的前 " + agents.length + " 条。") : null)
            }
            renderDetail() {
                var detail = this.state.detail
                  , summary = detail.summary
                  , columns = [{
                    title: "UID",
                    dataIndex: "user_id",
                    key: "user_id",
                    sorter: !0,
                    width: 90
                }, {
                    title: "邮箱",
                    dataIndex: "email",
                    // 邮箱不在聚合里排不了序 —— 点了没反应的排序箭头比没有更糟，刻意不给 sorter。
                    render: (value, row)=>row.deleted ? p.a.createElement("span", {
                        className: "text-muted"
                    }, "已删除用户（UID 仍保留在累积记录中）") : p.a.createElement("span", null, value || "-", row.banned ? p.a.createElement(y["a"], {
                        color: "red",
                        style: {
                            marginLeft: 6
                        }
                    }, "已封禁") : null)
                }, {
                    title: "拉取次数",
                    dataIndex: "request_count",
                    key: "request_count",
                    sorter: !0,
                    defaultSortOrder: "descend",
                    align: "right",
                    width: 110,
                    render: value=>value || 0
                }, {
                    title: "UA 数",
                    dataIndex: "ua_count",
                    align: "right",
                    width: 90,
                    render: value=>value || 0
                }, {
                    title: "首次出现",
                    dataIndex: "first_seen_at",
                    key: "first_seen_at",
                    sorter: !0,
                    width: 180,
                    render: value=>v2boardSharedIpTime(value)
                }, {
                    title: "最近出现",
                    dataIndex: "last_seen_at",
                    key: "last_seen_at",
                    sorter: !0,
                    width: 180,
                    render: value=>v2boardSharedIpTime(value)
                }, {
                    title: "操作",
                    align: "right",
                    width: 140,
                    render: (value, row)=>row.deleted || !row.email ? p.a.createElement("span", {
                        className: "text-muted"
                    }, "-") : p.a.createElement("a", {
                        href: "javascript:void(0);",
                        onClick: ()=>this.openInUserManage(row.email)
                    }, "在用户管理中打开")
                }];
                return p.a.createElement("div", null, summary ? p.a.createElement("div", {
                    style: {
                        marginBottom: 12
                    }
                }, p.a.createElement("p", {
                    className: "mb-1"
                }, p.a.createElement("strong", {
                    style: {
                        wordBreak: "break-all"
                    }
                }, v2boardSharedIpText(summary.request_ip)), p.a.createElement("span", {
                    className: "text-muted",
                    style: {
                        marginLeft: 10
                    }
                }, v2boardSharedIpLocation(summary.ip_location) + " · " + ((summary.ip_location || {}).isp || "未知运营商") + " · IDC/云厂商：" + v2boardSharedIpIdc(summary.ip_location))), p.a.createElement("p", {
                    className: "mb-0 text-muted font-size-sm"
                }, (summary.account_count || 0) + " 个账号 · " + (summary.ua_count || 0) + " 种 User-Agent · 拉取 " + (summary.request_count || 0) + " 次 · " + v2boardSharedIpTime(summary.first_seen_at) + " ~ " + v2boardSharedIpTime(summary.last_seen_at))) : null, p.a.createElement(o["a"], {
                    size: "small",
                    tableLayout: "auto",
                    loading: detail.loading,
                    rowKey: row=>row.user_id,
                    dataSource: detail.rows,
                    columns: columns,
                    expandedRowKeys: detail.expandedRowKeys,
                    onExpand: (expanded, record)=>this.detailOnExpand(expanded, record),
                    expandedRowRender: record=>this.renderDetailUserAgents(record),
                    pagination: i()({}, detail.pagination, {
                        size: "small",
                        showSizeChanger: !0,
                        pageSizeOptions: ["10", "50", "100"]
                    }),
                    onChange: (pagination, filters, sorter)=>this.detailOnChange(pagination, sorter),
                    locale: {
                        emptyText: detail.loading ? "正在加载…" : "该 IP 在当前时间范围内没有记录"
                    },
                    scroll: {
                        x: 900
                    }
                }))
            }
            render() {
                var state = this.state
                  , columns = [{
                    title: "IP",
                    dataIndex: "request_ip",
                    render: (value, row)=>p.a.createElement("span", null, p.a.createElement("span", {
                        style: {
                            wordBreak: "break-all"
                        }
                    }, v2boardSharedIpText(value)),
                    // 后端给的 ip_kind：非公网地址通常是代理配置问题的产物，标出来免得被
                    // 当成头号线索（黄条里已经解释了原因）。
                    row.ip_kind && "public" !== row.ip_kind ? p.a.createElement(y["a"], {
                        color: "orange",
                        style: {
                            marginLeft: 6
                        }
                    }, "非公网地址") : null, p.a.createElement("div", {
                        className: "text-muted font-size-sm"
                    }, v2boardSharedIpLocation(row.ip_location) + " · " + ((row.ip_location || {}).isp || "未知运营商") + " · IDC：" + v2boardSharedIpIdc(row.ip_location)))
                }, {
                    title: "关联账号数",
                    dataIndex: "account_count",
                    key: "account_count",
                    sorter: !0,
                    defaultSortOrder: "descend",
                    align: "right",
                    width: 120,
                    render: value=>value || 0
                }, {
                    title: "请求次数",
                    dataIndex: "request_count",
                    key: "request_count",
                    sorter: !0,
                    align: "right",
                    width: 110,
                    render: value=>value || 0
                }, {
                    title: "首次出现",
                    dataIndex: "first_seen_at",
                    // 后端 sort 白名单只有 account_count / last_seen_at / request_count，
                    // 「首次出现」排不了，所以刻意不给 sorter。
                    width: 180,
                    render: value=>v2boardSharedIpTime(value)
                }, {
                    title: "最近出现",
                    dataIndex: "last_seen_at",
                    key: "last_seen_at",
                    sorter: !0,
                    width: 180,
                    render: value=>v2boardSharedIpTime(value)
                }, {
                    title: "账号",
                    dataIndex: "accounts",
                    render: (value, row)=>this.renderAccounts(row)
                }, {
                    title: "操作",
                    align: "right",
                    width: 110,
                    render: (value, row)=>p.a.createElement("a", {
                        href: "javascript:void(0);",
                        onClick: ()=>this.openDetail(row)
                    }, "查看明细")
                }];
                // 必须展开路由 props，否则侧边栏会在 location.pathname 上崩。
                return p.a.createElement(m["a"], i()({}, this.props, {
                    title: "多账号同 IP"
                }), p.a.createElement(g["a"], {
                    loading: state.fetchLoading
                }, this.renderNotice(), p.a.createElement("div", {
                    className: "block block-rounded"
                }, p.a.createElement("div", {
                    className: "bg-white"
                }, p.a.createElement("div", {
                    className: "d-flex flex-wrap align-items-center",
                    style: {
                        padding: 15
                    }
                }, p.a.createElement("span", {
                    className: "text-muted",
                    style: {
                        marginRight: 6,
                        marginBottom: 6
                    }
                }, "最少账号数"), p.a.createElement(s["a"], {
                    type: "number",
                    min: 2,
                    max: 50,
                    // 带防抖的输入框一律用 defaultValue：受控值要等 400 毫秒才回来，
                    // 打字过程中输入框会像卡住一样。
                    defaultValue: state.minAccounts,
                    onChange: event=>this.filterOnChange("minAccounts", event.target.value),
                    style: {
                        width: 90,
                        marginRight: 12,
                        marginBottom: 6
                    }
                }), p.a.createElement("span", {
                    className: "text-muted",
                    style: {
                        marginRight: 6,
                        marginBottom: 6
                    }
                }, "时间范围"), p.a.createElement(D["a"].RangePicker, {
                    format: "YYYY-MM-DD",
                    placeholder: ["开始日期", "结束日期"],
                    value: state.range,
                    onChange: dates=>this.resetPage({
                        range: dates && dates.length ? dates : [null, null]
                    }),
                    style: {
                        width: 260,
                        marginRight: 12,
                        marginBottom: 6
                    }
                }), p.a.createElement(s["a"], {
                    placeholder: "IP 前缀，如 203.0.113",
                    allowClear: !0,
                    defaultValue: state.ipKeyword,
                    onChange: event=>this.filterOnChange("ipKeyword", event.target.value),
                    style: {
                        width: 190,
                        marginRight: 12,
                        marginBottom: 6
                    }
                }), p.a.createElement(s["a"], {
                    placeholder: "邮箱关键词",
                    allowClear: !0,
                    defaultValue: state.emailKeyword,
                    onChange: event=>this.filterOnChange("emailKeyword", event.target.value),
                    style: {
                        width: 190,
                        marginRight: 12,
                        marginBottom: 6
                    }
                }), p.a.createElement(a["a"], {
                    style: {
                        marginBottom: 6
                    },
                    onClick: ()=>this.fetch()
                }, p.a.createElement(l["a"], {
                    type: "reload"
                }), " 刷新")), p.a.createElement("div", {
                    style: {
                        padding: "0 15px 15px"
                    }
                }, p.a.createElement(o["a"], {
                    tableLayout: "auto",
                    rowKey: row=>row.request_ip,
                    dataSource: state.rows,
                    columns: columns,
                    pagination: i()({}, state.pagination, {
                        size: "small",
                        showSizeChanger: !0,
                        pageSizeOptions: ["10", "50", "100"]
                    }),
                    onChange: (pagination, filters, sorter)=>this.tableOnChange(pagination, sorter),
                    locale: {
                        emptyText: "当前条件下没有被多个账号共用的 IP"
                    },
                    scroll: {
                        x: 1100
                    }
                }))))), p.a.createElement(c["a"], {
                    title: "IP 明细" + (state.detail.ip ? " - " + v2boardSharedIpText(state.detail.ip) : ""),
                    visible: state.detail.visible,
                    width: 1080,
                    footer: null,
                    onCancel: ()=>this.closeDetail()
                }, p.a.createElement("div", {
                    style: {
                        maxHeight: "62vh",
                        overflowY: "auto"
                    }
                }, this.renderDetail())))
            }
        }
        t["default"] = Object(k["c"])()(v2boardSharedIpPanel)
    }
