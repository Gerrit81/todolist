<?php
/**
 * 任务管理系统 v2.2.7 — 效率办公中心
 *
 * 功能：任务管理 / 番茄钟 / 多皮肤主题 / 任务工作流(待办→处理→完成) / 每日回顾 / 子任务 / 附件 / 打卡
 *
 * 文件结构：
 *   css/style.css — 全局样式 + 6套皮肤
 *   js/app.js    — 前端应用逻辑
 *   config.php   — 数据库 / 认证 / 工具函数
 *   api.php      — REST API
 */
require_once __DIR__ . '/config.php';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>任务管理系统</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="stylesheet" href="css/style.css">
</head>
<body data-theme="default">

<!-- ========== 认证页面 ========== -->
<div id="authPage" class="auth-page">
    <div class="auth-card">
        <h2 id="authTitle">任务管理系统</h2>
        <p class="auth-sub">效率办公 · 即刻开始</p>
        <div class="form-group" id="emailGroup" style="display:none">
            <label>邮箱（选填，用于邮件提醒）</label>
            <input type="email" id="regEmail" placeholder="your@email.com">
        </div>
        <div class="form-group">
            <label>用户名</label>
            <input type="text" id="authUsername" placeholder="请输入用户名" onkeydown="if(event.key==='Enter')submitAuth()">
        </div>
        <div class="form-group">
            <label>密码</label>
            <input type="password" id="authPassword" placeholder="请输入密码" onkeydown="if(event.key==='Enter')submitAuth()">
        </div>
        <input type="hidden" id="authMode" value="login">
        <button class="auth-btn" id="authBtn" onclick="submitAuth()">登 录</button>
        <div class="auth-msg" id="authMsg"></div>
        <div class="auth-switch">
            <span id="authSwitchText">还没有账号？</span>
            <a id="authSwitchLink" onclick="toggleAuthMode()">立即注册</a>
        </div>
    </div>
</div>

<!-- ========== 主应用 ========== -->
<div id="mainApp" class="hidden">
    <!-- 顶栏 -->
    <header class="header">
        <div class="header-inner">
            <div class="header-left">
                <span class="header-logo" onclick="switchNav('today')">任务管理</span>
                <div class="header-search">
                    <input type="text" id="searchInput" placeholder="搜索任务标题或备注..." onkeydown="if(event.key==='Enter')startSearch()">
                </div>
            </div>
            <div class="header-right">
                <div class="theme-switcher">
                    <span class="theme-dot t-default active" data-theme="default" onclick="switchTheme('default')" title="默认"></span>
                    <span class="theme-dot t-green" data-theme="green" onclick="switchTheme('green')" title="护眼绿"></span>
                    <span class="theme-dot t-pink" data-theme="pink" onclick="switchTheme('pink')" title="樱花粉"></span>
                    <span class="theme-dot t-dark" data-theme="dark" onclick="switchTheme('dark')" title="暗夜模式"></span>
                    <span class="theme-dot t-ocean" data-theme="ocean" onclick="switchTheme('ocean')" title="海洋蓝"></span>
                    <span class="theme-dot t-sunset" data-theme="sunset" onclick="switchTheme('sunset')" title="日落橙"></span>
                </div>
                <span class="header-user" id="headerUsername" onclick="showPasswordDialog()" title="修改密码"></span>
                <button class="header-btn" onclick="showSettings()">⚙️</button>
                <button class="header-btn" onclick="logout()">退出</button>
                <span class="header-version">v<?php echo htmlspecialchars($config['app_version']); ?></span>
            </div>
        </div>
    </header>

    <div class="layout">
        <!-- 侧边栏 -->
        <aside class="sidebar" id="sidebar">
            <div class="nav-section">
                <div class="nav-section-title">📌 导航</div>
                <div class="nav-item active" data-nav="all" onclick="switchNav('all')"><span class="nav-icon">📂</span><span class="nav-label">所有任务</span><span class="nav-count" id="count-all">0</span></div>
                <div class="nav-item" data-nav="today" onclick="switchNav('today')"><span class="nav-icon">☀️</span><span class="nav-label">今天</span><span class="nav-count" id="count-today">0</span></div>
                <div class="nav-item" data-nav="next7days" onclick="switchNav('next7days')"><span class="nav-icon">📅</span><span class="nav-label">最近7天</span><span class="nav-count" id="count-next7days">0</span></div>
                <div class="nav-item" data-nav="inbox" onclick="switchNav('inbox')"><span class="nav-icon">📥</span><span class="nav-label">收集箱</span><span class="nav-count" id="count-inbox">0</span></div>
                <div class="nav-item" data-nav="review" onclick="switchNav('review')"><span class="nav-icon">📊</span><span class="nav-label">每日回顾</span></div>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">📋 独立模块</div>
                <div class="nav-item" data-nav="habits" onclick="switchNav('habits')"><span class="nav-icon">✅</span><span class="nav-label">打卡</span><span class="nav-count" id="count-habits">0</span></div>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">🗂️ 清单</div>
                <div id="categoryList" class="category-list"></div>
                <button class="btn-new" onclick="showCategoryDialog()">+ 新建清单</button>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">🏷️ 标签</div>
                <div id="tagList" class="tag-list"></div>
                <button class="btn-new" onclick="showTagDialog()">+ 新建标签</button>
            </div>

            <div class="nav-section" style="margin-top:auto">
                <div class="nav-item" data-nav="completed" onclick="switchNav('completed')"><span class="nav-icon">✅</span><span class="nav-label">已完成</span><span class="nav-count" id="count-completed">0</span></div>
                <div class="nav-item" data-nav="trash" onclick="switchNav('trash')"><span class="nav-icon">🗑️</span><span class="nav-label">垃圾桶</span><span class="nav-count" id="count-trash">0</span></div>
            </div>
        </aside>

        <!-- 内容区 -->
        <main class="content">
            <div class="content-header">
                <div>
                    <div class="content-title" id="pageTitle">今天</div>
                    <div class="content-sub" id="pageSub"></div>
                </div>
            </div>

            <div class="view-tabs" id="viewTabs">
                <button class="view-tab active" data-view="list" onclick="switchView('list')">📝 列表</button>
                <button class="view-tab" data-view="calendar" onclick="switchView('calendar')">📅 月历</button>
                <button class="view-tab" data-view="quadrants" onclick="switchView('quadrants')">➕ 四象限</button>
                <button class="view-tab" data-view="pomodoro" onclick="switchView('pomodoro')">🍅 番茄钟</button>
            </div>

            <!-- 列表视图 -->
            <div id="listView">
                <div class="task-form" id="quickAddForm">
                    <div class="task-form-row">
                        <input type="text" class="task-input-title" id="inputTitle" placeholder="输入新任务，按 Enter 快速创建...">
                        <select class="task-input-category" id="inputCategory"></select>
                        <select class="task-input-priority" id="inputPriority"><option value="high">🔴 高</option><option value="medium" selected>🟡 中</option><option value="low">🟢 低</option></select>
                        <input type="date" class="task-input-date" id="inputDueDate" value="<?php echo date('Y-m-d'); ?>">
                        <input type="time" class="task-input-time" id="inputDueTime" value="09:00">
                        <select class="task-input-reminder" id="inputReminder"><option value="0">准时</option><option value="5">5分钟前</option><option value="15">15分钟前</option><option value="30">30分钟前</option><option value="60">1小时前</option><option value="1440">1天前</option><option value="-1">自定义</option></select>
                        <button class="btn-submit" onclick="createTask()">＋ 添加</button>
                        <button class="btn-submit" onclick="showTaskCreateDialog()" title="详细创建">📝 详细</button>
                    </div>
                    <div class="tag-select-row" id="quickAddTags"></div>
                    <div class="custom-reminder-row hidden" id="quickCustomReminder">
                        <label>自定义提醒时间</label>
                        <input type="datetime-local" id="quickReminderDatetime">
                    </div>
                </div>
                <div class="task-list" id="taskList"></div>
            </div>

            <!-- 月历视图 -->
            <div id="calendarView" class="hidden">
                <div class="calendar-toolbar">
                    <button class="cal-toolbar-btn" onclick="calNavigate(-1)">‹</button>
                    <span class="cal-toolbar-title" id="calTitle"></span>
                    <button class="cal-toolbar-btn" onclick="calNavigate(1)">›</button>
                    <button class="cal-toolbar-btn" onclick="goCalToday()">今天</button>
                    <div class="cal-view-tabs">
                        <button class="cal-view-tab active" data-cal-view="month" onclick="switchCalView('month')">月</button>
                        <button class="cal-view-tab" data-cal-view="week" onclick="switchCalView('week')">周</button>
                        <button class="cal-view-tab" data-cal-view="day" onclick="switchCalView('day')">日</button>
                    </div>
                </div>
                <!-- 月视图 -->
                <div class="full-calendar" id="monthView">
                    <div class="full-cal-weekdays"><div class="full-cal-weekday">一</div><div class="full-cal-weekday">二</div><div class="full-cal-weekday">三</div><div class="full-cal-weekday">四</div><div class="full-cal-weekday">五</div><div class="full-cal-weekday">六</div><div class="full-cal-weekday">日</div></div>
                    <div class="full-cal-days" id="fullCalDays"></div>
                </div>
                <!-- 周视图 -->
                <div class="week-view hidden" id="weekView">
                    <div class="week-view-header" id="weekViewHeader"></div>
                    <div class="week-view-grid" id="weekGrid"></div>
                </div>
                <!-- 日视图 -->
                <div class="day-view hidden" id="dayView">
                    <div class="day-header" id="dayHeader"></div>
                    <div class="task-list" id="dayTaskList"></div>
                </div>
            </div>

            <!-- 四象限 -->
            <div id="quadrantsView" class="hidden">
                <div class="quadrants-grid">
                    <div class="quadrant urgent-important"><div class="quadrant-header"><span class="quadrant-title">🔴 重要且紧急</span><span class="quadrant-count" id="q-count-iu">0</span></div><div class="quadrant-body" id="q-iu"></div></div>
                    <div class="quadrant important"><div class="quadrant-header"><span class="quadrant-title">🟠 重要不紧急</span><span class="quadrant-count" id="q-count-inu">0</span></div><div class="quadrant-body" id="q-inu"></div></div>
                    <div class="quadrant urgent"><div class="quadrant-header"><span class="quadrant-title">🔵 紧急不重要</span><span class="quadrant-count" id="q-count-niu">0</span></div><div class="quadrant-body" id="q-niu"></div></div>
                    <div class="quadrant normal"><div class="quadrant-header"><span class="quadrant-title">⚪ 不重要不紧急</span><span class="quadrant-count" id="q-count-ninu">0</span></div><div class="quadrant-body" id="q-ninu"></div></div>
                </div>
            </div>

            <!-- 番茄钟视图 -->
            <div id="pomodoroView" class="hidden">
                <div class="pomo-full" id="pomoFullPanel">
                    <div class="pomo-config">
                        <span>工作时长</span><input type="number" id="pomoWorkMin" value="25" min="1" max="120"> <span>分钟</span>
                        <span style="margin-left:8px">休息时长</span><input type="number" id="pomoBreakMin" value="5" min="1" max="60"> <span>分钟</span>
                        <span style="margin-left:8px">关联任务</span>
                        <select id="pomoTaskSelect" style="max-width:160px"><option value="0">无关联</option></select>
                    </div>
                    <div class="pomo-big-timer" id="pomoBigTimer">25:00</div>
                    <div class="pomo-big-label" id="pomoBigLabel">准备开始</div>
                    <div class="pomo-big-controls">
                        <button class="pomo-big-btn short" onclick="startPomodoroShort()">🍅 短休息 5分钟</button>
                        <button class="pomo-big-btn start" onclick="startPomodoro()">▶ 开始专注</button>
                        <button class="pomo-big-btn stop hidden" id="pomoStopBtn" onclick="stopPomodoro()">⏹ 停止</button>
                        <button class="pomo-big-btn reset" onclick="resetPomodoro()">↺ 重置</button>
                    </div>
                    <div class="pomo-today-stats" id="pomoTodayStats"></div>
                    <div class="pomo-week-chart">
                        <div class="pomo-week-title">📊 本周专注趋势</div>
                        <div class="week-chart" id="pomoWeekChart"></div>
                    </div>
                </div>
            </div>

            <!-- 打卡视图 -->
            <div id="habitsView" class="hidden">
                <div class="habits-toolbar">
                    <div class="habits-stats" id="habitsStats"></div>
                    <button class="btn-submit" onclick="showHabitDialog()">＋ 新建习惯</button>
                </div>
                <div class="habits-trend" id="habitsTrend"></div>
                <div class="habits-grid" id="habitsGrid"></div>
                <div class="habit-detail-panel hidden" id="habitDetailPanel"></div>
            </div>

            <!-- 每日回顾 -->
            <div id="reviewView" class="hidden">
                <div class="review-grid" id="reviewGrid"></div>
                <div class="summary-grid" id="reviewSummary"></div>
            </div>

            <!-- 统计摘要 -->
            <div id="summaryView" class="hidden">
                <div class="summary-grid" id="summaryGrid"></div>
                <div class="task-list" id="summaryTaskList"></div>
            </div>
        </main>
    </div>

    <!-- 页脚 -->
    <footer class="footer">
        <div class="footer-inner">
            <div class="footer-left">
                <span class="footer-logo">任务管理系统</span>
                <span class="footer-version">v<?php echo htmlspecialchars($config['app_version']); ?></span>
            </div>
            <div class="footer-center">
                <span>高效 · 专注 · 简洁</span>
            </div>
            <div class="footer-right">
                <span>© <?php echo date('Y'); ?> TodoList</span>
            </div>
        </div>
    </footer>
</div>

<!-- ========== 弹窗 ========== -->

<!-- 清单分类弹窗 -->
<div id="categoryDialog" class="modal-overlay" onclick="if(event.target===this)closeCategoryDialog()">
    <div class="modal-box">
        <h3 id="categoryDialogTitle">新建清单</h3><span class="modal-close" onclick="closeCategoryDialog()">✕</span>
        <input type="hidden" id="editCategoryId" value="">
        <div class="edit-field"><label>清单名称</label><input type="text" id="categoryNameInput" placeholder="例如：项目、阅读..."></div>
        <div class="edit-field">
            <label>颜色</label>
            <div class="color-options">
                <span class="color-dot" style="background:#4A90D9" onclick="pickColor('#4A90D9',this)"></span>
                <span class="color-dot" style="background:#7ED321" onclick="pickColor('#7ED321',this)"></span>
                <span class="color-dot" style="background:#F5A623" onclick="pickColor('#F5A623',this)"></span>
                <span class="color-dot" style="background:#E74C3C" onclick="pickColor('#E74C3C',this)"></span>
                <span class="color-dot" style="background:#9B59B6" onclick="pickColor('#9B59B6',this)"></span>
                <span class="color-dot" style="background:#1ABC9C" onclick="pickColor('#1ABC9C',this)"></span>
                <input type="color" id="categoryColorInput" value="#4A90D9" style="width:28px;height:28px;border:none;cursor:pointer;padding:0" oninput="pickColor(this.value)">
            </div>
        </div>
        <div class="modal-actions"><button class="btn-cancel" onclick="closeCategoryDialog()">取消</button><button class="btn-save" onclick="saveCategory()">保存</button></div>
    </div>
</div>

<!-- 标签弹窗 -->
<div id="tagDialog" class="modal-overlay" onclick="if(event.target===this)closeTagDialog()">
    <div class="modal-box">
        <h3 id="tagDialogTitle">新建标签</h3><span class="modal-close" onclick="closeTagDialog()">✕</span>
        <input type="hidden" id="editTagId" value="">
        <div class="edit-field"><label>标签名称</label><input type="text" id="tagNameInput" placeholder="例如：提升、生活..."></div>
        <div class="edit-field"><label>颜色</label><input type="color" id="tagColorInput" value="#95A5A6" style="width:40px;height:32px;border:none;cursor:pointer;padding:0"></div>
        <div class="modal-actions"><button class="btn-cancel" onclick="closeTagDialog()">取消</button><button class="btn-save" onclick="saveTag()">保存</button></div>
    </div>
</div>

<!-- 详细任务创建弹窗 -->
<div id="taskCreateDialog" class="modal-overlay" onclick="if(event.target===this)closeTaskCreate()">
    <div class="modal-box modal-wide">
        <h3>📝 新建任务</h3><span class="modal-close" onclick="closeTaskCreate()">✕</span>
        <div class="edit-field"><label>标题 *</label><input type="text" id="createTaskTitle" placeholder="任务标题"></div>
        <div class="edit-field"><label>描述</label><textarea id="createTaskDescription" placeholder="任务的详细描述，支持换行..." rows="3"></textarea></div>
        <div class="edit-row">
            <div class="edit-field"><label>清单</label><select id="createTaskCategory"></select></div>
            <div class="edit-field"><label>优先级</label><select id="createTaskPriority"><option value="high">🔴 高</option><option value="medium" selected>🟡 中</option><option value="low">🟢 低</option></select></div>
        </div>
        <div class="edit-row">
            <div class="edit-field"><label>截止日期</label><input type="date" id="createTaskDate" value="<?php echo date('Y-m-d'); ?>"></div>
            <div class="edit-field"><label>截止时间</label><input type="time" id="createTaskTime" value="09:00"></div>
        </div>
        <div class="edit-row">
            <div class="edit-field"><label>提醒方式</label><select id="createTaskReminder" onchange="toggleCreateReminderCustom()"><option value="0">准时</option><option value="5">5分钟前</option><option value="15">15分钟前</option><option value="30">30分钟前</option><option value="60">1小时前</option><option value="1440">1天前</option><option value="-1">自定义</option></select></div>
            <div class="edit-field hidden" id="createReminderCustomField"><label>自定义提醒时间</label><input type="datetime-local" id="createReminderDatetime"></div>
        </div>
        <!-- 重复任务 -->
        <div class="edit-section">
            <div class="edit-section-header"><label>🔁 重复任务</label></div>
            <div class="edit-row">
                <div class="edit-field"><label>重复类型</label><select id="createRecurrenceType" onchange="toggleCreateRecurrence()">
                    <option value="">不重复</option>
                    <option value="daily">每天</option>
                    <option value="weekly">每周</option>
                    <option value="monthly">每月</option>
                    <option value="yearly">每年</option>
                </select></div>
                <div class="edit-field hidden" id="createRecurrenceEndField"><label>重复截止</label><input type="date" id="createRecurrenceEnd"></div>
            </div>
            <div class="edit-row hidden" id="createRecurrenceStartField">
                <div class="edit-field"><label>开始日期</label><input type="date" id="createRecurrenceStart"></div>
            </div>
            <div class="edit-row hidden" id="createWeeklyDays">
                <div class="edit-field"><label>每周几</label>
                    <div class="weekday-picker" id="createWeekdayPicker">
                        <label class="weekday-btn"><input type="checkbox" value="1"> 一</label>
                        <label class="weekday-btn"><input type="checkbox" value="2"> 二</label>
                        <label class="weekday-btn"><input type="checkbox" value="3"> 三</label>
                        <label class="weekday-btn"><input type="checkbox" value="4"> 四</label>
                        <label class="weekday-btn"><input type="checkbox" value="5"> 五</label>
                        <label class="weekday-btn"><input type="checkbox" value="6"> 六</label>
                        <label class="weekday-btn"><input type="checkbox" value="7"> 日</label>
                    </div>
                </div>
            </div>
            <div class="edit-row hidden" id="createMonthlyDay">
                <div class="edit-field"><label>每月几号</label><input type="number" id="createRecurrenceDay" min="1" max="31" value="1" style="width:80px"></div>
            </div>
            <div class="edit-row hidden" id="createYearlyDate">
                <div class="edit-field"><label>每年几月几日</label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input type="number" id="createRecurrenceMonth" min="1" max="12" value="1" style="width:60px" placeholder="月">
                        <span>月</span>
                        <input type="number" id="createRecurrenceDayY" min="1" max="31" value="1" style="width:60px" placeholder="日">
                        <span>日</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="edit-field"><label>标签</label><div class="tag-edit-row" id="createTaskTags"></div></div>
        <div class="edit-field"><label>备注</label><textarea id="createTaskNotes" placeholder="补充说明..." rows="2"></textarea></div>
        <div class="edit-field">
            <label>附件</label>
            <div class="attach-zone">
                <input type="file" id="createTaskFile" multiple style="display:none" onchange="handleCreateFileSelect()">
                <button class="btn-attach" onclick="document.getElementById('createTaskFile').click()">📎 选择文件 (PDF可在线预览)</button>
                <span class="attach-hint">支持图片、PDF、文档等，单文件最大 20MB</span>
                <div class="attach-list" id="createAttachList"></div>
            </div>
        </div>
        <div class="modal-actions"><button class="btn-cancel" onclick="closeTaskCreate()">取消</button><button class="btn-save" onclick="saveTaskCreate()">创建任务</button></div>
    </div>
</div>

<!-- 任务编辑弹窗 -->
<div id="taskEditDialog" class="modal-overlay" onclick="if(event.target===this)closeTaskEdit()">
    <div class="modal-box modal-wide">
        <h3>✏️ 编辑任务</h3><span class="modal-close" onclick="closeTaskEdit()">✕</span>
        <input type="hidden" id="editTaskId" value="">
        <div class="edit-field"><label>标题</label><input type="text" id="editTaskTitle"></div>
        <div class="edit-field"><label>描述</label><textarea id="editTaskDescription" placeholder="任务详细描述..." rows="3"></textarea></div>
        <div class="edit-row">
            <div class="edit-field"><label>清单</label><select id="editTaskCategory"></select></div>
            <div class="edit-field"><label>状态</label><select id="editTaskStatus"><option value="todo">📝 待办</option><option value="doing">🔄 处理中</option><option value="done">✅ 已完成</option></select></div>
        </div>
        <div class="edit-row"><div class="edit-field"><label>优先级</label><select id="editTaskPriority"><option value="high">🔴 高</option><option value="medium">🟡 中</option><option value="low">🟢 低</option></select></div></div>
        <div class="edit-row">
            <div class="edit-field"><label>截止日期</label><input type="date" id="editTaskDate"></div>
            <div class="edit-field"><label>截止时间</label><input type="time" id="editTaskTime"></div>
        </div>
        <div class="edit-row">
            <div class="edit-field"><label>提醒方式</label><select id="editTaskReminder" onchange="toggleEditReminderCustom()"><option value="0">准时</option><option value="5">5分钟前</option><option value="15">15分钟前</option><option value="30">30分钟前</option><option value="60">1小时前</option><option value="1440">1天前</option><option value="-1">自定义</option></select></div>
            <div class="edit-field hidden" id="editReminderCustomField"><label>自定义提醒时间</label><input type="datetime-local" id="editReminderDatetime"></div>
        </div>
        <!-- 重复任务 -->
        <div class="edit-section">
            <div class="edit-section-header"><label>🔁 重复任务</label></div>
            <div class="edit-row">
                <div class="edit-field"><label>重复类型</label><select id="editRecurrenceType" onchange="toggleEditRecurrence()">
                    <option value="">不重复</option>
                    <option value="daily">每天</option>
                    <option value="weekly">每周</option>
                    <option value="monthly">每月</option>
                    <option value="yearly">每年</option>
                </select></div>
                <div class="edit-field hidden" id="editRecurrenceEndField"><label>重复截止</label><input type="date" id="editRecurrenceEnd"></div>
            </div>
            <div class="edit-row hidden" id="editRecurrenceStartField">
                <div class="edit-field"><label>开始日期</label><input type="date" id="editRecurrenceStart"></div>
            </div>
            <div class="edit-row hidden" id="editWeeklyDays">
                <div class="edit-field"><label>每周几</label>
                    <div class="weekday-picker" id="editWeekdayPicker">
                        <label class="weekday-btn"><input type="checkbox" value="1"> 一</label>
                        <label class="weekday-btn"><input type="checkbox" value="2"> 二</label>
                        <label class="weekday-btn"><input type="checkbox" value="3"> 三</label>
                        <label class="weekday-btn"><input type="checkbox" value="4"> 四</label>
                        <label class="weekday-btn"><input type="checkbox" value="5"> 五</label>
                        <label class="weekday-btn"><input type="checkbox" value="6"> 六</label>
                        <label class="weekday-btn"><input type="checkbox" value="7"> 日</label>
                    </div>
                </div>
            </div>
            <div class="edit-row hidden" id="editMonthlyDay">
                <div class="edit-field"><label>每月几号</label><input type="number" id="editRecurrenceDay" min="1" max="31" value="1" style="width:80px"></div>
            </div>
            <div class="edit-row hidden" id="editYearlyDate">
                <div class="edit-field"><label>每年几月几日</label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input type="number" id="editRecurrenceMonth" min="1" max="12" value="1" style="width:60px" placeholder="月">
                        <span>月</span>
                        <input type="number" id="editRecurrenceDayY" min="1" max="31" value="1" style="width:60px" placeholder="日">
                        <span>日</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="edit-field"><label>标签</label><div class="tag-edit-row" id="editTaskTags"></div></div>
        <div class="edit-field"><label>备注</label><textarea id="editTaskNotes" placeholder="任务描述..."></textarea></div>
        <!-- 子任务 -->
        <div class="edit-section">
            <div class="edit-section-header"><label>📋 子任务</label><button class="btn-small" onclick="addSubtask()">＋ 添加子任务</button></div>
            <div class="subtask-list" id="editSubtaskList"></div>
        </div>
        <!-- 附件 -->
        <div class="edit-field">
            <label>📎 附件</label>
            <div class="attach-zone">
                <input type="file" id="editTaskFile" multiple style="display:none" onchange="handleEditFileSelect()">
                <button class="btn-attach" onclick="document.getElementById('editTaskFile').click()">选择文件</button>
                <div class="attach-list" id="editAttachList"></div>
            </div>
        </div>
        <div class="modal-actions"><button class="btn-cancel" onclick="closeTaskEdit()">取消</button><button class="btn-save" onclick="saveTaskEdit()">保存</button></div>
    </div>
</div>

<!-- 修改密码弹窗 -->
<div id="passwordDialog" class="modal-overlay" onclick="if(event.target===this)closePasswordDialog()">
    <div class="modal-box">
        <h3>🔑 修改密码</h3><span class="modal-close" onclick="closePasswordDialog()">✕</span>
        <div class="edit-field"><label>当前密码</label><input type="password" id="oldPassword" placeholder="当前密码"></div>
        <div class="edit-field"><label>新密码</label><input type="password" id="newPassword" placeholder="新密码（至少6位）"></div>
        <div class="edit-field"><label>确认新密码</label><input type="password" id="confirmPassword" placeholder="再次输入"></div>
        <div class="modal-actions"><button class="btn-cancel" onclick="closePasswordDialog()">取消</button><button class="btn-save" onclick="changePassword()">修改</button></div>
    </div>
</div>

<!-- 打卡习惯弹窗 -->
<div id="habitDialog" class="modal-overlay" onclick="if(event.target===this)closeHabitDialog()">
    <div class="modal-box">
        <h3 id="habitDialogTitle">新建打卡习惯</h3><span class="modal-close" onclick="closeHabitDialog()">✕</span>
        <input type="hidden" id="editHabitId" value="">
        <div class="edit-field"><label>习惯名称</label><input type="text" id="habitNameInput" placeholder="例如：晨跑、阅读..."></div>
        <div class="edit-row">
            <div class="edit-field"><label>图标</label>
                <div class="icon-options" id="habitIconOptions">
                    <span class="icon-option selected" data-icon="📌" onclick="pickHabitIcon('📌',this)">📌</span>
                    <span class="icon-option" data-icon="🏃" onclick="pickHabitIcon('🏃',this)">🏃</span>
                    <span class="icon-option" data-icon="📖" onclick="pickHabitIcon('📖',this)">📖</span>
                    <span class="icon-option" data-icon="💪" onclick="pickHabitIcon('💪',this)">💪</span>
                    <span class="icon-option" data-icon="🧘" onclick="pickHabitIcon('🧘',this)">🧘</span>
                    <span class="icon-option" data-icon="💧" onclick="pickHabitIcon('💧',this)">💧</span>
                    <span class="icon-option" data-icon="🍎" onclick="pickHabitIcon('🍎',this)">🍎</span>
                    <span class="icon-option" data-icon="✍️" onclick="pickHabitIcon('✍️',this)">✍️</span>
                    <span class="icon-option" data-icon="🎵" onclick="pickHabitIcon('🎵',this)">🎵</span>
                    <span class="icon-option" data-icon="🌱" onclick="pickHabitIcon('🌱',this)">🌱</span>
                    <span class="icon-option" data-icon="💤" onclick="pickHabitIcon('💤',this)">💤</span>
                    <span class="icon-option" data-icon="🎯" onclick="pickHabitIcon('🎯',this)">🎯</span>
                </div>
            </div>
        </div>
        <div class="edit-field">
            <label>颜色</label>
            <input type="color" id="habitColorInput" value="#4A90D9" style="width:50px;height:34px;border:none;padding:0;cursor:pointer">
        </div>
        <div class="edit-field">
            <label>打卡日</label>
            <div class="weekday-select" id="habitWeekdays">
                <span class="wday-chip selected" data-day="1" onclick="toggleHabitDay(this)">一</span>
                <span class="wday-chip selected" data-day="2" onclick="toggleHabitDay(this)">二</span>
                <span class="wday-chip selected" data-day="3" onclick="toggleHabitDay(this)">三</span>
                <span class="wday-chip selected" data-day="4" onclick="toggleHabitDay(this)">四</span>
                <span class="wday-chip selected" data-day="5" onclick="toggleHabitDay(this)">五</span>
                <span class="wday-chip selected" data-day="6" onclick="toggleHabitDay(this)">六</span>
                <span class="wday-chip selected" data-day="7" onclick="toggleHabitDay(this)">日</span>
            </div>
        </div>
        <div class="modal-actions"><button class="btn-cancel" onclick="closeHabitDialog()">取消</button><button class="btn-save" onclick="saveHabit()">保存</button></div>
    </div>
</div>

<!-- 提醒设置弹窗 -->
<div id="settingsDialog" class="modal-overlay" onclick="if(event.target===this)closeSettings()">
    <div class="modal-box">
        <h3>⚙️ 提醒设置</h3><span class="modal-close" onclick="closeSettings()">✕</span>
        <div class="settings-section">
            <h4>💻 本地提醒</h4>
            <div class="settings-row"><label>🔔 声音提醒</label><span class="toggle-switch on" id="toggleSound" onclick="toggleSetting('sound')"></span></div>
            <div class="settings-row"><label>📑 标签闪烁</label><span class="toggle-switch on" id="toggleTab" onclick="toggleSetting('tab')"></span></div>
        </div>
        <div class="settings-section">
            <h4>📧 邮件提醒</h4>
            <div class="settings-row"><label>📨 开启邮件提醒</label><span class="toggle-switch" id="toggleEmail" onclick="toggleSetting('email')"></span></div>
            <label style="font-size:13px;margin-top:8px">SMTP 服务器</label><input type="text" class="settings-input" id="smtpHost" placeholder="smtp.qq.com">
            <div style="display:flex;gap:8px">
                <div style="flex:1"><label style="font-size:13px;margin-top:6px">端口</label><input type="number" class="settings-input" id="smtpPort" value="587"></div>
                <div style="flex:1"><label style="font-size:13px;margin-top:6px">加密</label><select class="settings-input" id="smtpEncryption"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">无</option></select></div>
            </div>
            <label style="font-size:13px;margin-top:6px">账号</label><input type="text" class="settings-input" id="smtpUsername" placeholder="your@email.com">
            <label style="font-size:13px;margin-top:6px">密码/授权码</label><input type="password" class="settings-input" id="smtpPassword" placeholder="SMTP 授权码">
            <label style="font-size:13px;margin-top:6px">收件地址</label><input type="email" class="settings-input" id="emailRecipient">
            <button class="btn-test-email" onclick="sendTestEmail()">📨 测试邮件</button>
            <span id="testEmailResult" style="font-size:12px;margin-left:8px"></span>
        </div>
        <div class="modal-actions"><button class="btn-cancel" onclick="closeSettings()">关闭</button><button class="btn-save" onclick="saveSettings()">保存</button></div>
    </div>
</div>

<script src="js/app.js"></script>
</body>
</html>
