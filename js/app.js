/* =============================================================================
 * 任务管理系统 v2.4.1 — 前端应用逻辑
 * 
 * 包含：
 *   1. 全局状态 / 初始化
 *   2. 用户认证（登录/注册/登出）
 *   3. 主题切换
 *   4. 提醒设置 / 修改密码
 *   5. 分类 / 标签 CRUD
 *   6. 导航 / 视图切换
 *   7. 任务加载 / 渲染 / 创建 / 编辑 / 删除
 *   8. 摘要统计 / 侧边栏计数
 *   9. 月历视图 / 四象限
 *   10. 番茄钟（完整面板 + 侧边小部件）
 *   11. 每日回顾
 *   12. 桌面通知 / 声音 / 标签闪烁
 *   13. 工具函数（esc, showToast, 格式化等）
 * ============================================================================= */

const API = 'api.php';

// ========== 全局状态 ==========
let categories = [], tags = [], tasks = [], groupedTasks = null, habits = [];
let currentNav = 'today', currentView = 'list', currentCategory = 0, currentTag = 0, currentSearch = '';
let selectedQuickTags = [], selectedEditTags = [], selectedColor = '#4A90D9';
let userSettings = {}, summaryStats = {}, notificationShown = false;
let pomoTimer = null, pomoRunning = false, pomoIsBreak = false, pomoSeconds = 0, pomoTotalSeconds = 0, pomoWorkMin = 25, pomoBreakMin = 5, pomoTaskId = 0;
let createAttachFiles = [], editAttachFiles = [], selectedHabitIcon = '📌', selectedHabitDays = [1,2,3,4,5,6,7];

// ========== 工具函数 ==========
/** 获取本地时区的 YYYY-MM-DD，不依赖任何 locale，兼容所有浏览器/OS */
function localDateStr(d) {
    d = d || new Date();
    var m = d.getMonth() + 1, dt = d.getDate();
    return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (dt < 10 ? '0' + dt : dt);
}
function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

// ========== 初始化 ==========
document.addEventListener('DOMContentLoaded', async () => {
    const authed = await checkAuth();
    if (authed) {
        showMainApp();
        await loadSettings();
        await Promise.all([loadCategories(), loadTags()]);
        initFullCalendar();
        switchNav('today');
        checkNotifications();
        setInterval(checkNotifications, 30000); // 每30秒轮询提醒
        setInterval(() => {
            if (currentNav !== 'review' && currentView === 'list') loadTasks();
        }, 60000);
    } else {
        document.getElementById('authPage').classList.remove('hidden');
    }
});

function showMainApp() {
    document.getElementById('authPage').classList.add('hidden');
    document.getElementById('mainApp').classList.remove('hidden');
}

// ========== 用户认证 ==========
async function checkAuth() {
    try {
        const r = await fetch(API + '?action=check_auth');
        const j = await r.json();
        if (j.success && j.data) {
            document.getElementById('headerUsername').textContent = '👤 ' + j.data.username;
            if (j.data.role === 'admin') {
                document.getElementById('adminLink').style.display = '';
            }
            return true;
        }
    } catch (e) {}
    return false;
}

function submitAuth() {
    document.getElementById('authMode').value === 'login' ? login() : register();
}

function toggleAuthMode() {
    const m = document.getElementById('authMode');
    const isLogin = m.value === 'login';
    m.value = isLogin ? 'register' : 'login';
    document.getElementById('authTitle').textContent = isLogin ? '注册帐号' : '任务管理系统';
    document.getElementById('authBtn').textContent = isLogin ? '注 册' : '登 录';
    document.getElementById('emailGroup').style.display = isLogin ? 'block' : 'none';
    document.getElementById('rememberGroup').style.display = isLogin ? 'none' : 'block';
    document.getElementById('authSwitchText').textContent = isLogin ? '已有账号？' : '还没有账号？';
    document.getElementById('authSwitchLink').textContent = isLogin ? '立即登录' : '立即注册';
    clearAuthMsg();
}

function clearAuthMsg() { const e = document.getElementById('authMsg'); e.textContent = ''; e.className = 'auth-msg'; }
function setAuthMsg(msg, type) { const e = document.getElementById('authMsg'); e.textContent = msg; e.className = 'auth-msg ' + type; }

async function login() {
    const u = document.getElementById('authUsername').value.trim(), p = document.getElementById('authPassword').value;
    if (!u || !p) { setAuthMsg('请输入用户名和密码', 'error'); return; }
    try {
        const rememberMe = document.getElementById('rememberMe').checked;
        const r = await fetch(API + '?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: u, password: p, remember_me: rememberMe }) });
        const j = await r.json();
        if (j.success) { setAuthMsg('登录成功...', 'success'); setTimeout(() => location.reload(), 400); }
        else setAuthMsg(j.message || '登录失败', 'error');
    } catch (e) { setAuthMsg('网络错误', 'error'); }
}

async function register() {
    const u = document.getElementById('authUsername').value.trim(), p = document.getElementById('authPassword').value, em = document.getElementById('regEmail').value.trim();
    if (!u || !p) { setAuthMsg('请填写用户名和密码', 'error'); return; }
    if (p.length < 6) { setAuthMsg('密码至少6位', 'error'); return; }
    try {
        const r = await fetch(API + '?action=register', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: u, password: p, email: em }) });
        const j = await r.json();
        if (j.success) { setAuthMsg('注册成功...', 'success'); setTimeout(() => location.reload(), 400); }
        else setAuthMsg(j.message || '注册失败', 'error');
    } catch (e) { setAuthMsg('网络错误', 'error'); }
}

async function logout() {
    if (!await showConfirm('退出登录', '确定退出当前账号？')) return;
    try { await fetch(API + '?action=logout', { method: 'POST' }); } catch (e) {}
    location.reload();
}

// ========== 主题 ==========
const THEMES = [
    { id:'default', name:'默认蓝', desc:'清爽办公蓝', dots:['#4A90D9','#85C1E9','#E8F1FB'] },
    { id:'green',   name:'护眼绿', desc:'柔和润目', dots:['#3CB371','#90EE90','#E8F5E9'] },
    { id:'pink',    name:'樱花粉', desc:'温暖甜美', dots:['#E87DA0','#F8BBD0','#FCE4EC'] },
    { id:'ocean',   name:'海洋蓝', desc:'深邃稳重', dots:['#0077B6','#48CAE4','#E0F0FA'] },
    { id:'sunset',  name:'日落橙', desc:'暖色活力', dots:['#E07B53','#FFB347','#FFF0E8'] },
    { id:'stone',   name:'岩石灰', desc:'护眼低亮', dots:['#6B7B8D','#A0B0C0','#ECF0F2'] },
    { id:'coffee',  name:'摩卡棕', desc:'暖调护眼', dots:['#8D6E5A','#C0A890','#EDE0D8'] },
    { id:'dark',    name:'暗夜', desc:'夜间舒适', dots:['#6C8CFF','#4A5568','#1E2A45'] },
    { id:'midnight',name:'深夜暗', desc:'极致暗色', dots:['#7B8CD0','#2A3045','#0D1117'] },
    { id:'frost',   name:'毛玻璃', desc:'冰透朦胧', dots:['#7C8CE0','#E8EAFA','#F2F4FA'] },
    { id:'sand',    name:'暖沙', desc:'仿纸书阅读', dots:['#C9A96E','#EDE0CC','#FDF5E6'] },
    { id:'lavender',name:'薰衣草', desc:'柔紫低蓝光', dots:['#A78BFA','#D0C8E8','#F3F0FF'] },
];

let currentTheme = 'default';

function buildThemeGrid() {
    const grid = document.getElementById('themeGrid');
    if (!grid) return;
    grid.innerHTML = THEMES.map(t => {
        const dots = t.dots.map(c => `<span class="tc-dot" style="background:${c}"></span>`).join('');
        const cls = t.id === currentTheme ? ' active' : '';
        return `<div class="theme-card${cls}" onclick="switchTheme('${t.id}')">
            <div class="tc-dots">${dots}</div>
            <div class="tc-name">${t.name}</div>
            <div class="tc-desc">${t.desc}</div>
        </div>`;
    }).join('');
    // 更新顶栏按钮状态
    const btn = document.getElementById('themeBtn');
    if (btn) {
        const t = THEMES.find(x => x.id === currentTheme);
        btn.title = '配色：' + (t ? t.name : currentTheme);
    }
}

function showThemePicker() {
    buildThemeGrid();
    document.getElementById('themePicker').classList.add('show');
}

function closeThemePicker() {
    document.getElementById('themePicker').classList.remove('show');
}

async function switchTheme(theme) {
    currentTheme = theme;
    document.body.setAttribute('data-theme', theme);
    buildThemeGrid();
    try { await fetch(API + '?action=update_theme', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ theme }) }); } catch (e) {}
    closeThemePicker();
}

// ========== 提醒设置 ==========
async function loadSettings() {
    try {
        const r = await fetch(API + '?action=get_settings');
        const j = await r.json();
        if (j.success && j.data) {
            userSettings = {
                sound_enabled: parseInt(j.data.sound_enabled) === 1,
                tab_flash_enabled: parseInt(j.data.tab_flash_enabled) === 1,
                email_reminder_enabled: parseInt(j.data.email_reminder_enabled) === 1,
                smtp_host: j.data.smtp_host || '',
                smtp_port: parseInt(j.data.smtp_port) || 587,
                smtp_username: j.data.smtp_username || '',
                smtp_password: j.data.smtp_password || '',
                smtp_encryption: j.data.smtp_encryption || 'tls',
                email_recipient: j.data.email_recipient || '',
                theme: j.data.theme || 'default'
            };
            if (userSettings.theme && userSettings.theme !== 'default') {
                currentTheme = userSettings.theme;
                document.body.setAttribute('data-theme', userSettings.theme);
            } else {
                currentTheme = 'default';
            }
            buildThemeGrid();
        }
        syncSettingsToUI();
    } catch (e) {}
}

function syncSettingsToUI() {
    updateToggleUI('toggleSound', userSettings.sound_enabled);
    updateToggleUI('toggleTab', userSettings.tab_flash_enabled);
    updateToggleUI('toggleEmail', userSettings.email_reminder_enabled);
    document.getElementById('smtpHost').value = userSettings.smtp_host;
    document.getElementById('smtpPort').value = userSettings.smtp_port;
    document.getElementById('smtpUsername').value = userSettings.smtp_username;
    document.getElementById('smtpPassword').value = userSettings.smtp_password ? '******' : '';
    document.getElementById('smtpEncryption').value = userSettings.smtp_encryption;
    document.getElementById('emailRecipient').value = userSettings.email_recipient;
}

function updateToggleUI(id, on) { const e = document.getElementById(id); if (e) e.className = 'toggle-switch' + (on ? ' on' : ''); }

function toggleSetting(t) {
    if (t === 'sound') userSettings.sound_enabled = !userSettings.sound_enabled;
    if (t === 'tab') userSettings.tab_flash_enabled = !userSettings.tab_flash_enabled;
    if (t === 'email') userSettings.email_reminder_enabled = !userSettings.email_reminder_enabled;
    syncSettingsToUI();
}

function showSettings() { syncSettingsToUI(); document.getElementById('settingsDialog').classList.add('show'); }
function closeSettings() { document.getElementById('settingsDialog').classList.remove('show'); }

async function saveSettings() {
    userSettings.smtp_host = document.getElementById('smtpHost').value.trim();
    userSettings.smtp_port = parseInt(document.getElementById('smtpPort').value) || 587;
    userSettings.smtp_username = document.getElementById('smtpUsername').value.trim();
    userSettings.smtp_encryption = document.getElementById('smtpEncryption').value;
    userSettings.email_recipient = document.getElementById('emailRecipient').value.trim();
    const p = document.getElementById('smtpPassword').value, password = p === '******' ? '******' : p;
    try {
        const r = await fetch(API + '?action=update_settings', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                smtp_host: userSettings.smtp_host, smtp_port: userSettings.smtp_port,
                smtp_username: userSettings.smtp_username, smtp_password: password,
                smtp_encryption: userSettings.smtp_encryption,
                sound_enabled: userSettings.sound_enabled ? 1 : 0,
                tab_flash_enabled: userSettings.tab_flash_enabled ? 1 : 0,
                email_reminder_enabled: userSettings.email_reminder_enabled ? 1 : 0,
                email_recipient: userSettings.email_recipient
            })
        });
        const j = await r.json();
        showToast(j.success ? '设置已保存' : (j.message || '保存失败'), j.success ? 'success' : 'error');
    } catch (e) { showToast('网络错误', 'error'); }
}

async function sendTestEmail() {
    const to = document.getElementById('emailRecipient').value.trim() || document.getElementById('smtpUsername').value.trim();
    const res = document.getElementById('testEmailResult');
    if (!to) { res.textContent = '请填写收件人邮箱'; return; }
    res.textContent = '发送中...';
    try {
        await saveSettingsSilent();
        const r = await fetch(API + '?action=send_test_email', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ to_email: to }) });
        const j = await r.json();
        res.textContent = j.success ? '✅ ' + j.message : '❌ ' + j.message;
        res.style.color = j.success ? 'var(--success)' : 'var(--danger)';
    } catch (e) { res.textContent = '❌ 网络错误'; res.style.color = 'var(--danger)'; }
}

async function saveSettingsSilent() {
    const p = document.getElementById('smtpPassword').value, password = p === '******' ? '******' : p;
    try {
        await fetch(API + '?action=update_settings', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                smtp_host: document.getElementById('smtpHost').value.trim(),
                smtp_port: parseInt(document.getElementById('smtpPort').value) || 587,
                smtp_username: document.getElementById('smtpUsername').value.trim(),
                smtp_password: password,
                smtp_encryption: document.getElementById('smtpEncryption').value,
                sound_enabled: userSettings.sound_enabled ? 1 : 0,
                tab_flash_enabled: userSettings.tab_flash_enabled ? 1 : 0,
                email_reminder_enabled: userSettings.email_reminder_enabled ? 1 : 0,
                email_recipient: document.getElementById('emailRecipient').value.trim()
            })
        });
    } catch (e) {}
}

// ========== 修改密码 ==========
function showPasswordDialog() { document.getElementById('passwordDialog').classList.add('show'); document.getElementById('oldPassword').focus(); }
function closePasswordDialog() { document.getElementById('passwordDialog').classList.remove('show'); ['oldPassword', 'newPassword', 'confirmPassword'].forEach(id => document.getElementById(id).value = ''); }

async function changePassword() {
    const op = document.getElementById('oldPassword').value, np = document.getElementById('newPassword').value, cp = document.getElementById('confirmPassword').value;
    if (!op) { showToast('请输入当前密码', 'error'); return; }
    if (!np || np.length < 6) { showToast('新密码至少6位', 'error'); return; }
    if (np !== cp) { showToast('两次输入不一致', 'error'); return; }
    try {
        const r = await fetch(API + '?action=change_password', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ old_password: op, new_password: np }) });
        const j = await r.json();
        if (j.success) { closePasswordDialog(); showToast(j.message || '密码已修改，请重新登录', 'success'); setTimeout(() => location.reload(), 1500); }
        else showToast(j.message || '修改失败', 'error');
    } catch (e) { showToast('网络错误', 'error'); }
}

// ========== 分类管理 ==========
async function loadCategories() {
    try { const r = await fetch(API + '?action=list_categories'); const j = await r.json(); if (j.success) { categories = j.data; renderCategories(); renderCategorySelects(); } } catch (e) {}
}

function renderCategories() {
    const c = document.getElementById('categoryList');
    let h = '';
    categories.forEach(cat => {
        const n = parseInt(cat.task_count) || 0;
        h += `<div class="category-item${currentCategory === parseInt(cat.id) ? ' active' : ''}" onclick="switchCategory(${cat.id})"><span class="category-dot" style="background:${esc(cat.color)}"></span><span class="category-name">${esc(cat.name)}</span>${n > 0 ? `<span class="category-count">${n}</span>` : ''}<span class="category-delete" onclick="event.stopPropagation();deleteCategory(${cat.id})">×</span></div>`;
    });
    c.innerHTML = h;
}

function renderCategorySelects() {
    const ss = [document.getElementById('inputCategory'), document.getElementById('editTaskCategory')];
    ss.forEach(s => { if (!s) return; s.innerHTML = categories.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join(''); });
}

function switchCategory(id) { currentCategory = id; currentTag = 0; currentNav = 'all'; updateNavActive(); renderCategories(); loadTasks(); loadSummary(); }

function showCategoryDialog(eid = 0, en = '', ec = '#4A90D9') {
    document.getElementById('categoryDialogTitle').textContent = eid ? '编辑清单' : '新建清单';
    document.getElementById('editCategoryId').value = eid || '';
    document.getElementById('categoryNameInput').value = en;
    document.getElementById('categoryColorInput').value = ec;
    selectedColor = ec;
    document.querySelectorAll('.color-dot').forEach(d => { d.classList.toggle('selected', d.style.background === ec || (d.tagName === 'INPUT' && d.value === ec)); });
    document.getElementById('categoryDialog').classList.add('show');
}

function closeCategoryDialog() { document.getElementById('categoryDialog').classList.remove('show'); }

function pickColor(c, el) {
    selectedColor = c;
    document.getElementById('categoryColorInput').value = c;
    document.querySelectorAll('.color-dot').forEach(d => d.classList.remove('selected'));
    if (el) el.classList.add('selected');
}

async function saveCategory() {
    const nm = document.getElementById('categoryNameInput').value.trim(), eid = document.getElementById('editCategoryId').value, c = document.getElementById('categoryColorInput').value;
    if (!nm) { showToast('请输入名称', 'error'); return; }
    const isEdit = eid !== '';
    try {
        const r = await fetch(API + '?action=' + (isEdit ? 'update_category' : 'create_category'), {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(isEdit ? { id: parseInt(eid), name: nm, color: c, sort_order: 0 } : { name: nm, color: c, sort_order: 0 })
        });
        const j = await r.json();
        if (j.success) { closeCategoryDialog(); await loadCategories(); loadTasks(); showToast(isEdit ? '清单已更新' : '清单已创建', 'success'); }
        else showToast(j.message || '操作失败', 'error');
    } catch (e) { showToast('网络错误', 'error'); }
}

async function deleteCategory(id) {
    if (!await showConfirm('删除清单', '删除清单会一并删除其下任务，确定？')) return;
    try { await fetch(API + '?action=delete_category', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) }); await loadCategories(); loadTasks(); loadSummary(); } catch (e) {}
}

// ========== 标签管理 ==========
async function loadTags() {
    try { const r = await fetch(API + '?action=list_tags'); const j = await r.json(); if (j.success) { tags = j.data; renderTags(); renderQuickAddTags(); renderEditTags(); } } catch (e) {}
}

function renderTags() {
    const c = document.getElementById('tagList');
    let h = '';
    tags.forEach(t => {
        const n = parseInt(t.task_count) || 0;
        h += `<div class="tag-item${currentTag === parseInt(t.id) ? ' active' : ''}" onclick="switchTag(${t.id})"><span class="tag-dot" style="background:${esc(t.color)}"></span><span class="tag-name">${esc(t.name)}</span>${n > 0 ? `<span class="tag-count">${n}</span>` : ''}<span class="tag-delete" onclick="event.stopPropagation();deleteTag(${t.id})">×</span></div>`;
    });
    c.innerHTML = h;
}

function renderQuickAddTags() {
    document.getElementById('quickAddTags').innerHTML = tags.map(t =>
        `<span class="tag-pill${selectedQuickTags.includes(parseInt(t.id)) ? ' selected' : ''}" style="${selectedQuickTags.includes(parseInt(t.id)) ? 'background:' + esc(t.color) : ''}" onclick="toggleQuickTag(${t.id})">${esc(t.name)}</span>`
    ).join('');
}

function renderEditTags() {
    const c = document.getElementById('editTaskTags');
    if (!c) return;
    c.innerHTML = tags.map(t =>
        `<span class="tag-edit-pill${selectedEditTags.includes(parseInt(t.id)) ? ' selected' : ''}" style="${selectedEditTags.includes(parseInt(t.id)) ? 'background:' + esc(t.color) : ''}" onclick="toggleEditTag(${t.id})">${esc(t.name)}</span>`
    ).join('');
}

function toggleQuickTag(id) { id = parseInt(id); selectedQuickTags = selectedQuickTags.includes(id) ? selectedQuickTags.filter(x => x !== id) : [...selectedQuickTags, id]; renderQuickAddTags(); }
function toggleEditTag(id) { id = parseInt(id); selectedEditTags = selectedEditTags.includes(id) ? selectedEditTags.filter(x => x !== id) : [...selectedEditTags, id]; renderEditTags(); }
function switchTag(id) { currentTag = id; currentCategory = 0; currentNav = 'all'; updateNavActive(); renderCategories(); renderTags(); loadTasks(); }

function showTagDialog(eid = 0, en = '', ec = '#95A5A6') {
    document.getElementById('tagDialogTitle').textContent = eid ? '编辑标签' : '新建标签';
    document.getElementById('editTagId').value = eid || '';
    document.getElementById('tagNameInput').value = en;
    document.getElementById('tagColorInput').value = ec;
    document.getElementById('tagDialog').classList.add('show');
}

function closeTagDialog() { document.getElementById('tagDialog').classList.remove('show'); }

async function saveTag() {
    const nm = document.getElementById('tagNameInput').value.trim(), eid = document.getElementById('editTagId').value, c = document.getElementById('tagColorInput').value;
    if (!nm) { showToast('请输入名称', 'error'); return; }
    try {
        const r = await fetch(API + '?action=' + (eid ? 'update_tag' : 'create_tag'), {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(eid ? { id: parseInt(eid), name: nm, color: c } : { name: nm, color: c })
        });
        const j = await r.json();
        if (j.success) { closeTagDialog(); await loadTags(); loadTasks(); showToast(eid ? '标签已更新' : '标签已创建', 'success'); }
        else showToast(j.message || '操作失败', 'error');
    } catch (e) { showToast('网络错误', 'error'); }
}

async function deleteTag(id) {
    if (!await showConfirm('删除标签', '确定删除该标签？')) return;
    try { await fetch(API + '?action=delete_tag', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) }); await loadTags(); loadTasks(); } catch (e) {}
}

// ========== 导航与视图切换 ==========
function switchNav(nav) {
    currentNav = nav; currentCategory = 0; currentTag = 0; currentSearch = '';
    document.getElementById('searchInput').value = '';
    currentView = 'list';
    document.querySelectorAll('.view-tab').forEach(t => t.classList.remove('active'));
    const vt = document.querySelector('.view-tab[data-view="list"]');
    if (vt) vt.classList.add('active');
    document.getElementById('listView').classList.remove('hidden');
    ['calendarView', 'quadrantsView', 'pomodoroView', 'reviewView', 'summaryView', 'habitsView'].forEach(id => document.getElementById(id).classList.add('hidden'));
    document.getElementById('viewTabs').classList.remove('hidden');
    updateNavActive();
    renderCategories();
    renderTags();
    if (nav === 'review') { showReview(); return; }
    if (nav === 'habits') { showHabitsView(); return; }
    loadTasks();
    loadSummary();
}

function switchView(view) {
    currentView = view;
    document.querySelectorAll('.view-tab').forEach(t => t.classList.remove('active'));
    const vt = document.querySelector(`.view-tab[data-view="${view}"]`);
    if (vt) vt.classList.add('active');
    ['listView', 'calendarView', 'quadrantsView', 'pomodoroView', 'reviewView', 'summaryView', 'habitsView'].forEach(id => document.getElementById(id).classList.add('hidden'));
    document.getElementById(view === 'list' ? 'listView' : (view === 'calendar' ? 'calendarView' : (view === 'quadrants' ? 'quadrantsView' : 'pomodoroView'))).classList.remove('hidden');
    if (view === 'calendar') {
        document.getElementById('pageTitle').textContent = '📅 日历';
        document.getElementById('pageSub').textContent = '月 / 周 / 日视图';
        renderCalendar();
    } else if (view === 'quadrants') {
        document.getElementById('pageTitle').textContent = '➕ 四象限';
        document.getElementById('pageSub').textContent = '重要与紧急矩阵';
        loadQuadrants();
    } else if (view === 'pomodoro') {
        showPomodoroView();
    } else {
        loadTasks();
    }
}

function updateNavActive() {
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    const e = document.querySelector(`.nav-item[data-nav="${currentNav}"]`);
    if (e) e.classList.add('active');
}

function updatePageTitle() {
    const titles = { all: '所有任务', today: '今天', next7days: '最近7天', inbox: '收集箱', completed: '已完成', trash: '垃圾桶', search: '搜索结果' };
    let t = titles[currentNav] || '任务';
    if (currentCategory > 0) { const c = categories.find(x => parseInt(x.id) === currentCategory); if (c) t = c.name; }
    if (currentTag > 0) { const tg = tags.find(x => parseInt(x.id) === currentTag); if (tg) t = '#' + tg.name; }
    if (currentSearch) t = '搜索：' + currentSearch;
    document.getElementById('pageTitle').textContent = t;
    const total = groupedTasks ? Object.values(groupedTasks).reduce((a, g) => a + g.items.length, 0) : tasks.length;
    document.getElementById('pageSub').textContent = currentNav === 'completed' ? total + ' 项已完成' : total + ' 项任务';
}

function startSearch() {
    currentSearch = document.getElementById('searchInput').value.trim();
    currentNav = currentSearch ? 'search' : 'all';
    updateNavActive();
    currentView = 'list';
    document.querySelectorAll('.view-tab').forEach(t => t.classList.remove('active'));
    document.querySelector('.view-tab[data-view="list"]').classList.add('active');
    document.getElementById('listView').classList.remove('hidden');
    ['calendarView', 'quadrantsView', 'pomodoroView'].forEach(id => document.getElementById(id).classList.add('hidden'));
    loadTasks();
}

// ========== 任务列表加载与渲染 ==========
async function loadTasks() {
    const params = new URLSearchParams();
    params.set('filter', currentNav === 'search' ? 'all' : currentNav);
    if (currentCategory > 0) params.set('category_id', currentCategory);
    if (currentTag > 0) params.set('tag_id', currentTag);
    if (currentSearch) params.set('search', currentSearch);
    params.set('group', 'auto');
    try {
        const r = await fetch(API + '?action=list_tasks&' + params.toString());
        const j = await r.json();
        if (!j.success) { if (j.message === '请先登录') location.reload(); return; }
        if (j.data && j.data.grouped) {
            groupedTasks = j.data.groups;
            tasks = [];
            Object.values(groupedTasks).forEach(g => tasks.push(...g.items));
        } else {
            tasks = j.data || [];
            groupedTasks = null;
        }
        renderTasks();
    } catch (e) { console.error('loadTasks error:', e); }
}

function renderTasks() {
    const c = document.getElementById('taskList');
    const isTrash = currentNav === 'trash';
    updatePageTitle();
    document.getElementById('quickAddForm').classList.toggle('hidden', isTrash || currentNav === 'completed' || currentNav === 'review');
    document.getElementById('viewTabs').classList.toggle('hidden', isTrash || currentNav === 'review' || currentNav === 'completed');
    if (isTrash) { c.innerHTML = tasks.length ? tasks.map(t => renderTaskRow(t, true)).join('') : es('🗑️', '垃圾桶是空的'); return; }
    if (groupedTasks) {
        const o = ['overdue', 'today', 'tomorrow', 'future', 'nodate', 'completed'];
        const l = { overdue: '⚠️ 已过期', today: '☀️ 今天', tomorrow: '🌟 明天', future: '📆 未来', nodate: '📝 无日期', completed: '✅ 已完成' };
        let h = '';
        o.forEach(k => {
            const g = groupedTasks[k];
            if (g && g.items.length > 0)
                h += `<div class="task-group"><div class="task-group-title">${l[k]} (${g.items.length})</div><div class="task-list">${g.items.map(t => renderTaskRow(t)).join('')}</div></div>`;
        });
        c.innerHTML = h || es('✌️', '没有任务');
        return;
    }
    c.innerHTML = tasks.length ? tasks.map(t => renderTaskRow(t)).join('') : es(currentNav === 'completed' ? '🎉' : '✌️', currentNav === 'completed' ? '还没有已完成的任务' : '暂无任务，添加一个吧');
}

function renderTaskRow(t, isTrash = false) {
    const cc = t.is_completed == 1 ? ' completed' : '';
    const dc = t.status === 'doing' ? ' doing' : '';
    const check = t.is_completed == 1 ? '✓' : '';
    const pl = { high: '高', medium: '中', low: '低' }[t.priority] || '中';
    const tagsHtml = (t.tags || []).map(tg => `<span class="task-tag-mini" style="background:${esc(tg.color)}">${esc(tg.name)}</span>`).join('');
    const pomoCount = (t.pomodoro_count || 0) > 0 ? `<span class="task-pomo-count">🍅 ${t.pomodoro_count}</span>` : '';

    // 子任务标识
    const isSubtask = t.parent_id > 0;
    const subBadge = (t.subtask_count > 0)
        ? `<span class="task-sub-badge" title="${t.subtask_done}/${t.subtask_count} 子任务已完成">📋 ${t.subtask_done}/${t.subtask_count}</span>`
        : '';
    const subClass = isSubtask ? ' sub-task' : '';
    const titlePrefix = isSubtask ? '<span class="sub-connector">↳ </span>' : '';

    if (isTrash) {
        return `<div class="task-item${cc}" id="task-${t.id}">
            <div class="task-content"><div class="task-title">${esc(t.title)}</div>
            <div class="task-meta">${t.category_name ? `<span class="task-category-tag" style="background:${esc(t.category_color || '#95A5A6')}">${esc(t.category_name)}</span>` : ''}<span style="background:${pc(t.priority)};color:#fff;padding:1px 6px;border-radius:8px;font-size:11px">${pl}</span></div></div>
            <div class="task-trash-actions"><span class="link" onclick="restoreTask(${t.id})">恢复</span><span class="link danger" onclick="permanentDeleteTask(${t.id})">永久删除</span></div>
        </div>`;
    }

    const timeHtml = formatTaskTime(t);
    const recurIcon = t.recurrence_type ? `<span class="task-recur-icon" title="${formatRecurrenceLabel(t.recurrence_type, t.recurrence_rule)}">🔁 ${(t.completion_count||0)}次</span>` : '';
    const statusLabel = { todo: '📝 待办', doing: '🔄 处理中', done: '✅ 完成' };
    const statusClass = { todo: '', doing: 'doing', done: 'done' };

    // 虚拟重复实例：可完成，checkbox 保持独立；🔁 标志移到标题
    if (t._virtual == 1) {
        const recurLabel = formatRecurrenceLabel(t.recurrence_type, t.recurrence_rule);
        return `<div class="task-item virtual-task${subClass}">
            <div class="task-checkbox" onclick="toggleTask(${t.id},${t.is_completed == 1 ? 0 : 1})" title="标记此预排实例为完成"> </div>
            <div class="task-content" onclick="editTask(${t.id})">
                <div class="task-title">${titlePrefix}${esc(t.title)} ${recurIcon} <span class="virtual-badge" title="${recurLabel}">预排</span></div>
                <div class="task-meta">
                    ${t.category_name ? `<span class="task-category-tag" style="background:${esc(t.category_color || '#95A5A6')}">${esc(t.category_name)}</span>` : ''}
                    <span style="background:${pc(t.priority)};color:#fff;padding:1px 6px;border-radius:8px;font-size:11px">${pl}</span>
                    ${timeHtml}
                    <div class="task-tags">${tagsHtml}</div>
                    ${subBadge}
                </div>
            </div>
            <div class="task-actions">
                <span class="virtual-hint" title="完成当前实例后将自动推进到此日期">⏳ 待推进</span>
            </div>
        </div>`;
    }

    return `<div class="task-item${cc}${dc}${subClass}" id="task-${t.id}">
        <div class="task-checkbox" onclick="toggleTask(${t.id},${t.is_completed == 1 ? 0 : 1})" title="${t.is_completed == 1 ? '标记未完成' : '标记已完成'}">${check}</div>
        <div class="task-content" onclick="editTask(${t.id})">
            <div class="task-title">${titlePrefix}${esc(t.title)}${recurIcon}${t.notes ? ' <span style="color:var(--text-muted);font-size:11px">📝</span>' : ''}${isSubtask ? ' <span class="task-tag-mini" style="background:var(--text-muted);color:#fff">子任务</span>' : ''}</div>
            <div class="task-meta">
                ${t.category_name ? `<span class="task-category-tag" style="background:${esc(t.category_color || '#95A5A6')}">${esc(t.category_name)}</span>` : ''}
                <span style="background:${pc(t.priority)};color:#fff;padding:1px 6px;border-radius:8px;font-size:11px">${pl}</span>
                ${timeHtml}
                <div class="task-tags">${tagsHtml}</div>
                ${pomoCount}
                ${subBadge}
            </div>
        </div>
        <div class="task-actions">
            ${t.status !== 'done' ? `<button class="task-state-btn ${statusClass[t.status]}" onclick="event.stopPropagation();quickStatus('${t.id}','${t.status === 'todo' ? 'doing' : 'done'}')" title="切换状态">${statusLabel[t.status]}</button>` : ''}
            <span class="act-btn danger" onclick="event.stopPropagation();deleteTask(${t.id})" title="删除">🗑️</span>
        </div>
    </div>`;
}

function formatTaskTime(t) {
    if (!t.due_datetime) return '';
    const parts = t.due_datetime.split(' '), dp = parts[0].split('-'), time = parts[1] ? parts[1].substring(0, 5) : '';
    const now = new Date(), tm = new Date(now); tm.setDate(tm.getDate() + 1); const today = localDateStr(now), tomorrow = localDateStr(tm), d = parts[0];
    let cls = 'upcoming', txt = `${parseInt(dp[1])}月${parseInt(dp[2])}日 ${time}`;
    if (t.is_completed == 1) { } else if (d < today) { cls = 'overdue'; txt = `${parseInt(dp[1])}月${parseInt(dp[2])}日 ${time} (逾期)`; }
    else if (d === today) { cls = 'today-due'; txt = `今天 ${time}`; }
    else if (d === tomorrow) { cls = 'upcoming'; txt = `明天 ${time}`; }
    return `<span class="task-time ${cls}">🕐 ${txt}</span>`;
}

function pc(p) { return { high: '#E74C3C', medium: '#F39C12', low: '#27AE60' }[p] || '#F39C12'; }
function es(icon, text) { return `<div class="empty-state"><div class="empty-state-icon">${icon}</div><div class="empty-state-text">${text}</div></div>`; }

// ========== 任务创建与编辑 ==========
async function createTask() {
    const t = document.getElementById('inputTitle').value.trim(), cid = parseInt(document.getElementById('inputCategory').value);
    if (!t) { showToast('请输入任务标题', 'error'); return; }
    const remOffset = parseInt(document.getElementById('inputReminder').value);
    let reminderCustom = null;
    if (remOffset === -1) {
        reminderCustom = document.getElementById('quickReminderDatetime').value;
        if (reminderCustom) reminderCustom = reminderCustom.replace('T', ' ');
    }
    try {
        const r = await fetch(API + '?action=create_task', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                title: t, category_id: cid,
                priority: document.getElementById('inputPriority').value,
                due_datetime: buildDt(document.getElementById('inputDueDate').value, document.getElementById('inputDueTime').value),
                reminder_offset: remOffset === -1 ? 0 : remOffset,
                reminder_custom: reminderCustom,
                tag_ids: selectedQuickTags
            })
        });
        const j = await r.json();
        if (j.success) { document.getElementById('inputTitle').value = ''; selectedQuickTags = []; renderQuickAddTags(); await Promise.all([loadTasks(), loadCategories(), loadTags(), loadSummary()]); showToast('任务已添加', 'success'); }
        else showToast(j.message || '创建失败', 'error');
    } catch (e) { showToast('网络错误', 'error'); }
}

function buildDt(d, t) { return d ? (d + (t ? ' ' + t + ':00' : ' 09:00:00')) : null; }

async function toggleTask(id, s) {
    try {
        const r = await fetch(API + '?action=toggle_task', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, is_completed: s }) });
        const j = await r.json();
        if (!j.success) return;
        // 循环任务推进：仅刷新列表（任务已移到下一期，不做消失动画）
        if (j.data && j.data.due_datetime) {
            showToast(j.message || '任务已推进');
            await Promise.all([loadTasks(), loadCategories(), loadTags(), loadSummary()]);
            return;
        }
        const el = document.getElementById('task-' + id);
        if (el && s === 1) {
            el.classList.add('completed');
            const cb = el.querySelector('.task-checkbox'); if (cb) cb.textContent = '✓';
            setTimeout(() => { el.classList.add('completing'); setTimeout(() => { loadTasks(); loadCategories(); loadTags(); loadSummary(); }, 350); }, 1200);
        } else { await loadTasks(); await loadCategories(); await loadTags(); loadSummary(); }
    } catch (e) {}
}

async function quickStatus(id, s) {
    try {
        await fetch(API + '?action=update_task_status', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: parseInt(id), status: s }) });
        await Promise.all([loadTasks(), loadCategories(), loadTags(), loadSummary()]);
        showToast(s === 'done' ? '任务已完成' : (s === 'doing' ? '标记为处理中' : '已恢复待办'), 'success');
    } catch (e) { showToast('操作失败', 'error'); }
}

async function deleteTask(id) {
    if (!await showConfirm('删除任务', '确定删除该任务？')) return;
    try {
        const r = await fetch(API + '?action=delete_task', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) });
        const j = await r.json();
        if (j.success) { showToast('已移入垃圾桶', 'success'); await Promise.all([loadTasks(), loadCategories(), loadTags(), loadSummary()]); }
        else showToast(j.message || '删除失败', 'error');
    } catch (e) { showToast('网络错误', 'error'); }
}

async function restoreTask(id) { try { await fetch(API + '?action=restore_task', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) }); showToast('已恢复', 'success'); await loadTasks(); loadSummary(); } catch (e) {} }

async function permanentDeleteTask(id) {
    if (!await showConfirm('永久删除', '永久删除后无法恢复，确定？')) return;
    try { await fetch(API + '?action=permanent_delete_task', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) }); showToast('已永久删除', 'success'); await loadTasks(); loadSummary(); } catch (e) {}
}

async function emptyTrash() {
    if (!await showConfirm('清空垃圾桶', '清空垃圾桶？此操作不可撤销。')) return;
    try { const r = await fetch(API + '?action=empty_trash', { method: 'POST' }); const j = await r.json(); showToast('已清空 ' + (j.data.count || 0) + ' 项', 'success'); await loadTasks(); loadSummary(); } catch (e) {}
}

async function editTask(id) {
    try {
        const r = await fetch(API + '?action=get_task&id=' + id);
        if (!r.ok) { showToast('获取任务失败(' + r.status + ')', 'error'); return; }
        const j = await r.json();
        if (!j.success) { showToast(j.message || '获取任务失败', 'error'); return; }
        const t = j.data;
        if (!t) { showToast('任务数据为空', 'error'); return; }
        document.getElementById('editTaskId').value = t.id;
        document.getElementById('editTaskTitle').value = t.title;
        document.getElementById('editTaskDescription').value = t.description || '';
        document.getElementById('editTaskPriority').value = t.priority;
        document.getElementById('editTaskReminder').value = t.reminder_offset || '0';
        document.getElementById('editTaskNotes').value = t.notes || '';
        document.getElementById('editTaskStatus').value = t.status || 'todo';
        const cs = document.getElementById('editTaskCategory');
        cs.innerHTML = categories.map(c => `<option value="${c.id}" ${c.id == t.category_id ? 'selected' : ''}>${esc(c.name)}</option>`).join('');
        if (t.due_datetime) { const p = t.due_datetime.split(' '); document.getElementById('editTaskDate').value = p[0] || ''; document.getElementById('editTaskTime').value = (p[1] || '').substring(0, 5); }
        else { document.getElementById('editTaskDate').value = ''; document.getElementById('editTaskTime').value = ''; }
        // 自定义提醒
        if (t.reminder_custom) {
            document.getElementById('editReminderDatetime').value = t.reminder_custom.replace(' ', 'T');
            document.getElementById('editTaskReminder').value = '-1';
        } else {
            document.getElementById('editReminderDatetime').value = '';
        }
        toggleEditReminderCustom();
        // 重复任务
        document.getElementById('editRecurrenceType').value = t.recurrence_type || '';
        document.getElementById('editRecurrenceEnd').value = t.recurrence_end || '';
        document.getElementById('editStartDate').value = t.recurrence_start || '';
        try {
            const rrule = JSON.parse(t.recurrence_rule || '{}');
            if (t.recurrence_type === 'weekly') {
                const days = rrule.days || [];
                document.querySelectorAll('#editWeekdayPicker input[type="checkbox"]').forEach(cb => {
                    cb.checked = days.includes(parseInt(cb.value));
                });
            }
            if (t.recurrence_type === 'monthly' && rrule.day) document.getElementById('editRecurrenceDay').value = rrule.day;
            if (t.recurrence_type === 'yearly') {
                if (rrule.month) document.getElementById('editRecurrenceMonth').value = rrule.month;
                if (rrule.day) document.getElementById('editRecurrenceDayY').value = rrule.day;
            }
        } catch (e) {}
        toggleEditRecurrence();
        selectedEditTags = (t.tag_ids || []).map(x => parseInt(x));
        renderEditTags();
        // 子任务
        renderEditSubtasks(t.subtasks || []);
        // 附件
        renderEditAttachments(t.attachments || []);
        document.getElementById('taskEditDialog').classList.add('show');
    } catch (e) {
        console.error('editTask error:', e);
        showToast('编辑弹窗加载失败：' + (e.message || '未知错误'), 'error');
    }
}

function closeTaskEdit() { document.getElementById('taskEditDialog').classList.remove('show'); }

async function saveTaskEdit() {
    const id = parseInt(document.getElementById('editTaskId').value), title = document.getElementById('editTaskTitle').value.trim();
    if (!title) { showToast('请输入标题', 'error'); return; }
    const remOffset = parseInt(document.getElementById('editTaskReminder').value);
    let reminderCustom = null;
    if (remOffset === -1) {
        reminderCustom = document.getElementById('editReminderDatetime').value;
        if (reminderCustom) reminderCustom = reminderCustom.replace('T', ' ');
    }
    // 先上传新附件
    if (editAttachFiles.length > 0) {
        for (const f of editAttachFiles) {
            const fd = new FormData();
            fd.append('task_id', id);
            fd.append('file', f);
            try { await fetch(API + '?action=upload_attachment', { method: 'POST', body: fd }); } catch (e) {}
        }
        editAttachFiles = [];
    }
    try {
        const r = await fetch(API + '?action=update_task', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id, title, category_id: parseInt(document.getElementById('editTaskCategory').value),
                priority: document.getElementById('editTaskPriority').value,
                due_datetime: buildDt(document.getElementById('editTaskDate').value, document.getElementById('editTaskTime').value),
                reminder_offset: remOffset === -1 ? 0 : remOffset,
                reminder_custom: reminderCustom,
                notes: document.getElementById('editTaskNotes').value.trim(),
                description: document.getElementById('editTaskDescription').value.trim(),
                status: document.getElementById('editTaskStatus').value,
                recurrence_type: document.getElementById('editRecurrenceType').value,
                recurrence_rule: getEditRecurrenceRule(),
                recurrence_end: document.getElementById('editRecurrenceEnd').value || null,
                recurrence_start: document.getElementById('editStartDate').value || null,
                tag_ids: selectedEditTags
            })
        });
        const j = await r.json();
        if (j.success) { closeTaskEdit(); await Promise.all([loadTasks(), loadCategories(), loadTags(), loadSummary()]); showToast('任务已更新', 'success'); }
        else showToast(j.message || '更新失败', 'error');
    } catch (e) { showToast('网络错误', 'error'); }
}

// ========== 摘要统计 ==========
async function loadSummary() {
    try {
        const r = await fetch(API + '?action=summary');
        const j = await r.json();
        if (j.success) {
            summaryStats = j.data;
            document.getElementById('count-all').textContent = j.data.total || 0;
            document.getElementById('count-today').textContent = j.data.today || 0;
            document.getElementById('count-next7days').textContent = j.data.next7days || 0;
            document.getElementById('count-inbox').textContent = j.data.no_due || 0;
            document.getElementById('count-completed').textContent = j.data.completed || 0;
            document.getElementById('count-trash').textContent = j.data.trash || 0;
        }
    } catch (e) {}
}

// ========== 日历视图（月/周/日） ==========
let calViewMode = 'month'; // month | week | day
let calDate = new Date();

function initFullCalendar() { calDate = new Date(); }

function switchCalView(view) {
    calViewMode = view;
    document.querySelectorAll('.cal-view-tab').forEach(t => t.classList.toggle('active', t.dataset.calView === view));
    renderCalendar();
}

function calNavigate(dir) {
    if (calViewMode === 'month') { calDate.setMonth(calDate.getMonth() + dir); }
    else if (calViewMode === 'week') { calDate.setDate(calDate.getDate() + dir * 7); }
    else { calDate.setDate(calDate.getDate() + dir); }
    renderCalendar();
}

function goCalToday() { calDate = new Date(); renderCalendar(); }

async function renderCalendar() {
    document.getElementById('monthView').classList.toggle('hidden', calViewMode !== 'month');
    document.getElementById('weekView').classList.toggle('hidden', calViewMode !== 'week');
    document.getElementById('dayView').classList.toggle('hidden', calViewMode !== 'day');
    if (calViewMode === 'month') await renderMonthView();
    else if (calViewMode === 'week') await renderWeekView();
    else await renderDayView();
}

function renderFullCalendar() { calViewMode = 'month'; document.querySelectorAll('.cal-view-tab').forEach(t => t.classList.toggle('active', t.dataset.calView === 'month')); renderCalendar(); }

// ========== ISO 周数计算 ==========
function getISOWeekNumber(d) {
    const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    const dayNum = date.getUTCDay() || 7;
    date.setUTCDate(date.getUTCDate() + 4 - dayNum);
    const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1));
    return Math.ceil((((date - yearStart) / 86400000) + 1) / 7);
}

function getWeekDates(ref) {
    const d = new Date(ref);
    const day = d.getDay();
    const diff = d.getDate() - day + (day === 0 ? -6 : 1);
    const mon = new Date(d); mon.setDate(diff);
    mon.setHours(0, 0, 0, 0);
    const dates = [];
    for (let i = 0; i < 7; i++) {
        const dd = new Date(mon);
        dd.setDate(mon.getDate() + i);
        dates.push(dd);
    }
    return dates;
}

function fmtDate(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
function fmtDateShort(d) { return (d.getMonth() + 1) + '/' + d.getDate(); }

// ========== 月视图 ==========
async function renderMonthView() {
    const y = calDate.getFullYear(), m = calDate.getMonth() + 1;
    document.getElementById('calTitle').textContent = y + '年 ' + m + '月';
    try {
        const r = await fetch(API + `?action=calendar_tasks&year=${y}&month=${m}`);
        const j = await r.json();
        const cal = j.success ? j.data.calendar : {};
        const today = localDateStr();
        const first = new Date(y, m - 1, 1);
        const last = new Date(y, m, 0);
        let sd = first.getDay(); sd = sd === 0 ? 6 : sd - 1;
        const pl = new Date(y, m - 1, 0).getDate();
        const totalRows = Math.ceil((sd + last.getDate()) / 7);
        const maxTasks = totalRows <= 4 ? 7 : totalRows === 5 ? 5 : 3;
        let h = '';
        for (let i = sd - 1; i >= 0; i--) h += `<div class="full-cal-day other-month"><div class="full-cal-day-num">${pl - i}</div></div>`;
        for (let d = 1; d <= last.getDate(); d++) {
            const ds = y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
            const isToday = ds === today;
            const list = cal[ds] || [];
            const shown = list.slice(0, maxTasks);
            const overflow = list.length > maxTasks;
            h += `<div class="full-cal-day${isToday ? ' today' : ''}" onclick="openDayView('${ds}')">`;
            h += `<div class="full-cal-day-num">${d}${isToday ? ' 今天' : ''}</div>`;
            h += `<div class="full-cal-tasks">`;
            shown.forEach((t, idx) => {
                const lastOne = overflow && idx === shown.length - 1;
                const vCls = t._virtual ? ' virtual' : '';
                const compCls = t.is_completed == 1 ? ' completed' : '';
                const clickFn = t._virtual ? `openDayView('${ds}')` : `editTask(${t.id})`;
                h += `<div class="full-cal-task${compCls}${vCls}" style="background:${esc(t.category_color || '#95A5A6')}" onclick="event.stopPropagation();${clickFn}" title="${esc(t.title)}${t._virtual ? '（预排）' : ''}">${lastOne ? '…' + esc(t.title) : esc(t.title)}</div>`;
            });
            h += `</div>`;
            if (overflow) h += `<div class="full-cal-more" onclick="event.stopPropagation();openDayView('${ds}')">+${list.length - maxTasks} 更多</div>`;
            h += `</div>`;
        }
        const total = sd + last.getDate(), rem = total % 7 === 0 ? 0 : 7 - (total % 7);
        for (let d = 1; d <= rem; d++) h += `<div class="full-cal-day other-month"><div class="full-cal-day-num">${d}</div></div>`;
        const daysEl = document.getElementById('fullCalDays');
        daysEl.innerHTML = h;
        daysEl.style.gridTemplateRows = `repeat(${totalRows}, 1fr)`;
    } catch (e) {}
}

// ========== 周视图 ==========
async function renderWeekView() {
    const weekDates = getWeekDates(calDate);
    const weekNum = getISOWeekNumber(weekDates[0]);
    const year = weekDates[0].getFullYear();
    const startStr = fmtDateShort(weekDates[0]), endStr = fmtDateShort(weekDates[6]);
    document.getElementById('calTitle').textContent = year + '年 第' + weekNum + '周 (' + startStr + ' - ' + endStr + ')';
    document.getElementById('weekViewHeader').innerHTML = `<span class="week-num-badge">第 ${weekNum} 周</span><span>${year}年 ${startStr} — ${endStr}</span>`;

    // 获取该周涉及的月份数据
    const months = new Set();
    weekDates.forEach(d => months.add(d.getFullYear() + '-' + (d.getMonth() + 1)));
    const allCal = {};
    try {
        const fetches = [...months].map(mk => {
            const [yy, mm] = mk.split('-');
            return fetch(API + `?action=calendar_tasks&year=${yy}&month=${mm}`).then(r => r.json());
        });
        const results = await Promise.all(fetches);
        results.forEach(j => { if (j.success && j.data.calendar) Object.assign(allCal, j.data.calendar); });
    } catch (e) {}

    const today = localDateStr();
    const dayLabels = ['一', '二', '三', '四', '五', '六', '日'];
    let h = '';
    weekDates.forEach((d, idx) => {
        const ds = fmtDate(d);
        const isToday = ds === today;
        const list = allCal[ds] || [];
        h += `<div class="week-day-col">`;
        h += `<div class="week-day-header${isToday ? ' today' : ''}"><div class="wday-num">${d.getDate()}</div><div class="wday-label">周${dayLabels[idx]}</div></div>`;
        h += `<div class="week-day-body">`;
        if (list.length === 0) h += `<div style="font-size:10px;color:var(--text-muted);text-align:center;padding:8px">-</div>`;
        else list.slice(0, 12).forEach(t => {
            const vCls = t._virtual ? ' virtual' : '';
            const compCls = t.is_completed == 1 ? ' completed' : '';
            const clickFn = t._virtual ? `openDayView('${ds}')` : `editTask(${t.id})`;
            h += `<div class="week-task${compCls}${vCls}" style="background:${esc(t.category_color || '#95A5A6')}" onclick="${clickFn}" title="${esc(t.title)}${t._virtual ? '（预排）' : ''}">${esc(t.title)}</div>`;
        });
        if (list.length > 12) h += `<div class="cal-more-link" onclick="openDayView('${ds}')">+${list.length - 12} 更多</div>`;
        h += `</div></div>`;
    });
    document.getElementById('weekGrid').innerHTML = h;
}

// ========== 日视图 ==========
async function renderDayView() {
    const y = calDate.getFullYear(), m = calDate.getMonth() + 1, d = calDate.getDate();
    const ds = fmtDate(calDate);
    const weekdays = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'];
    const wd = weekdays[calDate.getDay()];
    document.getElementById('calTitle').textContent = y + '年' + m + '月' + d + '日 ' + wd;
    document.getElementById('dayHeader').innerHTML = `<div class="day-header-left"><div class="day-date-num">${d}</div><div class="day-date-info"><div class="day-date-weekday">${wd}</div><div class="day-date-full">${y}年${m}月</div></div></div>`;
    try {
        const r = await fetch(API + `?action=list_tasks&filter=all&calendar_date=${ds}`);
        const j = await r.json();
        const list = j.success ? (j.data || []) : [];
        document.getElementById('dayTaskList').innerHTML = list.length
            ? list.map(t => renderTaskRow(t)).join('')
            : `<div class="empty-state"><div class="empty-state-icon">📅</div><div class="empty-state-text">该日无任务</div></div>`;
    } catch (e) {}
}

function openDayView(ds) {
    const parts = ds.split('-');
    calDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
    calViewMode = 'day';
    document.querySelectorAll('.cal-view-tab').forEach(t => t.classList.toggle('active', t.dataset.calView === 'day'));
    renderCalendar();
}

// ========== 日历日期选择（跳转列表视图） ==========
function selectCalDate(ds) {
    currentNav = 'all'; currentView = 'list'; currentSearch = '';
    document.getElementById('searchInput').value = '';
    updateNavActive();
    document.querySelectorAll('.view-tab').forEach(t => t.classList.remove('active'));
    document.querySelector('.view-tab[data-view="list"]').classList.add('active');
    document.getElementById('calendarView').classList.add('hidden');
    document.getElementById('listView').classList.remove('hidden');
    loadTasksWithDate(ds);
}

async function loadTasksWithDate(ds) {
    try {
        const r = await fetch(API + `?action=list_tasks&filter=all&calendar_date=${ds}`);
        const j = await r.json();
        tasks = j.success ? (j.data || []) : [];
        groupedTasks = null;
        document.getElementById('pageTitle').textContent = ds + ' 的任务';
        document.getElementById('pageSub').textContent = tasks.length + ' 项';
        document.getElementById('taskList').innerHTML = tasks.length ? tasks.map(t => renderTaskRow(t)).join('') : es('📅', '该日无任务');
    } catch (e) {}
}

// ========== 四象限 ==========
async function loadQuadrants() {
    try {
        const r = await fetch(API + '?action=quadrants');
        const j = await r.json();
        if (!j.success) return;
        const d = j.data;
        renderQ('q-iu', d.important_urgent, 'iu');
        renderQ('q-inu', d.important_not_urgent, 'inu');
        renderQ('q-niu', d.not_important_urgent, 'niu');
        renderQ('q-ninu', d.not_important_not_urgent, 'ninu');
    } catch (e) { console.error('loadQuadrants error:', e); }
}

function renderQ(el, k, key) {
    document.getElementById('q-count-' + key).textContent = k.length;
    document.getElementById(el).innerHTML = k.length
        ? k.map(t => `<div class="quadrant-task" onclick="editTask(${t.id})"><span class="q-checkbox${t.is_completed == 1 ? ' done' : ''}" onclick="event.stopPropagation();toggleTask(${t.id},${t.is_completed == 1 ? 0 : 1})"></span><span style="font-size:13px;flex:1">${esc(t.title)}</span></div>`).join('')
        : `<div style="color:var(--text-muted);font-size:12px;text-align:center;padding:20px">暂无</div>`;
}

// ========== 番茄钟 ==========
function showPomodoroView() {
    document.getElementById('pageTitle').textContent = '🍅 番茄钟';
    document.getElementById('pageSub').textContent = '专注工作，高效产出';
    loadPomoTaskSelect();
    loadPomodoroStats();
}

function loadPomoTaskSelect() {
    const sel = document.getElementById('pomoTaskSelect');
    sel.innerHTML = '<option value="0">无关联</option>' + tasks.filter(t => t.is_completed == 0).map(t => `<option value="${t.id}">${esc(t.title)}</option>`).join('');
}

async function loadPomodoroStats() {
    try {
        const r = await fetch(API + '?action=pomodoro_today');
        const j = await r.json();
        if (!j.success) return;
        const s = j.data;
        document.getElementById('pomoTodayStats').innerHTML = `<span>🍅 今日番茄 <strong>${s.total}</strong> 个</span><span>⏱ 专注 <strong>${s.total_minutes}</strong> 分钟</span><span>📝 涉及 <strong>${s.task_count}</strong> 个任务</span>`;
        const wkRes = await fetch(API + '?action=pomodoro_week_stats');
        const wk = await wkRes.json();
        if (wk.success && wk.data.length) {
            const max = Math.max(...wk.data.map(d => parseInt(d.cnt)), 1);
            const days = ['日', '一', '二', '三', '四', '五', '六'];
            let chart = '';
            wk.data.forEach(d => {
                const pct = parseInt(d.cnt) / max;
                const barH = Math.round(pct * 72);
                chart += `<div class="week-bar-col"><div class="week-bar" style="height:${Math.max(2, barH)}px" title="${d.day}: ${d.cnt}个 (${d.minutes}分钟)"></div><div class="week-bar-label">${days[new Date(d.day).getDay()]}</div></div>`;
            });
            document.getElementById('pomoWeekChart').innerHTML = chart;
        } else {
            document.getElementById('pomoWeekChart').innerHTML = '<div class="week-chart-empty">暂无本周专注数据，开始一个番茄钟吧！</div>';
        }
    } catch (e) {}
}

function startPomodoro() {
    pomoWorkMin = parseInt(document.getElementById('pomoWorkMin').value) || 25;
    pomoBreakMin = parseInt(document.getElementById('pomoBreakMin').value) || 5;
    pomoTaskId = parseInt(document.getElementById('pomoTaskSelect').value) || 0;
    pomoIsBreak = false; pomoSeconds = pomoWorkMin * 60; pomoTotalSeconds = pomoSeconds;
    startPomoTimer();
    showToast('开始专注！🍅', 'info');
}

function startPomodoroShort() {
    pomoBreakMin = parseInt(document.getElementById('pomoBreakMin').value) || 5;
    pomoIsBreak = true; pomoSeconds = pomoBreakMin * 60; pomoTotalSeconds = pomoSeconds;
    startPomoTimer();
    showToast('休息一下 ☕', 'info');
}

function startPomoTimer() {
    pomoRunning = true;
    document.getElementById('pomoFullPanel').classList.add('pomo-active');
    document.getElementById('pomoStopBtn').classList.remove('hidden');
    document.body.classList.add('pomo-running');
    document.title = '🍅 ' + formatPomoTime() + ' - 专注中';
    pomoTimer = setInterval(() => { if (pomoSeconds <= 0) { pomoComplete(); return; } pomoSeconds--; updatePomoDisplay(); }, 1000);
    updatePomoDisplay();
}

function updatePomoDisplay() {
    document.getElementById('pomoBigTimer').textContent = formatPomoTime();
    document.getElementById('pomoBigLabel').textContent = pomoIsBreak ? '☕ 休息中...' : '🍅 专注中...';
    document.title = (pomoIsBreak ? '☕ ' : '🍅 ') + formatPomoTime() + ' - ' + (pomoIsBreak ? '休息中' : '专注中');
}

function stopPomodoro() {
    if (pomoTimer) { clearInterval(pomoTimer); pomoTimer = null; }
    pomoRunning = false;
    document.getElementById('pomoFullPanel').classList.remove('pomo-active');
    document.getElementById('pomoStopBtn').classList.add('hidden');
    document.body.classList.remove('pomo-running');
    document.getElementById('pomoBigLabel').textContent = '已停止';
    document.title = '任务管理系统';
}

function resetPomodoro() {
    stopPomodoro();
    pomoIsBreak = false;
    pomoWorkMin = parseInt(document.getElementById('pomoWorkMin').value) || 25;
    pomoSeconds = pomoWorkMin * 60;
    pomoTotalSeconds = pomoSeconds;
    document.getElementById('pomoBigTimer').textContent = formatPomoTime();
    document.getElementById('pomoBigLabel').textContent = '准备开始';
    document.getElementById('pomoFullPanel').classList.remove('pomo-active');
}

async function pomoComplete() {
    stopPomodoro();
    document.getElementById('pomoBigLabel').textContent = '完成！';

    // 声音提醒
    if (userSettings.sound_enabled) playBeep();

    // 标签闪烁提醒
    const flashMsg = pomoIsBreak ? '☕ 休息结束，开始专注吧！' : '🍅 番茄钟完成！';
    if (userSettings.tab_flash_enabled) startTabFlashMsg(flashMsg);

    // 桌面通知
    if ('Notification' in window && Notification.permission === 'granted') {
        new Notification(flashMsg, { tag: 'pomodoro-done', body: pomoIsBreak ? '等您回来继续专注' : '太棒了，休息一下吧！' });
    }

    if (!pomoIsBreak && pomoWorkMin > 0) {
        try {
            await fetch(API + '?action=pomodoro_start', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ task_id: pomoTaskId, work_duration: pomoWorkMin, break_duration: pomoBreakMin })
            });
            showToast('番茄钟完成！+1 🍅', 'success');
            loadPomodoroStats();
            if (currentNav === 'today' || currentNav === 'all') loadTasks();
        } catch (e) { showToast('保存番茄记录失败', 'error'); }
    }
    setTimeout(() => {
        pomoSeconds = pomoIsBreak ? pomoWorkMin * 60 : pomoBreakMin * 60;
        pomoIsBreak = !pomoIsBreak;
        pomoTotalSeconds = pomoSeconds;
        document.getElementById('pomoBigLabel').textContent = pomoIsBreak ? '休息一下？点开始休息' : '再来一个？点开始专注';
        document.getElementById('pomoBigTimer').textContent = formatPomoTime();
    }, 500);
}

function formatPomoTime() {
    const m = Math.floor(pomoSeconds / 60), s = pomoSeconds % 60;
    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
}

// ========== 每日回顾 ==========
async function showReview() {
    currentNav = 'review';
    updateNavActive();
    document.getElementById('listView').classList.add('hidden');
    ['calendarView', 'quadrantsView', 'pomodoroView'].forEach(id => document.getElementById(id).classList.add('hidden'));
    document.getElementById('reviewView').classList.remove('hidden');
    document.getElementById('viewTabs').classList.add('hidden');
    document.getElementById('pageTitle').textContent = '📊 每日回顾';
    document.getElementById('pageSub').textContent = '今天的工作总结';
    try {
        const r = await fetch(API + '?action=daily_review');
        const j = await r.json();
        if (!j.success) return;
        const d = j.data;
        document.getElementById('reviewGrid').innerHTML = `
            <div class="review-card"><div class="r-icon">✅</div><div class="r-value">${d.done_today}</div><div class="r-label">今日完成任务</div></div>
            <div class="review-card"><div class="r-icon">➕</div><div class="r-value">${d.created_today}</div><div class="r-label">今日新建任务</div></div>
            <div class="review-card"><div class="r-icon">⚠️</div><div class="r-value" style="color:var(--danger)">${d.overdue}</div><div class="r-label">逾期未完成</div></div>
            <div class="review-card"><div class="r-icon">🍅</div><div class="r-value">${d.pomo_count}</div><div class="r-label">今日番茄数</div></div>
            <div class="review-card"><div class="r-icon">⏱️</div><div class="r-value">${d.pomo_minutes}</div><div class="r-label">专注时长(分钟)</div></div>
            <div class="review-card"><div class="r-icon">📈</div><div class="r-value">${d.done_today > 0 ? Math.round(d.done_today / (d.created_today || 1) * 100) : 0}%</div><div class="r-label">完成率</div></div>`;
        const total = d.done_today + d.overdue;
        const pct = total > 0 ? Math.round(d.done_today / total * 100) : 0;
        document.getElementById('reviewSummary').innerHTML = `
            <div class="summary-card success"><div class="num">${d.done_today}</div><div class="label">已完成</div></div>
            <div class="summary-card info"><div class="num">${d.pomo_count}</div><div class="label">番茄</div></div>
            <div class="summary-card"><div class="num">${pct}%</div><div class="label">完成率</div><div class="review-bar"><div class="review-bar-fill" style="width:${pct}%"></div></div></div>`;
    } catch (e) {}
}

// ========== 桌面通知与提醒 ==========
let shownReminderIds = {};  // 已弹出提醒弹窗的任务ID -> timer，避免重复弹窗
async function checkNotifications() {
    try {
        const r = await fetch(API + '?action=today_reminders');
        const j = await r.json();
        if (!j.success || !j.data || j.data.length === 0) return;
        const tasks = j.data;
        // 声音 & 标签闪烁 & 桌面通知
        if (userSettings.sound_enabled) playBeep();
        if (userSettings.tab_flash_enabled) startTabFlash(tasks.length);
        if ('Notification' in window) {
            if (Notification.permission === 'default') await Notification.requestPermission();
            if (Notification.permission === 'granted' && !notificationShown) {
                notificationShown = true;
                new Notification('📋 任务提醒 (' + tasks.length + ' 项)', { body: tasks.map((t, i) => (i + 1) + '. ' + t.title).join('\n'), tag: 'todolist-daily' });
            }
        }
        // 右下角弹窗提醒（懒人模式）
        tasks.forEach(t => {
            if (!shownReminderIds[t.id]) showReminderPopup(t);
        });
    } catch (e) {}
}

/** 右下角提醒弹窗 */
function showReminderPopup(task) {
    const ct = document.getElementById('reminderPopupContainer');
    if (!ct) return;
    shownReminderIds[task.id] = true;

    // 格式化时间
    const t = task.due_datetime ? task.due_datetime.slice(11, 16) : '';
    const now = new Date();
    let timeLabel = '';
    if (task.due_datetime) {
        const d = task.due_datetime.slice(0, 10);
        const tm = new Date(d + 'T' + task.due_datetime.slice(11, 16));
        const diffMin = Math.round((now - tm) / 60000);
        if (diffMin < -1) timeLabel = '还有 ' + Math.abs(diffMin) + ' 分钟';
        else if (diffMin < 0) timeLabel = '即将到期';
        else if (diffMin < 1) timeLabel = '就是现在！';
        else if (diffMin < 60) timeLabel = '已逾期 ' + diffMin + ' 分钟';
        else timeLabel = '已逾期 ' + Math.floor(diffMin / 60) + ' 小时';
    }

    const priorityColors = { 3: '#ef4444', 2: '#f59e0b', 1: '#3b82f6' };
    const priorityLabels = { 3: '高', 2: '中', 1: '低' };
    const pc = priorityColors[task.priority] || '#999';
    const pl = priorityLabels[task.priority] || '';

    const el = document.createElement('div');
    el.className = 'reminder-popup';
    el.id = 'rem-popup-' + task.id;
    el.innerHTML = `
        <div class="rem-popup-bar" style="background:${pc}"></div>
        <button class="rem-btn-close" onclick="dismissReminderPopup(${task.id})" title="关闭">✕</button>
        <div class="rem-popup-body">
            <div class="rem-popup-header">
                <span class="rem-popup-priority" style="background:${pc};color:#fff">${pl}</span>
                <span class="rem-popup-time">⏰ ${t}${timeLabel ? ' <em>' + timeLabel + '</em>' : ''}</span>
            </div>
            <div class="rem-popup-title">${escHtml(task.title)}</div>
            ${task.category_name ? '<div class="rem-popup-cat">📂 ' + escHtml(task.category_name) + '</div>' : ''}
            <div class="rem-popup-actions">
                <button class="rem-btn rem-btn-done" onclick="dismissFromReminder(${task.id})">⊘ 不再提醒</button>
                <button class="rem-btn rem-btn-snooze" onclick="snoozeReminder(${task.id},5)">+5分</button>
                <button class="rem-btn rem-btn-snooze" onclick="snoozeReminder(${task.id},10)">+10分</button>
                <button class="rem-btn rem-btn-snooze" onclick="snoozeReminder(${task.id},15)">+15分</button>
                <button class="rem-btn rem-btn-snooze" onclick="snoozeReminder(${task.id},30)">+30分</button>
            </div>
        </div>`;
    ct.appendChild(el);
    // 入场动画
    requestAnimationFrame(() => el.classList.add('rem-popup-show'));
    // 60秒后自动消失
    const timer = setTimeout(() => dismissReminderPopup(task.id), 60000);
    el._autoClose = timer;
}

/** 延时提醒 */
async function snoozeReminder(taskId, minutes) {
    const el = document.getElementById('rem-popup-' + taskId);
    if (el) { el.classList.remove('rem-popup-show'); setTimeout(() => el.remove(), 300); }
    if (el && el._autoClose) clearTimeout(el._autoClose);
    // 前一次延时定时器（如有）先清掉
    if (typeof shownReminderIds[taskId] === 'number') clearTimeout(shownReminderIds[taskId]);
    // 前端兜底封锁：延时期间不弹窗，不依赖后端是否成功
    shownReminderIds[taskId] = setTimeout(() => { delete shownReminderIds[taskId]; }, minutes * 60 * 1000 + 30000);
    try {
        const fd = new FormData();
        fd.append('id', taskId);
        fd.append('minutes', minutes);
        const r = await fetch(API + '?action=snooze_reminder', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            showToast('已延时 +' + minutes + ' 分钟提醒', 'info');
        } else {
            // 后端失败：清除前端封锁，立即恢复可弹窗状态
            if (typeof shownReminderIds[taskId] === 'number') clearTimeout(shownReminderIds[taskId]);
            delete shownReminderIds[taskId];
            showToast('延时失败：' + (j.message || '服务器错误'), 'error');
        }
    } catch (e) {
        if (typeof shownReminderIds[taskId] === 'number') clearTimeout(shownReminderIds[taskId]);
        delete shownReminderIds[taskId];
        showToast('网络错误，延时失败', 'error');
    }
}

/** 不再提醒：清除该任务所有提醒设置 */
async function dismissFromReminder(taskId) {
    const el = document.getElementById('rem-popup-' + taskId);
    if (el) { el.classList.add('rem-popup-remove'); setTimeout(() => el.remove(), 300); }
    if (el && el._autoClose) clearTimeout(el._autoClose);
    // 前端兜底：永久封锁，该页面生命周期内不再弹窗
    if (typeof shownReminderIds[taskId] === 'number') clearTimeout(shownReminderIds[taskId]);
    shownReminderIds[taskId] = 'dismissed';
    try {
        const fd = new FormData();
        fd.append('id', taskId);
        const r = await fetch(API + '?action=dismiss_reminder', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            showToast('已不再提醒', 'info');
        } else {
            showToast('操作失败：' + (j.message || '服务器错误'), 'error');
        }
    } catch (e) {
        showToast('网络错误，操作失败', 'error');
    }
}

/** 关闭提醒弹窗（仅关闭窗口，不改变封锁状态） */
function dismissReminderPopup(taskId) {
    const el = document.getElementById('rem-popup-' + taskId);
    if (!el) return;
    el.classList.add('rem-popup-remove');
    if (el._autoClose) clearTimeout(el._autoClose);
    setTimeout(() => el.remove(), 300);
    // 只有"展示中"状态（true）才清除，延时/永久封锁保留
    if (shownReminderIds[taskId] === true) delete shownReminderIds[taskId];
}

function playBeep() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        osc.type = 'sine';
        osc.frequency.value = 880;
        const gain = ctx.createGain();
        gain.gain.value = .3;
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + .15);
    } catch (e) {}
}

function startTabFlash(c) {
    const o = document.title, at = '🔔 您有 ' + c + ' 项待办任务！';
    if (window._tf) clearInterval(window._tf);
    let tg = false;
    window._tf = setInterval(() => { document.title = tg ? at : o; tg = !tg; }, 1000);
    const s = () => { document.title = o; if (window._tf) { clearInterval(window._tf); window._tf = null; } window.removeEventListener('focus', s); };
    window.addEventListener('focus', s);
}

function startTabFlashMsg(msg) {
    const o = document.title;
    if (window._tf) clearInterval(window._tf);
    let tg = false;
    window._tf = setInterval(() => { document.title = tg ? msg : o; tg = !tg; }, 1000);
    const s = () => { document.title = o; if (window._tf) { clearInterval(window._tf); window._tf = null; } window.removeEventListener('focus', s); };
    window.addEventListener('focus', s);
}

// ========== v6.0 详细任务创建弹窗 ==========
function showTaskCreateDialog() {
    // 同步清单选项
    const cs = document.getElementById('createTaskCategory');
    cs.innerHTML = categories.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
    // 同步标签
    selectedQuickTags = [];
    renderCreateTags();
    // 重置字段
    document.getElementById('createTaskTitle').value = '';
    document.getElementById('createTaskDescription').value = '';
    document.getElementById('createTaskNotes').value = '';
    document.getElementById('createTaskDate').value = localDateStr();
    document.getElementById('createTaskTime').value = '09:00';
    document.getElementById('createTaskReminder').value = '0';
    document.getElementById('createReminderDatetime').value = '';
    document.getElementById('createReminderCustomField').classList.add('hidden');
    document.getElementById('createRecurrenceType').value = '';
    document.getElementById('createRecurrenceEnd').value = '';
    document.getElementById('createStartDate').value = '';
    toggleCreateRecurrence();
    document.getElementById('createTaskPriority').value = 'medium';
    createAttachFiles = [];
    document.getElementById('createAttachList').innerHTML = '';
    document.getElementById('createTaskFile').value = '';
    document.getElementById('taskCreateDialog').classList.add('show');
}
function closeTaskCreate() { document.getElementById('taskCreateDialog').classList.remove('show'); }
function toggleCreateReminderCustom() {
    document.getElementById('createReminderCustomField').classList.toggle('hidden', document.getElementById('createTaskReminder').value !== '-1');
}
function renderCreateTags() {
    const c = document.getElementById('createTaskTags');
    if (!c) return;
    c.innerHTML = tags.map(t =>
        `<span class="tag-edit-pill${selectedQuickTags.includes(parseInt(t.id)) ? ' selected' : ''}" style="${selectedQuickTags.includes(parseInt(t.id)) ? 'background:' + esc(t.color) : ''}" onclick="toggleCreateTag(${t.id})">${esc(t.name)}</span>`
    ).join('');
}
function toggleCreateTag(id) { id = parseInt(id); selectedQuickTags = selectedQuickTags.includes(id) ? selectedQuickTags.filter(x => x !== id) : [...selectedQuickTags, id]; renderCreateTags(); }

function handleCreateFileSelect() {
    const inp = document.getElementById('createTaskFile');
    for (const f of inp.files) {
        if (f.size > 20 * 1024 * 1024) { showToast(`${f.name} 超过20MB限制`, 'error'); continue; }
        createAttachFiles.push(f);
    }
    renderCreateAttachList();
    inp.value = '';
}
function renderCreateAttachList() {
    document.getElementById('createAttachList').innerHTML = createAttachFiles.map((f, i) => {
        const size = f.size > 1048576 ? (f.size / 1048576).toFixed(1) + ' MB' : (f.size / 1024).toFixed(0) + ' KB';
        return `<div class="attach-item"><span class="att-name">📄 ${esc(f.name)}</span><span class="att-size">${size}</span><div class="att-actions"><button class="att-btn danger" onclick="createAttachFiles.splice(${i},1);renderCreateAttachList()">×</button></div></div>`;
    }).join('');
}
async function saveTaskCreate() {
    const title = document.getElementById('createTaskTitle').value.trim();
    const catId = parseInt(document.getElementById('createTaskCategory').value);
    if (!title) { showToast('请输入任务标题', 'error'); return; }
    if (!catId) { showToast('请选择清单', 'error'); return; }

    const remOffset = parseInt(document.getElementById('createTaskReminder').value);
    let reminderCustom = null;
    if (remOffset === -1) {
        reminderCustom = document.getElementById('createReminderDatetime').value;
        if (reminderCustom) reminderCustom = reminderCustom.replace('T', ' ');
    }

    try {
        const r = await fetch(API + '?action=create_task', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                title, category_id: catId,
                priority: document.getElementById('createTaskPriority').value,
                due_datetime: buildDt(document.getElementById('createTaskDate').value, document.getElementById('createTaskTime').value),
                reminder_offset: remOffset === -1 ? 0 : remOffset,
                reminder_custom: reminderCustom,
                description: document.getElementById('createTaskDescription').value.trim(),
                notes: document.getElementById('createTaskNotes').value.trim(),
                recurrence_type: document.getElementById('createRecurrenceType').value,
                recurrence_rule: getCreateRecurrenceRule(),
                recurrence_end: document.getElementById('createRecurrenceEnd').value || null,
                recurrence_start: document.getElementById('createStartDate').value || null,
                tag_ids: selectedQuickTags
            })
        });
        const j = await r.json();
        if (j.success) {
            const taskId = j.data.id;
            // 上传附件
            for (const f of createAttachFiles) {
                const fd = new FormData();
                fd.append('task_id', taskId);
                fd.append('file', f);
                try { await fetch(API + '?action=upload_attachment', { method: 'POST', body: fd }); } catch (e) {}
            }
            closeTaskCreate();
            selectedQuickTags = []; renderQuickAddTags();
            await Promise.all([loadTasks(), loadCategories(), loadTags(), loadSummary()]);
            showToast('任务已创建', 'success');
        } else showToast(j.message || '创建失败', 'error');
    } catch (e) { showToast('网络错误', 'error'); }
}

// ========== 编辑弹窗辅助（提醒/子任务/附件） ==========
function toggleEditReminderCustom() {
    document.getElementById('editReminderCustomField').classList.toggle('hidden', document.getElementById('editTaskReminder').value !== '-1');
}
// ========== 重复任务辅助 ==========
function toggleEditRecurrence() {
    const t = document.getElementById('editRecurrenceType').value;
    document.getElementById('editRecurrenceEndField').classList.toggle('hidden', !t);
    document.getElementById('editWeeklyDays').classList.toggle('hidden', t !== 'weekly');
    document.getElementById('editMonthlyDay').classList.toggle('hidden', t !== 'monthly');
    document.getElementById('editYearlyDate').classList.toggle('hidden', t !== 'yearly');
}
function toggleCreateRecurrence() {
    const t = document.getElementById('createRecurrenceType').value;
    document.getElementById('createRecurrenceEndField').classList.toggle('hidden', !t);
    document.getElementById('createWeeklyDays').classList.toggle('hidden', t !== 'weekly');
    document.getElementById('createMonthlyDay').classList.toggle('hidden', t !== 'monthly');
    document.getElementById('createYearlyDate').classList.toggle('hidden', t !== 'yearly');
}
function getEditRecurrenceRule() {
    const t = document.getElementById('editRecurrenceType').value;
    if (!t) return '';
    if (t === 'weekly') {
        const cbs = document.querySelectorAll('#editWeekdayPicker input[type="checkbox"]:checked');
        const days = Array.from(cbs).map(cb => parseInt(cb.value)).sort((a,b) => a-b);
        return JSON.stringify({ days });
    }
    if (t === 'monthly') return JSON.stringify({ day: parseInt(document.getElementById('editRecurrenceDay').value) || 1 });
    if (t === 'yearly') return JSON.stringify({ month: parseInt(document.getElementById('editRecurrenceMonth').value) || 1, day: parseInt(document.getElementById('editRecurrenceDayY').value) || 1 });
    if (t === 'daily') return JSON.stringify({ interval: 1 });
    return '';
}
function getCreateRecurrenceRule() {
    const t = document.getElementById('createRecurrenceType').value;
    if (!t) return '';
    if (t === 'weekly') {
        const cbs = document.querySelectorAll('#createWeekdayPicker input[type="checkbox"]:checked');
        const days = Array.from(cbs).map(cb => parseInt(cb.value)).sort((a,b) => a-b);
        return JSON.stringify({ days });
    }
    if (t === 'monthly') return JSON.stringify({ day: parseInt(document.getElementById('createRecurrenceDay').value) || 1 });
    if (t === 'yearly') return JSON.stringify({ month: parseInt(document.getElementById('createRecurrenceMonth').value) || 1, day: parseInt(document.getElementById('createRecurrenceDayY').value) || 1 });
    if (t === 'daily') return JSON.stringify({ interval: 1 });
    return '';
}
function formatRecurrenceLabel(type, rule) {
    if (!type) return '';
    const labels = { daily: '每天', weekly: '每周', monthly: '每月', yearly: '每年' };
    let detail = '';
    try {
        const r = JSON.parse(rule || '{}');
        if (type === 'weekly' && r.days && r.days.length) {
            const dn = {1:'周一',2:'周二',3:'周三',4:'周四',5:'周五',6:'周六',7:'周日'};
            detail = r.days.map(d => dn[d]).join('、');
        } else if (type === 'monthly' && r.day) {
            detail = r.day + '号';
        } else if (type === 'yearly' && r.month) {
            detail = r.month + '月' + (r.day || 1) + '日';
        }
    } catch (e) {}
    return labels[type] + (detail ? '（' + detail + '）' : '');
}

function renderEditSubtasks(subtasks) {
    const c = document.getElementById('editSubtaskList');
    c.innerHTML = subtasks.map(st => {
        const isDone = st.is_completed == 1;
        const ds = st.due_datetime ? st.due_datetime.substring(0, 10) : '';
        return `<div class="subtask-item">
            <div class="sub-checkbox${isDone ? ' done' : ''}" onclick="toggleSubtask(${st.id},${isDone ? 0 : 1})">${isDone ? '✓' : ''}</div>
            <div class="sub-title" onclick="editTask(${st.id})">${esc(st.title)}</div>
            <div class="sub-date">${ds || '无截止'}</div>
            <span class="sub-del" onclick="deleteTask(${st.id})">×</span>
        </div>`;
    }).join('');
}
function addSubtask() {
    const parentId = parseInt(document.getElementById('editTaskId').value);
    if (!parentId) return;
    const title = prompt('子任务标题：');
    if (!title || !title.trim()) return;
    const dueDate = prompt('截止日期（可选，格式 YYYY-MM-DD）：', '');
    (async () => {
        try {
            const r = await fetch(API + '?action=create_task', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    title: title.trim(), category_id: parseInt(document.getElementById('editTaskCategory').value),
                    priority: 'medium', due_datetime: dueDate ? dueDate + ' 09:00' : null,
                    parent_id: parentId
                })
            });
            const j = await r.json();
            if (j.success) {
                showToast('子任务已添加', 'success');
                editTask(parentId); // 重新加载编辑弹窗
            } else showToast(j.message || '添加失败', 'error');
        } catch (e) { showToast('网络错误', 'error'); }
    })();
}
async function toggleSubtask(id, s) {
    try {
        await fetch(API + '?action=toggle_task', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, is_completed: s }) });
        const parentId = parseInt(document.getElementById('editTaskId').value);
        if (parentId) editTask(parentId);
    } catch (e) {}
}

function renderEditAttachments(atts) {
    const c = document.getElementById('editAttachList');
    c.innerHTML = atts.map(a => {
        const size = a.file_size > 1048576 ? (a.file_size / 1048576).toFixed(1) + ' MB' : (a.file_size / 1024).toFixed(0) + ' KB';
        const isPdf = a.orig_name.toLowerCase().endsWith('.pdf') || (a.file_type && a.file_type.includes('pdf'));
        return `<div class="attach-item">
            <span class="att-name">📄 ${esc(a.orig_name)}</span>
            <span class="att-size">${size}</span>
            <div class="att-actions">
                <button class="att-btn" onclick="window.open('${API}?action=download_attachment&id=${a.id}','_blank')" title="${isPdf ? '在线预览' : '下载'}">${isPdf ? '预览' : '下载'}</button>
                <button class="att-btn danger" onclick="deleteAttachment(${a.id})">×</button>
            </div>
        </div>`;
    }).join('');
}
async function deleteAttachment(id) {
    if (!await showConfirm('删除附件', '删除此附件？')) return;
    try { await fetch(API + '?action=delete_attachment', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) }); showToast('附件已删除', 'success'); const taskId = parseInt(document.getElementById('editTaskId').value); if (taskId) editTask(taskId); } catch (e) {}
}
function handleEditFileSelect() {
    const inp = document.getElementById('editTaskFile');
    for (const f of inp.files) {
        if (f.size > 20 * 1024 * 1024) { showToast(`${f.name} 超过20MB限制`, 'error'); continue; }
        editAttachFiles.push(f);
    }
    renderEditFileList();
    inp.value = '';
}
function renderEditFileList() {
    const existing = document.getElementById('editAttachList').innerHTML;
    const pending = editAttachFiles.map((f, i) => {
        const size = f.size > 1048576 ? (f.size / 1048576).toFixed(1) + ' MB' : (f.size / 1024).toFixed(0) + ' KB';
        return `<div class="attach-item"><span class="att-name">⏳ ${esc(f.name)}</span><span class="att-size">${size}</span><span style="color:var(--text-muted);font-size:11px">待上传</span></div>`;
    }).join('');
    document.getElementById('editAttachList').innerHTML = existing + pending;
}

// ========== 快速添加提醒自定义 ==========
// 监听快速添加的提醒选择
document.getElementById('inputReminder').addEventListener('change', function() {
    document.getElementById('quickCustomReminder').classList.toggle('hidden', this.value !== '-1');
});
document.getElementById('inputTitle').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); createTask(); }
});

// ========== v6.0 打卡模块 ==========
async function showHabitsView() {
    currentNav = 'habits';
    updateNavActive();
    document.getElementById('listView').classList.add('hidden');
    ['calendarView', 'quadrantsView', 'pomodoroView', 'reviewView', 'summaryView'].forEach(id => document.getElementById(id).classList.add('hidden'));
    document.getElementById('habitsView').classList.remove('hidden');
    document.getElementById('viewTabs').classList.add('hidden');
    document.getElementById('habitDetailPanel').classList.add('hidden');
    document.getElementById('pageTitle').textContent = '✅ 打卡';
    document.getElementById('pageSub').textContent = '每日习惯追踪';
    await loadHabits();
}
async function loadHabits() {
    try {
        const r = await fetch(API + '?action=list_habits');
        const j = await r.json();
        if (!j.success) return;
        habits = j.data;
        document.getElementById('count-habits').textContent = habits.length;
        renderHabitsGrid();
        loadHabitsStats();
    } catch (e) { console.error('loadHabits error:', e); }
}
async function loadHabitsStats() {
    try {
        const r = await fetch(API + '?action=all_habits_stats');
        const j = await r.json();
        if (!j.success) return;
        document.getElementById('habitsStats').innerHTML = `
            <span>今日打卡 <strong>${j.data.today_count}</strong> / ${j.data.total_habits}</span>
            <span>习惯数 <strong>${j.data.total_habits}</strong></span>
        `;
        renderTrendChart(j.data.trend);
    } catch (e) {}
}
function renderTrendChart(trend) {
    const maxCnt = Math.max(...trend.map(d => d.count), 1);
    document.getElementById('habitsTrend').innerHTML = `
        <div class="habits-trend-title">📈 近7天打卡趋势</div>
        <div class="habits-trend-chart">
            ${trend.map(d => {
                const h = Math.round(d.count / maxCnt * 70);
                return `<div class="habit-trend-col">
                    <div class="habit-trend-bar ${d.count > 0 ? 'filled' : 'empty'}" style="height:${Math.max(h, 3)}px" title="${d.date}: ${d.count}项"></div>
                    <div class="habit-trend-day">${d.day}</div>
                </div>`;
            }).join('')}
        </div>
    `;
}
function renderHabitsGrid() {
    const c = document.getElementById('habitsGrid');
    if (habits.length === 0) {
        c.innerHTML = `<div class="empty-state"><div class="empty-state-icon">✅</div><div class="empty-state-text">还没有打卡习惯，点击"新建习惯"开始吧</div></div>`;
        return;
    }
    c.innerHTML = '';
    habits.forEach(h => {
        const card = document.createElement('div');
        card.className = 'habit-card fade-in';
        card.onclick = function(e) { if (e.target.closest('.habit-check-btn') || e.target.closest('.habit-card-delete')) return; showHabitDetail(h.id); };
        card.innerHTML = `
            <div class="habit-card-header">
                <div class="habit-card-icon" style="background:${esc(h.color)}22">${esc(h.icon)}</div>
                <div class="habit-card-name">${esc(h.name)}</div>
                <span class="habit-card-delete" onclick="event.stopPropagation();deleteHabit(${h.id})">×</span>
            </div>
            <div class="habit-card-stats" id="habit-stats-${h.id}">
                <span><span class="hs-val">-</span><span class="hs-label">连续</span></span>
                <span><span class="hs-val">-</span><span class="hs-label">完成率</span></span>
            </div>
            <div class="habit-card-check">
                <button class="habit-check-btn ${h.checked_today ? 'checked' : 'unchecked'}" onclick="toggleHabitCheck(${h.id}, this)" id="habit-btn-${h.id}">
                    ${h.checked_today ? '✅ 今日已打卡' : '☐ 打卡'}
                </button>
            </div>
        `;
        c.appendChild(card);
        loadHabitCardStats(h.id);
    });
}
async function loadHabitCardStats(habitId) {
    try {
        const r = await fetch(API + '?action=habit_stats&habit_id=' + habitId);
        const j = await r.json();
        if (!j.success) return;
        const el = document.getElementById('habit-stats-' + habitId);
        if (el) el.innerHTML = `
            <span><span class="hs-val">${j.data.streak}</span><span class="hs-label">连续</span></span>
            <span><span class="hs-val">${j.data.month_rate}%</span><span class="hs-label">本月</span></span>
        `;
    } catch (e) {}
}
async function toggleHabitCheck(habitId, btn) {
    btn.disabled = true;
    try {
        const r = await fetch(API + '?action=toggle_habit', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: habitId, date: new Date().toISOString().slice(0, 10) })
        });
        const j = await r.json();
        if (j.success) {
            if (j.data.checked) { btn.textContent = '✅ 今日已打卡'; btn.className = 'habit-check-btn checked'; }
            else { btn.textContent = '☐ 打卡'; btn.className = 'habit-check-btn unchecked'; }
            showToast(j.message, j.data.checked ? 'success' : 'info');
            loadHabitCardStats(habitId);
            loadHabitsStats();
        }
    } catch (e) {}
    btn.disabled = false;
}
async function showHabitDetail(habitId) {
    document.getElementById('habitsGrid').classList.add('hidden');
    document.getElementById('habitsTrend').classList.add('hidden');
    document.getElementById('habitsStats').parentElement.classList.add('hidden');
    const panel = document.getElementById('habitDetailPanel');
    panel.classList.remove('hidden');
    panel.innerHTML = '<div style="text-align:center;padding:40px">加载中...</div>';
    try {
        const [hr, sr] = await Promise.all([
            fetch(API + '?action=list_habits').then(r => r.json()),
            fetch(API + '?action=habit_stats&habit_id=' + habitId).then(r => r.json())
        ]);
        const h = (hr.data || []).find(x => parseInt(x.id) === habitId);
        if (!h) { panel.innerHTML = '未找到该习惯'; return; }
        const s = sr.data;
        const monthDays = parseInt(s.month_total);
        const calendarDays = [];
        for (let i = monthDays - 1; i >= 0; i--) {
            const dt = new Date();
            dt.setDate(dt.getDate() - i);
            const ds = dt.toISOString().slice(0, 10);
            const wd = ['日', '一', '二', '三', '四', '五', '六'][dt.getDay()];
            const checked = s.daily_30 ? s.daily_30.some(d => d.date === ds && d.checked) : false;
            calendarDays.push({ ds, wd, checked, isToday: i === 0 });
        }
        panel.innerHTML = `
            <span class="habit-detail-back" onclick="showHabitsList()">← 返回打卡列表</span>
            <div class="habit-detail-header">
                <div class="habit-card-icon" style="background:${esc(h.color)}22;font-size:36px;width:56px;height:56px">${esc(h.icon)}</div>
                <div class="hd-name">${esc(h.name)}</div>
            </div>
            <div class="habit-detail-grid">
                <div class="hd-stat"><div class="hd-val">${s.total_checks}</div><div class="hd-label">总打卡</div></div>
                <div class="hd-stat"><div class="hd-val">${s.streak}</div><div class="hd-label">连续天数</div></div>
                <div class="hd-stat"><div class="hd-val">${s.month_rate}%</div><div class="hd-label">本月完成率</div></div>
            </div>
            <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:10px">📅 本月打卡日历</div>
            <div class="habit-detail-calendar">
                ${calendarDays.map(d => `<div class="habit-cal-day${d.checked ? ' checked' : ''}${d.isToday ? ' today' : ''}" title="${d.ds} 周${d.wd}">${d.ds.slice(8)}</div>`).join('')}
            </div>
        `;
    } catch (e) { panel.innerHTML = '加载失败'; }
}
function showHabitsList() {
    document.getElementById('habitsGrid').classList.remove('hidden');
    document.getElementById('habitsTrend').classList.remove('hidden');
    document.getElementById('habitsStats').parentElement.classList.remove('hidden');
    document.getElementById('habitDetailPanel').classList.add('hidden');
}

// ========== 打卡习惯 CRUD ==========
function showHabitDialog(eid = 0, en = '', ei = '📌', ec = '#4A90D9', ed = '1,2,3,4,5,6,7') {
    document.getElementById('habitDialogTitle').textContent = eid ? '编辑习惯' : '新建打卡习惯';
    document.getElementById('editHabitId').value = eid || '';
    document.getElementById('habitNameInput').value = en;
    document.getElementById('habitColorInput').value = ec;
    selectedHabitIcon = ei;
    selectedHabitDays = ed ? ed.split(',').map(x => parseInt(x)) : [1,2,3,4,5,6,7];
    // 更新图标选中状态
    document.querySelectorAll('#habitIconOptions .icon-option').forEach(el => {
        el.classList.toggle('selected', el.dataset.icon === ei);
    });
    // 更新工作日选中状态
    document.querySelectorAll('#habitWeekdays .wday-chip').forEach(el => {
        el.classList.toggle('selected', selectedHabitDays.includes(parseInt(el.dataset.day)));
    });
    document.getElementById('habitDialog').classList.add('show');
}
function closeHabitDialog() { document.getElementById('habitDialog').classList.remove('show'); }
function pickHabitIcon(icon, el) { selectedHabitIcon = icon; document.querySelectorAll('#habitIconOptions .icon-option').forEach(e => e.classList.remove('selected')); if (el) el.classList.add('selected'); }
function toggleHabitDay(el) { const d = parseInt(el.dataset.day); if (selectedHabitDays.includes(d)) selectedHabitDays = selectedHabitDays.filter(x => x !== d); else selectedHabitDays.push(d); el.classList.toggle('selected'); }
async function saveHabit() {
    const name = document.getElementById('habitNameInput').value.trim();
    const eid = document.getElementById('editHabitId').value;
    if (!name) { showToast('请输入习惯名称', 'error'); return; }
    const targetDays = selectedHabitDays.sort((a, b) => a - b).join(',');
    try {
        const r = await fetch(API + '?action=' + (eid ? 'update_habit' : 'create_habit'), {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: eid ? parseInt(eid) : undefined, name, icon: selectedHabitIcon, color: document.getElementById('habitColorInput').value, target_days: targetDays })
        });
        const j = await r.json();
        if (j.success) { closeHabitDialog(); await loadHabits(); showToast(eid ? '习惯已更新' : '习惯已创建', 'success'); }
        else showToast(j.message || '操作失败', 'error');
    } catch (e) { showToast('网络错误', 'error'); }
}
async function deleteHabit(id) {
    if (!await showConfirm('删除习惯', '确定删除该打卡习惯？所有记录将被清除。')) return;
    try { await fetch(API + '?action=delete_habit', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) }); showToast('习惯已删除', 'success'); await loadHabits(); } catch (e) {}
}

// ========== 工具函数 ==========
function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

function showToast(msg, type) {
    type = type || 'success';
    const ex = document.querySelector('.toast');
    if (ex) ex.remove();
    const t = document.createElement('div');
    t.className = 'toast toast-' + type;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}

function showConfirm(title, message) {
    return new Promise((resolve) => {
        document.getElementById('confirmTitle').textContent = title || '确认操作';
        document.getElementById('confirmMessage').textContent = message || '确定执行此操作？';
        document.getElementById('confirmDialog').classList.add('show');
        document.getElementById('confirmOk').focus();

        function onOk() { cleanup(); resolve(true); }
        function onCancel() { cleanup(); resolve(false); }
        function cleanup() {
            document.getElementById('confirmDialog').classList.remove('show');
            document.getElementById('confirmOk').removeEventListener('click', onOk);
            document.getElementById('confirmCancel').removeEventListener('click', onCancel);
        }

        document.getElementById('confirmOk').addEventListener('click', onOk);
        document.getElementById('confirmCancel').addEventListener('click', onCancel);
    });
}
