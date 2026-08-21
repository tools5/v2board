    v2bLoginSettings: function(e, t, n) {
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
        function adminEndpoint(e) {
            var t = window.settings && window.settings.secure_path ? window.settings.secure_path : "";
            return "/" + (t ? t + "/" : "") + String(e || "").replace(/^\/+/, "")
        }
        function fallbackCopy(e) {
            var t = document.createElement("textarea");
            t.value = e;
            t.setAttribute("readonly", "readonly");
            t.style.position = "fixed";
            t.style.opacity = "0";
            document.body.appendChild(t);
            t.select();
            var n = document.execCommand("copy");
            document.body.removeChild(t);
            if (!n)
                throw new Error("copy failed")
        }
        function copyText(e) {
            if (navigator.clipboard && navigator.clipboard.writeText)
                return navigator.clipboard.writeText(e).catch(function() {
                    fallbackCopy(e)
                });
            try {
                fallbackCopy(e);
                return Promise.resolve()
            } catch (t) {
                return Promise.reject(t)
            }
        }
        function Row(key, id, label, desc, control) {
            var describedBy = desc && id ? id + "-help" : void 0;
            return a.a.createElement("div", {
                key: key,
                className: "v2b-login-field row align-items-center"
            }, a.a.createElement("div", {
                className: "col-lg-6"
            }, id ? a.a.createElement("label", {
                className: "v2b-login-field-label",
                htmlFor: id
            }, label) : a.a.createElement("div", {
                className: "v2b-login-field-label"
            }, label), desc ? a.a.createElement("div", {
                id: describedBy,
                className: "v2b-login-field-help"
            }, desc) : null), a.a.createElement("div", {
                className: "col-lg-6 v2b-login-field-control"
            }, "function" === typeof control ? control(describedBy) : control))
        }
        function Switch(checked, onChange, label, disabled) {
            var isOn = !!checked;
            return a.a.createElement("button", {
                type: "button",
                role: "switch",
                "aria-checked": isOn,
                "aria-label": label,
                disabled: !!disabled,
                className: isOn ? "ant-switch ant-switch-checked" : "ant-switch",
                onClick: function() {
                    onChange && onChange({
                        target: {
                            checked: !isOn
                        }
                    })
                }
            }, a.a.createElement("span", {
                className: "ant-switch-inner"
            }))
        }
        class LoginSettings extends a.a.Component {
            constructor(e) {
                super(e);
                this.state = {
                    loading: true,
                    saving: false,
                    loadError: false,
                    providers: [],
                    values: {},
                    expanded: {},
                    customCallbacks: {},
                    clearSecrets: {},
                    dirty: false
                };
                this.unmounted = false;
                this.loadProviders = this.loadProviders.bind(this);
                this.onSaveClick = this.onSaveClick.bind(this)
            }
            componentDidMount() {
                this.loadProviders()
            }
            componentWillUnmount() {
                this.unmounted = true
            }
            loadProviders() {
                var self = this;
                this.setState({
                    loading: true,
                    loadError: false
                });
                // 同时读取 login 段（providers + oauth_app_scheme）与 safe 段（cap_* 验证码配置）
                Promise.all([Object(c["a"])(adminEndpoint("config/fetch"), {
                    key: "login"
                }), Object(c["a"])(adminEndpoint("config/fetch"), {
                    key: "safe"
                })]).then(function(results) {
                    var res = results[0];
                    var safeRes = results[1];
                    var data = res && 200 === res.code && res.data && res.data.login;
                    if (!data || !Array.isArray(data.providers))
                        throw new Error("invalid login settings response");
                    // safe 段缺失视为加载失败，避免保存时把 cap_* 误清空
                    var safe = safeRes && 200 === safeRes.code && safeRes.data && safeRes.data.safe;
                    if (!safe)
                        throw new Error("invalid safe settings response");
                    var providers = data.providers;
                    var values = {};
                    var expanded = {};
                    var customCallbacks = {};
                    var clearSecrets = {};
                    providers.forEach(function(p) {
                        var f = p.fields || {};
                        if (f.enable_key)
                            values[f.enable_key] = parseInt(p.enable, 10) ? 1 : 0;
                        if (f.client_id_key)
                            values[f.client_id_key] = String(p.client_id == null ? "" : p.client_id);
                        if (f.client_secret_key)
                            values[f.client_secret_key] = "";
                        if (f.bot_username_key)
                            values[f.bot_username_key] = String(p.bot_username == null ? "" : p.bot_username);
                        if (f.bot_token_key)
                            values[f.bot_token_key] = "";
                        if (f.auto_register_key)
                            values[f.auto_register_key] = parseInt(p.auto_register == null ? 1 : p.auto_register, 10) ? 1 : 0;
                        if (f.min_trust_level_key)
                            values[f.min_trust_level_key] = parseInt(p.min_trust_level || 0, 10) || 0;
                        if (f.callback_url_key)
                            values[f.callback_url_key] = String(p.callback_url == null ? "" : p.callback_url);
                        expanded[p.provider] = false;
                        customCallbacks[p.provider] = !!String(p.callback_url == null ? "" : p.callback_url).trim();
                        clearSecrets[p.provider] = false
                    });
                    // 应用回调 scheme 与 Cap 验证码并入 values，统一由 config/save 保存
                    values.oauth_app_scheme = String(data.oauth_app_scheme == null ? "" : data.oauth_app_scheme);
                    values.cap_enable = parseInt(safe.cap_enable, 10) ? 1 : 0;
                    values.cap_endpoint = String(safe.cap_endpoint == null ? "" : safe.cap_endpoint);
                    values.cap_site_key = String(safe.cap_site_key == null ? "" : safe.cap_site_key);
                    values.cap_secret_key = String(safe.cap_secret_key == null ? "" : safe.cap_secret_key);
                    if (self.unmounted)
                        return;
                    self.setState({
                        loading: false,
                        loadError: false,
                        providers: providers,
                        values: values,
                        expanded: expanded,
                        customCallbacks: customCallbacks,
                        clearSecrets: clearSecrets,
                        dirty: false
                    })
                }).catch(function() {
                    if (self.unmounted)
                        return;
                    self.setState({
                        loading: false,
                        loadError: true
                    });
                    msg["a"].error("\u767b\u5f55\u8bbe\u7f6e\u52a0\u8f7d\u5931\u8d25")
                })
            }
            setValue(key, val) {
                this.setState(function(state) {
                    var values = i()({}, state.values);
                    values[key] = val;
                    return {
                        values: values,
                        dirty: true
                    }
                })
            }
            toggleExpand(key) {
                this.setState(function(state) {
                    var shouldOpen = !state.expanded[key];
                    var expanded = {};
                    state.providers.forEach(function(p) {
                        expanded[p.provider] = false
                    });
                    expanded[key] = shouldOpen;
                    return {
                        expanded: expanded
                    }
                })
            }
            setProviderEnabled(p, enabled) {
                var f = p.fields || {};
                this.setState(function(state) {
                    var values = i()({}, state.values);
                    var expanded = i()({}, state.expanded);
                    values[f.enable_key] = enabled ? 1 : 0;
                    return {
                        values: values,
                        expanded: expanded,
                        dirty: true
                    }
                })
            }
            setCustomCallback(p, enabled) {
                var f = p.fields || {};
                this.setState(function(state) {
                    var customCallbacks = i()({}, state.customCallbacks);
                    var values = i()({}, state.values);
                    customCallbacks[p.provider] = enabled;
                    if (f.callback_url_key)
                        values[f.callback_url_key] = enabled ? values[f.callback_url_key] || p.default_callback_url || "" : "";
                    return {
                        customCallbacks: customCallbacks,
                        values: values,
                        dirty: true
                    }
                })
            }
            setSecretCleared(p, cleared) {
                var f = p.fields || {};
                this.setState(function(state) {
                    var clearSecrets = i()({}, state.clearSecrets);
                    var values = i()({}, state.values);
                    clearSecrets[p.provider] = cleared;
                    if (f.client_secret_key)
                        values[f.client_secret_key] = "";
                    if (f.bot_token_key)
                        values[f.bot_token_key] = "";
                    return {
                        clearSecrets: clearSecrets,
                        values: values,
                        dirty: true
                    }
                })
            }
            matchesCondition(p, condition) {
                if (!condition)
                    return true;
                var f = p.fields || {};
                var sourceKey = f[condition.field] || condition.field;
                var value = this.state.values[sourceKey];
                if ("equals" === condition.operator)
                    return String(value == null ? "" : value) === String(condition.value == null ? "" : condition.value);
                if ("not_empty" === condition.operator)
                    return String(value == null ? "" : value).trim() !== "";
                return !!value
            }
            isProviderReady(p) {
                var f = p.fields || {};
                var values = this.state.values;
                if (!parseInt(values[f.enable_key], 10))
                    return false;
                if (p.auth_type === "telegram_login_widget") {
                    var username = f.bot_username_key ? String(values[f.bot_username_key] || "").trim() : "";
                    var enteredToken = f.bot_token_key ? String(values[f.bot_token_key] || "").trim() : "";
                    var hasToken = true;
                    if (f.bot_token_key) {
                        // 已输入新 token：可用
                        // 清除登录专用 token：仅当存在系统回退 token 时可用
                        // 未清除：已有专用配置或系统回退均可
                        if (enteredToken !== "")
                            hasToken = true;
                        else if (this.state.clearSecrets[p.provider])
                            hasToken = !!p.bot_token_fallback_configured;
                        else
                            hasToken = !!p.bot_token_configured || !!p.bot_token_fallback_configured
                    }
                    return username !== "" && hasToken
                }
                var hasClientId = !f.client_id_key || String(values[f.client_id_key] || "").trim() !== "";
                var enteredSecret = f.client_secret_key ? String(values[f.client_secret_key] || "").trim() : "";
                var hasSecret = !f.client_secret_key || !this.state.clearSecrets[p.provider] && (!!p.client_secret_configured || enteredSecret !== "");
                return hasClientId && hasSecret
            }
            providerStatus(p) {
                var f = p.fields || {};
                if (!parseInt(this.state.values[f.enable_key], 10))
                    return {
                        text: "\u672a\u542f\u7528",
                        className: "badge badge-secondary"
                    };
                if (!this.isProviderReady(p))
                    return {
                        text: "\u5f85\u5b8c\u5584",
                        className: "badge badge-warning"
                    };
                return {
                    text: "\u5df2\u542f\u7528",
                    className: "badge badge-success"
                }
            }
            collectPayload() {
                var values = this.state.values;
                var payload = {};
                var self = this;
                this.state.providers.forEach(function(p) {
                    var f = p.fields || {};
                    if (f.enable_key)
                        payload[f.enable_key] = parseInt(values[f.enable_key], 10) ? 1 : 0;
                    if (f.client_id_key)
                        payload[f.client_id_key] = values[f.client_id_key] || "";
                    if (f.client_secret_key) {
                        var secret = String(values[f.client_secret_key] || "");
                        if (self.state.clearSecrets[p.provider])
                            payload[f.client_secret_key] = "";
                        else if (secret !== "")
                            payload[f.client_secret_key] = secret
                    }
                    if (f.bot_username_key)
                        payload[f.bot_username_key] = String(values[f.bot_username_key] || "").replace(/^@+/, "");
                    if (f.bot_token_key) {
                        var botToken = String(values[f.bot_token_key] || "");
                        if (self.state.clearSecrets[p.provider])
                            payload[f.bot_token_key] = "";
                        else if (botToken !== "")
                            payload[f.bot_token_key] = botToken
                    }
                    if (f.auto_register_key)
                        payload[f.auto_register_key] = parseInt(values[f.auto_register_key], 10) ? 1 : 0;
                    if (f.min_trust_level_key)
                        payload[f.min_trust_level_key] = parseInt(values[f.min_trust_level_key] || 0, 10) || 0;
                    if (f.callback_url_key)
                        payload[f.callback_url_key] = (values[f.callback_url_key] || "").trim()
                });
                // 应用回调 scheme（客户端深链白名单）
                payload.oauth_app_scheme = String(values.oauth_app_scheme || "").trim();
                // Cap 自托管验证码；cap_secret_key 已预填原值，按当前输入原样保存（清空即清除）
                payload.cap_enable = parseInt(values.cap_enable, 10) ? 1 : 0;
                payload.cap_endpoint = String(values.cap_endpoint || "").trim();
                payload.cap_site_key = String(values.cap_site_key || "").trim();
                payload.cap_secret_key = String(values.cap_secret_key || "").trim();
                return payload
            }
            validateBeforeSave() {
                var values = this.state.values;
                for (var index = 0; index < this.state.providers.length; index++) {
                    var p = this.state.providers[index];
                    var f = p.fields || {};
                    if (!parseInt(values[f.enable_key], 10))
                        continue;
                    if (p.auth_type === "telegram_login_widget") {
                        if (f.bot_username_key && String(values[f.bot_username_key] || "").trim() === "")
                            return "\u542f\u7528 " + (p.name || p.provider) + " \u524d\u8bf7\u586b\u5199 Bot Username";
                        var botToken = f.bot_token_key ? String(values[f.bot_token_key] || "").trim() : "";
                        if (f.bot_token_key) {
                            var hasUsableToken = false;
                            if (botToken !== "")
                                hasUsableToken = true;
                            else if (this.state.clearSecrets[p.provider])
                                // 清除登录专用 Token：允许回退系统 Token，禁止在无回退时保存
                                hasUsableToken = !!p.bot_token_fallback_configured;
                            else
                                hasUsableToken = !!p.bot_token_configured || !!p.bot_token_fallback_configured;
                            if (!hasUsableToken)
                                return "\u542f\u7528 " + (p.name || p.provider) + " \u524d\u8bf7\u586b\u5199 Bot Token\uff08\u6216\u5148\u5728\u7cfb\u7edf Telegram \u914d\u7f6e Bot Token\uff09"
                        }
                        continue
                    }
                    if (f.client_id_key && String(values[f.client_id_key] || "").trim() === "")
                        return "\u542f\u7528 " + (p.name || p.provider) + " \u524d\u8bf7\u586b\u5199 Client ID";
                    var secret = f.client_secret_key ? String(values[f.client_secret_key] || "").trim() : "";
                    if (f.client_secret_key && (this.state.clearSecrets[p.provider] || !p.client_secret_configured && secret === ""))
                        return "\u542f\u7528 " + (p.name || p.provider) + " \u524d\u8bf7\u586b\u5199 Client Secret"
                }
                if (parseInt(values.cap_enable, 10)) {
                    if (String(values.cap_endpoint || "").trim() === "")
                        return "\u542f\u7528 Cap \u9a8c\u8bc1\u7801\u524d\u8bf7\u586b\u5199\u670d\u52a1\u7aef\u5730\u5740";
                    if (String(values.cap_site_key || "").trim() === "")
                        return "\u542f\u7528 Cap \u9a8c\u8bc1\u7801\u524d\u8bf7\u586b\u5199 Site Key";
                    if (String(values.cap_secret_key || "").trim() === "")
                        return "\u542f\u7528 Cap \u9a8c\u8bc1\u7801\u524d\u8bf7\u586b\u5199 Secret Key"
                }
                return ""
            }
            save() {
                var self = this;
                if (this.state.saving || !this.state.dirty)
                    return;
                var validationError = this.validateBeforeSave();
                if (validationError) {
                    msg["a"].error(validationError);
                    return
                }
                var payload = this.collectPayload();
                this.setState({
                    saving: true
                });
                Object(c["b"])(adminEndpoint("config/save"), payload).then(function(res) {
                    if (self.unmounted)
                        return;
                    if (!res || 200 !== res.code) {
                        self.setState({
                            saving: false
                        });
                        return
                    }
                    self.setState(function(state) {
                        var providers = state.providers.map(function(p) {
                            var next = i()({}, p);
                            var secretKey = (p.fields || {}).client_secret_key;
                            if (secretKey && Object.prototype.hasOwnProperty.call(payload, secretKey))
                                next.client_secret_configured = String(payload[secretKey] || "").trim() !== "";
                            var botTokenKey = (p.fields || {}).bot_token_key;
                            if (botTokenKey && Object.prototype.hasOwnProperty.call(payload, botTokenKey))
                                next.bot_token_configured = String(payload[botTokenKey] || "").trim() !== "";
                            return next
                        });
                        var values = i()({}, state.values);
                        var clearSecrets = i()({}, state.clearSecrets);
                        providers.forEach(function(p) {
                            var secretKey = (p.fields || {}).client_secret_key;
                            if (secretKey && Object.prototype.hasOwnProperty.call(payload, secretKey))
                                values[secretKey] = "";
                            var botTokenKey = (p.fields || {}).bot_token_key;
                            if (botTokenKey && Object.prototype.hasOwnProperty.call(payload, botTokenKey))
                                values[botTokenKey] = "";
                            clearSecrets[p.provider] = false
                        });
                        return {
                            saving: false,
                            providers: providers,
                            values: values,
                            clearSecrets: clearSecrets,
                            dirty: false
                        }
                    });
                    msg["a"].success("\u767b\u5f55\u8bbe\u7f6e\u5df2\u4fdd\u5b58")
                }).catch(function() {
                    if (self.unmounted)
                        return;
                    self.setState({
                        saving: false
                    });
                    msg["a"].error("\u4fdd\u5b58\u5931\u8d25")
                })
            }
            onSaveClick() {
                this.save()
            }
            copyCallback(url) {
                copyText(url).then(function() {
                    msg["a"].success("\u56de\u8c03\u5730\u5740\u5df2\u590d\u5236")
                }).catch(function() {
                    msg["a"].error("\u590d\u5236\u5931\u8d25\uff0c\u8bf7\u624b\u52a8\u590d\u5236\u56de\u8c03\u5730\u5740")
                })
            }
            renderDetail(p) {
                var self = this;
                var f = p.fields || {};
                var values = this.state.values;
                var disabled = this.state.saving;
                var prefix = "oauth-" + String(p.provider || "provider").replace(/[^a-z0-9_-]/gi, "-");
                var rows = [];
                if (f.bot_username_key)
                    rows.push(Row("bot-user", prefix + "-bot-username", "Bot Username", "Telegram Bot \u7528\u6237\u540d\uff08\u4e0d\u542b @\uff09\uff0c\u9700\u5728 BotFather /setdomain \u914d\u7f6e\u672c\u7ad9\u57df\u540d", function(helpId) {
                        return a.a.createElement("input", {
                            id: prefix + "-bot-username",
                            type: "text",
                            className: "form-control",
                            value: values[f.bot_username_key] || "",
                            placeholder: "YourBot",
                            disabled: disabled,
                            "aria-describedby": helpId,
                            onChange: function(e) {
                                self.setValue(f.bot_username_key, e.target.value)
                            }
                        })
                    }));
                if (f.bot_token_key) {
                    var secretClearedBot = !!this.state.clearSecrets[p.provider];
                    var botConfigured = !!p.bot_token_configured;
                    var botFallback = !!p.bot_token_fallback_configured;
                    var botHelp = botConfigured ? "\u5df2\u914d\u7f6e\u767b\u5f55\u4e13\u7528 Token\uff0c\u7559\u7a7a\u8868\u793a\u4fdd\u6301\u4e0d\u53d8" : botFallback ? "\u5f53\u524d\u5c06\u590d\u7528\u7cfb\u7edf Telegram Bot Token\uff1b\u53ef\u9009\u586b\u5199\u72ec\u7acb Token" : "\u767b\u5f55\u4e13\u7528 Bot Token\uff1b\u4e5f\u53ef\u5148\u5728\u7cfb\u7edf Telegram \u914d\u7f6e Token \u540e\u7559\u7a7a";
                    rows.push(Row("bot-token", prefix + "-bot-token", "Bot Token", botHelp, function(helpId) {
                        return a.a.createElement("div", {
                            className: "v2b-secret-control"
                        }, a.a.createElement("input", {
                            id: prefix + "-bot-token",
                            type: "password",
                            autoComplete: "new-password",
                            className: "form-control",
                            value: values[f.bot_token_key] || "",
                            placeholder: secretClearedBot ? "\u4fdd\u5b58\u540e\u5c06\u6e05\u9664\u767b\u5f55\u4e13\u7528 Token\uff08\u53ef\u56de\u9000\u7cfb\u7edf Token\uff09" : "",
                            disabled: disabled || secretClearedBot,
                            "aria-describedby": helpId,
                            onChange: function(e) {
                                self.setValue(f.bot_token_key, e.target.value)
                            }
                        }), botConfigured || secretClearedBot ? a.a.createElement("button", {
                            type: "button",
                            className: secretClearedBot ? "btn btn-sm btn-alt-secondary ml-2" : "btn btn-sm btn-alt-danger ml-2",
                            disabled: disabled,
                            onClick: function() {
                                self.setSecretCleared(p, !secretClearedBot)
                            }
                        }, secretClearedBot ? "\u64a4\u9500\u6e05\u9664" : "\u6e05\u9664") : null)
                    }))
                }
                if (f.client_id_key)
                    rows.push(Row("cid", prefix + "-client-id", "Client ID", (p.name || "") + " \u5e94\u7528 Client ID", function(helpId) {
                        return a.a.createElement("input", {
                            id: prefix + "-client-id",
                            type: "text",
                            className: "form-control",
                            value: values[f.client_id_key] || "",
                            disabled: disabled,
                            "aria-describedby": helpId,
                            onChange: function(e) {
                                self.setValue(f.client_id_key, e.target.value)
                            }
                        })
                    }));
                if (f.client_secret_key) {
                    var secretCleared = !!this.state.clearSecrets[p.provider];
                    var secretConfigured = !!p.client_secret_configured;
                    rows.push(Row("csecret", prefix + "-client-secret", "Client Secret", secretConfigured ? "\u5df2\u914d\u7f6e\u5bc6\u94a5\uff0c\u7559\u7a7a\u8868\u793a\u4fdd\u6301\u4e0d\u53d8" : (p.name || "") + " \u5e94\u7528 Client Secret", function(helpId) {
                        return a.a.createElement("div", {
                            className: "v2b-secret-control"
                        }, a.a.createElement("input", {
                            id: prefix + "-client-secret",
                            type: "password",
                            autoComplete: "new-password",
                            className: "form-control",
                            value: values[f.client_secret_key] || "",
                            placeholder: secretCleared ? "\u4fdd\u5b58\u540e\u5c06\u6e05\u9664\u5f53\u524d\u5bc6\u94a5" : "",
                            disabled: disabled || secretCleared,
                            "aria-describedby": helpId,
                            onChange: function(e) {
                                self.setValue(f.client_secret_key, e.target.value)
                            }
                        }), secretConfigured || secretCleared ? a.a.createElement("button", {
                            type: "button",
                            className: secretCleared ? "btn btn-sm btn-alt-secondary ml-2" : "btn btn-sm btn-alt-danger ml-2",
                            disabled: disabled,
                            onClick: function() {
                                self.setSecretCleared(p, !secretCleared)
                            }
                        }, secretCleared ? "\u64a4\u9500\u6e05\u9664" : "\u6e05\u9664") : null)
                    }))
                }
                if (f.auto_register_key)
                    rows.push(Row("autoreg", null, "\u81ea\u52a8\u6ce8\u518c", "\u5f00\u542f\u540e\uff0c\u9996\u6b21\u7b2c\u4e09\u65b9\u767b\u5f55\u4f1a\u81ea\u52a8\u521b\u5efa\u672c\u7ad9\u8d26\u53f7", Switch(parseInt(values[f.auto_register_key], 10), function(e) {
                        self.setValue(f.auto_register_key, e.target.checked ? 1 : 0)
                    }, "\u5141\u8bb8 " + (p.name || p.provider) + " \u7528\u6237\u81ea\u52a8\u6ce8\u518c", disabled)));
                if (f.min_trust_level_key)
                    rows.push(Row("trust", prefix + "-trust-level", "\u6700\u4f4e\u4fe1\u4efb\u7b49\u7ea7", "0-4\uff0c0 \u8868\u793a\u4e0d\u9650\u5236\uff1b\u8be5\u9650\u5236\u5bf9\u767b\u5f55\u548c\u7ed1\u5b9a\u5747\u751f\u6548", function(helpId) {
                        return a.a.createElement("input", {
                            id: prefix + "-trust-level",
                            type: "number",
                            min: "0",
                            max: "4",
                            step: "1",
                            className: "form-control",
                            value: values[f.min_trust_level_key],
                            disabled: disabled,
                            "aria-describedby": helpId,
                            onChange: function(e) {
                                self.setValue(f.min_trust_level_key, e.target.value)
                            }
                        })
                    }));
                if (f.callback_url_key) {
                    var customCallback = !!this.state.customCallbacks[p.provider];
                    rows.push(Row("callback-mode", null, "\u81ea\u5b9a\u4e49\u56de\u8c03\u5730\u5740", "\u9ed8\u8ba4\u4f7f\u7528\u7ad9\u70b9 URL \u81ea\u52a8\u751f\u6210\uff0c\u4ec5\u5728\u53cd\u5411\u4ee3\u7406\u6216\u591a\u57df\u540d\u573a\u666f\u4e0b\u9700\u8981\u8986\u76d6", Switch(customCallback, function(e) {
                        self.setCustomCallback(p, e.target.checked)
                    }, "\u4e3a " + (p.name || p.provider) + " \u4f7f\u7528\u81ea\u5b9a\u4e49\u56de\u8c03\u5730\u5740", disabled)));
                    if (customCallback)
                        rows.push(Row("callback-custom", prefix + "-callback", "\u56de\u8c03\u5730\u5740\uff08Redirect URL\uff09", "\u5fc5\u987b\u6307\u5411\u672c\u7ad9 OAuth \u56de\u8c03\u8def\u5f84\u5e76\u643a\u5e26\u6b63\u786e\u7684 provider \u53c2\u6570", function(helpId) {
                            return a.a.createElement("div", {
                                className: "v2b-callback-control"
                            }, a.a.createElement("input", {
                                id: prefix + "-callback",
                                type: "url",
                                className: "form-control",
                                value: values[f.callback_url_key] || "",
                                disabled: disabled,
                                "aria-describedby": helpId,
                                onChange: function(e) {
                                    self.setValue(f.callback_url_key, e.target.value)
                                }
                            }), a.a.createElement("button", {
                                type: "button",
                                className: "btn btn-sm btn-alt-primary ml-2",
                                disabled: disabled,
                                title: "\u590d\u5236\u56de\u8c03\u5730\u5740",
                                onClick: function() {
                                    self.copyCallback(values[f.callback_url_key] || "")
                                }
                            }, a.a.createElement("i", {
                                className: "fa fa-copy mr-1"
                            }), "\u590d\u5236"))
                        }));
                    else
                        rows.push(Row("callback-default", prefix + "-callback-default", "\u9ed8\u8ba4\u56de\u8c03\u5730\u5740", "\u8bf7\u5c06\u8be5\u5730\u5740\u586b\u5165\u7b2c\u4e09\u65b9\u5e73\u53f0\u7684 Redirect URL", function(helpId) {
                            return a.a.createElement("div", {
                                className: "v2b-callback-control"
                            }, a.a.createElement("input", {
                                id: prefix + "-callback-default",
                                type: "text",
                                readOnly: true,
                                className: "form-control",
                                value: p.default_callback_url || "",
                                "aria-describedby": helpId
                            }), a.a.createElement("button", {
                                type: "button",
                                className: "btn btn-sm btn-alt-primary ml-2",
                                title: "\u590d\u5236\u56de\u8c03\u5730\u5740",
                                onClick: function() {
                                    self.copyCallback(p.default_callback_url || "")
                                }
                            }, a.a.createElement("i", {
                                className: "fa fa-copy mr-1"
                            }), "\u590d\u5236"))
                        }))
                }
                if (p.docs_url)
                    rows.push(Row("docs", null, "\u63a5\u5165\u6587\u6863", "", a.a.createElement("a", {
                        href: p.docs_url,
                        target: "_blank",
                        rel: "noopener noreferrer",
                        className: "v2b-doc-link"
                    }, p.docs_url, a.a.createElement("i", {
                        className: "fa fa-external-link-alt ml-1"
                    }))));
                return rows
            }
            renderProvider(p, idx) {
                var self = this;
                var f = p.fields || {};
                var isEnabled = !!parseInt(this.state.values[f.enable_key], 10);
                var isOpen = !!this.state.expanded[p.provider];
                var status = this.providerStatus(p);
                var panelId = "oauth-panel-" + String(p.provider || idx).replace(/[^a-z0-9_-]/gi, "-");
                var header = a.a.createElement("div", {
                    className: "v2b-provider-header"
                }, a.a.createElement("button", {
                    type: "button",
                    className: "v2b-provider-disclosure",
                    id: panelId + "-trigger",
                    "aria-expanded": isOpen,
                    "aria-controls": panelId,
                    onClick: function() {
                        self.toggleExpand(p.provider)
                    }
                }, a.a.createElement("i", {
                    className: isOpen ? "fa fa-angle-down" : "fa fa-angle-right",
                    "aria-hidden": "true"
                }), a.a.createElement("span", {
                    className: "v2b-provider-heading"
                }, a.a.createElement("span", {
                    className: "v2b-provider-name"
                }, p.name || p.provider), p.description ? a.a.createElement("span", {
                    className: "v2b-provider-description"
                }, p.description) : null)), a.a.createElement("div", {
                    className: "v2b-provider-controls"
                }, a.a.createElement("span", {
                    className: status.className
                }, status.text), Switch(isEnabled, function(e) {
                    self.setProviderEnabled(p, e.target.checked)
                }, "\u542f\u7528 " + (p.name || p.provider) + " \u767b\u5f55", this.state.saving)));
                var body = isOpen ? a.a.createElement("div", {
                    id: panelId,
                    role: "region",
                    "aria-labelledby": panelId + "-trigger",
                    className: "v2b-provider-body"
                }, this.matchesCondition(p, p.visible_when) ? a.a.createElement("div", null, this.renderDetail(p)) : a.a.createElement("div", {
                    className: "v2b-provider-disabled"
                }, "\u542f\u7528\u6b64\u63d0\u4f9b\u5546\u540e\u663e\u793a\u914d\u7f6e\u9879\u3002")) : null;
                return a.a.createElement("div", {
                    key: p.provider || idx,
                    className: "v2b-provider-panel mb-3 " + (isOpen ? "is-open" : "")
                }, header, body)
            }
            renderStaticCard(key, title, desc, controls, rows) {
                // 复用 custom.css 中 v2b-provider-* 样式，保持与提供商面板一致的主题外观
                return a.a.createElement("div", {
                    key: key,
                    className: "v2b-provider-panel mb-3 is-open"
                }, a.a.createElement("div", {
                    className: "v2b-provider-header"
                }, a.a.createElement("div", {
                    className: "v2b-provider-disclosure"
                }, a.a.createElement("span", {
                    className: "v2b-provider-heading"
                }, a.a.createElement("span", {
                    className: "v2b-provider-name"
                }, title), desc ? a.a.createElement("span", {
                    className: "v2b-provider-description"
                }, desc) : null)), controls || null), a.a.createElement("div", {
                    className: "v2b-provider-body"
                }, rows))
            }
            renderAppSchemeCard() {
                var self = this;
                var values = this.state.values;
                var disabled = this.state.saving;
                var rows = [Row("app-scheme", "v2b-oauth-app-scheme", "\u5e94\u7528\u56de\u8c03 Scheme", "\u5ba2\u6237\u7aef\u5e94\u7528\u5185 OAuth \u6df1\u94fe\u56de\u8c03\u767d\u540d\u5355\uff0c\u9017\u53f7\u5206\u9694\uff0c\u9700\u4e0e\u5ba2\u6237\u7aef XBCLIENT_OAUTH_CALLBACK_SCHEME \u4e00\u81f4\uff0c\u7559\u7a7a\u7981\u7528", function(helpId) {
                    return a.a.createElement("input", {
                        id: "v2b-oauth-app-scheme",
                        type: "text",
                        className: "form-control",
                        value: values.oauth_app_scheme || "",
                        placeholder: "xbclient",
                        disabled: disabled,
                        "aria-describedby": helpId,
                        onChange: function(e) {
                            self.setValue("oauth_app_scheme", e.target.value)
                        }
                    })
                })];
                return this.renderStaticCard("client-app", "\u5ba2\u6237\u7aef\u5e94\u7528", "\u5e94\u7528\u5185 OAuth \u767b\u5f55\u7684\u6df1\u94fe\u56de\u8c03\u8bbe\u7f6e", null, rows)
            }
            renderCapCard() {
                var self = this;
                var values = this.state.values;
                var disabled = this.state.saving;
                var capEnabled = !!parseInt(values.cap_enable, 10);
                var capReady = String(values.cap_endpoint || "").trim() !== "" && String(values.cap_site_key || "").trim() !== "" && String(values.cap_secret_key || "").trim() !== "";
                var status = capEnabled ? capReady ? {
                    text: "\u5df2\u542f\u7528",
                    className: "badge badge-success"
                } : {
                    text: "\u5f85\u5b8c\u5584",
                    className: "badge badge-warning"
                } : {
                    text: "\u672a\u542f\u7528",
                    className: "badge badge-secondary"
                };
                var controls = a.a.createElement("div", {
                    className: "v2b-provider-controls"
                }, a.a.createElement("span", {
                    className: status.className
                }, status.text), Switch(capEnabled, function(e) {
                    self.setValue("cap_enable", e.target.checked ? 1 : 0)
                }, "\u542f\u7528 Cap \u81ea\u6258\u7ba1\u9a8c\u8bc1\u7801", disabled));
                var rows = [Row("cap-endpoint", "v2b-cap-endpoint", "\u670d\u52a1\u7aef\u5730\u5740", "Cap \u81ea\u6258\u7ba1\u670d\u52a1 API \u5730\u5740\uff0c\u4f8b\u5982 https://cap.example.com/", function(helpId) {
                    return a.a.createElement("input", {
                        id: "v2b-cap-endpoint",
                        type: "url",
                        className: "form-control",
                        value: values.cap_endpoint || "",
                        placeholder: "https://cap.example.com/",
                        disabled: disabled,
                        "aria-describedby": helpId,
                        onChange: function(e) {
                            self.setValue("cap_endpoint", e.target.value)
                        }
                    })
                }), Row("cap-site-key", "v2b-cap-site-key", "Site Key", "Cap \u7ad9\u70b9\u516c\u5f00\u5bc6\u94a5\uff08Site Key\uff09", function(helpId) {
                    return a.a.createElement("input", {
                        id: "v2b-cap-site-key",
                        type: "text",
                        className: "form-control",
                        value: values.cap_site_key || "",
                        disabled: disabled,
                        "aria-describedby": helpId,
                        onChange: function(e) {
                            self.setValue("cap_site_key", e.target.value)
                        }
                    })
                }), Row("cap-secret-key", "v2b-cap-secret-key", "Secret Key", "\u670d\u52a1\u7aef\u6821\u9a8c\u5bc6\u94a5\uff1b\u5df2\u9884\u586b\u5f53\u524d\u503c\uff0c\u6e05\u7a7a\u5e76\u4fdd\u5b58\u5c06\u6e05\u9664\u8be5\u5bc6\u94a5", function(helpId) {
                    return a.a.createElement("input", {
                        id: "v2b-cap-secret-key",
                        type: "text",
                        autoComplete: "off",
                        className: "form-control",
                        value: values.cap_secret_key || "",
                        disabled: disabled,
                        "aria-describedby": helpId,
                        onChange: function(e) {
                            self.setValue("cap_secret_key", e.target.value)
                        }
                    })
                })];
                return this.renderStaticCard("cap-captcha", "Cap \u81ea\u6258\u7ba1\u9a8c\u8bc1\u7801", "\u81ea\u5efa Cap \u670d\u52a1\u7684\u4eba\u673a\u6821\u9a8c\uff0c\u542f\u7528\u540e\u767b\u5f55\u3001\u6ce8\u518c\u7b49\u573a\u666f\u5c06\u4f7f\u7528 Cap \u9a8c\u8bc1", controls, rows)
            }
            render() {
                var self = this;
                var e = this.state;
                var props = i()({}, this.props, {
                    title: "\u767b\u5f55\u8bbe\u7f6e",
                    loading: !!e.loading
                });
                var panels = e.loadError ? a.a.createElement("div", {
                    className: "v2b-login-error"
                }, a.a.createElement("i", {
                    className: "fa fa-exclamation-circle",
                    "aria-hidden": "true"
                }), a.a.createElement("div", null, a.a.createElement("div", {
                    className: "font-w600"
                }, "\u65e0\u6cd5\u52a0\u8f7d\u767b\u5f55\u8bbe\u7f6e"), a.a.createElement("button", {
                    type: "button",
                    className: "btn btn-sm btn-alt-primary mt-2",
                    onClick: this.loadProviders
                }, a.a.createElement("i", {
                    className: "fa fa-redo mr-1"
                }), "\u91cd\u8bd5"))) : e.providers.length ? e.providers.map(function(p, idx) {
                    return self.renderProvider(p, idx)
                }) : a.a.createElement("div", {
                    className: "v2b-login-empty"
                }, "\u6682\u65e0\u53ef\u914d\u7f6e\u7684\u7b2c\u4e09\u65b9\u767b\u5f55\u5e73\u53f0");
                // \u5e94\u7528\u56de\u8c03 scheme \u4e0e Cap \u9a8c\u8bc1\u7801\u5361\u7247\uff1a\u52a0\u8f7d\u5b8c\u6210\u4e14\u672a\u51fa\u9519\u65f6\u5c55\u793a
                var extras = !e.loadError && !e.loading ? [this.renderAppSchemeCard(), this.renderCapCard()] : null;
                var actions = !e.loadError && !e.loading ? a.a.createElement("div", {
                    className: "v2b-login-actions"
                }, a.a.createElement("span", {
                    className: e.dirty ? "v2b-save-state is-dirty" : "v2b-save-state",
                    role: "status",
                    "aria-live": "polite"
                }, e.dirty ? "\u6709\u672a\u4fdd\u5b58\u7684\u66f4\u6539" : "\u5df2\u4fdd\u5b58"), a.a.createElement("button", {
                    type: "button",
                    className: "btn btn-primary",
                    disabled: e.saving || !e.dirty,
                    onClick: this.onSaveClick
                }, a.a.createElement("i", {
                    className: e.saving ? "fa fa-spinner fa-spin mr-2" : "fa fa-save mr-2"
                }), e.saving ? "\u4fdd\u5b58\u4e2d..." : "\u4fdd\u5b58\u767b\u5f55\u8bbe\u7f6e")) : null;
                return a.a.createElement(s["a"], props, a.a.createElement("div", {
                    className: "v2b-login-settings"
                }, panels, extras, actions))
            }
        }
        t.default = Object(l.c)(function() {
            return {}
        })(LoginSettings)
    }
