/* =============================================================================
   v2board admin theme runtime (vanilla JS)
   - light / dark / system
   - preset / radius / scale / font / contentLayout
   - localStorage: v2b-admin-theme
   - neutralize legacy /assets/admin/theme/*.css after umi inject
   - floating settings panel
   No React/Vue rewrite. Business logic untouched.
   ============================================================================= */
(function () {
  'use strict';

  var KEY = 'v2b-admin-theme';
  var root = document.documentElement;
  var mql = window.matchMedia('(prefers-color-scheme: dark)');

  var PRESETS = [
    { key: 'default', name: '默认', color: 'oklch(0.62 0.15 248)' },
    { key: 'anthropic', name: 'Anthropic', color: 'oklch(0.685 0.142 38)' },
    { key: 'underground', name: '地下', color: 'oklch(0.5315 0.0694 156.19)' },
    { key: 'simple-large', name: '简约大字', color: 'oklch(0.25 0 0)' },
    { key: 'ocean-breeze', name: '海洋', color: 'oklch(0.5461 0.2152 262.88)' },
    { key: 'rose-garden', name: '玫瑰', color: 'oklch(0.5827 0.2418 12.23)' },
    { key: 'forest-whisper', name: '森林', color: 'oklch(0.5276 0.1072 182.22)' },
    { key: 'lavender-dream', name: '薰衣草', color: 'oklch(0.5709 0.1808 306.89)' },
    { key: 'sunset-glow', name: '日落', color: 'oklch(0.5591 0.1882 25.33)' }
  ];

  var MODES = [
    { k: 'light', n: '浅色' },
    { k: 'dark', n: '深色' },
    { k: 'system', n: '跟随系统' }
  ];
  var RADII = [
    { k: 'sm', n: '小' },
    { k: 'md', n: '中' },
    { k: 'lg', n: '大' },
    { k: 'xl', n: '超' }
  ];
  var SCALES = [
    { k: 'sm', n: '紧凑' },
    { k: 'md', n: '默认' },
    { k: 'lg', n: '宽松' }
  ];
  var FONTS = [
    { k: 'sans', n: '无衬线' },
    { k: 'serif', n: '衬线' }
  ];
  var LAYOUTS = [
    { k: 'full', n: '全宽' },
    { k: 'centered', n: '居中' }
  ];

  var PRESET_DEFAULT_FONT = {
    default: 'sans',
    anthropic: 'serif',
    underground: 'sans',
    'simple-large': 'sans',
    'ocean-breeze': 'sans',
    'rose-garden': 'sans',
    'forest-whisper': 'sans',
    'lavender-dream': 'sans',
    'sunset-glow': 'sans'
  };

  function read() {
    try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; }
    catch (e) { return {}; }
  }

  function write(s) {
    try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {}
  }

  var state = Object.assign({
    mode: 'system',
    preset: 'default',
    radius: 'lg',
    scale: 'md',
    font: 'default',
    contentLayout: 'full'
  }, read());

  function isDark() {
    return state.mode === 'dark' || (state.mode === 'system' && mql.matches);
  }

  function resolveFont() {
    if (state.font && state.font !== 'default') return state.font;
    return PRESET_DEFAULT_FONT[state.preset] || 'sans';
  }

  function apply() {
    root.classList.toggle('dark', isDark());
    root.setAttribute('data-theme-preset', state.preset || 'default');
    root.setAttribute('data-theme-radius', state.radius || 'lg');
    root.setAttribute('data-theme-scale', state.scale || 'md');
    root.setAttribute('data-theme-font', resolveFont());
    root.setAttribute('data-theme-content-layout', state.contentLayout || 'full');
  }

  try {
    mql.addEventListener('change', function () {
      if (state.mode === 'system') apply();
    });
  } catch (e) {
    if (mql.addListener) {
      mql.addListener(function () {
        if (state.mode === 'system') apply();
      });
    }
  }

  apply();

  function isLegacyThemeHref(href) {
    if (!href) return false;
    return /\/assets\/admin\/theme\/[^/?#]+\.css/i.test(href) ||
      /(?:^|\/)theme\/(default|black|darkblue|green)\.css/i.test(href);
  }

  function findCustomCss() {
    return document.getElementById('v2b-admin-custom-css') ||
      document.querySelector('link[rel="stylesheet"][href*="/assets/admin/custom.css"]') ||
      document.querySelector('link[rel="stylesheet"][href*="custom.css"]');
  }

  var obs = null;

  function startObserver() {
    if (!obs) return;
    // 只看 <head> 内的节点增删与 href 变化；绝不监听 disabled（我们自己会写它 → 会自触发）
    obs.observe(document.head || document.documentElement, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['href']
    });
  }

  // 幂等：先算出「确实要改什么」，无事可做就直接返回，不产生任何 DOM 变更。
  // 有改动时先断开观察者再写，写完重连，彻底切断自触发回路。
  function neutralizeLegacyTheme() {
    var custom = findCustomCss();
    var links = document.querySelectorAll('link[rel="stylesheet"]');
    var pending = [];
    var seenCustom = false;
    var needMove = false;

    Array.prototype.forEach.call(links, function (link) {
      if (link === custom) { seenCustom = true; return; }
      if (!isLegacyThemeHref(link.getAttribute('href') || '')) return;
      // 已经压制过的跳过，避免重复写入
      if (!link.disabled || !link.hasAttribute('data-v2b-theme-disabled')) pending.push(link);
      // 仅当旧主题排在 custom.css 之后才需要重新挂到末尾
      if (seenCustom) needMove = true;
    });

    if (!pending.length && !needMove) return;

    if (obs) obs.disconnect();
    pending.forEach(function (link) {
      link.disabled = true;
      link.setAttribute('data-v2b-theme-disabled', '1');
    });
    if (needMove && custom && custom.parentNode) custom.parentNode.appendChild(custom);
    startObserver();
  }

  neutralizeLegacyTheme();

  if (typeof MutationObserver !== 'undefined') {
    obs = new MutationObserver(neutralizeLegacyTheme);
    startObserver();
    setTimeout(neutralizeLegacyTheme, 0);
    setTimeout(neutralizeLegacyTheme, 300);
    setTimeout(neutralizeLegacyTheme, 1200);
  }

  function seg(groupLabel, items, current, onPick) {
    var wrap = document.createElement('div');
    wrap.className = 'nb-theme-group';
    var lab = document.createElement('label');
    lab.textContent = groupLabel;
    wrap.appendChild(lab);
    var segEl = document.createElement('div');
    segEl.className = 'nb-seg';
    items.forEach(function (it) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = it.n;
      b.dataset.k = it.k;
      if (it.k === current()) b.className = 'is-active';
      b.addEventListener('click', function () {
        onPick(it.k);
        Array.prototype.forEach.call(segEl.children, function (c) {
          c.className = c.dataset.k === it.k ? 'is-active' : '';
        });
      });
      segEl.appendChild(b);
    });
    wrap.appendChild(segEl);
    return wrap;
  }

  function syncFontButtons(panel) {
    var want = resolveFont();
    Array.prototype.forEach.call(panel.querySelectorAll('.nb-theme-group'), function (g) {
      var label = g.querySelector('label');
      if (!label || label.textContent !== '字体') return;
      Array.prototype.forEach.call(g.querySelectorAll('button'), function (b) {
        b.className = b.dataset.k === want ? 'is-active' : '';
      });
    });
  }

  function buildPanel() {
    if (document.getElementById('nb-theme-fab')) return;

    var fab = document.createElement('button');
    fab.id = 'nb-theme-fab';
    fab.type = 'button';
    fab.className = 'nb-theme-fab';
    fab.title = '主题设置';
    fab.setAttribute('aria-label', '主题设置');
    fab.textContent = '☼';
    document.body.appendChild(fab);

    var panel = document.createElement('div');
    panel.id = 'nb-theme-panel';
    panel.className = 'nb-theme-panel';
    var title = document.createElement('h4');
    title.textContent = '主题设置';
    panel.appendChild(title);

    panel.appendChild(seg('外观', MODES, function () { return state.mode; }, function (k) {
      state.mode = k; write(state); apply();
    }));

    var pg = document.createElement('div');
    pg.className = 'nb-theme-group';
    var pl = document.createElement('label');
    pl.textContent = '预设主题';
    pg.appendChild(pl);
    var sw = document.createElement('div');
    sw.className = 'nb-swatches';
    PRESETS.forEach(function (p) {
      var d = document.createElement('span');
      d.className = 'nb-swatch' + (p.key === state.preset ? ' is-active' : '');
      d.style.background = p.color;
      d.title = p.name;
      d.dataset.k = p.key;
      d.addEventListener('click', function () {
        state.preset = p.key;
        write(state);
        apply();
        Array.prototype.forEach.call(sw.children, function (c) {
          c.className = 'nb-swatch' + (c.dataset.k === p.key ? ' is-active' : '');
        });
        syncFontButtons(panel);
      });
      sw.appendChild(d);
    });
    pg.appendChild(sw);
    panel.appendChild(pg);

    panel.appendChild(seg('字体', FONTS, function () { return resolveFont(); }, function (k) {
      state.font = k; write(state); apply();
    }));
    panel.appendChild(seg('内容宽度', LAYOUTS, function () { return state.contentLayout || 'full'; }, function (k) {
      state.contentLayout = k; write(state); apply();
    }));
    panel.appendChild(seg('圆角', RADII, function () { return state.radius; }, function (k) {
      state.radius = k; write(state); apply();
    }));
    panel.appendChild(seg('密度', SCALES, function () { return state.scale; }, function (k) {
      state.scale = k; write(state); apply();
    }));

    document.body.appendChild(panel);

    fab.addEventListener('click', function (e) {
      e.stopPropagation();
      panel.classList.toggle('is-open');
    });
    document.addEventListener('click', function (e) {
      if (!panel.contains(e.target) && e.target !== fab) panel.classList.remove('is-open');
    });
  }

  if (document.body) buildPanel();
  else document.addEventListener('DOMContentLoaded', buildPanel);
})();