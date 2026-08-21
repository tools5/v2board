v2bAdmobSettings: function(e, t, n) {
    "use strict";
    n.r(t);
    var React = n("q1tI")
      , ReactDefault = n.n(React)
      , Page = n("Bl7J")
      , Spin = n("v32e")
      , h = ReactDefault.a.createElement;
    var STYLE_ID = "v2b-admob-admin-style";
    function ensureStyles() {
        if (document.getElementById(STYLE_ID)) return;
        var style = document.createElement("style");
        style.id = STYLE_ID;
        style.textContent = ".admob-admin-page{color:#495057;font-size:14px;line-height:1.5}.admob-admin-page *{box-sizing:border-box}.am-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:18px 20px;border-bottom:1px solid #edf0f2}.am-head h2{margin:0;color:#343a40;font-size:18px;font-weight:600}.am-head p{margin:5px 0 0;color:#6c757d;font-size:13px}.am-save{min-height:38px;padding:0 16px;border:0;border-radius:4px;background:#0667d9;color:#fff;font:inherit;cursor:pointer}.am-save:disabled{opacity:.55;cursor:wait}.am-ssv-wrap{margin:15px 20px 0;padding:13px 15px;border:1px solid #e9ecef;background:#fff}.am-ssv-wrap h3{margin:0 0 8px;color:#343a40;font-size:15px}.am-ssv{display:flex;align-items:center;gap:10px}.am-ssv code{flex:1 1 auto;min-width:0;overflow-x:auto;padding:9px 12px;border:1px solid #ced4da;border-radius:4px;background:#f8f9fa;color:#c7254e;font-family:SFMono-Regular,Consolas,Menlo,monospace;font-size:12px;white-space:nowrap}.am-copy{min-height:34px;padding:0 12px;border:1px solid #0667d9;border-radius:4px;background:#fff;color:#0667d9;font:inherit;font-size:13px;cursor:pointer}.am-copy:hover{background:#eef6ff}.am-ssv-help{margin:8px 0 0;color:#868e96;font-size:12px}.am-body{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;padding:20px}.am-section{border:1px solid #e9ecef;background:#fff}.am-section h3{margin:0;padding:13px 15px;border-bottom:1px solid #e9ecef;color:#343a40;font-size:15px}.am-section-copy{margin:0;padding:10px 15px 0;color:#868e96;font-size:12px}.am-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;padding:15px}.am-field{display:flex;flex-direction:column;gap:6px;color:#495057;font-size:13px}.am-field small{color:#868e96;font-size:12px}.am-field input{width:100%;height:38px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;color:#343a40;background:#fff;font:inherit}.am-field-wide{grid-column:1/-1}.am-switch{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px;border-bottom:1px solid #edf0f2}.am-switch strong{display:block;color:#343a40;font-size:14px}.am-switch span{display:block;margin-top:3px;color:#868e96;font-size:12px}.am-switch input{width:18px;height:18px;margin:0;accent-color:#0667d9}.am-note{margin:0 20px 20px;padding:11px 13px;border-left:3px solid #2f80ed;background:#eef6ff;color:#3f5f7c;font-size:12px}.am-alert{margin:15px 20px 0;padding:10px 12px;border:1px solid #f5c6cb;background:#fff0f1;color:#9b1c25}.am-success{margin:15px 20px 0;padding:10px 12px;border:1px solid #b9e4d5;background:#edfff8;color:#087f72}@media(max-width:780px){.am-head{display:block}.am-save{margin-top:12px}.am-body{grid-template-columns:1fr;padding:12px}.am-fields{grid-template-columns:1fr}.am-ssv{flex-direction:column;align-items:stretch}.am-ssv-wrap{margin:12px 12px 0}.am-note{margin:0 12px 12px}}";
        document.head.appendChild(style);
    }
    function number(value, fallback) {
        var parsed = Number(value);
        return isFinite(parsed) ? parsed : fallback;
    }
    function fallbackCopy(text) {
        var area = document.createElement("textarea");
        area.value = text;
        area.setAttribute("readonly", "readonly");
        area.style.position = "fixed";
        area.style.opacity = "0";
        document.body.appendChild(area);
        area.select();
        var ok = document.execCommand("copy");
        document.body.removeChild(area);
        if (!ok) throw new Error("copy failed");
    }
    class AdmobSettingsPage extends ReactDefault.a.Component {
        constructor(props) {
            super(props);
            this.state = {loading: true, saving: false, error: "", notice: "", ssvCallbackUrl: "", values: {payment_enabled: 0, app_open_ad_enabled: 0, app_open_ad_unit_id: "", github_project_url: "", plan_reward_ad_enabled: 0, plan_rewarded_ad_unit_id: "", plan_reward_expire_days: 0, plan_reward_transfer_gb: 0, plan_reward_daily_limit: 0, points_reward_ad_enabled: 0, points_rewarded_ad_unit_id: "", points_reward_balance: 0, points_reward_daily_limit: 0}};
        }
        componentDidMount() { ensureStyles(); this.load(); }
        api(path, options) {
            options = options || {};
            var headers = {Accept: "application/json"};
            var authorization = window.localStorage.getItem("authorization");
            if (authorization) headers.authorization = authorization;
            if (options.body) headers["Content-Type"] = "application/json";
            return fetch("/api/v1/" + window.settings.secure_path + path, {method: options.method || "GET", headers: headers, body: options.body}).then(function(response) {
                return response.text().then(function(body) {
                    var data = {};
                    try { data = body ? JSON.parse(body) : {}; } catch (error) {}
                    if (!response.ok) {
                        var message = data.message || data.error;
                        if (!message && data.errors) message = Object.values(data.errors)[0][0];
                        throw new Error(message || "\u8bf7\u6c42\u5931\u8d25\uff0c\u8bf7\u7a0d\u540e\u91cd\u8bd5");
                    }
                    return data;
                });
            });
        }
        load() {
            var self = this;
            this.setState({loading: true, error: "", notice: ""});
            // config/fetch 的 admob 段返回去前缀字段（payment_enabled 等）与只读 ssv_callback_url
            this.api("/config/fetch?key=admob").then(function(result) {
                var data = result && result.data && result.data.admob || {};
                self.setState({loading: false, ssvCallbackUrl: String(data.ssv_callback_url || ""), values: {
                    payment_enabled: Number(data.payment_enabled) === 1 ? 1 : 0,
                    app_open_ad_enabled: Number(data.app_open_ad_enabled) === 1 ? 1 : 0,
                    app_open_ad_unit_id: String(data.app_open_ad_unit_id == null ? "" : data.app_open_ad_unit_id),
                    github_project_url: String(data.github_project_url == null ? "" : data.github_project_url),
                    plan_reward_ad_enabled: Number(data.plan_reward_ad_enabled) === 1 ? 1 : 0,
                    plan_rewarded_ad_unit_id: String(data.plan_rewarded_ad_unit_id == null ? "" : data.plan_rewarded_ad_unit_id),
                    plan_reward_expire_days: number(data.plan_reward_expire_days, 0),
                    plan_reward_transfer_gb: number(data.plan_reward_transfer_gb, 0),
                    plan_reward_daily_limit: number(data.plan_reward_daily_limit, 0),
                    points_reward_ad_enabled: Number(data.points_reward_ad_enabled) === 1 ? 1 : 0,
                    points_rewarded_ad_unit_id: String(data.points_rewarded_ad_unit_id == null ? "" : data.points_rewarded_ad_unit_id),
                    points_reward_balance: number(data.points_reward_balance, 0),
                    points_reward_daily_limit: number(data.points_reward_daily_limit, 0)
                }});
            }).catch(function(error) { self.setState({loading: false, error: error.message || "\u914d\u7f6e\u8bfb\u53d6\u5931\u8d25\uff0c\u8bf7\u7a0d\u540e\u91cd\u8bd5"}); });
        }
        setValue(key, value) {
            var values = Object.assign({}, this.state.values); values[key] = value; this.setState({values: values, notice: ""});
        }
        save(event) {
            event.preventDefault();
            var values = this.state.values;
            // 保存键使用后端 ConfigSave 的完整键名：admob_* 与 xbclient_github_project_url
            var body = {
                admob_payment_enabled: Number(values.payment_enabled) === 1 ? 1 : 0,
                admob_app_open_ad_enabled: Number(values.app_open_ad_enabled) === 1 ? 1 : 0,
                admob_app_open_ad_unit_id: String(values.app_open_ad_unit_id || "").trim(),
                admob_plan_reward_ad_enabled: Number(values.plan_reward_ad_enabled) === 1 ? 1 : 0,
                admob_plan_rewarded_ad_unit_id: String(values.plan_rewarded_ad_unit_id || "").trim(),
                admob_plan_reward_expire_days: number(values.plan_reward_expire_days, 0),
                admob_plan_reward_transfer_gb: number(values.plan_reward_transfer_gb, 0),
                admob_plan_reward_daily_limit: number(values.plan_reward_daily_limit, 0),
                admob_points_reward_ad_enabled: Number(values.points_reward_ad_enabled) === 1 ? 1 : 0,
                admob_points_rewarded_ad_unit_id: String(values.points_rewarded_ad_unit_id || "").trim(),
                admob_points_reward_balance: number(values.points_reward_balance, 0),
                admob_points_reward_daily_limit: number(values.points_reward_daily_limit, 0),
                xbclient_github_project_url: String(values.github_project_url || "").trim()
            };
            var self = this;
            this.setState({saving: true, error: "", notice: ""});
            this.api("/config/save", {method: "POST", body: JSON.stringify(body)}).then(function() {
                self.setState({saving: false, notice: "\u914d\u7f6e\u5df2\u4fdd\u5b58\uff0c\u5ba2\u6237\u7aef\u5c06\u5728\u4e0b\u6b21\u62c9\u53d6\u65f6\u751f\u6548\u3002"});
            }).catch(function(error) { self.setState({saving: false, error: error.message || "\u4fdd\u5b58\u5931\u8d25\uff0c\u8bf7\u91cd\u8bd5"}); });
        }
        copySsv() {
            var self = this;
            var text = this.state.ssvCallbackUrl || "";
            var done = function() { self.setState({notice: "\u56de\u8c03\u5730\u5740\u5df2\u590d\u5236", error: ""}); };
            var fail = function() { self.setState({error: "\u590d\u5236\u5931\u8d25\uff0c\u8bf7\u624b\u52a8\u590d\u5236", notice: ""}); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function() {
                    try { fallbackCopy(text); done(); } catch (error) { fail(); }
                });
                return;
            }
            try { fallbackCopy(text); done(); } catch (error) { fail(); }
        }
        toggle(key, label, copy) {
            var values = this.state.values;
            return h("label", {className: "am-switch"}, h("span", null, h("strong", null, label), h("span", null, copy)), h("input", {type: "checkbox", checked: Number(values[key]) === 1, onChange: this.setValue.bind(this, key, Number(values[key]) === 1 ? 0 : 1)}));
        }
        textField(key, label, hint, placeholder, wide) {
            var self = this;
            return h("label", {className: wide ? "am-field am-field-wide" : "am-field"}, label, h("input", {type: "text", value: this.state.values[key], placeholder: placeholder || "", onChange: function(event) { self.setValue(key, event.target.value); }}), h("small", null, hint));
        }
        numberField(key, label, hint, min) {
            var self = this;
            return h("label", {className: "am-field"}, label, h("input", {type: "number", min: min, step: "1", value: this.state.values[key], onChange: function(event) { self.setValue(key, event.target.value); }}), h("small", null, hint));
        }
        render() {
            var form = h("form", {onSubmit: this.save.bind(this)}, this.state.error ? h("div", {className: "am-alert", role: "alert"}, this.state.error) : null, this.state.notice ? h("div", {className: "am-success", role: "status"}, this.state.notice) : null, h("div", {className: "block block-rounded admob-admin-page"}, h("div", {className: "am-head"}, h("div", null, h("h2", null, "\u5ba2\u6237\u7aef\u5e7f\u544a\uff08AdMob\uff09"), h("p", null, "\u914d\u7f6e XBClient \u5ba2\u6237\u7aef\u7684 AdMob \u5e7f\u544a\u3001\u5e94\u7528\u5185\u8d2d\u4e70\u4e0e\u89c2\u770b\u5e7f\u544a\u5956\u52b1\u3002\u4fdd\u5b58\u540e\u5199\u5165\u7ad9\u70b9\u914d\u7f6e\u3002")), h("button", {className: "am-save", type: "submit", disabled: this.state.loading || this.state.saving}, this.state.saving ? "\u6b63\u5728\u4fdd\u5b58..." : "\u4fdd\u5b58\u914d\u7f6e")), h("div", {className: "am-ssv-wrap"}, h("h3", null, "SSV \u56de\u8c03\u5730\u5740"), h("div", {className: "am-ssv"}, h("code", null, this.state.ssvCallbackUrl || "-"), h("button", {type: "button", className: "am-copy", onClick: this.copySsv.bind(this)}, "\u590d\u5236")), h("p", {className: "am-ssv-help"}, "\u5c06\u8be5\u5730\u5740\u586b\u5230 AdMob \u63a7\u5236\u53f0\u6fc0\u52b1\u5e7f\u544a\u7684\u300c\u670d\u52a1\u5668\u7aef\u9a8c\u8bc1\uff08SSV\uff09\u300d\u8bbe\u7f6e\u4e2d\uff0c\u7528\u4e8e\u6821\u9a8c\u5956\u52b1\u56de\u8c03\u3002")), h("div", {className: "am-body"}, h("section", {className: "am-section"}, h("h3", null, "\u901a\u7528\u8bbe\u7f6e"), this.toggle("payment_enabled", "\u5e94\u7528\u5185\u5728\u7ebf\u8d2d\u4e70", "\u5f00\u542f\u540e\u5ba2\u6237\u7aef\u5c55\u793a\u5957\u9910\u8d2d\u4e70\u4e0e\u5728\u7ebf\u652f\u4ed8\u5165\u53e3\u3002"), this.toggle("app_open_ad_enabled", "\u5f00\u5c4f\u5e7f\u544a", "\u5f00\u542f\u540e\u5ba2\u6237\u7aef\u542f\u52a8\u65f6\u5c55\u793a AdMob \u5f00\u5c4f\u5e7f\u544a\u3002"), h("div", {className: "am-fields"}, this.textField("app_open_ad_unit_id", "\u5f00\u5c4f\u5e7f\u544a\u5355\u5143 ID", "AdMob \u5f00\u5c4f\u5e7f\u544a\u4f4d ID\uff0c\u4f8b\u5982 ca-app-pub-xxx/yyy\u3002", "ca-app-pub-", true), this.textField("github_project_url", "GitHub \u9879\u76ee\u5730\u5740", "\u5ba2\u6237\u7aef\u300c\u5173\u4e8e\u300d\u9875\u5c55\u793a\u7684\u5f00\u6e90\u9879\u76ee\u5730\u5740\uff08\u4fdd\u5b58\u952e xbclient_github_project_url\uff09\u3002", "https://github.com/", true))), h("section", {className: "am-section"}, h("h3", null, "\u5957\u9910\u5956\u52b1"), h("p", {className: "am-section-copy"}, "\u7528\u6237\u89c2\u770b\u6fc0\u52b1\u5e7f\u544a\u53ef\u83b7\u5f97\u9650\u65f6\u5957\u9910\u6216\u6d41\u91cf\u5956\u52b1\u3002"), this.toggle("plan_reward_ad_enabled", "\u542f\u7528\u5957\u9910\u5956\u52b1\u5e7f\u544a", ""), h("div", {className: "am-fields"}, this.textField("plan_rewarded_ad_unit_id", "\u6fc0\u52b1\u5e7f\u544a\u5355\u5143 ID", "AdMob \u6fc0\u52b1\u5e7f\u544a\u4f4d ID\u3002", "ca-app-pub-", true), this.numberField("plan_reward_expire_days", "\u5956\u52b1\u5957\u9910\u6709\u6548\u671f\uff08\u5929\uff09", "\u89c2\u770b\u5e7f\u544a\u83b7\u5f97\u7684\u5957\u9910\u6709\u6548\u5929\u6570\uff0c0 \u8868\u793a\u4e0d\u5ef6\u957f\u3002", 0), this.numberField("plan_reward_transfer_gb", "\u5956\u52b1\u6d41\u91cf\uff08GB\uff09", "\u6bcf\u6b21\u89c2\u770b\u5956\u52b1\u7684\u6d41\u91cf\uff0c\u5355\u4f4d GB\u3002", 0), this.numberField("plan_reward_daily_limit", "\u6bcf\u65e5\u6b21\u6570\u4e0a\u9650", "\u6bcf\u7528\u6237\u6bcf\u65e5\u53ef\u83b7\u5f97\u5956\u52b1\u7684\u6b21\u6570\uff0c0 \u8868\u793a\u4e0d\u9650\u3002", 0))), h("section", {className: "am-section"}, h("h3", null, "\u79ef\u5206\u5956\u52b1"), h("p", {className: "am-section-copy"}, "\u7528\u6237\u89c2\u770b\u6fc0\u52b1\u5e7f\u544a\u53ef\u83b7\u5f97\u4f59\u989d\u79ef\u5206\u3002"), this.toggle("points_reward_ad_enabled", "\u542f\u7528\u79ef\u5206\u5956\u52b1\u5e7f\u544a", ""), h("div", {className: "am-fields"}, this.textField("points_rewarded_ad_unit_id", "\u6fc0\u52b1\u5e7f\u544a\u5355\u5143 ID", "AdMob \u6fc0\u52b1\u5e7f\u544a\u4f4d ID\u3002", "ca-app-pub-", true), this.numberField("points_reward_balance", "\u5355\u6b21\u5956\u52b1\u4f59\u989d\uff08\u5206\uff09", "\u6bcf\u6b21\u89c2\u770b\u5956\u52b1\u7684\u4f59\u989d\uff0c\u5355\u4f4d\u5206\uff0c100 \u5206 = 1 \u5143\u3002", 0), this.numberField("points_reward_daily_limit", "\u6bcf\u65e5\u6b21\u6570\u4e0a\u9650", "\u6bcf\u7528\u6237\u6bcf\u65e5\u53ef\u83b7\u5f97\u5956\u52b1\u7684\u6b21\u6570\uff0c0 \u8868\u793a\u4e0d\u9650\u3002", 0)))), h("p", {className: "am-note"}, "\u4fdd\u5b58\u540e\u914d\u7f6e\u5199\u5165 config/v2board.php\uff1b\u5ba2\u6237\u7aef\u5728\u4e0b\u6b21\u540c\u6b65\u914d\u7f6e\u65f6\u751f\u6548\u3002")));
            return h(Page["a"], Object.assign({}, this.props, {title: "\u5ba2\u6237\u7aef\u5e7f\u544a"}), h(Spin["a"], {loading: this.state.loading}, form));
        }
    }
    t.default = AdmobSettingsPage;
}
