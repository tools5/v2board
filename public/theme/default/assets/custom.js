/**
 * 原版 default 主题：第三方登录增强
 * 文件编码：UTF-8
 *
 * 功能：
 * 1. 登录/注册页注入第三方登录按钮
 * 2. 强制邀请码开启时，OAuth/Telegram 自动注册前收集邀请码
 * 3. OAuth 首次注册后弹出「完善信息」引导层（可设置密码 / 真实邮箱，也可跳过）
 * 4. 邮件链接注册 / 找回密码：register_email_mode=link 时改写对应页面流程
 */
(function () {
  'use strict';

  var STYLE_ID = 'v2b-oauth-login-style';
  var BOX_CLASS = 'v2b-oauth-login-box';
  var SETUP_ID = 'v2b-oauth-setup-modal';
  // 标记本次会话是否已处理过引导，避免重复弹出
  var SETUP_HANDLED_KEY = 'v2b_oauth_setup_handled';
  // 锁存「待展示引导」标记：登录跳转会丢弃 hash 中的 oauth_setup 参数，
  // 且 token 在处理 verify 后才可用，因此首次看到参数时先存起来，稍后再弹。
  var SETUP_PENDING_KEY = 'v2b_oauth_setup_pending';
  // OAuth 跳转前填写的邀请码（强制邀请时使用）
  var INVITE_STORAGE_KEY = 'v2b_oauth_invite_code';

  function ensureStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent =
      '.' + BOX_CLASS + '{margin-top:16px;}' +
      '.' + BOX_CLASS + ' .oauth-divider{display:flex;align-items:center;gap:10px;margin:8px 0 14px;color:#94a3b8;font-size:12px;}' +
      '.' + BOX_CLASS + ' .oauth-divider:before,.' + BOX_CLASS + ' .oauth-divider:after{content:"";flex:1;height:1px;background:#e2e8f0;}' +
      '.' + BOX_CLASS + ' .oauth-invite{margin:0 0 12px;}' +
      '.' + BOX_CLASS + ' .oauth-invite label{display:block;font-size:12px;color:#64748b;margin-bottom:6px;}' +
      '.' + BOX_CLASS + ' .oauth-invite input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:9px 12px;font-size:14px;outline:none;}' +
      '.' + BOX_CLASS + ' .oauth-invite input:focus{border-color:#6366f1;}' +
      '.' + BOX_CLASS + ' .oauth-invite-hint{font-size:12px;color:#94a3b8;margin-top:4px;line-height:1.4;}' +
      '.' + BOX_CLASS + ' .oauth-btn{display:block;width:100%;text-align:center;border:none;border-radius:6px;padding:10px 14px;color:#fff;font-size:14px;cursor:pointer;text-decoration:none;margin-bottom:8px;}' +
      '.' + BOX_CLASS + ' .oauth-btn:hover{opacity:.92;color:#fff;}' +
      '.' + BOX_CLASS + ' .oauth-msg{margin-top:8px;font-size:12px;}' +
      '.' + BOX_CLASS + ' .oauth-msg.error{color:#dc2626;}' +
      '.' + BOX_CLASS + ' .oauth-msg.success{color:#059669;}' +
      '#' + SETUP_ID + '{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.55);}' +
      '#' + SETUP_ID + ' .setup-card{width:92%;max-width:400px;background:#fff;border-radius:12px;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.25);font-size:14px;color:#1e293b;}' +
      '#' + SETUP_ID + ' .setup-title{font-size:18px;font-weight:600;margin:0 0 6px;}' +
      '#' + SETUP_ID + ' .setup-desc{color:#64748b;font-size:13px;margin:0 0 18px;line-height:1.5;}' +
      '#' + SETUP_ID + ' .setup-field{margin-bottom:14px;}' +
      '#' + SETUP_ID + ' .setup-field label{display:block;font-size:13px;color:#475569;margin-bottom:6px;}' +
      '#' + SETUP_ID + ' .setup-field input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:9px 12px;font-size:14px;outline:none;}' +
      '#' + SETUP_ID + ' .setup-field input:focus{border-color:#6366f1;}' +
      '#' + SETUP_ID + ' .setup-hint{font-size:12px;color:#94a3b8;margin-top:4px;}' +
      '#' + SETUP_ID + ' .setup-msg{min-height:18px;font-size:12px;margin-bottom:10px;}' +
      '#' + SETUP_ID + ' .setup-msg.error{color:#dc2626;}' +
      '#' + SETUP_ID + ' .setup-msg.success{color:#059669;}' +
      '#' + SETUP_ID + ' .setup-actions{display:flex;gap:10px;margin-top:6px;}' +
      '#' + SETUP_ID + ' .setup-btn{flex:1;border:none;border-radius:6px;padding:10px 14px;font-size:14px;cursor:pointer;}' +
      '#' + SETUP_ID + ' .setup-btn.primary{background:#6366f1;color:#fff;}' +
      '#' + SETUP_ID + ' .setup-btn.primary:hover{background:#4f46e5;}' +
      '#' + SETUP_ID + ' .setup-btn.primary:disabled{background:#a5b4fc;cursor:not-allowed;}' +
      '#' + SETUP_ID + ' .setup-btn.ghost{background:#f1f5f9;color:#475569;}' +
      '#' + SETUP_ID + ' .setup-btn.ghost:hover{background:#e2e8f0;}' +
      '#v2b-register-link-hint{margin:0 0 12px;padding:10px 12px;border-radius:6px;background:#eff6ff;color:#1d4ed8;font-size:13px;line-height:1.5;}' +
      '#v2b-register-link-hint.error{background:#fef2f2;color:#b91c1c;}' +
      '#v2b-register-link-hint.success{background:#ecfdf5;color:#047857;}';
    document.head.appendChild(style);
  }

  function isLoginPage() {
    var hash = location.hash || '';
    return hash.indexOf('#/login') === 0 || hash.indexOf('#/register') === 0;
  }

  function findLoginFormRoot() {
    var candidates = [
      document.querySelector('form'),
      document.querySelector('.ant-form'),
      document.querySelector('.block-content'),
      document.querySelector('.content')
    ];
    for (var i = 0; i < candidates.length; i++) {
      if (candidates[i]) return candidates[i];
    }
    return null;
  }

  function getHashQuery() {
    var hash = location.hash || '';
    var idx = hash.indexOf('?');
    return new URLSearchParams(idx >= 0 ? hash.slice(idx + 1) : '');
  }

  function readInviteFromUrl() {
    var params = getHashQuery();
    var code = params.get('code') || params.get('invite_code') || params.get('invite');
    if (code) return String(code).trim();
    params = new URLSearchParams(location.search.replace(/^\?/, ''));
    code = params.get('code') || params.get('invite_code') || params.get('invite');
    return code ? String(code).trim() : '';
  }

  function getInviteInputValue(box) {
    if (!box) return '';
    var input = box.querySelector('.oauth-invite-input');
    if (!input) return '';
    return String(input.value || '').trim();
  }

  function persistInviteCode(code) {
    try {
      if (code) sessionStorage.setItem(INVITE_STORAGE_KEY, code);
      else sessionStorage.removeItem(INVITE_STORAGE_KEY);
    } catch (e) {}
  }

  function loadPersistedInviteCode() {
    try {
      return sessionStorage.getItem(INVITE_STORAGE_KEY) || '';
    } catch (e) {
      return '';
    }
  }

  function requireInviteIfNeeded(inviteForce, box) {
    if (!inviteForce) {
      var optional = getInviteInputValue(box);
      persistInviteCode(optional);
      return optional;
    }
    var code = getInviteInputValue(box);
    if (!code) {
      alert('请先填写邀请码，再使用第三方登录注册');
      var input = box && box.querySelector('.oauth-invite-input');
      if (input) input.focus();
      return null;
    }
    persistInviteCode(code);
    return code;
  }

  function appendInviteToUrl(url, inviteCode) {
    if (!inviteCode || !url) return url;
    var sep = url.indexOf('?') >= 0 ? '&' : '?';
    return url + sep + 'invite_code=' + encodeURIComponent(inviteCode);
  }

  function showOauthMessage() {
    var params = getHashQuery();
    if (!params.get('oauth_msg')) {
      params = new URLSearchParams(location.search.replace(/^\?/, ''));
    }
    var msg = params.get('oauth_msg');
    var isError = params.get('oauth_error') === '1';
    if (!msg) return null;
    var el = document.createElement('div');
    el.className = 'oauth-msg ' + (isError ? 'error' : 'success');
    el.textContent = decodeURIComponent(msg);
    return el;
  }

  function setAuthData(authData) {
    try {
      if (authData) {
        window.localStorage.setItem('authorization', authData);
      }
    } catch (e) {}
  }

  function finishTelegramLogin(data) {
    if (!data || !data.auth_data) {
      alert('Telegram 登录失败：未返回登录凭证');
      return;
    }
    setAuthData(data.auth_data);
    if (data.is_new) {
      try {
        sessionStorage.setItem(SETUP_PENDING_KEY, '1');
      } catch (e) {}
    }
    try {
      sessionStorage.removeItem(INVITE_STORAGE_KEY);
    } catch (e) {}
    location.hash = data.is_new ? '#/dashboard?oauth_setup=1' : '#/dashboard';
    setTimeout(function () {
      location.reload();
    }, 50);
  }

  function postTelegramLogin(user, inviteCode) {
    var body = Object.assign({}, user || {});
    if (inviteCode) body.invite_code = inviteCode;
    return fetch('/api/v1/passport/auth/oauth/telegram', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.json().then(function (json) {
        return { ok: res.ok, status: res.status, json: json };
      });
    });
  }

  function mountTelegramWidget(container, item, inviteForce, box) {
    if (!item.bot_username) return;
    var wrap = document.createElement('div');
    wrap.className = 'oauth-telegram-wrap';
    wrap.style.marginBottom = '8px';
    wrap.style.textAlign = 'center';

    var script = document.createElement('script');
    script.async = true;
    script.src = 'https://telegram.org/js/telegram-widget.js?22';
    script.setAttribute('data-telegram-login', item.bot_username);
    script.setAttribute('data-size', 'large');
    script.setAttribute('data-radius', '6');
    script.setAttribute('data-request-access', 'write');
    script.setAttribute('data-userpic', 'false');
    script.setAttribute('data-lang', 'zh-hans');
    var cbName = 'v2bTelegramOauthLogin_' + String(item.bot_username).replace(/[^a-zA-Z0-9_]/g, '');
    window[cbName] = function (user) {
      var inviteCode = requireInviteIfNeeded(inviteForce, box);
      if (inviteCode === null) return;
      postTelegramLogin(user, inviteCode)
        .then(function (res) {
          if (res.ok && res.json && res.json.data) {
            finishTelegramLogin(res.json.data);
            return;
          }
          var err =
            (res.json && (res.json.message || res.json.error)) ||
            'Telegram 登录失败';
          alert(err);
        })
        .catch(function () {
          alert('网络错误，Telegram 登录失败');
        });
    };
    script.setAttribute('data-onauth', cbName + '(user)');
    wrap.appendChild(script);
    container.appendChild(wrap);
  }

  function renderProviders(providers, inviteForce) {
    if (!isLoginPage()) {
      var old = document.querySelector('.' + BOX_CLASS);
      if (old) old.remove();
      return;
    }
    if (!providers || !providers.length) return;
    if (document.querySelector('.' + BOX_CLASS)) return;

    ensureStyle();
    var root = findLoginFormRoot();
    if (!root) return;

    var box = document.createElement('div');
    box.className = BOX_CLASS;

    var divider = document.createElement('div');
    divider.className = 'oauth-divider';
    divider.textContent = '第三方登录';
    box.appendChild(divider);

    var inviteWrap = document.createElement('div');
    inviteWrap.className = 'oauth-invite';
    var inviteLabel = document.createElement('label');
    inviteLabel.textContent = inviteForce ? '邀请码（第三方注册必填）' : '邀请码（第三方注册选填）';
    inviteWrap.appendChild(inviteLabel);
    var inviteInput = document.createElement('input');
    inviteInput.type = 'text';
    inviteInput.className = 'oauth-invite-input';
    inviteInput.placeholder = inviteForce ? '请输入邀请码' : '可选';
    inviteInput.autocomplete = 'off';
    inviteInput.value = readInviteFromUrl() || loadPersistedInviteCode();
    inviteInput.addEventListener('change', function () {
      persistInviteCode(String(inviteInput.value || '').trim());
    });
    inviteWrap.appendChild(inviteInput);
    var inviteHint = document.createElement('div');
    inviteHint.className = 'oauth-invite-hint';
    inviteHint.textContent = inviteForce
      ? '首次用第三方登录会创建账号，需填写有效邀请码；已绑定账号可直接登录。'
      : '填写后，首次第三方注册将关联邀请人。';
    inviteWrap.appendChild(inviteHint);
    box.appendChild(inviteWrap);

    providers.forEach(function (item) {
      if (item.auth_type === 'telegram_login_widget') {
        mountTelegramWidget(box, item, inviteForce, box);
        return;
      }
      if (!item.redirect_url) return;
      var a = document.createElement('a');
      a.className = 'oauth-btn';
      a.href = item.redirect_url;
      a.textContent = item.button_text || ('使用 ' + item.name + ' 登录');
      a.style.background = item.button_color || '#222';
      a.addEventListener('click', function (event) {
        var inviteCode = requireInviteIfNeeded(inviteForce, box);
        if (inviteCode === null) {
          event.preventDefault();
          return;
        }
        a.href = appendInviteToUrl(item.redirect_url, inviteCode);
      });
      box.appendChild(a);
    });

    var msgEl = showOauthMessage();
    if (msgEl) box.appendChild(msgEl);

    root.appendChild(box);
  }

  function loadProviders() {
    fetch('/api/v1/passport/auth/oauth/providers', {
      method: 'GET',
      headers: { Accept: 'application/json' }
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (json) {
        var inviteForce = !!(json && Number(json.invite_force) === 1);
        renderProviders((json && json.data) || [], inviteForce);
      })
      .catch(function () {});
  }

  function getAuthToken() {
    try {
      return window.localStorage.getItem('authorization') || '';
    } catch (e) {
      return '';
    }
  }

  function apiRequest(path, method, body) {
    var headers = { Accept: 'application/json' };
    var token = getAuthToken();
    if (token) headers.authorization = token;
    var opts = { method: method || 'GET', headers: headers };
    if (body) {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch('/api/v1/user' + path, opts).then(function (res) {
      return res.json().then(function (json) {
        return { ok: res.ok, status: res.status, json: json };
      });
    });
  }

  function closeSetupModal() {
    var el = document.getElementById(SETUP_ID);
    if (el) el.remove();
  }

  function removeSetupFlagFromHash() {
    var hash = location.hash || '';
    var idx = hash.indexOf('?');
    if (idx < 0) return;
    var base = hash.slice(0, idx);
    var params = new URLSearchParams(hash.slice(idx + 1));
    params.delete('oauth_setup');
    var rest = params.toString();
    try {
      history.replaceState(null, '', location.pathname + location.search + base + (rest ? '?' + rest : ''));
    } catch (e) {}
  }

  function buildSetupModal(userInfo) {
    ensureStyle();

    var overlay = document.createElement('div');
    overlay.id = SETUP_ID;

    var card = document.createElement('div');
    card.className = 'setup-card';

    var title = document.createElement('h3');
    title.className = 'setup-title';
    title.textContent = '完善账号信息';
    card.appendChild(title);

    var desc = document.createElement('p');
    desc.className = 'setup-desc';
    var isPlaceholder = userInfo && userInfo.is_placeholder_email;
    desc.textContent = isPlaceholder
      ? '你使用第三方账号快速注册成功。建议绑定真实邮箱并设置登录密码，方便后续用邮箱直接登录与找回账号。你也可以先跳过。'
      : '你使用第三方账号快速注册成功。建议设置一个登录密码，方便后续用邮箱直接登录。你也可以先跳过。';
    card.appendChild(desc);

    var emailInput = null;
    if (isPlaceholder) {
      var emailField = document.createElement('div');
      emailField.className = 'setup-field';
      var emailLabel = document.createElement('label');
      emailLabel.textContent = '真实邮箱（可选）';
      emailField.appendChild(emailLabel);
      emailInput = document.createElement('input');
      emailInput.type = 'email';
      emailInput.placeholder = '你的常用邮箱';
      emailInput.autocomplete = 'email';
      emailField.appendChild(emailInput);
      card.appendChild(emailField);
    }

    var pwField = document.createElement('div');
    pwField.className = 'setup-field';
    var pwLabel = document.createElement('label');
    pwLabel.textContent = '登录密码（可选）';
    pwField.appendChild(pwLabel);
    var pwInput = document.createElement('input');
    pwInput.type = 'password';
    pwInput.placeholder = '至少 8 位';
    pwInput.autocomplete = 'new-password';
    pwField.appendChild(pwInput);
    var pwHint = document.createElement('div');
    pwHint.className = 'setup-hint';
    pwHint.textContent = '留空则本次不设置密码';
    pwField.appendChild(pwHint);
    card.appendChild(pwField);

    var msg = document.createElement('div');
    msg.className = 'setup-msg';
    card.appendChild(msg);

    var actions = document.createElement('div');
    actions.className = 'setup-actions';

    var skipBtn = document.createElement('button');
    skipBtn.type = 'button';
    skipBtn.className = 'setup-btn ghost';
    skipBtn.textContent = '跳过';

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'setup-btn primary';
    saveBtn.textContent = '保存并继续';

    actions.appendChild(skipBtn);
    actions.appendChild(saveBtn);
    card.appendChild(actions);

    overlay.appendChild(card);
    document.body.appendChild(overlay);

    function finish() {
      try {
        sessionStorage.setItem(SETUP_HANDLED_KEY, '1');
        sessionStorage.removeItem(SETUP_PENDING_KEY);
      } catch (e) {}
      removeSetupFlagFromHash();
      closeSetupModal();
    }

    skipBtn.addEventListener('click', finish);

    saveBtn.addEventListener('click', function () {
      var email = emailInput ? emailInput.value.trim() : '';
      var password = pwInput.value;

      if (!email && !password) {
        finish();
        return;
      }
      if (password && password.length < 8) {
        msg.className = 'setup-msg error';
        msg.textContent = '密码至少需要 8 位';
        return;
      }

      var payload = {};
      if (email) payload.email = email;
      if (password) payload.password = password;

      saveBtn.disabled = true;
      saveBtn.textContent = '保存中...';
      msg.className = 'setup-msg';
      msg.textContent = '';

      apiRequest('/setupOauthInfo', 'POST', payload)
        .then(function (res) {
          if (res.ok && res.json && res.json.data) {
            msg.className = 'setup-msg success';
            msg.textContent = '保存成功';
            setTimeout(finish, 600);
          } else {
            var errMsg =
              (res.json && (res.json.message || res.json.error)) || '保存失败，请重试';
            msg.className = 'setup-msg error';
            msg.textContent = errMsg;
            saveBtn.disabled = false;
            saveBtn.textContent = '保存并继续';
          }
        })
        .catch(function () {
          msg.className = 'setup-msg error';
          msg.textContent = '网络错误，请重试';
          saveBtn.disabled = false;
          saveBtn.textContent = '保存并继续';
        });
    });
  }

  function maybeShowSetup() {
    if (getHashQuery().get('oauth_setup') === '1') {
      try {
        sessionStorage.setItem(SETUP_PENDING_KEY, '1');
      } catch (e) {}
    }

    var pending = false;
    try {
      pending = sessionStorage.getItem(SETUP_PENDING_KEY) === '1';
    } catch (e) {}
    if (!pending) return;

    if (document.getElementById(SETUP_ID)) return;
    try {
      if (sessionStorage.getItem(SETUP_HANDLED_KEY) === '1') return;
    } catch (e) {}
    if (!getAuthToken()) return;

    apiRequest('/info', 'GET')
      .then(function (res) {
        if (res.ok && res.json && res.json.data) {
          buildSetupModal(res.json.data);
        }
      })
      .catch(function () {});
  }

  // ---------- 邮件链接注册（register_email_mode=link）----------
  var guestConfigCache = null;
  var REG_HINT_ID = 'v2b-register-link-hint';

  function isRegisterPage() {
    return (location.hash || '').indexOf('#/register') === 0;
  }

  function loadGuestConfig() {
    if (guestConfigCache) return Promise.resolve(guestConfigCache);
    return fetch('/api/v1/guest/comm/config', {
      method: 'GET',
      headers: { Accept: 'application/json' }
    })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        guestConfigCache = (json && json.data) || {};
        return guestConfigCache;
      })
      .catch(function () {
        guestConfigCache = {};
        return guestConfigCache;
      });
  }

  function isLinkRegisterMode(cfg) {
    return !!(cfg && Number(cfg.is_email_verify) === 1 && cfg.register_email_mode === 'link');
  }

  function isLinkPasswordResetMode(cfg) {
    // 找回密码：只要后台选择了 link 模式就生效（不依赖注册邮箱验证开关）
    return !!(cfg && cfg.register_email_mode === 'link');
  }

  function isForgetPage() {
    var hash = location.hash || '';
    return hash.indexOf('#/forgetpassword') === 0 || hash.indexOf('#/forget') === 0;
  }

  function passportRequest(path, method, body) {
    var headers = { Accept: 'application/json' };
    var opts = { method: method || 'GET', headers: headers };
    if (body) {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch('/api/v1/passport' + path, opts).then(function (res) {
      return res.json().then(function (json) {
        return { ok: res.ok, status: res.status, json: json };
      });
    });
  }

  function findRegisterFormFields(root) {
    var fields = {
      email: null,
      emailCode: null,
      password: null,
      repassword: null,
      invite: null,
      submit: null,
      emailCodeGroup: null
    };
    var passwordInputs = [];
    Array.prototype.forEach.call(root.querySelectorAll('input'), function (input) {
      var type = (input.getAttribute('type') || '').toLowerCase();
      var ph = input.getAttribute('placeholder') || '';
      if (type === 'password') {
        passwordInputs.push(input);
        return;
      }
      if (type === 'checkbox') return;
      if (ph.indexOf('验证码') >= 0) {
        fields.emailCode = input;
        return;
      }
      if (ph.indexOf('邀请') >= 0) {
        fields.invite = input;
        return;
      }
      if (ph.indexOf('邮箱') >= 0 || type === 'email') {
        fields.email = input;
        return;
      }
      if (type === 'text' || type === '') {
        if (!fields.email) fields.email = input;
        else if (!fields.invite) fields.invite = input;
      }
    });
    fields.password = passwordInputs[0] || null;
    fields.repassword = passwordInputs[1] || null;
    if (fields.emailCode) {
      fields.emailCodeGroup = fields.emailCode.closest('.form-group') || fields.emailCode.parentElement;
    }
    var buttons = root.querySelectorAll('button[type="submit"], button.btn-primary');
    if (buttons.length) fields.submit = buttons[buttons.length - 1];
    return fields;
  }

  function setGroupVisible(el, visible) {
    if (!el) return;
    var group = el.closest ? (el.closest('.form-group') || el.parentElement) : el.parentElement;
    if (group) group.style.display = visible ? '' : 'none';
  }

  function ensureRegHint(root, text, type) {
    ensureStyle();
    var el = document.getElementById(REG_HINT_ID);
    if (!el) {
      el = document.createElement('div');
      el.id = REG_HINT_ID;
      var firstGroup = root.querySelector('.form-group');
      if (firstGroup && firstGroup.parentNode) {
        firstGroup.parentNode.insertBefore(el, firstGroup);
      } else {
        root.insertBefore(el, root.firstChild);
      }
    }
    el.className = type || '';
    el.id = REG_HINT_ID;
    el.textContent = text || '';
    el.style.display = text ? '' : 'none';
    return el;
  }

  function getEmailValue(fields, cfg) {
    if (!fields.email) return '';
    var local = String(fields.email.value || '').trim();
    if (cfg && cfg.email_whitelist_suffix && Array.isArray(cfg.email_whitelist_suffix)) {
      var select = fields.email.parentElement && fields.email.parentElement.querySelector('select');
      if (select && select.value) return local + '@' + select.value;
    }
    return local;
  }

  function applyAuthDataAndGo(data) {
    if (!data) return;
    try {
      if (data.auth_data) window.localStorage.setItem('authorization', data.auth_data);
      if (data.token) window.localStorage.setItem('token', data.token);
    } catch (e) {}
    location.hash = '#/dashboard';
    setTimeout(function () { location.reload(); }, 50);
  }

  function enhanceRegisterPage(cfg) {
    if (!isRegisterPage() || !isLinkRegisterMode(cfg)) {
      var oldHint = document.getElementById(REG_HINT_ID);
      if (oldHint) oldHint.remove();
      return;
    }

    var root = findLoginFormRoot();
    if (!root) return;
    var fields = findRegisterFormFields(root);
    if (!fields.email || !fields.submit) return;

    var registerToken = getHashQuery().get('register_token') || '';
    var modeKey = (registerToken ? 'complete:' : 'send:') + registerToken;
    if (root.getAttribute('data-v2b-reg-mode') === modeKey && fields.submit.getAttribute('data-v2b-reg-bound') === '1') {
      return;
    }
    root.setAttribute('data-v2b-reg-mode', modeKey);

    if (fields.emailCodeGroup) fields.emailCodeGroup.style.display = 'none';
    else setGroupVisible(fields.emailCode, false);

    // 隐藏主题自带的「发送验证码」按钮所在列
    Array.prototype.forEach.call(root.querySelectorAll('button'), function (btn) {
      var text = (btn.textContent || '').trim();
      if (text === '发送' || text.indexOf('发送') === 0) {
        var col = btn.closest('.col-3') || btn.parentElement;
        if (col && !btn.classList.contains('btn-block')) {
          // 可能是发送验证码按钮
          if (fields.emailCodeGroup && fields.emailCodeGroup.contains(btn)) {
            btn.style.display = 'none';
          }
        }
      }
    });

    if (registerToken) {
      ensureRegHint(root, '邮箱已验证。请设置登录密码以完成注册。', '');
      setGroupVisible(fields.password, true);
      setGroupVisible(fields.repassword, true);
      if (fields.email) {
        fields.email.readOnly = true;
      }

      passportRequest('/auth/checkRegisterLink?token=' + encodeURIComponent(registerToken), 'GET')
        .then(function (res) {
          if (res.ok && res.json && res.json.data) {
            if (fields.email && res.json.data.email) {
              fields.email.value = res.json.data.email;
              fields.email.readOnly = true;
            }
            if (fields.invite && res.json.data.invite_code) {
              fields.invite.value = res.json.data.invite_code;
            }
          } else {
            ensureRegHint(root, (res.json && res.json.message) || '链接无效或已过期，请重新获取', 'error');
          }
        })
        .catch(function () {
          ensureRegHint(root, '无法校验链接，请稍后重试', 'error');
        });

      fields.submit.setAttribute('data-v2b-reg-bound', '1');
      var span = fields.submit.querySelector('span');
      if (span) span.lastChild && (span.lastChild.textContent = '完成注册');
      else fields.submit.textContent = '完成注册';

      fields.submit.addEventListener('click', function onCompleteRegister(ev) {
        ev.preventDefault();
        ev.stopPropagation();
        if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();

        var password = fields.password ? fields.password.value : '';
        var repassword = fields.repassword ? fields.repassword.value : '';
        if (!password || password.length < 8) {
          ensureRegHint(root, '密码至少需要 8 位', 'error');
          return false;
        }
        if (password !== repassword) {
          ensureRegHint(root, '两次密码输入不同', 'error');
          return false;
        }
        var invite = fields.invite ? String(fields.invite.value || '').trim() : '';
        fields.submit.disabled = true;
        ensureRegHint(root, '正在完成注册...', '');
        passportRequest('/auth/registerWithLink', 'POST', {
          token: registerToken,
          password: password,
          invite_code: invite
        }).then(function (res) {
          if (res.ok && res.json && res.json.data) {
            ensureRegHint(root, '注册成功，正在登录...', 'success');
            applyAuthDataAndGo(res.json.data);
          } else {
            ensureRegHint(root, (res.json && res.json.message) || '注册失败', 'error');
            fields.submit.disabled = false;
          }
        }).catch(function () {
          ensureRegHint(root, '网络错误，请重试', 'error');
          fields.submit.disabled = false;
        });
        return false;
      }, true);
      return;
    }

    setGroupVisible(fields.password, false);
    setGroupVisible(fields.repassword, false);
    ensureRegHint(root, '请填写邮箱后发送注册邮件，打开邮件中的链接设置密码即可完成注册。', '');

    fields.submit.setAttribute('data-v2b-reg-bound', '1');
    var submitSpan = fields.submit.querySelector('span');
    if (submitSpan) {
      var icon = submitSpan.querySelector('i');
      submitSpan.textContent = '';
      if (icon) submitSpan.appendChild(icon);
      submitSpan.appendChild(document.createTextNode('发送注册邮件'));
    } else {
      fields.submit.textContent = '发送注册邮件';
    }

    fields.submit.addEventListener('click', function onSendRegisterLink(ev) {
      ev.preventDefault();
      ev.stopPropagation();
      if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();

      var email = getEmailValue(fields, cfg);
      var invite = fields.invite ? String(fields.invite.value || '').trim() : '';
      if (!email || email.indexOf('@') < 0) {
        ensureRegHint(root, '请填写有效邮箱', 'error');
        return false;
      }
      if (Number(cfg.is_invite_force) === 1 && !invite) {
        ensureRegHint(root, '请填写邀请码', 'error');
        return false;
      }
      fields.submit.disabled = true;
      ensureRegHint(root, '正在发送邮件...', '');
      passportRequest('/auth/sendRegisterLink', 'POST', {
        email: email,
        invite_code: invite
      }).then(function (res) {
        if (res.ok && res.json && res.json.data) {
          ensureRegHint(root, '注册邮件已发送，请查收（约 30 分钟内有效）。打开邮件中的链接设置密码即可完成注册。', 'success');
        } else {
          ensureRegHint(root, (res.json && res.json.message) || '发送失败', 'error');
        }
        fields.submit.disabled = false;
      }).catch(function () {
        ensureRegHint(root, '网络错误，请重试', 'error');
        fields.submit.disabled = false;
      });
      return false;
    }, true);
  }

  function maybeEnhanceRegister() {
    if (!isRegisterPage()) return;
    loadGuestConfig().then(function (cfg) {
      enhanceRegisterPage(cfg);
    });
  }

  function enhanceForgetPage(cfg) {
    if (!isForgetPage() || !isLinkPasswordResetMode(cfg)) {
      return;
    }

    var root = findLoginFormRoot();
    if (!root) return;
    var fields = findRegisterFormFields(root);
    if (!fields.email || !fields.submit) return;

    var resetToken = getHashQuery().get('reset_token') || '';
    var modeKey = (resetToken ? 'complete:' : 'send:') + resetToken;
    if (root.getAttribute('data-v2b-reset-mode') === modeKey && fields.submit.getAttribute('data-v2b-reset-bound') === '1') {
      return;
    }
    root.setAttribute('data-v2b-reset-mode', modeKey);

    if (fields.emailCodeGroup) fields.emailCodeGroup.style.display = 'none';
    else setGroupVisible(fields.emailCode, false);

    Array.prototype.forEach.call(root.querySelectorAll('button'), function (btn) {
      var text = (btn.textContent || '').trim();
      if (text === '发送' || text.indexOf('发送') === 0) {
        if (fields.emailCodeGroup && fields.emailCodeGroup.contains(btn)) {
          btn.style.display = 'none';
        }
      }
    });

    if (resetToken) {
      ensureRegHint(root, '邮箱已验证。请设置新的登录密码。', '');
      setGroupVisible(fields.password, true);
      setGroupVisible(fields.repassword, true);
      if (fields.email) fields.email.readOnly = true;

      passportRequest('/auth/checkPasswordResetLink?token=' + encodeURIComponent(resetToken), 'GET')
        .then(function (res) {
          if (res.ok && res.json && res.json.data) {
            if (fields.email && res.json.data.email) {
              fields.email.value = res.json.data.email;
              fields.email.readOnly = true;
            }
          } else {
            ensureRegHint(root, (res.json && res.json.message) || '链接无效或已过期，请重新获取', 'error');
          }
        })
        .catch(function () {
          ensureRegHint(root, '无法校验链接，请稍后重试', 'error');
        });

      fields.submit.setAttribute('data-v2b-reset-bound', '1');
      var span = fields.submit.querySelector('span');
      if (span) span.lastChild && (span.lastChild.textContent = '完成重置');
      else fields.submit.textContent = '完成重置';

      fields.submit.addEventListener('click', function onCompleteReset(ev) {
        ev.preventDefault();
        ev.stopPropagation();
        if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();

        var password = fields.password ? fields.password.value : '';
        var repassword = fields.repassword ? fields.repassword.value : '';
        if (!password || password.length < 8) {
          ensureRegHint(root, '密码至少需要 8 位', 'error');
          return false;
        }
        if (password !== repassword) {
          ensureRegHint(root, '两次密码输入不同', 'error');
          return false;
        }
        fields.submit.disabled = true;
        ensureRegHint(root, '正在重置密码...', '');
        passportRequest('/auth/resetPasswordWithLink', 'POST', {
          token: resetToken,
          password: password
        }).then(function (res) {
          if (res.ok && res.json && res.json.data) {
            ensureRegHint(root, '密码已重置，请使用新密码登录', 'success');
            setTimeout(function () {
              location.hash = '#/login';
              location.reload();
            }, 800);
          } else {
            ensureRegHint(root, (res.json && res.json.message) || '重置失败', 'error');
            fields.submit.disabled = false;
          }
        }).catch(function () {
          ensureRegHint(root, '网络错误，请重试', 'error');
          fields.submit.disabled = false;
        });
        return false;
      }, true);
      return;
    }

    setGroupVisible(fields.password, false);
    setGroupVisible(fields.repassword, false);
    ensureRegHint(root, '请填写邮箱后发送重置邮件，打开邮件中的链接设置新密码。', '');

    fields.submit.setAttribute('data-v2b-reset-bound', '1');
    var submitSpan = fields.submit.querySelector('span');
    if (submitSpan) {
      var icon = submitSpan.querySelector('i');
      submitSpan.textContent = '';
      if (icon) submitSpan.appendChild(icon);
      submitSpan.appendChild(document.createTextNode('发送重置邮件'));
    } else {
      fields.submit.textContent = '发送重置邮件';
    }

    fields.submit.addEventListener('click', function onSendResetLink(ev) {
      ev.preventDefault();
      ev.stopPropagation();
      if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();

      var email = getEmailValue(fields, cfg);
      if (!email || email.indexOf('@') < 0) {
        ensureRegHint(root, '请填写有效邮箱', 'error');
        return false;
      }
      fields.submit.disabled = true;
      ensureRegHint(root, '正在发送邮件...', '');
      passportRequest('/auth/sendPasswordResetLink', 'POST', {
        email: email
      }).then(function (res) {
        if (res.ok && res.json && res.json.data) {
          ensureRegHint(root, '重置邮件已发送，请查收（约 30 分钟内有效）。', 'success');
        } else {
          ensureRegHint(root, (res.json && res.json.message) || '发送失败', 'error');
        }
        fields.submit.disabled = false;
      }).catch(function () {
        ensureRegHint(root, '网络错误，请重试', 'error');
        fields.submit.disabled = false;
      });
      return false;
    }, true);
  }

  function maybeEnhanceForget() {
    if (!isForgetPage()) return;
    loadGuestConfig().then(function (cfg) {
      enhanceForgetPage(cfg);
    });
  }

  function boot() {
    loadProviders();
    maybeShowSetup();
    maybeEnhanceRegister();
    maybeEnhanceForget();
    window.addEventListener('hashchange', function () {
      guestConfigCache = null;
      setTimeout(function () {
        loadProviders();
        maybeShowSetup();
        maybeEnhanceRegister();
        maybeEnhanceForget();
      }, 250);
    });
    var retries = 0;
    var timer = setInterval(function () {
      retries++;
      if (isLoginPage() && !document.querySelector('.' + BOX_CLASS)) {
        loadProviders();
      }
      maybeShowSetup();
      maybeEnhanceRegister();
      maybeEnhanceForget();
      if (retries >= 20 || document.getElementById(SETUP_ID)) {
        if (retries >= 20) clearInterval(timer);
      }
    }, 800);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
