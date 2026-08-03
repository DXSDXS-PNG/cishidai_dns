const $ = selector => document.querySelector(selector);
const $$ = selector => document.querySelectorAll(selector);
const state = {
  domains: [],
  selectedDomainId: '',
  checkedHost: '',
  checkedDomainId: '',
  tenant: null,
  creditLogs: [],
  siteSettings: {}
};

const SESSION_KEY = 'dnsTenantSession';
const RENTALS_KEY = 'dnsTenantRentals';
const RECHARGE_KEY = 'dnsTenantRecharges';

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, s => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  }[s]));
}

function platformName(platform) {
  return ({ aliyun: '阿里云', tencent: '腾讯云' }[platform] || platform || '默认平台');
}
function normalizeAccount(account) {
  if (!account) return null;
  const credits = Number(account.credits ?? account.balance ?? 0);
  return {
    credits: Number.isFinite(credits) ? credits : 0,
    contact: '',
    ...account
  };
}

function updateTenant(patch) {
  if (!state.tenant) return;
  state.tenant = normalizeAccount({ ...state.tenant, ...patch });
  localStorage.setItem(SESSION_KEY, JSON.stringify({ tenant: state.tenant, loggedAt: new Date().toISOString() }));
}

function getCurrentTenant() {
  try {
    const session = JSON.parse(localStorage.getItem(SESSION_KEY) || 'null');
    if (!session?.tenant?.username) return null;
    return normalizeAccount(session.tenant);
  } catch {
    return null;
  }
}

function setSession(account) {
  const tenant = normalizeAccount(account);
  localStorage.setItem(SESSION_KEY, JSON.stringify({ tenant, loggedAt: new Date().toISOString() }));
  state.tenant = tenant;
}

function clearSession() {
  localStorage.removeItem(SESSION_KEY);
  state.tenant = null;
}

function randomHex(bytes = 16) {
  const values = new Uint8Array(bytes);
  if (window.crypto?.getRandomValues) {
    window.crypto.getRandomValues(values);
  } else {
    for (let i = 0; i < values.length; i += 1) values[i] = Math.floor(Math.random() * 256);
  }
  return Array.from(values, v => v.toString(16).padStart(2, '0')).join('');
}

async function hashPassword(password, salt) {
  const text = `${salt}:${password}`;
  if (window.crypto?.subtle && window.TextEncoder) {
    const bytes = new TextEncoder().encode(text);
    const digest = await window.crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest), v => v.toString(16).padStart(2, '0')).join('');
  }
  return btoa(unescape(encodeURIComponent(text)));
}

function setMessage(selector, message, type = '') {
  const el = $(selector);
  if (!el) return;
  el.textContent = message;
  el.className = `hint ${type}`;
}

function formatLocalDateTime(value) {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  const pad = number => String(number).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

function splitContact(contact) {
  const value = String(contact || '').trim();
  return {
    email: value.includes('@') ? value : '',
    phone: value && !value.includes('@') ? value : ''
  };
}

function renderAvatar(target, avatar) {
  const el = $(target);
  if (!el) return;
  const avatarUrl = avatar || state.siteSettings.defaultAvatarUrl || '';
  if (avatarUrl) {
    el.classList.remove('avatar-placeholder');
    el.innerHTML = `<img src="${esc(avatarUrl)}" alt="头像">`;
  } else {
    el.classList.add('avatar-placeholder');
    el.textContent = '👤';
  }
}

function readRentals() {
  try {
    const rentals = JSON.parse(localStorage.getItem(RENTALS_KEY) || '[]');
    return Array.isArray(rentals) ? rentals : [];
  } catch {
    return [];
  }
}

function writeRentals(rentals) {
  localStorage.setItem(RENTALS_KEY, JSON.stringify(rentals));
}

function readRecharges() {
  try {
    const recharges = JSON.parse(localStorage.getItem(RECHARGE_KEY) || '[]');
    return Array.isArray(recharges) ? recharges : [];
  } catch {
    return [];
  }
}

function writeRecharges(recharges) {
  localStorage.setItem(RECHARGE_KEY, JSON.stringify(recharges));
}

async function loadCredits() {
  if (!state.tenant?.username) return Number(state.tenant?.credits || 0);
  const data = await api(`/api/public/credits?username=${encodeURIComponent(state.tenant.username)}`);
  const credits = Number(data.credits || 0);
  const patch = { credits: Number.isFinite(credits) ? credits : 0 };
  updateTenant(patch);
  renderDashboard();
  return Number(state.tenant.credits || 0);
}

async function loadCreditLogs() {
  if (!state.tenant?.username) return [];
  const data = await api(`/api/public/credit-logs?username=${encodeURIComponent(state.tenant.username)}`);
  state.creditLogs = Array.isArray(data.logs) ? data.logs : [];
  renderDashboard();
  return state.creditLogs;
}


async function syncTenantUser(account) {
  if (!account?.username) return null;
  const data = await api('/api/public/tenant-users', {
    method: 'POST',
    body: JSON.stringify({
      id: account.id,
      username: account.username,
      name: account.name || '',
      contact: account.contact || '',
      phone: account.phone || '',
      email: account.email || '',
      password: account.password || ''
    })
  });
  return data.user || null;
}

async function fetchTenantUser(username) {
  try {
    const data = await api(`/api/public/tenant-users/${encodeURIComponent(username)}`);
    return data.user || null;
  } catch {
    return null;
  }
}

async function updateTenantUserProfile(account) {
  if (!account?.username) return null;
  try {
    const data = await api(`/api/public/tenant-users/${encodeURIComponent(account.username)}`, {
      method: 'PATCH',
      body: JSON.stringify({
        name: account.name || '',
        contact: account.contact || '',
        phone: account.phone || '',
        email: account.email || ''
      })
    });
    return data.user || null;
  } catch {
    return null;
  }
}

async function tenantLogin(username, password) {
  const data = await api('/api/public/tenant-login', {
    method: 'POST',
    body: JSON.stringify({ username, password })
  });
  return data.user || null;
}

async function changeTenantPassword(username, currentPassword, newPassword) {
  const data = await api('/api/public/tenant-password', {
    method: 'POST',
    body: JSON.stringify({ username, currentPassword, newPassword })
  });
  return data.user || null;
}

function applyAuthImage(url) {
  const value = String(url || '').trim();
  const el = document.querySelector('.auth-copy');
  if (!el || !value) return;
  const image = new Image();
  image.onload = () => {
    el.style.backgroundImage = `url("${value.replace(/"/g, '\\"')}")`;
    el.classList.add('has-custom-image');
  };
  image.src = value;
}

async function loadSiteSettings() {
  try {
    const settings = await api('/api/public/site-settings');
    state.siteSettings = settings || {};
    applyAuthImage(settings.authImageUrl);
    renderNotices();
    if (state.tenant) renderDashboard();
  } catch {
  }
}

function renderNotices() {
  const listEl = $('#noticeList');
  if (!listEl) return;
  const notices = Array.isArray(state.siteSettings.userNotices) ? state.siteSettings.userNotices : [];
  const active = notices.filter(item => (item.status || 'active') === 'active');
  if (!active.length) {
    listEl.innerHTML = '<div class="notice-empty"><strong>暂无公告</strong><span>平台通知会显示在这里。</span></div>';
    return;
  }
  listEl.innerHTML = active.map(item => {
    const level = item.level || 'info';
    const levelText = {info:'通知',success:'公告',warning:'提醒',danger:'重要'}[level] || '通知';
    const time = item.createdAt || item.created_at || '';
    return '<article class="notice-item notice-' + esc(level) + '">'
      + '<div class="notice-main">'
      + '<div class="notice-icon">' + esc(levelText.slice(0, 1)) + '</div>'
      + '<div class="notice-content">'
      + '<div class="notice-title-row"><h3>' + esc(item.title || '平台通知') + '</h3><span class="notice-pill">' + esc(levelText) + '</span></div>'
      + '<div class="notice-meta">' + esc(time || '刚刚发布') + '</div>'
      + '<p>' + esc(item.body || '') + '</p>'
      + '</div>'
      + '</div>'
      + '</article>';
  }).join('');
}

function applyRechargeReturn() {
  if (!state.tenant) return;
  const params = new URLSearchParams(window.location.search);
  const tradeNo = params.get('trade_no') || params.get('out_trade_no') || '';
  if (params.get('recharge') !== 'success' && !tradeNo) return;
  const recharges = readRecharges();
  const recharge = recharges.find(item => item.tradeNo === tradeNo && item.tenantId === state.tenant.id);
  setMessage('#rechargeMsg', '已返回支付页面，正在同步支付结果...', '');
  pollRecharge(tradeNo, 30, recharge).catch(error => setMessage('#rechargeMsg', error.message, 'bad'));
  window.history.replaceState({}, document.title, window.location.pathname);
}

async function settleRecharge(recharge) {
  const remote = await api(`/api/public/recharge/${encodeURIComponent(recharge.tradeNo)}`);
  if (remote.status !== 'paid') return false;
  const totalCredits = Number(remote.credits || 0);
  const addedCredits = Number(remote.addedCredits || recharge.credits || remote.amount || 0);
  if (!Number.isFinite(totalCredits)) throw new Error('服务器返回的积分余额异常，请联系管理员。');
  updateTenant({ credits: totalCredits });
  const recharges = readRecharges();
  writeRecharges(recharges.map(item => item.tradeNo === recharge.tradeNo ? { ...item, status: 'paid', paidAt: remote.paidAt || new Date().toISOString() } : item));
  setMessage('#rechargeMsg', `支付成功，到账 ${addedCredits.toFixed(0)} 积分，当前积分 ${totalCredits.toFixed(0)}。`, 'ok');
  renderDashboard();
  loadCreditLogs().catch(() => {});
  return true;
}

async function pollRecharge(tradeNo, maxTimes = 80, fallbackRecharge = null) {
  for (let i = 0; i < maxTimes; i += 1) {
    const recharge = readRecharges().find(item => item.tradeNo === tradeNo && item.tenantId === state.tenant.id && item.status === 'pending') ||
      fallbackRecharge ||
      { tradeNo, tenantId: state.tenant.id, credits: 0 };
    if (!recharge) return;
    try {
      if (await settleRecharge(recharge)) return;
      await loadCredits();
      setMessage('#rechargeMsg', '等待支付平台回调，回调成功后积分会自动同步...', '');
    } catch (error) {
      setMessage('#rechargeMsg', error.message, 'bad');
      return;
    }
    await new Promise(resolve => setTimeout(resolve, i < 10 ? 1000 : 3000));
  }
  await loadCredits().catch(() => {});
  setMessage('#rechargeMsg', '暂未收到支付平台回调，请稍后刷新页面，系统会从数据库同步积分余额。', 'bad');
}

function currentRentals() {
  if (!state.tenant) return [];
  return readRentals().filter(item => item.tenantId === state.tenant.id);
}

function setActivePanel(panelId) {
  document.querySelectorAll('.side-item').forEach(item => {
    item.classList.toggle('active', item.dataset.panel === panelId);
  });
  document.getElementById(panelId)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function renderDashboard() {
  if (!state.tenant) return;
  const rentals = currentRentals();
  const credits = Number(state.tenant.credits ?? state.tenant.balance ?? 0);
  const displayName = state.tenant.name || state.tenant.username;
  const contact = splitContact(state.tenant.contact);
  $('#statCredits').textContent = credits.toFixed(0);
  $('#statRecords').textContent = rentals.length;
  $('#statUsername').textContent = displayName;
  $('#overviewUsername').textContent = displayName;
  $('#overviewCredits').textContent = credits.toFixed(0);
  $('#overviewRecords').textContent = rentals.length;
  $('#overviewContact').textContent = state.tenant.contact || '-';
  $('#currentBalance').textContent = credits.toFixed(0);
  $('#profileCardName').textContent = displayName;
  $('#profileCardUsername').textContent = state.tenant.username;
  $('#profileMetaUsername').textContent = displayName;
  $('#profileMetaEmail').textContent = contact.email || state.tenant.contact || '-';
  $('#profileMetaCreated').textContent = formatLocalDateTime(state.tenant.createdAt);
  $('#profileUsernameInput').value = state.tenant.username || '';
  $('#profileNameInput').value = state.tenant.name || state.tenant.username || '';
  $('#profilePhoneInput').value = state.tenant.phone || contact.phone || '';
  $('#profileEmailInput').value = state.tenant.email || contact.email || '';
  renderAvatar('#topAvatar', state.tenant.avatar);
  renderAvatar('#profileAvatarPreview', state.tenant.avatar);
  const logList = $('#creditLogList');
  if (logList) {
    logList.innerHTML = state.creditLogs.length ? state.creditLogs.slice(0, 12).map(log => {
      const amount = Number(log.amount || 0);
      const positive = amount > 0;
      const title = log.remark || (positive ? '积分充值' : '积分消费');
      return `
        <article class="credit-log-item ${positive ? 'plus' : 'minus'}">
          <div>
            <strong>${esc(title)}</strong>
            <span>${esc(log.created_at || '-')}</span>
          </div>
          <b>${positive ? '+' : ''}${amount.toFixed(0)}</b>
        </article>`;
    }).join('') : '<div class="credit-empty">暂无积分记录</div>';
  }

  $('#recentRows').innerHTML = rentals.length ? rentals.slice(0, 8).map(item => `
    <tr>
      <td>${esc(item.id)}</td>
      <td>${esc(item.fullDomain)}</td>
      <td>${esc(item.recordType)}</td>
      <td><span class="badge warn">${esc(item.status)}</span></td>
      <td>${esc(item.expiresAt)}</td>
    </tr>`).join('') : '<tr><td colspan="5" class="empty-cell">暂无数据</td></tr>';

}

function switchAuth(mode) {
  const isLogin = mode === 'login';
  $('#loginTab').classList.toggle('active', isLogin);
  $('#registerTab').classList.toggle('active', !isLogin);
  $('#loginForm').classList.toggle('hidden', !isLogin);
  $('#registerForm').classList.toggle('hidden', isLogin);
  setMessage('#loginMsg', '');
  setMessage('#registerMsg', '');
}

function showAuth() {
  $('#authView').classList.remove('hidden');
  $('#rentView').classList.add('hidden');
}

function showRent() {
  $('#authView').classList.add('hidden');
  $('#rentView').classList.remove('hidden');
  $('#tenantName').textContent = state.tenant.name || state.tenant.username;
  renderDashboard();
  loadCredits().catch(() => setMessage('#rechargeMsg', '积分同步失败，请稍后刷新或联系管理员。', 'bad'));
  loadCreditLogs().catch(() => {});
  if (!state.domains.length) loadCatalog().catch(showCatalogError);
}

function getSelectedDomain() {
  return state.domains.find(domain => domain.id === state.selectedDomainId);
}

function calcPrice() {
  const months = parseInt($('#monthsSelect').value, 10) || 1;
  const domain = getSelectedDomain();
  const monthPrice = parseFloat(domain?.pricePerMonth || domain?.plans?.[0]?.price || 29);
  return Number((monthPrice * months).toFixed(2));
}

function updatePrice() {
  $('#priceText').textContent = calcPrice().toFixed(0);
}

function openRentModal() {
  $('#rentModal')?.classList.remove('hidden');
  $('#orderResult')?.classList.add('hidden');
  document.body.style.overflow = 'hidden';
}

function closeRentModal() {
  $('#rentModal')?.classList.add('hidden');
  document.body.style.overflow = '';
}

function selectDomainForRent(domainId, jumpToRent = false) {
  if (!domainId) return;
  state.selectedDomainId = domainId;
  state.checkedHost = '';
  state.checkedDomainId = '';
  $('#domainSelect').value = domainId;
  setCheck('提交租赁时会自动查重。', '');
  renderDomains();
  if (jumpToRent) {
    openRentModal();
    setTimeout(() => $('#hostInput')?.focus(), 120);
  }
}

async function api(path, opts = {}) {
  const request = async target => {
    const response = await fetch(target, {
      ...opts,
      headers: {
        'Content-Type': 'application/json',
        ...(opts.headers || {})
      }
    });
    const text = await response.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch {
      const error = new Error('服务器返回的数据格式无效');
      error.status = response.status;
      error.nonJson = true;
      throw error;
    }
    if (!response.ok) {
      const error = new Error(data.error || '请求失败');
      error.status = response.status;
      throw error;
    }
    return data;
  };
  try {
    return await request(path);
  } catch (error) {
    if ((error.status === 404 || error.nonJson) && path.startsWith('/api/')) {
      return request(`/api.php?route=${encodeURIComponent(path)}`);
    }
    throw error;
  }
}

function renderDomains() {
  if (!state.selectedDomainId && state.domains[0]) state.selectedDomainId = state.domains[0].id;

  $('#domainSelect').innerHTML = state.domains.map(domain => {
    return `<option value="${esc(domain.id)}">${esc(domain.domain)}</option>`;
  }).join('');

  $('#domainSelect').disabled = state.domains.length === 0;
  $('#domainSelect').value = state.selectedDomainId;
  updatePrice();
  renderDashboard();

  $('#domainGrid').innerHTML = state.domains.length ? state.domains.map(domain => {
    const price = parseFloat(domain.pricePerMonth || domain.plans?.[0]?.price || 29).toFixed(2);
    const active = domain.id === state.selectedDomainId ? ' active' : '';
    return `
      <article class="domain-card${active}" data-id="${esc(domain.id)}">
        <h3>${esc(domain.domain)}</h3>
        <div class="domain-meta">
          <span class="badge good">${platformName(domain.platform)}</span>
          <span class="badge">${esc(domain.group || '默认分组')}</span>
        </div>
        <div class="plans">
          <div class="plan">
            <strong><span>月租</span><span>￥${price}/月</span></strong>
            <p>${esc(domain.plans?.[0]?.desc || '按月出租，适合各类业务场景')}</p>
          </div>
        </div>
        <button class="pick-btn" type="button" data-id="${esc(domain.id)}">选择该域名</button>
      </article>`;
  }).join('') : '<div class="empty">暂无可租域名，请联系管理员上架域名。</div>';

  document.querySelectorAll('.domain-card,.pick-btn').forEach(el => {
    el.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();
      const id = event.currentTarget.dataset.id;
      selectDomainForRent(id, true);
    });
  });
}

function setCheck(message, type) {
  const el = $('#checkResult');
  el.textContent = message;
  el.className = `hint ${type}`;
}

async function loadCatalog() {
  $('#domainGrid').innerHTML = '<div class="empty">正在加载可租域名...</div>';
  const data = await api('/api/public/catalog');
  state.domains = data.domains || [];
  if (!state.domains.some(domain => domain.id === state.selectedDomainId)) {
    state.selectedDomainId = state.domains[0]?.id || '';
  }
  renderDomains();
}

function showCatalogError(error) {
  $('#domainGrid').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
}

async function handleRegister(event) {
  event.preventDefault();
  const formEl = event.currentTarget;
  const form = Object.fromEntries(new FormData(formEl).entries());
  const username = String(form.username || '').trim().toLowerCase();
  const name = String(form.name || '').trim();
  const contact = String(form.contact || '').trim();
  const password = String(form.password || '');
  const confirmPassword = String(form.confirmPassword || '');

  if (!/^\S{3,32}$/.test(username)) {
    setMessage('#registerMsg', '账号需为 3-32 位，不能包含空格。', 'bad');
    return;
  }
  if (password.length < 6) {
    setMessage('#registerMsg', '密码至少 6 位。', 'bad');
    return;
  }
  if (password !== confirmPassword) {
    setMessage('#registerMsg', '两次输入的密码不一致。', 'bad');
    return;
  }

  const salt = randomHex();
  const account = {
    id: `tenant_${Date.now()}_${randomHex(4)}`,
    username,
    name,
    contact,
    credits: 0,
    salt,
    passwordHash: await hashPassword(password, salt),
    createdAt: new Date().toISOString()
  };
  let remoteUser = null;
  try {
    remoteUser = await syncTenantUser({ ...account, password });
    if (!remoteUser) throw new Error('注册失败，请稍后重试。');
  } catch (error) {
    setMessage('#registerMsg', error.message, 'bad');
    return;
  }
  setSession(normalizeAccount({ ...account, ...remoteUser }));
  formEl.reset();
  showRent();
}

async function handleLogin(event) {
  event.preventDefault();
  const formEl = event.currentTarget;
  const form = Object.fromEntries(new FormData(formEl).entries());
  const username = String(form.username || '').trim().toLowerCase();
  const password = String(form.password || '');
  let remoteUser;
  try {
    remoteUser = await tenantLogin(username, password);
  } catch (error) {
    setMessage('#loginMsg', error.message, 'bad');
    return;
  }

  const salt = randomHex();
  const account = normalizeAccount({
    ...(remoteUser || {}),
    id: remoteUser?.id || `tenant_${Date.now()}_${randomHex(4)}`,
    username,
    salt,
    passwordHash: await hashPassword(password, salt),
    createdAt: remoteUser?.created_at || new Date().toISOString()
  });
  setSession(account);
  formEl.reset();
  showRent();
}

function switchProfileTab(tab) {
  const isBase = tab === 'base';
  document.querySelectorAll('[data-profile-tab]').forEach(button => {
    button.classList.toggle('active', button.dataset.profileTab === tab);
  });
  $('#profileForm').classList.toggle('hidden', !isBase);
  $('#passwordForm').classList.toggle('hidden', isBase);
  setMessage('#profileMsg', '');
  setMessage('#passwordMsg', '');
}

async function handleProfileSave(event) {
  event.preventDefault();
  const name = $('#profileNameInput').value.trim();
  const phone = $('#profilePhoneInput').value.trim();
  const email = $('#profileEmailInput').value.trim();
  updateTenant({
    name: name || state.tenant.username,
    phone,
    email,
    contact: email || phone || ''
  });
  await updateTenantUserProfile(state.tenant);
  $('#tenantName').textContent = state.tenant.name || state.tenant.username;
  renderDashboard();
  setMessage('#profileMsg', '保存成功。', 'ok');
}

async function handlePasswordSave(event) {
  event.preventDefault();
  const formEl = event.currentTarget;
  const form = Object.fromEntries(new FormData(formEl).entries());
  const currentPassword = String(form.currentPassword || '');
  const newPassword = String(form.newPassword || '');
  const confirmPassword = String(form.confirmPassword || '');
  if (newPassword !== confirmPassword) {
    setMessage('#passwordMsg', '两次输入的新密码不一致。', 'bad');
    return;
  }
  const salt = randomHex(12);
  try {
    await changeTenantPassword(state.tenant.username, currentPassword, newPassword);
    updateTenant({
      salt,
      passwordHash: await hashPassword(newPassword, salt)
    });
  } catch (error) {
    setMessage('#passwordMsg', error.message || '当前密码错误。', 'bad');
    return;
  }
  formEl.reset();
  setMessage('#passwordMsg', '密码修改成功。', 'ok');
}

function handleAvatarChange(event) {
  const file = event.target.files?.[0];
  if (!file) return;
  if (!file.type.startsWith('image/')) {
    setMessage('#profileMsg', '请选择图片文件。', 'bad');
    event.target.value = '';
    return;
  }
  if (file.size > 1024 * 1024) {
    setMessage('#profileMsg', '头像图片不能超过 1MB。', 'bad');
    event.target.value = '';
    return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    updateTenant({ avatar: String(reader.result || '') });
    renderDashboard();
    setMessage('#profileMsg', '头像已更新。', 'ok');
    event.target.value = '';
  };
  reader.onerror = () => setMessage('#profileMsg', '头像读取失败，请重新选择。', 'bad');
  reader.readAsDataURL(file);
}

function bindEvents() {
  $('#loginTab').addEventListener('click', () => switchAuth('login'));
  $('#registerTab').addEventListener('click', () => switchAuth('register'));
  $('#registerForm').addEventListener('submit', handleRegister);
  $('#loginForm').addEventListener('submit', handleLogin);
  $('#logoutBtn').addEventListener('click', () => {
    clearSession();
    showAuth();
  });
  $('#menuToggle').addEventListener('click', () => document.querySelector('.console-sidebar').classList.toggle('open'));
  $('#fullscreenBtn').addEventListener('click', () => {
    if (!document.fullscreenElement && document.documentElement.requestFullscreen) {
      document.documentElement.requestFullscreen().catch(() => {});
    } else if (document.exitFullscreen) {
      document.exitFullscreen().catch(() => {});
    }
  });
  document.querySelectorAll('[data-panel]').forEach(el => {
    el.addEventListener('click', () => setActivePanel(el.dataset.panel));
  });
  document.querySelectorAll('[data-profile-tab]').forEach(button => {
    button.addEventListener('click', () => switchProfileTab(button.dataset.profileTab));
  });
  $('#profileForm').addEventListener('submit', handleProfileSave);
  $('#passwordForm').addEventListener('submit', handlePasswordSave);
  $('#avatarInput').addEventListener('change', handleAvatarChange);
  $('#noticeRefresh').addEventListener('click', () => { loadSiteSettings().catch(() => {}); setActivePanel('noticePanel'); });
  $('#historyRefresh').addEventListener('click', () => {
    renderDashboard();
    loadCreditLogs().catch(() => {});
  });
  $('#rechargeForm').addEventListener('submit', async event => {
    event.preventDefault();
    const amount = Number($('#rechargeAmount').value);
    if (!Number.isFinite(amount) || amount <= 0) {
      setMessage('#rechargeMsg', '请输入正确的充值积分数量。', 'bad');
      return;
    }
    const payWindow = window.open('about:blank', '_blank');
    if (payWindow) {
      payWindow.document.write('<!doctype html><title>正在打开支付</title><p style="font:16px sans-serif;padding:24px">正在创建支付订单...</p>');
      setTimeout(() => {
        if (!payWindow.closed && payWindow.location.href === 'about:blank') {
          payWindow.document.body.innerHTML = '<p style="font:16px sans-serif;padding:24px">服务器正在创建订单，请稍候；如果长时间没有跳转，请关闭窗口后重试。</p>';
        }
      }, 800);
    }
    try {
      setMessage('#rechargeMsg', '正在创建支付订单...', '');
      const order = await api('/api/public/recharge', {
        method: 'POST',
        body: JSON.stringify({
          amount,
          username: state.tenant.username,
          customer: state.tenant.name || state.tenant.username,
          contact: state.tenant.contact || ''
        })
      });
      $('#rechargeAmount').value = '';
      const recharges = readRecharges();
      recharges.unshift({
        tradeNo: order.tradeNo,
        tenantId: state.tenant.id,
        credits: Number(order.credits || amount),
        amount: Number(order.amount || amount),
        status: 'pending',
        createdAt: new Date().toISOString()
      });
      writeRecharges(recharges);
      setMessage('#rechargeMsg', '支付订单已创建，请在打开的付款页面完成支付。', 'ok');
      if (order.payUrl) {
        if (payWindow) {
          payWindow.location.href = order.payUrl;
        } else {
          window.location.href = order.payUrl;
        }
      } else {
        throw new Error('支付链接生成失败');
      }
    } catch (error) {
      if (payWindow) payWindow.close();
      setMessage('#rechargeMsg', error.message, 'bad');
    }
  });
  $('#reloadBtn').addEventListener('click', () => loadCatalog().catch(showCatalogError));
  $('#domainSelect').addEventListener('change', event => {
    selectDomainForRent(event.target.value, false);
  });
  $$('[data-close-rent]').forEach(el => el.addEventListener('click', closeRentModal));
  $('#monthsSelect').addEventListener('change', updatePrice);
  $('#hostInput').addEventListener('input', () => {
    state.checkedHost = '';
    state.checkedDomainId = '';
    setCheck('提交租赁时会自动查重。', '');
  });
  $('#checkBtn').addEventListener('click', async () => {
    try {
      const host = $('#hostInput').value.trim().toLowerCase();
      const domainId = $('#domainSelect').value;
      if (!domainId) throw new Error('请先选择主域名。');
      const result = await api('/api/public/check', {
        method: 'POST',
        body: JSON.stringify({ domainId, host })
      });
      state.checkedHost = result.ok ? host : '';
      state.checkedDomainId = result.ok ? domainId : '';
      setCheck(result.reason, result.ok ? 'ok' : 'bad');
    } catch (error) {
      state.checkedHost = '';
      state.checkedDomainId = '';
      setCheck(error.message, 'bad');
    }
  });
  $('#rentForm').addEventListener('submit', handleRentSubmit);
}

async function handleRentSubmit(event) {
  event.preventDefault();
  try {
    if (!state.tenant) throw new Error('请先登录。');
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    data.plan = 'standard';
    data.host = String(data.host || '').trim().toLowerCase();
    data.customer = String(state.tenant.name || state.tenant.username).trim();
    data.contact = state.tenant.contact || state.tenant.email || state.tenant.phone || state.tenant.username;

    if (!data.domainId) throw new Error('请先选择主域名。');
    setCheck('正在自动查重...', '');
    const checkResult = await api('/api/public/check', {
      method: 'POST',
      body: JSON.stringify({ domainId: data.domainId, host: data.host })
    });
    state.checkedHost = checkResult.ok ? data.host : '';
    state.checkedDomainId = checkResult.ok ? data.domainId : '';
    if (!checkResult.ok) {
      throw new Error('此前缀已有解析记录，请填写其他前缀');
    }
    setCheck('查重通过，正在创建解析...', 'ok');
    const amount = calcPrice();
    const credits = Number(state.tenant.credits ?? 0);
    if (credits < amount) {
      closeRentModal();
      setActivePanel('orderPanel');
      throw new Error(`积分不足，当前积分 ${credits.toFixed(0)}，本次租赁需要 ${amount.toFixed(0)}。请先充值积分。`);
    }

    const order = await api('/api/public/orders', {
      method: 'POST',
      body: JSON.stringify({
        ...data,
        username: state.tenant.username
      })
    });
    const domain = getSelectedDomain();
    const fullDomain = domain ? `${data.host}.${domain.domain}` : data.host;
    const expiresAt = new Date();
    expiresAt.setMonth(expiresAt.getMonth() + (parseInt(data.months, 10) || 1));
    const rentals = readRentals();
    rentals.unshift({
      id: order.tradeNo || `R${Date.now()}`,
      tenantId: state.tenant.id,
      fullDomain,
      host: data.host,
      domainId: data.domainId,
      recordType: data.recordType,
      recordValue: data.recordValue,
      status: '已租赁',
      amount,
      expiresAt: expiresAt.toISOString().slice(0, 10),
      createdAt: new Date().toISOString()
    });
    writeRentals(rentals);
    const nextCredits = Number(order.credits ?? (credits - amount));
    updateTenant({ credits: nextCredits });

    $('#orderResult').classList.remove('hidden');
    $('#orderResult').innerHTML = `
      <h3>租赁成功</h3>
      <p>订单号：<strong>${esc(order.tradeNo)}</strong></p>
      <p>租用域名：${esc(fullDomain)}</p>
      <p>租期：${esc(data.months)} 个月</p>
      <p>扣除积分：<strong>${amount.toFixed(0)}</strong></p>
      <p>剩余积分：<strong>${nextCredits.toFixed(0)}</strong></p>`;
    state.checkedHost = '';
    state.checkedDomainId = '';
    setCheck('租赁已完成，如需继续租用请重新查重。', 'ok');
    renderDashboard();
    await loadCreditLogs().catch(() => {});
    await loadCatalog();
  } catch (error) {
    setCheck(error.message, 'bad');
  }
}

document.addEventListener('DOMContentLoaded', async () => {
  try {
    bindEvents();
    await loadSiteSettings();
    const initialAuthMode = window.location.pathname.replace(/\/+$/, '') === '/register' ? 'register' : 'login';
    switchAuth(initialAuthMode);
    state.tenant = getCurrentTenant();
    if (state.tenant) {
      const remoteUser = await fetchTenantUser(state.tenant.username);
      if (!remoteUser || remoteUser.status === 'disabled' || remoteUser.status === 'deleted') {
        clearSession();
        showAuth();
        return;
      }
      setSession({ ...state.tenant, ...remoteUser });
      showRent();
      applyRechargeReturn();
    } else {
      showAuth();
    }
  } finally {
    document.body.classList.remove('app-initializing');
  }
});
