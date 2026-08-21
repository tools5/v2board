    v2bOauthManage: function(e, t, n) {
        "use strict";
        n.r(t);
        var r = n("jehZ")
          , i = n.n(r)
          , o = n("q1tI")
          , a = n.n(o)
          , s = n("Bl7J")
          , l = n("/MKj")
          , c = n("t3Un");
        n("miYZ");
        var msg = n("tsqr");
        var STYLE_ID = "v2b-oauth-manage-style";
        function ensureStyles() {
            if (document.getElementById(STYLE_ID))
                return;
            var style = document.createElement("style");
            style.id = STYLE_ID;
            style.textContent = ".v2b-oauth-manage .v2b-oauth-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}.v2b-oauth-manage .v2b-oauth-table{min-width:1180px;width:100%}.v2b-oauth-manage .v2b-oauth-table th{white-space:nowrap}.v2b-oauth-manage .v2b-oauth-table td{vertical-align:top}.v2b-oauth-manage .v2b-oauth-col-user{min-width:190px}.v2b-oauth-manage .v2b-oauth-col-provider{min-width:90px}.v2b-oauth-manage .v2b-oauth-col-external{min-width:150px}.v2b-oauth-manage .v2b-oauth-col-info{min-width:140px}.v2b-oauth-manage .v2b-oauth-col-plan{min-width:185px}.v2b-oauth-manage .v2b-oauth-col-online{min-width:175px}.v2b-oauth-manage .v2b-oauth-col-status{min-width:72px}.v2b-oauth-manage .v2b-oauth-col-actions{min-width:210px}.v2b-oauth-manage .v2b-oauth-cell-plan,.v2b-oauth-manage .v2b-oauth-cell-online,.v2b-oauth-manage .v2b-oauth-cell-status{white-space:nowrap}.v2b-oauth-manage .v2b-oauth-actions{white-space:normal}.v2b-oauth-manage .v2b-oauth-actions-inner{display:flex;flex-wrap:wrap;gap:4px;max-width:240px}.v2b-oauth-manage .v2b-oauth-actions-inner .btn{margin:0;padding:.15rem .5rem;font-size:12px;line-height:1.5;white-space:nowrap}";
            document.head.appendChild(style)
        }
        function adminEndpoint(path) {
            var secure = window.settings && window.settings.secure_path ? window.settings.secure_path : "";
            return "/" + (secure ? secure + "/" : "") + String(path || "").replace(/^\/+/, "")
        }
        function formatBytes(bytes) {
            var value = Number(bytes) || 0;
            return (value / 1073741824).toFixed(2) + " GB"
        }
        function formatTime(ts) {
            if (ts === null || ts === undefined || ts === "")
                return "长期有效";
            var number = Number(ts);
            if (!number)
                return "-";
            var date = new Date(number * 1000);
            if (isNaN(date.getTime()))
                return "-";
            var pad = function(n) {
                return n < 10 ? "0" + n : "" + n
            };
            return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate()) + " " + pad(date.getHours()) + ":" + pad(date.getMinutes())
        }
        class OauthManage extends a.a.Component {
            constructor(props) {
                super(props);
                this.state = {
                    loading: true,
                    rows: [],
                    total: 0,
                    current: 1,
                    pageSize: 10,
                    providers: [],
                    plans: [],
                    filterProvider: "",
                    filterEmail: "",
                    filterExternalId: "",
                    filterBanned: "",
                    selected: null,
                    editVisible: false,
                    editRecord: {},
                    saving: false,
                    mailVisible: false,
                    mailSubject: "",
                    mailContent: ""
                };
                this.unmounted = false;
                this.load = this.load.bind(this);
                this.loadPlans = this.loadPlans.bind(this)
            }
            componentDidMount() {
                ensureStyles();
                this.loadPlans();
                this.load()
            }
            componentWillUnmount() {
                this.unmounted = true
            }
            loadPlans() {
                var self = this;
                Object(c["a"])(adminEndpoint("plan/fetch")).then(function(res) {
                    if (self.unmounted)
                        return;
                    var data = res && res.data;
                    self.setState({
                        plans: Array.isArray(data) ? data : (data && data.data) || []
                    })
                }).catch(function() {})
            }
            buildFilterParams() {
                var filters = [];
                if (this.state.filterProvider)
                    filters.push({
                        key: "provider",
                        condition: "=",
                        value: this.state.filterProvider
                    });
                if (this.state.filterEmail)
                    filters.push({
                        key: "email",
                        condition: "模糊",
                        value: this.state.filterEmail
                    });
                if (this.state.filterExternalId)
                    filters.push({
                        key: "provider_user_id",
                        condition: "模糊",
                        value: this.state.filterExternalId
                    });
                if (this.state.filterBanned !== "")
                    filters.push({
                        key: "banned",
                        condition: "=",
                        value: this.state.filterBanned
                    });
                return {
                    current: this.state.current,
                    pageSize: this.state.pageSize,
                    filter: filters
                }
            }
            load() {
                var self = this;
                this.setState({
                    loading: true
                });
                Object(c["a"])(adminEndpoint("oauth/fetch"), this.buildFilterParams()).then(function(res) {
                    if (self.unmounted)
                        return;
                    if (!res || res.code !== 200)
                        throw new Error((res && res.message) || "加载失败");
                    self.setState({
                        loading: false,
                        rows: res.data || [],
                        total: res.total || 0,
                        providers: res.providers || []
                    })
                }).catch(function(err) {
                    if (self.unmounted)
                        return;
                    self.setState({
                        loading: false
                    });
                    msg["a"].error((err && err.message) || "OAuth 用户加载失败")
                })
            }
            bytesToGb(value) {
                var number = Number(value) || 0;
                return Number((number / 1073741824).toFixed(2))
            }
            moneyToYuan(value) {
                var number = Number(value) || 0;
                return Number((number / 100).toFixed(2))
            }
            emptyToNull(value) {
                if (value === "" || value === undefined || value === null)
                    return null;
                return value
            }
            openEdit(row) {
                var self = this;
                Object(c["a"])(adminEndpoint("oauth/getInfoById"), {
                    id: row.binding_id || row.id,
                    user_id: row.user_id
                }).then(function(res) {
                    if (!res || res.code !== 200)
                        throw new Error((res && res.message) || "获取详情失败");
                    var user = (res.data && res.data.user) || {};
                    var aliveIp = res.data && res.data.alive_ip != null ? res.data.alive_ip : (user.alive_ip || 0);
                    var onlineIps = res.data && res.data.ips != null ? res.data.ips : (user.ips || "");
                    var isOnline = res.data && res.data.is_online != null ? res.data.is_online : (user.is_online || aliveIp > 0);
                    self.setState({
                        selected: row,
                        editVisible: true,
                        editRecord: {
                            id: user.id,
                            email: user.email || "",
                            invite_user_email: user.invite_user && user.invite_user.email ? user.invite_user.email : (user.invite_user_email || ""),
                            password: "",
                            balance: self.moneyToYuan(user.balance),
                            commission_balance: self.moneyToYuan(user.commission_balance),
                            u: self.bytesToGb(user.u),
                            d: self.bytesToGb(user.d),
                            transfer_enable: self.bytesToGb(user.transfer_enable),
                            device_limit: user.device_limit == null ? "" : user.device_limit,
                            expired_at: user.expired_at == null ? "" : user.expired_at,
                            plan_id: user.plan_id || null,
                            banned: user.banned ? 1 : 0,
                            commission_type: user.commission_type == null ? 0 : Number(user.commission_type),
                            commission_rate: user.commission_rate == null || user.commission_rate === "" ? "" : user.commission_rate,
                            discount: user.discount == null || user.discount === "" ? "" : user.discount,
                            speed_limit: user.speed_limit == null || user.speed_limit === "" ? "" : user.speed_limit,
                            is_admin: user.is_admin ? 1 : 0,
                            is_staff: user.is_staff ? 1 : 0,
                            remarks: user.remarks || "",
                            telegram_id: user.telegram_id || "",
                            subscribe_url: user.subscribe_url || row.subscribe_url || "",
                            token: user.token || "",
                            uuid: user.uuid || "",
                            alive_ip: aliveIp,
                            ips: onlineIps,
                            is_online: !!isOnline,
                            last_login_at: user.last_login_at || null,
                            bindings: (res.data && res.data.bindings) || row.bindings || []
                        }
                    })
                }).catch(function(err) {
                    msg["a"].error((err && err.message) || "获取详情失败")
                })
            }
            saveEdit() {
                var self = this;
                var record = this.state.editRecord || {};
                if (!record.id) {
                    msg["a"].error("用户 ID 无效");
                    return
                }
                if (!record.email) {
                    msg["a"].error("邮箱不能为空");
                    return
                }
                var payload = {
                    id: record.id,
                    email: record.email,
                    invite_user_email: record.invite_user_email || "",
                    transfer_enable: Math.round(Number(record.transfer_enable || 0) * 1073741824),
                    device_limit: record.device_limit === "" || record.device_limit === null ? null : Number(record.device_limit),
                    expired_at: record.expired_at === "" || record.expired_at === null ? null : Number(record.expired_at),
                    banned: Number(record.banned) ? 1 : 0,
                    plan_id: record.plan_id || null,
                    balance: Math.round(Number(record.balance || 0) * 100),
                    commission_balance: Math.round(Number(record.commission_balance || 0) * 100),
                    commission_type: Number(record.commission_type || 0),
                    commission_rate: this.emptyToNull(record.commission_rate) === null ? null : Number(record.commission_rate),
                    discount: this.emptyToNull(record.discount) === null ? null : Number(record.discount),
                    speed_limit: this.emptyToNull(record.speed_limit) === null ? null : Number(record.speed_limit),
                    remarks: record.remarks || null,
                    is_admin: Number(record.is_admin) ? 1 : 0,
                    is_staff: Number(record.is_staff) ? 1 : 0,
                    u: Math.round(Number(record.u || 0) * 1073741824),
                    d: Math.round(Number(record.d || 0) * 1073741824)
                };
                if (record.password)
                    payload.password = record.password;
                this.setState({
                    saving: true
                });
                Object(c["b"])(adminEndpoint("oauth/update"), payload).then(function(res) {
                    if (!res || res.code !== 200)
                        throw new Error((res && res.message) || "保存失败");
                    msg["a"].success("保存成功");
                    self.setState({
                        saving: false,
                        editVisible: false
                    });
                    self.load()
                }).catch(function(err) {
                    self.setState({
                        saving: false
                    });
                    msg["a"].error((err && err.message) || "保存失败")
                })
            }
            doUnbind(row, force) {
                var self = this;
                if (!window.confirm(force ? "强制解绑将忽略未设密码限制，确认？" : "确认解绑该第三方账号？"))
                    return;
                Object(c["b"])(adminEndpoint("oauth/unbind"), {
                    id: row.id,
                    binding_id: row.binding_id || row.id,
                    force: force ? 1 : 0
                }).then(function(res) {
                    if (!res || res.code !== 200)
                        throw new Error((res && res.message) || "解绑失败");
                    msg["a"].success("已解绑");
                    if (self.state.editVisible) {
                        self.setState({
                            editVisible: false
                        })
                    }
                    self.load()
                }).catch(function(err) {
                    msg["a"].error((err && err.message) || "解绑失败")
                })
            }
            doBan(row) {
                var self = this;
                if (!window.confirm("确认封禁用户 " + (row.email || row.user_id) + " ？"))
                    return;
                Object(c["b"])(adminEndpoint("oauth/ban"), {
                    filter: [{
                        key: "user_id",
                        condition: "=",
                        value: row.user_id
                    }]
                }).then(function(res) {
                    if (!res || res.code !== 200)
                        throw new Error((res && res.message) || "封禁失败");
                    msg["a"].success("已封禁");
                    self.load()
                }).catch(function(err) {
                    msg["a"].error((err && err.message) || "封禁失败")
                })
            }
            doResetSecret(row) {
                var self = this;
                if (!window.confirm("确认重置该用户订阅密钥？"))
                    return;
                Object(c["b"])(adminEndpoint("oauth/resetSecret"), {
                    user_id: row.user_id
                }).then(function(res) {
                    if (!res || res.code !== 200)
                        throw new Error((res && res.message) || "重置失败");
                    msg["a"].success("密钥已重置");
                    self.load()
                }).catch(function(err) {
                    msg["a"].error((err && err.message) || "重置失败")
                })
            }
            doDelete(row) {
                var self = this;
                if (!window.confirm("确认删除用户 " + (row.email || row.user_id) + " 及其全部 OAuth 绑定？此操作不可恢复！"))
                    return;
                Object(c["b"])(adminEndpoint("oauth/delUser"), {
                    user_id: row.user_id
                }).then(function(res) {
                    if (!res || res.code !== 200)
                        throw new Error((res && res.message) || "删除失败");
                    msg["a"].success("已删除");
                    self.load()
                }).catch(function(err) {
                    msg["a"].error((err && err.message) || "删除失败")
                })
            }
            doDumpCSV() {
                var params = this.buildFilterParams();
                Object(c["b"])(adminEndpoint("oauth/dumpCSV"), params, true).then(function() {
                    msg["a"].success("导出请求已发送")
                }).catch(function() {
                    // dumpCSV 可能直接返回文本
                    window.open(adminEndpoint("oauth/dumpCSV") + "?download=1", "_blank")
                })
            }
            sendMail() {
                var self = this;
                if (!this.state.mailSubject || !this.state.mailContent) {
                    msg["a"].error("请填写主题和内容");
                    return
                }
                Object(c["b"])(adminEndpoint("oauth/sendMail"), i()({}, this.buildFilterParams(), {
                    subject: this.state.mailSubject,
                    content: this.state.mailContent
                })).then(function(res) {
                    if (!res || res.code !== 200)
                        throw new Error((res && res.message) || "发送失败");
                    msg["a"].success("已加入发送队列");
                    self.setState({
                        mailVisible: false,
                        mailSubject: "",
                        mailContent: ""
                    })
                }).catch(function(err) {
                    msg["a"].error((err && err.message) || "发送失败")
                })
            }
            setEditField(key, value) {
                this.setState(function(state) {
                    var editRecord = i()({}, state.editRecord);
                    editRecord[key] = value;
                    return {
                        editRecord: editRecord
                    }
                })
            }
            renderFilters() {
                var self = this;
                var providerOptions = [{
                    value: "",
                    label: "全部平台"
                }].concat((this.state.providers || []).map(function(p) {
                    return {
                        value: p.value,
                        label: p.label
                    }
                }));
                return a.a.createElement("div", {
                    className: "v2b-oauth-filters card mb-3"
                }, a.a.createElement("div", {
                    className: "card-body py-3"
                }, a.a.createElement("div", {
                    className: "row"
                }, a.a.createElement("div", {
                    className: "col-md-2 mb-2"
                }, a.a.createElement("select", {
                    className: "form-control",
                    value: this.state.filterProvider,
                    onChange: function(e) {
                        self.setState({
                            filterProvider: e.target.value,
                            current: 1
                        }, self.load)
                    }
                }, providerOptions.map(function(opt) {
                    return a.a.createElement("option", {
                        key: opt.value || "all",
                        value: opt.value
                    }, opt.label)
                }))), a.a.createElement("div", {
                    className: "col-md-3 mb-2"
                }, a.a.createElement("input", {
                    className: "form-control",
                    placeholder: "邮箱关键词",
                    value: this.state.filterEmail,
                    onChange: function(e) {
                        self.setState({
                            filterEmail: e.target.value
                        })
                    },
                    onKeyDown: function(e) {
                        if (e.key === "Enter")
                            self.setState({
                                current: 1
                            }, self.load)
                    }
                })), a.a.createElement("div", {
                    className: "col-md-3 mb-2"
                }, a.a.createElement("input", {
                    className: "form-control",
                    placeholder: "论坛ID / TGID / 平台用户ID",
                    value: this.state.filterExternalId,
                    onChange: function(e) {
                        self.setState({
                            filterExternalId: e.target.value
                        })
                    },
                    onKeyDown: function(e) {
                        if (e.key === "Enter")
                            self.setState({
                                current: 1
                            }, self.load)
                    }
                })), a.a.createElement("div", {
                    className: "col-md-2 mb-2"
                }, a.a.createElement("select", {
                    className: "form-control",
                    value: this.state.filterBanned,
                    onChange: function(e) {
                        self.setState({
                            filterBanned: e.target.value,
                            current: 1
                        }, self.load)
                    }
                }, a.a.createElement("option", {
                    value: ""
                }, "封禁状态"), a.a.createElement("option", {
                    value: "0"
                }, "正常"), a.a.createElement("option", {
                    value: "1"
                }, "已封禁"))), a.a.createElement("div", {
                    className: "col-md-2 mb-2"
                }, a.a.createElement("button", {
                    type: "button",
                    className: "btn btn-primary btn-block",
                    onClick: function() {
                        self.setState({
                            current: 1
                        }, self.load)
                    }
                }, "搜索"))), a.a.createElement("div", {
                    className: "mt-2"
                }, a.a.createElement("button", {
                    type: "button",
                    className: "btn btn-sm btn-alt-secondary mr-2",
                    onClick: function() {
                        self.setState({
                            mailVisible: true
                        })
                    }
                }, "群发邮件"), a.a.createElement("button", {
                    type: "button",
                    className: "btn btn-sm btn-alt-secondary",
                    onClick: function() {
                        self.doDumpCSV()
                    }
                }, "导出 CSV"))))
            }
            renderTable() {
                var self = this;
                var rows = this.state.rows || [];
                return a.a.createElement("div", {
                    className: "card"
                }, a.a.createElement("div", {
                    className: "card-header"
                }, a.a.createElement("h3", {
                    className: "block-title"
                }, "OAuth 绑定列表"), a.a.createElement("div", {
                    className: "block-options text-muted font-size-sm"
                }, "共 ", this.state.total, " 个用户 · 同一用户的多平台绑定合并显示")), a.a.createElement("div", {
                    className: "card-body p-0 table-responsive v2b-oauth-table-wrap"
                }, a.a.createElement("table", {
                    className: "table table-striped table-vcenter table-hover mb-0 v2b-oauth-table"
                }, a.a.createElement("thead", null, a.a.createElement("tr", null, a.a.createElement("th", {
                    className: "v2b-oauth-col-user"
                }, "用户"), a.a.createElement("th", {
                    className: "v2b-oauth-col-provider"
                }, "平台"), a.a.createElement("th", {
                    className: "v2b-oauth-col-external"
                }, "外部ID"), a.a.createElement("th", {
                    className: "v2b-oauth-col-info"
                }, "第三方信息"), a.a.createElement("th", {
                    className: "v2b-oauth-col-plan"
                }, "套餐/流量"), a.a.createElement("th", {
                    className: "v2b-oauth-col-online"
                }, "在线/设备"), a.a.createElement("th", {
                    className: "v2b-oauth-col-status"
                }, "状态"), a.a.createElement("th", {
                    className: "v2b-oauth-col-actions"
                }, "操作"))), a.a.createElement("tbody", null, rows.length ? rows.map(function(row) {
                    var bindings = (row.bindings && row.bindings.length) ? row.bindings : [{
                        id: row.binding_id || row.id,
                        binding_id: row.binding_id || row.id,
                        provider: row.provider,
                        provider_name: row.provider_name || row.provider,
                        external_id_label: row.external_id_label,
                        external_id: row.external_id,
                        provider_username: row.provider_username,
                        provider_email: row.provider_email
                    }];
                    var aliveIpCount = Number(row.alive_ip || 0);
                    var isOnline = row.is_online === true || row.is_online === 1 || aliveIpCount > 0;
                    var deviceLimitLabel = row.device_limit === null || row.device_limit === undefined || row.device_limit === "" ? "∞" : String(row.device_limit);
                    var deviceText = aliveIpCount + " / " + deviceLimitLabel;
                    var onlineIps = row.ips ? String(row.ips) : "";
                    return a.a.createElement("tr", {
                        key: row.row_key || ("user_" + row.user_id) || row.id
                    }, a.a.createElement("td", null, a.a.createElement("div", {
                        className: "font-w600"
                    }, row.email || "-"), a.a.createElement("div", {
                        className: "text-muted font-size-sm"
                    }, "UID: ", row.user_id), row.is_oauth_managed ? a.a.createElement("span", {
                        className: "badge badge-primary mr-1"
                    }, "OAuth注册") : a.a.createElement("span", {
                        className: "badge badge-secondary mr-1"
                    }, "邮箱用户绑定"), row.is_placeholder_email ? a.a.createElement("span", {
                        className: "badge badge-warning"
                    }, "占位邮箱") : null, Number(row.password_never_set) === 1 ? a.a.createElement("span", {
                        className: "badge badge-info ml-1"
                    }, "未设密码") : null, bindings.length > 1 ? a.a.createElement("span", {
                        className: "badge badge-success ml-1"
                    }, bindings.length, " 个平台") : null), a.a.createElement("td", null, bindings.map(function(binding) {
                        return a.a.createElement("div", {
                            key: "p-" + (binding.id || binding.provider),
                            className: "mb-1"
                        }, a.a.createElement("span", {
                            className: "badge badge-primary"
                        }, binding.provider_name || binding.provider))
                    })), a.a.createElement("td", null, bindings.map(function(binding) {
                        return a.a.createElement("div", {
                            key: "e-" + (binding.id || binding.external_id),
                            className: "mb-2"
                        }, a.a.createElement("div", {
                            className: "font-w600"
                        }, binding.external_id_label || "平台用户ID"), a.a.createElement("code", null, binding.external_id || "-"))
                    }), row.telegram_id ? a.a.createElement("div", {
                        className: "text-muted font-size-sm"
                    }, "用户表 TGID: ", String(row.telegram_id)) : null), a.a.createElement("td", null, bindings.map(function(binding) {
                        return a.a.createElement("div", {
                            key: "i-" + (binding.id || binding.provider_username),
                            className: "mb-2"
                        }, a.a.createElement("div", null, binding.provider_username || "-"), a.a.createElement("div", {
                            className: "text-muted font-size-sm"
                        }, binding.provider_email || ""))
                    })), a.a.createElement("td", {
                        className: "v2b-oauth-cell-plan"
                    }, a.a.createElement("div", null, row.plan_name || "无订阅"), a.a.createElement("div", {
                        className: "text-muted font-size-sm"
                    }, formatBytes(row.total_used), " / ", formatBytes(row.transfer_enable)), a.a.createElement("div", {
                        className: "text-muted font-size-sm"
                    }, "到期: ", formatTime(row.expired_at))), a.a.createElement("td", {
                        className: "v2b-oauth-cell-online"
                    }, a.a.createElement("div", {
                        className: "mb-1"
                    }, isOnline ? a.a.createElement("span", {
                        className: "badge badge-success"
                    }, "在线") : a.a.createElement("span", {
                        className: "badge badge-secondary"
                    }, "离线")), a.a.createElement("div", {
                        className: "font-w600",
                        title: onlineIps || "暂无在线 IP"
                    }, "设备: ", deviceText), onlineIps ? a.a.createElement("div", {
                        className: "text-muted font-size-sm text-truncate",
                        style: {
                            maxWidth: 180
                        },
                        title: onlineIps
                    }, onlineIps) : a.a.createElement("div", {
                        className: "text-muted font-size-sm"
                    }, "无在线 IP"), row.last_login_at ? a.a.createElement("div", {
                        className: "text-muted font-size-sm"
                    }, "最近登录: ", formatTime(row.last_login_at)) : null), a.a.createElement("td", {
                        className: "v2b-oauth-cell-status"
                    }, Number(row.banned) === 1 ? a.a.createElement("span", {
                        className: "badge badge-danger"
                    }, "已封禁") : a.a.createElement("span", {
                        className: "badge badge-success"
                    }, "正常")), a.a.createElement("td", {
                        className: "v2b-oauth-actions"
                    }, a.a.createElement("div", {
                        className: "v2b-oauth-actions-inner"
                    }, a.a.createElement("button", {
                        type: "button",
                        className: "btn btn-sm btn-alt-primary",
                        onClick: function() {
                            self.openEdit(row)
                        }
                    }, "编辑"), bindings.map(function(binding) {
                        return a.a.createElement("button", {
                            key: "u-" + binding.id,
                            type: "button",
                            className: "btn btn-sm btn-alt-warning",
                            title: "解绑 " + (binding.provider_name || binding.provider),
                            onClick: function() {
                                self.doUnbind({
                                    id: binding.id,
                                    binding_id: binding.binding_id || binding.id,
                                    user_id: row.user_id,
                                    email: row.email
                                }, false)
                            }
                        }, "解绑", bindings.length > 1 ? (" " + (binding.provider_name || binding.provider)) : "")
                    }), a.a.createElement("button", {
                        type: "button",
                        className: "btn btn-sm btn-alt-secondary",
                        onClick: function() {
                            self.doResetSecret(row)
                        }
                    }, "重置密钥"), a.a.createElement("button", {
                        type: "button",
                        className: "btn btn-sm btn-alt-danger",
                        onClick: function() {
                            self.doBan(row)
                        }
                    }, "封禁"), row.is_oauth_managed ? a.a.createElement("button", {
                        type: "button",
                        className: "btn btn-sm btn-danger",
                        onClick: function() {
                            self.doDelete(row)
                        }
                    }, "删除") : a.a.createElement("button", {
                        type: "button",
                        className: "btn btn-sm btn-alt-secondary",
                        title: "邮箱用户请到用户管理删除账号",
                        disabled: true
                    }, "删除(用户管理)"))))
                }) : a.a.createElement("tr", null, a.a.createElement("td", {
                    colSpan: 8,
                    className: "text-center text-muted py-4"
                }, this.state.loading ? "加载中..." : "暂无第三方绑定记录"))))), this.renderPager())
            }
            renderPager() {
                var self = this;
                var total = this.state.total || 0;
                var pageSize = this.state.pageSize || 10;
                var current = this.state.current || 1;
                var pages = Math.max(1, Math.ceil(total / pageSize));
                return a.a.createElement("div", {
                    className: "card-footer d-flex justify-content-between align-items-center"
                }, a.a.createElement("div", null, "第 ", current, " / ", pages, " 页"), a.a.createElement("div", null, a.a.createElement("button", {
                    type: "button",
                    className: "btn btn-sm btn-alt-secondary mr-2",
                    disabled: current <= 1,
                    onClick: function() {
                        self.setState({
                            current: current - 1
                        }, self.load)
                    }
                }, "上一页"), a.a.createElement("button", {
                    type: "button",
                    className: "btn btn-sm btn-alt-secondary",
                    disabled: current >= pages,
                    onClick: function() {
                        self.setState({
                            current: current + 1
                        }, self.load)
                    }
                }, "下一页")))
            }
            renderEditModal() {
                var self = this;
                if (!this.state.editVisible)
                    return null;
                var record = this.state.editRecord || {};
                var plans = this.state.plans || [];
                var aliveIpCount = Number(record.alive_ip || 0);
                var isOnline = record.is_online === true || record.is_online === 1 || aliveIpCount > 0;
                var deviceLimitLabel = record.device_limit === "" || record.device_limit === null || record.device_limit === undefined ? "∞" : String(record.device_limit);
                return a.a.createElement("div", {
                    className: "v2b-oauth-modal-mask"
                }, a.a.createElement("div", {
                    className: "v2b-oauth-modal v2b-oauth-modal-lg"
                }, a.a.createElement("div", {
                    className: "v2b-oauth-modal-header"
                }, a.a.createElement("h4", null, "编辑用户 #", record.id), a.a.createElement("button", {
                    type: "button",
                    className: "btn btn-sm btn-alt-secondary",
                    onClick: function() {
                        self.setState({
                            editVisible: false
                        })
                    }
                }, "关闭")), a.a.createElement("div", {
                    className: "v2b-oauth-modal-body"
                }, a.a.createElement("div", {
                    className: "mb-3 p-2 border rounded bg-light"
                }, a.a.createElement("div", {
                    className: "d-flex flex-wrap align-items-center mb-1"
                }, isOnline ? a.a.createElement("span", {
                    className: "badge badge-success mr-2"
                }, "在线") : a.a.createElement("span", {
                    className: "badge badge-secondary mr-2"
                }, "离线"), a.a.createElement("span", {
                    className: "mr-3"
                }, "设备: ", aliveIpCount, " / ", deviceLimitLabel), record.last_login_at ? a.a.createElement("span", {
                    className: "text-muted font-size-sm"
                }, "最近登录: ", formatTime(record.last_login_at)) : null), record.ips ? a.a.createElement("div", {
                    className: "text-muted font-size-sm text-break"
                }, "在线IP: ", record.ips) : a.a.createElement("div", {
                    className: "text-muted font-size-sm"
                }, "无在线 IP"), record.subscribe_url ? a.a.createElement("div", {
                    className: "text-muted font-size-sm text-break mt-1"
                }, "订阅: ", record.subscribe_url) : null), a.a.createElement("div", {
                    className: "mb-3"
                }, a.a.createElement("div", {
                    className: "font-w600 mb-1"
                }, "绑定列表"), (record.bindings || []).length ? (record.bindings || []).map(function(b) {
                    return a.a.createElement("div", {
                        key: b.id,
                        className: "d-flex align-items-center mb-1"
                    }, a.a.createElement("span", {
                        className: "badge badge-primary mr-2"
                    }, b.provider_name, ": ", b.external_id_label, "=", b.external_id), a.a.createElement("button", {
                        type: "button",
                        className: "btn btn-xs btn-alt-warning",
                        onClick: function() {
                            self.doUnbind({
                                id: b.id,
                                binding_id: b.id
                            }, false)
                        }
                    }, "解绑"))
                }) : a.a.createElement("span", {
                    className: "text-muted"
                }, "无绑定")), a.a.createElement("div", {
                    className: "form-group"
                }, a.a.createElement("label", null, "邮箱"), a.a.createElement("input", {
                    className: "form-control",
                    value: record.email || "",
                    onChange: function(e) {
                        self.setEditField("email", e.target.value)
                    }
                })), a.a.createElement("div", {
                    className: "form-group"
                }, a.a.createElement("label", null, "邀请人邮箱"), a.a.createElement("input", {
                    className: "form-control",
                    placeholder: "请输入邀请人邮箱",
                    value: record.invite_user_email || "",
                    onChange: function(e) {
                        self.setEditField("invite_user_email", e.target.value)
                    }
                })), a.a.createElement("div", {
                    className: "form-group"
                }, a.a.createElement("label", null, "密码"), a.a.createElement("input", {
                    type: "password",
                    className: "form-control",
                    placeholder: "如需修改密码请输入",
                    value: record.password || "",
                    onChange: function(e) {
                        self.setEditField("password", e.target.value)
                    }
                })), a.a.createElement("div", {
                    className: "form-row"
                }, a.a.createElement("div", {
                    className: "form-group col-md-6"
                }, a.a.createElement("label", null, "余额 (¥)"), a.a.createElement("input", {
                    type: "number",
                    step: "0.01",
                    className: "form-control",
                    value: record.balance,
                    onChange: function(e) {
                        self.setEditField("balance", e.target.value)
                    }
                })), a.a.createElement("div", {
                    className: "form-group col-md-6"
                }, a.a.createElement("label", null, "推广佣金 (¥)"), a.a.createElement("input", {
                    type: "number",
                    step: "0.01",
                    className: "form-control",
                    value: record.commission_balance,
                    onChange: function(e) {
                        self.setEditField("commission_balance", e.target.value)
                    }
                }))), a.a.createElement("div", {
                    className: "form-row"
                }, a.a.createElement("div", {
                    className: "form-group col-md-6"
                }, a.a.createElement("label", null, "已用上行 (GB)"), a.a.createElement("input", {
                    type: "number",
                    step: "0.01",
                    className: "form-control",
                    value: record.u,
                    onChange: function(e) {
                        self.setEditField("u", e.target.value)
                    }
                })), a.a.createElement("div", {
                    className: "form-group col-md-6"
                }, a.a.createElement("label", null, "已用下行 (GB)"), a.a.createElement("input", {
                    type: "number",
                    step: "0.01",
                    className: "form-control",
                    value: record.d,
                    onChange: function(e) {
                        self.setEditField("d", e.target.value)
                    }
                }))), a.a.createElement("div", {
                    className: "form-row"
                }, a.a.createElement("div", {
                    className: "form-group col-md-6"
                }, a.a.createElement("label", null, "流量 (GB)"), a.a.createElement("input", {
                    type: "number",
                    step: "0.01",
                    className: "form-control",
                    value: record.transfer_enable,
                    onChange: function(e) {
                        self.setEditField("transfer_enable", e.target.value)
                    }
                })), a.a.createElement("div", {
                    className: "form-group col-md-6"
                }, a.a.createElement("label", null, "设备数限制"), a.a.createElement("input", {
                    type: "number",
                    className: "form-control",
                    placeholder: "留空则不限制",
                    value: record.device_limit == null ? "" : record.device_limit,
                    onChange: function(e) {
                        self.setEditField("device_limit", e.target.value)
                    }
                }))), a.a.createElement("div", {
                    className: "form-group"
                }, a.a.createElement("label", null, "到期时间戳（秒，空=长期有效）"), a.a.createElement("input", {
                    type: "number",
                    className: "form-control",
                    placeholder: "长期有效",
                    value: record.expired_at == null || record.expired_at === "" ? "" : record.expired_at,
                    onChange: function(e) {
                        self.setEditField("expired_at", e.target.value === "" ? "" : Number(e.target.value))
                    }
                }), a.a.createElement("div", {
                    className: "text-muted font-size-sm mt-1"
                }, "预览: ", formatTime(record.expired_at === "" ? null : record.expired_at))), a.a.createElement("div", {
                    className: "form-group"
                }, a.a.createElement("label", null, "订阅计划"), a.a.createElement("select", {
                    className: "form-control",
                    value: record.plan_id || "",
                    onChange: function(e) {
                        self.setEditField("plan_id", e.target.value ? Number(e.target.value) : null)
                    }
                }, a.a.createElement("option", {
                    value: ""
                }, "无"), plans.map(function(plan) {
                    return a.a.createElement("option", {
                        key: plan.id,
                        value: plan.id
                    }, plan.name)
                }))), a.a.createElement("div", {
                    className: "form-group"
                }, a.a.createElement("label", null, "账户状态"), a.a.createElement("select", {
                    className: "form-control",
                    value: Number(record.banned) ? 1 : 0,
                    onChange: function(e) {
                        self.setEditField("banned", Number(e.target.value) ? 1 : 0)
                    }
                }, a.a.createElement("option", {
                    value: 0
                }, "正常"), a.a.createElement("option", {
                    value: 1
                }, "封禁"))), a.a.createElement("div", {
                    className: "form-group"
                }, a.a.createElement("label", null, "推荐返利类型"), a.a.createElement("select", {
                    className: "form-control",
                    value: Number(record.commission_type || 0),
                    onChange: function(e) {
                        self.setEditField("commission_type", Number(e.target.value))
                    }
                }, a.a.createElement("option", {
                    value: 0
                }, "跟随系统设置"), a.a.createElement("option", {
                    value: 1
                }, "循环返利"), a.a.createElement("option", {
                    value: 2
                }, "首次返利"))), a.a.createElement("div", {
                    className: "form-row"
                }, a.a.createElement("div", {
                    className: "form-group col-md-6"
                }, a.a.createElement("label", null, "推荐返利比例 (%)"), a.a.createElement("input", {
                    type: "number",
                    min: 0,
                    max: 100,
                    className: "form-control",
                    placeholder: "为空则跟随站点设置返利比例",
                    value: record.commission_rate == null ? "" : record.commission_rate,
                    onChange: function(e) {
                        self.setEditField("commission_rate", e.target.value)
                    }
                })), a.a.createElement("div", {
                    className: "form-group col-md-6"
                }, a.a.createElement("label", null, "专享折扣比例 (%)"), a.a.createElement("input", {
                    type: "number",
                    min: 0,
                    max: 100,
                    className: "form-control",
                    placeholder: "设置后该用户购买任何订阅将始终享受该折扣",
                    value: record.discount == null ? "" : record.discount,
                    onChange: function(e) {
                        self.setEditField("discount", e.target.value)
                    }
                }))), a.a.createElement("div", {
                    className: "form-group"
                }, a.a.createElement("label", null, "限速 (Mbps)"), a.a.createElement("input", {
                    type: "number",
                    className: "form-control",
                    placeholder: "留空则不限制",
                    value: record.speed_limit == null ? "" : record.speed_limit,
                    onChange: function(e) {
                        self.setEditField("speed_limit", e.target.value)
                    }
                })), a.a.createElement("div", {
                    className: "form-row"
                }, a.a.createElement("div", {
                    className: "form-group col-md-6"
                }, a.a.createElement("label", null, a.a.createElement("input", {
                    type: "checkbox",
                    className: "mr-1",
                    checked: !!Number(record.is_admin),
                    onChange: function(e) {
                        self.setEditField("is_admin", e.target.checked ? 1 : 0)
                    }
                }), " 是否管理员")), a.a.createElement("div", {
                    className: "form-group col-md-6"
                }, a.a.createElement("label", null, a.a.createElement("input", {
                    type: "checkbox",
                    className: "mr-1",
                    checked: !!Number(record.is_staff),
                    onChange: function(e) {
                        self.setEditField("is_staff", e.target.checked ? 1 : 0)
                    }
                }), " 是否员工"))), a.a.createElement("div", {
                    className: "form-group"
                }, a.a.createElement("label", null, "备注"), a.a.createElement("textarea", {
                    className: "form-control",
                    rows: 4,
                    placeholder: "请在这里记录..",
                    value: record.remarks || "",
                    onChange: function(e) {
                        self.setEditField("remarks", e.target.value)
                    }
                })), record.uuid || record.token ? a.a.createElement("div", {
                    className: "form-row"
                }, a.a.createElement("div", {
                    className: "form-group col-md-6"
                }, a.a.createElement("label", null, "UUID"), a.a.createElement("input", {
                    className: "form-control",
                    readOnly: true,
                    value: record.uuid || ""
                })), a.a.createElement("div", {
                    className: "form-group col-md-6"
                }, a.a.createElement("label", null, "TOKEN"), a.a.createElement("input", {
                    className: "form-control",
                    readOnly: true,
                    value: record.token || ""
                }))) : null), a.a.createElement("div", {
                    className: "v2b-oauth-modal-footer"
                }, a.a.createElement("button", {
                    type: "button",
                    className: "btn btn-alt-secondary mr-2",
                    onClick: function() {
                        self.setState({
                            editVisible: false
                        })
                    }
                }, "取消"), a.a.createElement("button", {
                    type: "button",
                    className: "btn btn-primary",
                    disabled: this.state.saving,
                    onClick: function() {
                        self.saveEdit()
                    }
                }, this.state.saving ? "保存中..." : "提交"))))
            }
            renderMailModal() {
                var self = this;
                if (!this.state.mailVisible)
                    return null;
                return a.a.createElement("div", {
                    className: "v2b-oauth-modal-mask"
                }, a.a.createElement("div", {
                    className: "v2b-oauth-modal"
                }, a.a.createElement("div", {
                    className: "v2b-oauth-modal-header"
                }, a.a.createElement("h4", null, "向筛选结果群发邮件"), a.a.createElement("button", {
                    type: "button",
                    className: "btn btn-sm btn-alt-secondary",
                    onClick: function() {
                        self.setState({
                            mailVisible: false
                        })
                    }
                }, "关闭")), a.a.createElement("div", {
                    className: "v2b-oauth-modal-body"
                }, a.a.createElement("div", {
                    className: "form-group"
                }, a.a.createElement("label", null, "主题"), a.a.createElement("input", {
                    className: "form-control",
                    value: this.state.mailSubject,
                    onChange: function(e) {
                        self.setState({
                            mailSubject: e.target.value
                        })
                    }
                })), a.a.createElement("div", {
                    className: "form-group"
                }, a.a.createElement("label", null, "内容"), a.a.createElement("textarea", {
                    className: "form-control",
                    rows: 5,
                    value: this.state.mailContent,
                    onChange: function(e) {
                        self.setState({
                            mailContent: e.target.value
                        })
                    }
                }))), a.a.createElement("div", {
                    className: "v2b-oauth-modal-footer"
                }, a.a.createElement("button", {
                    type: "button",
                    className: "btn btn-primary",
                    onClick: function() {
                        self.sendMail()
                    }
                }, "发送"))))
            }
            render() {
                var props = i()({}, this.props, {
                    title: "OAuth 管理",
                    loading: !!this.state.loading
                });
                return a.a.createElement(s["a"], props, a.a.createElement("div", {
                    className: "content v2b-oauth-manage"
                }, a.a.createElement("div", {
                    className: "alert alert-info"
                }, "展示全部第三方绑定：含「OAuth 自动注册」与「邮箱用户后绑定」。标签可区分类型；邮箱用户删除账号请到用户管理，此处可解绑。"), this.renderFilters(), this.renderTable(), this.renderEditModal(), this.renderMailModal()))
            }
        }
        t["default"] = Object(l["c"])(function(state) {
            return {
                user: state.user
            }
        })(OauthManage)
    }