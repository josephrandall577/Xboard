<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>套餐赠送时长管理</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
    background: #f5f6f8; color: #26282b; padding: 24px; min-height: 100vh;
  }
  .container { max-width: 1080px; margin: 0 auto; }
  .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
  h1 { font-size: 20px; font-weight: 600; }
  .subtitle { color: #8a8f99; font-size: 13px; margin-top: 4px; }
  .btn {
    border: 1px solid #d9dbe0; background: #fff; color: #26282b; border-radius: 6px;
    padding: 7px 14px; font-size: 13px; cursor: pointer; transition: all .15s;
  }
  .btn:hover { border-color: #3464e0; color: #3464e0; }
  .btn-primary { background: #3464e0; border-color: #3464e0; color: #fff; }
  .btn-primary:hover { background: #2a56c4; color: #fff; }
  .btn-danger:hover { border-color: #e04434; color: #e04434; }
  .btn:disabled { opacity: .5; cursor: not-allowed; }
  .card { background: #fff; border: 1px solid #eaecf0; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 10px 8px; text-align: left; font-size: 13px; border-bottom: 1px solid #f0f1f4; vertical-align: middle; }
  th { color: #8a8f99; font-weight: 500; white-space: nowrap; }
  .plan-name { font-weight: 600; font-size: 14px; }
  .badges { margin-top: 4px; display: flex; gap: 4px; flex-wrap: wrap; }
  .badge { font-size: 11px; padding: 1px 6px; border-radius: 4px; background: #f0f1f4; color: #8a8f99; }
  .badge.on { background: #e8f1ff; color: #3464e0; }
  .bonus-input {
    width: 64px; padding: 6px 8px; border: 1px solid #d9dbe0; border-radius: 6px;
    font-size: 13px; text-align: center;
  }
  .bonus-input:focus { outline: none; border-color: #3464e0; box-shadow: 0 0 0 2px rgba(52,100,224,.12); }
  .bonus-input:disabled { background: #f7f8fa; color: #c0c4cc; }
  .na { color: #c0c4cc; }
  .actions { display: flex; gap: 6px; white-space: nowrap; }
  .toast {
    position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 99;
    padding: 10px 20px; border-radius: 8px; font-size: 13px; color: #fff;
    background: #26282b; box-shadow: 0 4px 16px rgba(0,0,0,.18); display: none;
  }
  .toast.error { background: #e04434; }
  .token-panel { max-width: 480px; margin: 80px auto; text-align: center; }
  .token-panel input[type=text] {
    width: 100%; padding: 10px 12px; border: 1px solid #d9dbe0; border-radius: 6px;
    font-size: 13px; margin: 12px 0;
  }
  .muted { color: #8a8f99; font-size: 12px; line-height: 1.8; }
  .loading { text-align: center; padding: 40px; color: #8a8f99; }
  .empty { text-align: center; padding: 40px; color: #8a8f99; }
</style>
</head>
<body>
<div class="toast" id="toast"></div>

<!-- 票据输入面板：自动探测失败时显示 -->
<div class="token-panel card" id="tokenPanel" style="display:none">
  <h1 style="margin-bottom:8px">需要管理员票据</h1>
  <p class="muted">
    未能在本浏览器中找到可用的管理面板登录票据。<br>
    请先登录管理面板，然后回到本页面刷新；<br>
    或直接粘贴管理面板的 Bearer 票据（登录接口返回的 auth_data）：
  </p>
  <input type="text" id="tokenInput" placeholder="Bearer eyJ...">
  <button class="btn btn-primary" id="tokenSubmit">保存并继续</button>
</div>

<div class="container" id="app" style="display:none">
  <div class="page-header">
    <div>
      <h1>套餐赠送时长管理</h1>
      <div class="subtitle">按周期配置赠送月数（如年付送 6 个月）。价格不变，开通时到期时间自动叠加赠送月数。</div>
    </div>
    <div>
      <button class="btn" id="refreshBtn">刷新</button>
      <button class="btn btn-danger" id="resetTokenBtn">重置票据</button>
    </div>
  </div>
  <div class="card">
    <div id="tableWrap" class="loading">加载中…</div>
  </div>
  <p class="muted">
    说明：填写 0 或留空表示该周期不赠送；「清空全部」会移除该套餐的所有赠送配置（活动下线）。
    已下单的订单按下单时的赠送快照发放，修改此处的配置不影响在途订单。
  </p>
</div>

<script>
(function () {
  var API_BASE = '/api/plugin/plan-bonus';
  var TOKEN_KEY = 'plan_bonus_admin_token';
  var state = { token: null, plans: [], periods: {}, maxBonus: 36 };

  var toastEl = document.getElementById('toast');
  function toast(msg, isError) {
    toastEl.textContent = msg;
    toastEl.className = 'toast' + (isError ? ' error' : '');
    toastEl.style.display = 'block';
    setTimeout(function () { toastEl.style.display = 'none'; }, 2600);
  }

  function normalizeToken(v) {
    if (!v) return null;
    v = String(v).trim();
    if (!v) return null;
    if (!/^Bearer\s/i.test(v)) v = 'Bearer ' + v;
    return v;
  }

  // 管理面板 token 的已知存储键（Xboard admin bundle: prefix Xboard_ + access_token）
  var ADMIN_TOKEN_STORAGE_KEYS = ['Xboard_access_token', 'access_token'];

  // 从任意 localStorage 值中提取候选 token：
  // 支持原始 Bearer 串、裸 token、以及 JSON 包装（如带过期时间的 {value, expire} 结构）
  function extractTokensFromValue(v) {
    var out = [];
    if (!v) return out;
    var m = v.match(/Bearer\s+[A-Za-z0-9.\-_~+/=]{20,}/g);
    if (m) out = out.concat(m);
    if (typeof v === 'string') {
      if (/^[\w\-.]{20,}$/.test(v)) out.push(v);
      try {
        v = JSON.parse(v);
      } catch (e) { return out; }
    }
    var walk = function (o) {
      if (!o) return;
      if (typeof o === 'string') {
        var mm = o.match(/Bearer\s+[A-Za-z0-9.\-_~+/=]{20,}/g);
        if (mm) { out = out.concat(mm); return; }
        if (/^[\w\-.]{20,}$/.test(o)) out.push(o);
        return;
      }
      if (typeof o === 'object') {
        for (var k in o) {
          if (/token|auth|value/i.test(k)) walk(o[k]);
        }
      }
    };
    walk(v);
    return out;
  }

  function collectTokenCandidates() {
    var candidates = [];
    var seen = {};
    function add(v) {
      v = normalizeToken(v);
      if (v && !seen[v]) { seen[v] = true; candidates.push(v); }
    }
    try {
      add(localStorage.getItem(TOKEN_KEY));
      ADMIN_TOKEN_STORAGE_KEYS.forEach(function (k) {
        extractTokensFromValue(localStorage.getItem(k)).forEach(add);
      });
      for (var i = 0; i < localStorage.length; i++) {
        var k = localStorage.key(i);
        var v = (localStorage.getItem(k) || '').trim();
        if (!v) continue;
        if (/^Bearer\s+\S+$/i.test(v)) { add(v); continue; }
        if (/token|auth/i.test(k)) {
          if (/^[\w\-.]{20,}$/.test(v)) add(v);
          extractTokensFromValue(v).forEach(add);
          continue;
        }
        // 兼容未来 bundle 键名变化：全量扫描 JSON 包装的值
        extractTokensFromValue(v).forEach(add);
      }
    } catch (e) {}
    return candidates;
  }

  function api(path, options) {
    options = options || {};
    options.headers = Object.assign(
      { 'Accept': 'application/json', 'Authorization': state.token },
      options.body ? { 'Content-Type': 'application/json' } : {}
    );
    return fetch(API_BASE + path, options).then(function (resp) {
      return resp.json().catch(function () { return {}; }).then(function (body) {
        return { status: resp.status, body: body };
      });
    });
  }

  function ensureToken() {
    var candidates = collectTokenCandidates();
    var chain = Promise.reject();
    candidates.forEach(function (token) {
      chain = chain.catch(function () {
        state.token = token;
        return api('/plans').then(function (r) {
          if (r.status === 200) {
            try { localStorage.setItem(TOKEN_KEY, token); } catch (e) {}
            state.plans = (r.body.data && r.body.data.plans) || [];
            state.periods = (r.body.data && r.body.data.periods) || {};
            state.maxBonus = (r.body.data && r.body.data.max_bonus_months) || 36;
            return true;
          }
          return Promise.reject(r.status);
        });
      });
    });
    return chain;
  }

  function showTokenPanel() {
    document.getElementById('tokenPanel').style.display = 'block';
    document.getElementById('app').style.display = 'none';
  }

  function showApp() {
    document.getElementById('tokenPanel').style.display = 'none';
    document.getElementById('app').style.display = 'block';
    renderTable();
  }

  function esc(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function badge(text, on) {
    return '<span class="badge' + (on ? ' on' : '') + '">' + esc(text) + '</span>';
  }

  function renderTable() {
    var wrap = document.getElementById('tableWrap');
    if (!state.plans.length) {
      wrap.className = 'empty';
      wrap.textContent = '暂无套餐，请先前往管理面板创建订阅套餐。';
      return;
    }
    var periodKeys = Object.keys(state.periods);
    var html = '<table><thead><tr><th>套餐</th>';
    periodKeys.forEach(function (p) {
      html += '<th>' + esc(state.periods[p].name) + '<br><span class="muted">赠送(月)</span></th>';
    });
    html += '<th>操作</th></tr></thead><tbody>';

    state.plans.forEach(function (plan) {
      html += '<tr data-id="' + plan.id + '">';
      html += '<td><div class="plan-name">#' + plan.id + ' ' + esc(plan.name) + '</div>'
        + '<div class="badges">'
        + badge('显示', plan.show) + badge('在售', plan.sell) + badge('可续费', plan.renew)
        + '</div></td>';

      periodKeys.forEach(function (period) {
        var price = plan.prices ? plan.prices[period] : null;
        if (price === null || price === undefined || !(price > 0)) {
          html += '<td><span class="na">—</span></td>';
          return;
        }
        var value = (plan.bonuses && plan.bonuses[period]) ? plan.bonuses[period] : '';
        html += '<td><input class="bonus-input" type="number" min="0" max="' + state.maxBonus
          + '" step="1" data-period="' + esc(period) + '" value="' + esc(value) + '" placeholder="0"></td>';
      });

      html += '<td><div class="actions">'
        + '<button class="btn btn-primary act-save">保存</button>'
        + '<button class="btn btn-danger act-clear">清空全部</button>'
        + '</div></td></tr>';
    });

    html += '</tbody></table>';
    wrap.className = '';
    wrap.innerHTML = html;
  }

  function readRowBonuses(row) {
    var bonuses = {};
    var invalid = null;
    row.querySelectorAll('.bonus-input').forEach(function (input) {
      var period = input.getAttribute('data-period');
      var raw = input.value.trim();
      if (raw === '') return;
      var months = parseInt(raw, 10);
      if (isNaN(months) || months < 0 || months > state.maxBonus) {
        invalid = period;
        return;
      }
      if (months > 0) bonuses[period] = months;
    });
    return { bonuses: bonuses, invalid: invalid };
  }

  function savePlan(planId, bonuses) {
    return api('/save', {
      method: 'POST',
      body: JSON.stringify({ plan_id: planId, bonuses: bonuses })
    }).then(function (r) {
      if (r.status === 200) {
        var saved = r.body.data && r.body.data.bonuses;
        var plan = state.plans.find(function (p) { return p.id === planId; });
        if (plan) plan.bonuses = saved || {};
        renderTable();
        toast('已保存');
        return;
      }
      var msg = (r.body && r.body.message) || ('保存失败（HTTP ' + r.status + '）');
      if (r.status === 403 || r.status === 401) {
        msg = '票据无效或已过期，请重新设置票据';
        resetToken();
      }
      toast(msg, true);
    }).catch(function () {
      toast('网络错误，保存失败', true);
    });
  }

  function resetToken() {
    try { localStorage.removeItem(TOKEN_KEY); } catch (e) {}
    state.token = null;
    showTokenPanel();
  }

  function boot() {
    ensureToken().then(showApp).catch(showTokenPanel);
  }

  document.getElementById('tableWrap').addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('button') : null;
    if (!btn) return;
    var row = btn.closest('tr');
    if (!row) return;
    var planId = parseInt(row.getAttribute('data-id'), 10);
    if (!planId) return;

    if (btn.classList.contains('act-save')) {
      var result = readRowBonuses(row);
      if (result.invalid) {
        toast('「' + (state.periods[result.invalid] ? state.periods[result.invalid].name : result.invalid)
          + '」赠送月数无效（0～' + state.maxBonus + ' 的整数）', true);
        return;
      }
      btn.disabled = true;
      savePlan(planId, result.bonuses).finally(function () { btn.disabled = false; });
    }

    if (btn.classList.contains('act-clear')) {
      if (!confirm('确定清空该套餐的全部赠送配置吗？（已下单订单不受影响）')) return;
      btn.disabled = true;
      savePlan(planId, null).finally(function () { btn.disabled = false; });
    }
  });

  document.getElementById('refreshBtn').addEventListener('click', function () {
    if (!state.token) { showTokenPanel(); return; }
    api('/plans').then(function (r) {
      if (r.status === 200) {
        state.plans = (r.body.data && r.body.data.plans) || [];
        state.periods = (r.body.data && r.body.data.periods) || {};
        renderTable();
      } else {
        toast('刷新失败（HTTP ' + r.status + '）', true);
        if (r.status === 403 || r.status === 401) resetToken();
      }
    });
  });

  document.getElementById('resetTokenBtn').addEventListener('click', resetToken);

  document.getElementById('tokenSubmit').addEventListener('click', function () {
    var raw = (document.getElementById('tokenInput').value || '').trim();
    // 容错：支持粘贴 Bearer 串、裸 token、localStorage 整段 JSON
    var found = raw ? extractTokensFromValue(raw) : [];
    var token = found.length ? normalizeToken(found[0]) : normalizeToken(raw);
    if (!token) { toast('请输入票据', true); return; }
    state.token = token;
    api('/plans').then(function (r) {
      if (r.status === 200) {
        try { localStorage.setItem(TOKEN_KEY, token); } catch (e) {}
        state.plans = (r.body.data && r.body.data.plans) || [];
        state.periods = (r.body.data && r.body.data.periods) || {};
        state.maxBonus = (r.body.data && r.body.data.max_bonus_months) || 36;
        showApp();
      } else {
        toast('票据无效（HTTP ' + r.status + '），请确认是管理员账号的票据', true);
      }
    });
  });

  boot();
})();
</script>
</body>
</html>
