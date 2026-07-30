# 更新日志

## v3.2.0 (2026-07-30)
### 修复 — 循环任务完成 = 终结整体系列（核心逻辑重构）
- 🐛 **根因**：`toggle_task` 对循环任务使用 "复制模型"——标记原任务 `is_completed=1` → `INSERT` 新行作为下一期。这导致原任务"死亡"不再被虚拟展开，新实例在边缘场景下（`seenKeys` 去重、视图日期范围不匹配等）不出现在列表中。
- 🔄 **重构为 "推进模型"**：循环任务完成时 **不再标记 `is_completed=1`**，而是直接 **`UPDATE due_datetime=next + recurrence_start=next`**。任务永远保持 `is_completed=0`，始终被主查询和虚拟展开正确发现。
  - `due_datetime`：推进到下一期（周一完成 → 下周一 / 下周四）
  - `recurrence_start`：同步推进，防止虚拟展开从历史锚点重复生成已完成的次
  - `completion_count`：递增作为完成次数计数
  - `reminder_custom`：重置为 NULL，下次提醒按正常规则触发
  - 仅当超过 `recurrence_end` 或无更多次时才正常标记 `is_completed=1`
- 🎨 **前端适配**：API 返回 `data.due_datetime` 时识别为循环推进，仅刷新列表不做消失动画
- 🗑️ **移除代码**：删除 `toggle_task` 中约 40 行 `INSERT` 复制逻辑，大幅简化

## v3.1.8 (2026-07-27)
### 新增 — 登录页版本号 + 自定义确认弹窗
- 🔖 **登录页版本号**：`.auth-card` 底部新增 `v3.1.8` 版本展示，登录页面也能一目了然当前版本
- 🎨 **自定义确认弹窗**：所有操作（退出登录、删除任务/清单/标签/附件/习惯、清空垃圾桶）的确认对话框从系统原生 `confirm()` 改为统一风格的自定义 `showConfirm()` 弹窗
  - HTML 结构使用项目已有的 `.modal-overlay` + `.modal-box` 体系，按钮带危险色（红色）`btn-danger`
  - JS 基于 Promise，`await showConfirm(标题, 描述)` 一行即可，代码清晰
  - 共替换 8 处 `confirm()` 调用

## v3.1.7 (2026-07-27)
### 修复 — 延时提醒（+30/15/10/5分）报"服务器内部错误"
- 🐛 **NOT NULL 约束冲突**：`reminder_offset` 列定义为 `INTEGER NOT NULL DEFAULT 0`，但 `snooze_reminder` 和 `dismiss_reminder` 仍用 v3.1.1 遗留的 `SET reminder_offset = NULL`。SQLite 抛出约束冲突 → PDOException → "服务器内部错误"
- 🔧 **due 路径守卫改用 `reminder_custom IS NULL`**：原守卫 `reminder_offset IS NOT NULL` 仅对 NULL 生效。snooze 后 `reminder_custom` 已有值，改为 `reminder_custom IS NULL` 守卫能正确拦截 snooze 后的 due 路径重复触发，且无需设 `reminder_offset = NULL`
  - `today_reminders`（弹窗提醒）+ 邮件通知两处查询均同步更新
- 🩹 **`jsonResponse([], false, ...)`→ `jsonResponse(null, 400, ...)`**：snooze/dismiss 分支的 `jsonResponse` 第二参数传入 `false` 当作 HTTP 状态码，PHP 8.2 下 `false→0` 触发隐式转换 Deprecation Notice，可能污染 JSON 输出

## v3.1.6 (2026-07-25)
### 修复 — 备份文件全部 0 字节的严重 Bug
- 🐛 **备份 0 字节根因**：三处备份代码（`backup.php` `performBackup()`、`config.php` `autoBackupDaily()`、`admin.php` `create_backup`）均使用 `SQLite3::backup()` 但从未检查返回值。`backup()` 失败返回 `false` 时，目标文件保持 `new SQLite3()` 创建的空壳（0 字节），代码仍当成功继续执行
  - 群晖 PHP 8.2 上 `pdo_sqlite` 和 `sqlite3` 两个扩展可能链接不同版本 SQLite 库，导致 `backup()` 跨实例 API 静默失败
- 🔧 **三层修复**：备份策略从 `SQLite3::backup()` 改为 **PHP 原生 `copy()` + WAL checkpoint 前置**
  1. 先通过 PDO 执行 `PRAGMA wal_checkpoint(TRUNCATE)` 将 WAL 日志写入主文件
  2. 用 `copy()` 直接复制数据库文件（简单可靠，零扩展依赖）
  3. 验证 `filesize() > 0`，失败则删除空文件并报错
  4. `copy()` 失败时自动回退到 `SQLite3::backup()`（检查返回值）
- 🔍 **自动备份增强**：检查今天备份时自动过滤/删除 0 字节无效文件，避免假备份阻塞后续尝试
- 🛡️ **异常捕获加固**：`autoBackupDaily()` 的 catch 从 `Exception` 改为 `Throwable`（覆盖 `Error` 如 SQLite3 类不存在）
- 📋 **备份列表优化**：`list_backups` 自动清除并跳过历史上遗留的 0 字节备份

## v3.1.5 (2026-07-25)
### 修复 — 群晖 WebStation PHP 8.2 白屏问题
- 🚨 **白屏诊断**：config.php 启用 `error_reporting(E_ALL)` + `display_errors` 后，致命错误会直接显示出来，不再"白屏"
- 🔍 **全局 Try-Catch**：`getDB()` + `initDatabase()` 调用包裹在 `catch(Throwable)` 中，即使 PDO/SQLite 初始化失败也会输出友好错误页面 + 调用栈
- 🕐 **默认时区**：`date_default_timezone_set('Asia/Shanghai')` 从 api.php 移到 config.php 顶部，确保 session cookie 等 date() 调用不产生时区警告

## v3.1.4 (2026-07-24)
### 修复 — snooze 到时间不提醒的根因
- 🐛 **Snooze 到期后不再弹窗**：`today_reminders` 查询 Custom 路径用了 `reminder_custom >= :min30`（30 分钟窗口）。Snooze 将 `reminder_custom` 设为"当时 Now + N 分钟"后，该值在 N+30 分钟后就会被刷掉。若前端定时器因浏览器节流稍有延迟，API 已经查不到该任务了。改为 `>= :min24h`（24 小时窗口），覆盖一天内的延时提醒
  - Due 路径保持 `-30 min` 不变（避免极旧逾期任务大量刷出）
  - Custom 路径用 `-24 hours`（用户主动设置的提醒理应保留更久）
- 🔒 **`snooze_reminder` API 加固**：UPDATE 增加 `user_id` 条件 + `rowCount()` 检测，更新失败时前端能感知
- 📡 **前端响应检查**：`snoozeReminder` / `dismissFromReminder` 现在解析 API 返回的 JSON 并检查 `success` 字段。后端失败时清除前端封锁定时器，避免"假成功"阻塞真实提醒
- ⏱️ **前端封锁缓冲**：延时封锁定时器缓冲从 3s 增加到 30s，覆盖 30 秒轮询间隔的最大延迟

## v3.1.3 (2026-07-22)
### 修复 — 前端兜底封锁，彻底杜绝弹窗重复
- 🛡️ **前端三态封锁**：`shownReminderIds` 值类型化为三态 —— `true`（展示中）、定时器ID（延时封锁）、`'dismissed'`（永久封锁）。不管后端 API 是否成功执行，前端自己保证不重复弹窗
  - 延时：`setTimeout` 封锁 N 分钟+3s 缓冲，期间 `dismissReminderPopup`（含 60s 自动关闭）不动封锁状态
  - 不再提醒：封锁值设为 `'dismissed'`，该页面生命周期内永不弹窗
  - 重复点击延时：自动清除旧定时器，以最新时长为准
- 🔧 **phpStudy Nginx 用户注意**：如果后端 `snooze_reminder` 仍旧不生效，可能是 PHP-FPM opcache 缓存了旧 `api.php`。phpStudy 中需单独重启 PHP（而非仅重启 Nginx）

## v3.1.2 (2026-07-22)
### 修复 — 弹窗仍然重复弹出 & 关闭按钮位置优化
- 🐛 **NULL 在 SQLite datetime() 中不生效**：上一版把 `reminder_offset` 设为 `NULL` 想阻断 due 路径，但 SQLite 的 `datetime(due_datetime, NULL)` 直接返回原值而非 NULL，逾期任务照旧命中。改为在 due 路径加显式 `t.reminder_offset IS NOT NULL` 守卫，真正阻断
- 🎨 **关闭按钮移至右上角**：✕ 从底部操作栏移到弹窗右上角，与其他弹窗设计语言统一

## v3.1.1 (2026-07-22)
### 修复 — 提醒弹窗重复弹出 & "完成"语义优化
- 🐛 **延时后弹窗立即重新弹出**：`snooze_reminder` 将 `reminder_offset` 设为 `0`，导致 SQLite 中 `datetime(due_datetime, '-0 minutes')` = 原值，逾期任务的 due 路径再次命中。改为 `NULL`，使表达式结果为 NULL（WHERE 自动判假）
- 🔕 **"完成"→"不再提醒"**：原按钮调用 `toggle_task` 将任务标记为已完成，容易和常规"完成任务"混淆。改为 `dismiss_reminder` 接口，仅清除 `reminder_custom` + `reminder_offset`，任务本身不受影响
- 🔄 **新增 `dismiss_reminder` API**：清除指定任务的所有提醒设置

## v3.1.0 (2026-07-22)
### 新增 — 懒人提醒模式
- 🔔 **智能轮询提醒**：每 30 秒自动检查提醒任务，不再仅靠页面加载触发
- 💬 **右下角弹窗提醒**：提醒任务以卡片形式从右下角滑入，显示任务名、优先级、截止时间、逾期时长
- ⏰ **懒人延时**：未完成任务可一键延时 +5 / +10 / +15 / +30 分钟再次提醒
- ✅ **弹窗中直接完成**：无需跳转，弹窗内即可标记完成任务
- 🛠 **`today_reminders` 时区修复**：遗漏的 SQLite `datetime('now','localtime')` 改用 PHP `date()`，Windows Server 兼容
- 🔄 **`snooze_reminder` API**：新增延时提醒后端接口

## v3.0.2 (2026-07-21)
### 修复 — 后台样式统一 & 布局重构
- 🐛 **修复 Tab 文字不可见**：Body 缺少 `data-theme` 属性，CSS 变量全部未定义；激活态白字 + 渐变回退透明 = 不可见
- 🏠 **页眉统一**：后台改用前端 `.header` 组件（sticky 定位、毛玻璃背景）
- 👣 **页脚固定**：提取 `_foot.php` 共用；admin body 改为 flex 全屏布局（`height:100vh;flex-direction:column`），内容区 `flex:1;overflow:auto` 负责滚动，页眉页脚始终固定不动
- 🔲 **内容区边框**：`admin-wrap` 毛玻璃边框 + 圆角
- 🎨 **登录页优化**：覆盖层 `position:fixed`，全屏居中
- 🕐 **修复 UTC 时区导致相对日期显示错误（彻底版）**：
  - **JS 端**：`toLocaleDateString('sv-SE')` 在部分 Windows 浏览器中 locale 不可用时会回退到系统格式（如 `7/21/2026`），日期比较失败。改用自主 `localDateStr()` 函数（`getFullYear`+`getMonth`+`getDate`），零 locale 依赖
  - **PHP 端**：SQLite `date('now','localtime')` 走 OS 时区而非 PHP 时区。Windows Server 系统时区常为 UTC，导致 `today`/`upcoming`/邮件提醒的任务筛选日期错误。4 处全部改为 PHP `date('Y-m-d')` 计算后传参
- 🧹 **`localDateStr()` 兼容性**：`padStart` 在部分旧浏览器中不支持，改用三目运算补零
- 🔄 **缓存版本号**：CSS/JS 引用添加 `?v=版本号`，更新后无需手动 Ctrl+F5 即可自动加载新文件

## v3.0.0 (2026-07-20)

## v3.0.1 (2026-07-20)
### 优化 — 后台页面美化
- 🪟 **全局毛玻璃效果**：header / 卡片 / 表格 / 弹窗 / Toast 全部使用 `backdrop-filter: blur()` 半透明玻璃态
- 🎨 **渐变装饰**：body 浮动彩色光球背景动画，登录页双光晕呼吸效果
- 📊 **统计卡片重构**：顶部彩色渐变线 + 图标圆角方块 + hover 上浮阴影
- 📑 **Tab 导航**：从下划线改为胶囊按钮组，激活态渐变填充
- 🏷️ **Badge 渐变**：角色/状态标签改为渐变底色 + 细边框
- 🔲 **表格包装器**：`admin-table-wrapper` 统一边框圆角容器
- 📦 **分区卡片**：`section-card` / `setting-card` 毛玻璃容器，内容分组清晰
- ✨ **动画**：面板切换 fadeSlide、弹窗 scale+blur 入场、Toast 滑入
### 新增 — 后台管理系统
- 🛡️ **管理员系统**：用户表增加 `role` 字段（admin/user），首个注册用户自动获得管理员权限
- 📊 **仪表盘**：用户/任务/数据库/备份统计数据 + 最近 10 条登录记录
- 👥 **用户管理**：用户列表、登录历史记录、管理员角色切换（提权/降权）、删除用户
- 🔑 **密码重置**：管理员可生成带时效的重置链接（10分钟/30分钟/3小时/8小时），复制或发送给用户自行重置密码
- 💾 **备份管理**：查看/创建/下载/删除备份，可配置自动清理天数 & 最大保留份数，一键清理过期备份
- 🖥 **系统信息**：PHP版本、服务器环境、数据库/磁盘空间、内存限制等
- 📝 **登录历史**：每次登录自动记录时间 + IP，可在管理后台按用户查看
### 新增文件
- `admin.php` — 后台管理面板（完整登录 → 鉴权 → 面板流程）
- `reset_password.php` — 面向用户的密码重置页面（令牌校验 + 改密表单）
### 修改
- `config.php` — v9 数据库迁移（role/登录历史/重置令牌/管理设置表）+ admin 辅助函数
- `api.php` — 登录时自动记录登录历史；`check_auth` 返回用户角色
- `index.php` — 管理员用户在顶栏显示后台入口链接（🔐）
- `js/app.js` — `checkAuth()` 根据角色显示/隐藏后台链接
- `backup.php` / `config.php::autoBackupDaily` — 每日自动备份策略融合后台管理设置

## v2.4.3 (2026-07-20)
### 修复
- 修复刷新页面时登录界面一闪而过的问题：`#authPage` 默认也设为 `hidden`，等 `checkAuth()` 返回后才决定显示登录页还是主界面
### 新增主题
- 🏜️ 暖沙（sand）：暖黄纸感配色，仿纸质书阅读体验，低蓝光暖色调护眼
- 💜 薰衣草（lavender）：柔紫色调，舒缓视觉，适合晚间光线较弱场景
- 主题总数增至 12 套，4 行 × 3 列网格完整铺满 (CHANGELOG)

本文件记录任务管理系统所有重要变更。

---

## [2.4.1] - 2026-07-20

### 新增

- **毛玻璃主题 `frost`**：冰透朦胧配色，大面积 `backdrop-filter: blur()` 实现毛玻璃效果
  - 顶栏、侧栏、卡片、弹窗、任务项、按钮、输入框全部半透明模糊
  - 紫蓝色主色调，`card-bg: rgba(255,255,255,.7)` 半透明白底
  - 适合追求视觉层次感和现代 UI 风格的用户

### 修复

- **主题按钮点击无响应**：`showThemePicker` / `closeThemePicker` 错误使用 `hidden` 类来控制弹窗显隐
  - 项目弹窗系统用 `.modal-overlay.show`（CSS `display:flex`）而非 `.hidden`（`display:none!important`）
  - 修复为 `classList.add('show')` / `classList.remove('show')`

### 改动文件

- `css/style.css`：新增 `frost` 主题变量 + 毛玻璃特殊效果样式
- `js/app.js`：THEMES 数组新增 frost；showThemePicker/closeThemePicker 改用 `.show` 类
- `api.php`：主题白名单新增 `frost`

---

## [2.4.0] - 2026-07-20

### 新增

- **主题选择弹窗**：顶栏主题圆点替换为「🎨」按钮，点击弹出九宫格配色选择面板
  - 每个主题方案以卡片形式展示，包含 3 色预览圆点 + 名称 + 描述
  - 当前激活主题有蓝色边框高亮 + 发光阴影
  - 点击卡片即时切换，关闭弹窗

- **三套护眼新主题**：

| 主题 | 代号 | 特点 |
|------|------|------|
| 岩石灰 | `stone` | 低亮中性灰色调，适合全天候办公护眼 |
| 摩卡棕 | `coffee` | 暖棕色调，纸质书般柔和，仿真纸阅读体验 |
| 深夜暗 | `midnight` | 极暗底色 (#0D1117)，比暗夜更深沉，适合深夜使用 |

- **登录页毛玻璃风格**：
  - 背景改为紫蓝粉渐变 + 浮动光斑装饰动画
  - 登录卡片使用 `backdrop-filter: blur(24px)` 毛玻璃效果
  - 半透明白色背景 + 白色边框，输入框透明底
  - 按钮改为渐变紫色，带发光阴影
  - 新增 📋 logo 图标

### 修复

- **Windows Server 自动登出问题**：
  - **根因**：`session_start()` 先发送了一个无过期时间的 session cookie（浏览器会话级），然后 `setcookie` 尝试用长期 cookie 覆盖。IIS 上 cookie header 顺序可能让短期 cookie 优先生效，导致 IIS Application Pool 回收或浏览器空闲后 session 丢失。
  - **修复**：
    - 在 `session_start()` 之前设置 `session.cookie_lifetime = 2592000`（30 天），让 PHP 本身发出的 session cookie 就是长期的
    - 设置 `session.cookie_path = /`、`session.cookie_httponly = 1`、`session.cookie_samesite = Lax`
    - 调整 `session.gc_probability = 1` / `session.gc_divisor = 100`，降低 IIS 上 GC 触发频率
    - `setcookie` 改用 PHP 7.3+ 数组参数语法，确保各平台 cookie 属性一致

### 改动文件

- `config.php`：Session 生命周期设置重写，cookie_lifetime 移到 `session_start()` 之前
- `index.php`：登录页新增毛玻璃背景装饰层 + auth-input class；主题圆点替换为 🎨 按钮 + 主题选择弹窗 HTML
- `css/style.css`：认证页全改毛玻璃风格；新增 stone/coffee/midnight 三套主题；主题选择弹窗样式；移除旧 theme-dot 代码
- `js/app.js`：主题系统重构为九宫格弹窗模式，THEMES 数组 + buildThemeGrid / showThemePicker / closeThemePicker
- `api.php`：主题白名单新增 stone/coffee/midnight

---

## [2.3.5] - 2026-07-20

### 修复

- **虚拟重复实例点击无响应 / 编辑报 400**：`expandRecurringTasks` 函数中 `$vt['id'] = null` 导致虚拟实例的 task ID 丢失
  - 前端 `toggleTask(null, 1)` 和 `editTask(null)` 传入空 ID → 后端 400 → 复选框无响应、编辑弹窗报错
  - 移除 `$vt['id'] = null`，虚拟实例保留真实 task ID，操作正确路由到对应真实任务

### 改动文件

- `api.php`：`expandRecurringTasks` 函数中删除 `$vt['id'] = null` 行

---

## [2.3.4] - 2026-07-20

### 修复

- **虚拟重复实例的循环图标覆盖复选框**：之前虚拟重复实例的 checkbox 被直接替换为 `🔁` 图标，无法点击完成
  - 改为保留普通复选框，可点击标记预排实例完成
  - `🔁 X次` 重复标志移到标题行，不再遮挡复选框
  - 移除已废弃的 `.task-checkbox.virtual` 样式

### 改动文件

- `js/app.js`：虚拟任务 checkbox 改为普通可点击复选框，🔁 图标放入 task-title
- `css/style.css`：移除 `.task-checkbox.virtual` 样式

---

## [2.3.3] - 2026-07-20

### 修复

- **循环标志溢出覆盖操作按钮**：标题行 `task-content` 缺少 `overflow:hidden` + `task-actions` 缺少 `flex-shrink:0`
  - 当标题较长时，行内 `🔁 X次` 徽章会溢出 `task-content` 区域遮盖右侧「编辑/已完成」按钮
  - 修复后内容区域自动裁剪，操作按钮始终保留完整宽度

- **侧栏「今天」「最近7天」循环任务计数为 0**：`summary` 端点只统计 real tasks 的 `due_datetime`
  - 循环任务完成推进后 `due_datetime` 移到未来，虚拟实例虽显示在列表中但不被计入 badge
  - 修复：`summary` 展开循环任务虚拟实例，将原任务 `due_datetime` 已超范围的虚拟实例补入计数
  - 自动去重：原任务 `due_datetime` 本身在范围内的不会被重复计数

### 改动文件

- `css/style.css`：`.task-content` 加 `overflow:hidden`、`.task-actions` 加 `flex-shrink:0`
- `api.php`：`summary` 端点补充循环任务虚拟实例计数逻辑

---

## [2.3.2] - 2026-07-20

### 修复

- **重复任务图标覆盖复选框**：将「🔁 X次」重复标志从 `.task-meta` 移至 `.task-title` 标题旁
  - 原来放在 meta 信息区，由于 `flex-wrap` 换行时可能靠近左侧 checkbox 区域，造成视觉上覆盖已完成复选框
  - 现作为标题行内小徽章展示，不会与 checkbox 产生布局冲突
  - 已完成任务中 `task-recur-icon` 取消 `line-through` 贯穿线，保持可读

### 改动文件

- `js/app.js`：`recurIcon` 从 task-meta 移至 task-title
- `css/style.css`：`task-recur-icon` 增加 `vertical-align:middle` + 已完成状态去下划线

---

## [2.3.1] - 2026-07-20

### 修复

- **Session 持久化 Cookie 被覆盖**：修复「保持登录」勾选后，后续请求中 `session_start()` 会发送无过期时间 Cookie 覆盖登录时设置的 30 天有效期 Cookie，导致一段时间后 Session 丢失跳回登录界面
  - 新增 `$_SESSION['persist_login']` 标记，仅在勾选「保持登录」的用户每次请求时刷新 Cookie 有效期
  - 服务器端 `gc_maxlifetime` 保持 30 天，防止 session 文件被过早清理

- **周/日循环重复任务首次出现偏移**：修复 `expandRecurringTasks` 以 `due_datetime`（当天创建的日期）作为展开锚点，当锚点日期恰好匹配重复规则中的目标日时（如周一创建每周一任务），`computeNextOccurrence` 会跳到下一周期
  - 改用 `recurrence_start`（开始日期）作为展开锚点，保证第一个命中日不会因锚点本身落在匹配日而被跳过
  - 此修复同时影响周循环（days-of-week）、月循环、年循环的首次命中计算

### 优化

- **新建/编辑任务表单字段顺序优化**：将「开始日期」移至「截止日期」之前，符合正常操作逻辑（先设起始再设截止）
- 「开始日期」字段提升为主表单字段（始终可见），不再隐藏在重复任务折叠区内
- 简化重复任务区域：移除冗余的「开始日期」独立行（开始日期已从主表单直接读取）

---

## [2.3.0] - 2026-07-20

### 新增

- **「保持登录」功能**：登录页新增「保持登录」复选框
  - 勾选后 Session 持久化 30 天，关闭浏览器/重启电脑后无需重新登录
  - 未勾选则保持原有行为：浏览器关闭后需重新登录
  - 后端 `session.gc_maxlifetime` 提升至 30 天，解决服务器端过早清理 Session 导致"刷新跳回登录"的问题
  - 登录时自动 `session_regenerate_id()` 重置 Session ID，防止会话固定攻击

### 改动文件

- `config.php`：Session 生命周期配置（gc_maxlifetime → 30 天）
- `api.php`：login 接口新增 `remember_me` 参数，持久化 Cookie + Session ID 再生
- `index.php`：登录表单新增「保持登录」复选框
- `js/app.js`：登录请求携带 `remember_me` 参数，切到注册模式时隐藏复选框
- `css/style.css`：新增复选框样式

---

## [2.2.9] - 2026-07-20

### 修复

- **重复任务首次出现计算错误**：修复 `computeNextOccurrence` 月/年循环无条件跳到下一周期的问题
  - 月循环：若目标日仍在当前月份，不再错误跳转至下个月（例：起始日期 7月15日、目标 27日 → 正确返回 7月27日而非 8月27日）
  - 年循环：同理修复，若目标日期仍在当年则留在当年
  - 同步修复 `computePrevOccurrence` 对应的逆向逻辑缺陷

### 影响范围

- 涉及重复任务的创建、完成自动推进、日历虚拟展开、倒计时计算等功能

---

## [2.2.8] - 2026-07-18

### 新增

- **数据库自动备份系统**：
  - 新增 `backup.php` 独立备份脚本，支持 CLI 直接执行和 HTTP 令牌触发
  - `config.php` 新增 `autoBackupDaily()` 惰性备份函数，每次 API 请求自动检查当日是否已备份，未备份则自动执行
  - 使用 SQLite 原生 `backup()` API 保证备份数据一致性（WAL 模式先 checkpoint）
  - 自动清理旧备份，默认最多保留 15 份（可在 `config.php` 中调整 `backup_max`）
  - 备份文件命名格式：`todolist_backup_YYYY-MM-DD_HHmmss.db`
- `config.php` 新增 `backup_path`、`backup_max` 配置项

---

## [2.2.7] - 2026-07-18

### 安全

- **数据库文件防直接下载**：新增 `.htaccess`（Apache）和 `data/.htaccess`，阻止 `data/` 目录及 `.db`/`.log`/配置文件被 HTTP 直接访问
- **SMTP 密码加密存储**：SMTP 密码不再明文存储于数据库，改用 AES-256-CBC 加密（`encryptSensitive` / `decryptSensitive`），兼容旧版明文数据自动迁移
- **安全响应头**：`.htaccess` 加入 `X-Content-Type-Options`、`X-Frame-Options`、`X-XSS-Protection`

### 新增

- `config.php` 新增 `encrypt_key` 配置项，部署后务必自定义随机密钥
- `config.php` 新增 `encryptSensitive()` / `decryptSensitive()` 加解密函数

---

## [2.2.6] - 2026-07-18

### 修复

- **月历视图 `recurrence_start` 约束失效**：`calendar_tasks` 接口的 SQL 查询缺少 `recurrence_start` 和 `created_at` 字段，导致 `expandRecurringTasks()` 中这两个字段永远为 NULL，开始日期约束完全不生效。现已补全字段，重复任务在月历中不会再无限向前展开。

---

## [2.2.5] - 2026-07-18

### 新增

- **重复任务开始日期（`recurrence_start`）**：循环重复任务现在支持设置开始日期，限制向前展开的边界。未设置时默认使用任务创建时间（`created_at`）。解决了之前切换月历到过去的月份时，重复任务会无限向前展开显示所有历史实例的问题。

### 修改

- `tasks` 表新增 `recurrence_start` 字段（DATETIME），v8.0 数据库自动迁移
- `expandRecurringTasks()` 逆向回退时检查 `recurrence_start`，不再展开早于开始日期的实例
- 创建/编辑任务弹窗中重复设置区新增「开始日期」输入框
- `toggle_task` 完成重复任务时同步复制 `recurrence_start` 到下一期实例
- `check.php` 诊断第 9 节显示 `recurrence_start` 字段信息

---

## [2.2.4] - 2026-07-18

### 修复

- **日视图不显示重复任务**：v2.2.3 的逆向回退锚点逻辑存在"差一步"问题。回退到刚好在范围之前时 `break` 但没有把 `$current` 移到该位置，导致 `computeNextOccurrence()` 多跳了一步，跳过了范围内的唯一一次发生。单天日视图（范围宽度仅 1 天）最容易触发此问题。
- 同时修复 v2.2.3 引入的月视图"少第一天"问题：7 月第一个周四（7/2）也会因为同样的锚点偏移被遗漏。

---

## [2.2.4] - 2026-07-18

### 修复

- **重复任务在月历/周历中"超前到期不显示"**：`expandRecurringTasks()` 之前只从 `due_datetime` 正向推算，如果 `due_datetime` 远在视图范围之后（如 12 月 31 日的任务看 7 月日历），所有虚拟实例都在视图之外，月历/周历中完全没有。

  修复方案：
  - 新增 `computePrevOccurrence()` 函数，作为 `computeNextOccurrence()` 的逆运算，支持 daily/weekly/monthly/yearly 四种重复类型
  - `expandRecurringTasks()` 增加逆向回退：当 `due_datetime` 超出视图范围时，先逆向回退到范围附近的锚点，再从锚点正向展开
  - `check.php` 诊断联动更新：逐项诊断同时展示逆向回退和正向推算结果

### 改进

- `check.php` 数据库路径修正：`todos.db` → `todolist.db`（与 `config.php` 一致）

---

## [2.2.2] - 2026-07-18

### 修复

- **`expandRecurringTasks()` 静默失败风险**：函数中使用 `@new DateTime()` 抑制错误，若日期格式异常，`$current` 会变成 `false`，导致后续 `$current->format()` 触发致命错误，使整个 `calendar_tasks` 接口返回 500。现改为 `try-catch` 显式捕获异常并跳过异常任务。
- **`check.php` 新增「重复任务诊断」**：第 9 节列出数据库中所有循环任务详情，并模拟当前月的虚拟展开，逐项显示每一期的计算结果以及是否落在视图范围内。方便快速定位"循环任务在日历中不显示"的根因（数据丢失 / 全部已完成 / 日期范围偏差 / 计算逻辑 bug）。

### 改进

- `expandRecurringTasks()` 中 `@new DateTime()` 全部替换为 `try-catch`，避免静默错误
- `check.php` 不再 require api.php（避免路由逻辑冲突），诊断逻辑完全内联

---

## [2.2.1] - 2026-07-18

### 修复

- **重复任务在日历/周历/列表视图中不可见**：上一版采用"惰性生成"模式，只有当前实例存入数据库，后续实例在完成时才生成，导致月历/周历/日历/列表中只能看到一期。现已改为"虚拟展开"模式 —— 查询时对重复任务自动向后推算所有未来出现日期，在视图中以半透明虚线样式展示，无需等待逐期完成。
  - 新增 `expandRecurringTasks()` 函数，支持按日期范围批量展开重复任务
  - `calendar_tasks` 月历视图：自动展开当月全部重复实例
  - `list_tasks` 日期筛选视图（今天/明天/7天内/未来/日历单天）：自动注入虚拟实例
  - 前端虚拟实例以半透明虚线样式展示，带"预排""⏳ 待推进"标识
  - 虚拟实例不可直接操作（完成/编辑/删除），需通过完成当前真实实例自然推进

---

## [2.2.0] - 2026-07-18

### 新增

- **重复任务（周期性任务）**：支持设置任务按日/周/月/年自动重复
  - 每日：每 N 天重复一次
  - 每周：选择一周中的指定日期（如每周一、三、五）
  - 每月：每月固定日期（如每月 5 号）
  - 每年：每年固定月日（如每年 3 月 15 日）
  - 可选设置重复截止日期，到达截止日自动停止生成新实例
  - 完成任务时自动计算并生成下一期实例，同时保留已完成记录
  - 任务行显示 🔁 重复标识和完成次数统计
  - 创建/编辑弹窗中完整的重复设置面板

### 数据库

- `tasks` 表新增 4 个字段：`recurrence_type`、`recurrence_rule`、`recurrence_end`、`completion_count`（v7.0 自动迁移）
- 新增 `computeNextOccurrence()` 工具函数，支持 DateTime 精确计算，自动处理月末天数和跨年

---

## [2.1.6] - 2026-07-18

### 修复

- **Windows phpStudy 环境下 SQL 排序报错**：`ORDER BY t.due_datetime ASC NULLS LAST` 语法需要 SQLite ≥ 3.30.0，而 phpStudy 捆绑的 SQLite 版本通常低于此。现已将全部 7 处 `NULLS LAST` 替换为 `CASE WHEN t.due_datetime IS NULL THEN 1 ELSE 0 END, t.due_datetime ASC`，在所有 SQLite 版本上兼容

---

## [2.1.5] - 2026-07-18

### 优化

- `check.php` 诊断脚本增强：显示 php.ini 加载路径、已加载扩展完整列表，并对缺失扩展给出群晖 Web Station「双层配置」的排查指引（套件中心打勾 ≠ Web Station 生效）

---

## [2.1.4] - 2026-07-18

### 修复

- **Windows Server 日视图/四象限不显示未来任务**：`list_tasks` API 的 `calendar_date` 日期筛选使用 `strtotime('YYYY-MM-DD +1 day')` 计算次日边界，该写法在 Windows 某些 PHP 版本下会返回 `false`，导致 `date('Y-m-d', false)` = `'1970-01-01'`，范围筛选变成 `>= 2026-10-15 AND < 1970-01-01` 永远为空。现已改为 `DateTime` 类计算次日，并在极端情况下降级为 `mktime` 手动拆分计算
- 日期边界值格式与 `calendar_tasks` 保持一致：从 `'YYYY-MM-DD HH:MM:SS'` 改为纯日期 `'YYYY-MM-DD'`，避免时间组件不一致导致的字符串比较歧义

### 优化

- `check.php` 新增 DateTime 日期计算测试（含 `strtotime` 兼容性检测）、SQLite 版本号及 `NULLS LAST` 支持检测，便于在服务器上快速诊断环境问题

---

## [2.1.3] - 2026-07-18

### 修复

- **未来日期任务在日视图/四象限/列表中不显示**：`list_tasks` API 中使用 `date(t.due_datetime)` 函数进行日期筛选，跨3个月的 `2026-10-15` 格式在 SQLite PDO 绑定下可能匹配失败。改为与 `calendar_tasks` 一致的范围比较 (`>= :cal_start AND < :cal_end`)，月视图能显示的任务现在日视图/四象限也能正确显示
- **`due_datetime` 格式统一**：`buildDt()` 生成的截止时间从 `HH:MM` 统一为 `HH:MM:SS`，确保 SQLite 字符串比较精确一致

### 优化

- **优先级下拉框宽度**：`task-input-priority` 从 80px 扩至 95px，解决 Windows Server 上「🔴 高」等中文选项右侧截断问题
- **视图切换标题更新**：`switchView` 中为日历视图和四象限视图显式设置 `pageTitle` / `pageSub`，切换番茄钟后再切回其他视图标题不再卡在"🍅 番茄钟"
- **错误日志**：`loadTasks`、`loadQuadrants` 空 catch 块改为 `console.error`，便于排查静默失败

---

## [2.1.2] - 2026-07-18

### 优化

- 左侧导航模块标题字号从 `12px` 提升至 `14px`，颜色改为正文色 `--text`，字重 `700`，明显大于导航项（13px），层级更清晰
- 全部 7 个弹窗右上角新增 ✕ 关闭按钮（绝对定位 `top:16px right:16px`，28px 圆形 hover 效果），无需滚动到底部点取消即可关闭

### 修复

- h3 标题增加 `padding-right: 28px` 防止标题过长与关闭按钮重叠

---

## [2.1.1] - 2026-07-18

### 修复

- **打卡视图统计不显示**：`loadHabits()` 中误调用了不存在的 `loadHabitsTrend()` 函数，导致 JS 抛出异常被空 `catch` 吞掉，后续的 `loadHabitsStats()` 从未执行。已删除该错误调用并将空 catch 改为 `console.error` 输出。
- 导航打开打卡页现在立即显示统计信息（今日打卡数、习惯数、近7天趋势图）

### 优化

- 左侧导航模块标题字号从 `10px` 调整为 `12px`，颜色从 `text-muted` 调整为 `text-light`，去掉 `uppercase` 全大写和 `letter-spacing`，视觉更协调

---

## [2.1.0] - 2026-07-18

### 新增功能

**📋 子任务列表标识**
- 子任务（有 `parent_id`）在列表中显示缩进 + `↳` 连接线和「子任务」标签，直观区分层级
- 父任务显示 `📋 已完成/总数` 徽章，一目了然子任务完成进度
- 左侧浅色竖线 + 连接横线形成树状视觉效果（`::before` 伪元素实现）
- `list_tasks` API 自动附加子任务计数（`subtask_count`、`subtask_done`）

**📊 左侧导航重排**
- 「打卡」模块移至「每日回顾」下方，作为「📋 独立模块」独立区域
- 与上方导航区以分组标题自然分隔，模块边界清晰

### 优化

**🎨 全局滚动条统一**
- 为全部 6 套主题皮肤添加专属滚动条配色变量
- 全局 WebKit + Firefox 滚动条统一样式（`scrollbar-width: thin`）
- 滚动条颜色与所属主题协调，暗夜模式也有专属暗色滚动条
- 侧边栏保留稍窄的 4px 滚动条宽度

### 修复

- 修复 `editTask` 函数空 catch 块静默吞错误导致点击任务无响应的问题
- 增加 HTTP 状态检查、数据空值校验和错误 Toast 反馈

---

## [2.0.0] - 2026-07-18

### 重大更新

**📝 详细任务创建弹窗**
- 保留快速添加功能，新增「📝 详细」按钮打开完整创建弹窗
- 新增字段：**描述**（description，支持换行）和**备注**（notes）
- 支持上传附件，常见 PDF 可在线预览（浏览器直接打开）
- 标签选择、优先级、截止日期/时间、自定义提醒一体设置

**📋 子任务功能**
- 任务新增 `parent_id` 字段支持层级关系
- 编辑弹窗中的「📋 子任务」区域：查看/添加/切换完成/删除子任务
- 每个子任务可设置独立的截止日期，适合项目分阶段管理
- 创建/编辑任务时可指定父任务

**⏰ 提醒时间升级**
- 提醒方式新增「**自定义**」选项，可选择任意日期和时间
- 适用于跨周末、节假日、调休等场景
- 默认提醒时间从 23:59 改为 **09:00**（合理工作时间）
- `reminder_custom` 字段存储精确的自定义提醒时间
- 邮件提醒和页面提醒均支持自定义时间触发

**✅ 打卡模块**
- 侧边栏新增「打卡」导航入口
- 支持创建自定义习惯项目（名称、图标、颜色、打卡日）
- 12 种预设图标可选（🏃📖💪🧘💧🍎✍️🎵🌱💤🎯📌）
- 每日打卡/取消打卡，卡片实时展示
- 统计展示：总打卡数、连续天数、本月完成率
- 近7天趋势柱状图（所有习惯聚合）
- 习惯详情面板：本月日历热力图
- 新增 3 张数据表：`habits`、`habit_logs`、`task_attachments`

**📎 附件系统**
- 任务支持上传文件附件（图片、PDF、文档等）
- 单文件最大 20MB
- PDF 文件支持浏览器**在线预览**（inline 模式）
- 附件存储在 `data/uploads/{user_id}/` 目录
- 新增 API：`upload_attachment`、`download_attachment`、`delete_attachment`、`list_attachments`

### 技术变更

- `tasks` 表新增 `parent_id`、`description`、`reminder_custom` 字段
- 新增表：`task_attachments`、`habits`、`habit_logs`
- v6.0 自动迁移逻辑兼容旧版数据库
- API 新增 10+ 接口
- 弹窗支持 `modal-wide` 宽版模式（720px）

---

## [1.0.0] - 2026-07-18

### 正式版发布

首个正式版本发布！经过 18 个小版本的迭代打磨，系统功能已趋于完善稳定。

**核心功能回顾：**
- 📝 完整的任务 CRUD + 三态工作流（待办→处理中→已完成）
- 📅 日历三视图（月/周/日），月历撑满全高度自适应
- ➕ 四象限视图，紧急/重要矩阵管理
- 🍅 番茄钟，支持倒计时+统计+周趋势图表
- 📊 每日回顾、搜索、分类/标签过滤
- 🎨 6 套主题皮肤（默认/护眼绿/樱花粉/暗夜/海洋蓝/日落橙）
- 🔐 多用户登录/注册/密码修改
- 📧 邮件提醒（SMTP 纯 PHP 发送）
- 🗑️ 软删除+垃圾桶+恢复机制
- 🏷️ 标签系统、快速添加、桌面通知

### 本版修复

- 修复番茄钟周趋势图表柱状图/标签悬空问题，改为底部对齐

---

## [0.2.1-beta] - 2026-07-18

### 优化

**四象限视图全高度自适应**
- 四象限网格撑满内容区高度，不再有固定 `min-height`
- 每个象限格子均分可用高度（`grid-template-rows: 1fr 1fr`）
- 象限任务区 `overflow-y:auto`，任务多时内部滚动不撑破布局

**番茄钟面板全高度自适应**
- 番茄钟面板撑满内容区到页脚上方
- 计时器、控制器固定在顶部，周图表自动填满剩余空间
- 周图表区域 `overflow-y:auto`，内容溢出可独立滚动

---

## [0.2.0-beta] - 2026-07-18

### 新增功能

**日历三视图：月视图 / 周视图 / 日视图**
- 日历工具栏新增视图切换按钮：`月` | `周` | `日`
- **月视图**：格子撑满整个内容区高度，行数自适应（4/5/6 行时自动均分高度）
  - 任务条数随行高自适应：4行月最多显示 7 条，5行月 5 条，6行月 3 条
  - 超出的任务显示 `+N 更多` 链接，最后一行的任务前面加 `…` 提示被截断
  - 点击日期格子或"更多"跳转到**日视图**
- **周视图**：7 列布局（周一~周日），显示 ISO 周数（如"第 29 周"）
  - 每列头部显示日期数字 + 周几标签，今天列高亮
  - 每列最多显示 12 条任务，超出显示 `+N 更多`，点击跳转日视图
  - 跨月自动合并多月 API 数据
- **日视图**：大日期数字 + 星期 + 完整任务列表，支持滚动

**月历全高度自适应**
- `.content` 改为 flex column 容器，所有视图均分可用高度
- `.full-cal-days` 使用 `grid-template-rows: repeat(N, 1fr)` 均分
- 任务格子 `overflow: hidden` + CSS 省略号，视觉上不乱不溢出
- 页脚始终锁定底部，月历/周历/日历均无页面级滚动条

### 修复 / 优化

- 移除旧 `fullCalYear`/`fullCalMonth` 全局变量，统一为 `calDate` + `calViewMode`
- 重构 `renderFullCalendar()` → `renderCalendar()` 派发月/周/日三个子视图
- 各视图切换时自动更新标题和导航按钮语义

---

## [0.1.8-beta] - 2026-07-18

### 修复

**Sticky Footer 真正锁定底部**
- `body` 改为 `height: 100vh; overflow: hidden`，彻底锁死视口高度
- `#mainApp` 加 `min-height: 0`，防止子元素撑大父容器
- `.footer` 改 `flex-shrink: 0` 替代 `margin-top: auto`，永不收缩
- 移除 `.sidebar` 的 `position: sticky` 和 `max-height`，在 flex 布局中高度自然由父容器约束，溢出时内部滚动
- 仅 `.content` 内部滚动，页眉和页脚始终固定于视口顶部/底部，完全脱离滚动

**月历 6 行自适应**
- `.full-cal-days` 移除 `min-height: 500px`，改为 `min-height: 0; grid-auto-rows: 1fr`
- `.full-cal-day` 移除 `min-height: 105px`，改为 `min-height: 0`
- 5 行月份：格子正常大小；6 行月份：格子等比缩小，整体不产生页面滚动条

**认证页**
- `.auth-page` 加 `overflow-y: auto`，极端小屏也能滚动查看完整内容

---

## [0.1.7-beta] - 2026-07-18

### 修复 / 美化

**布局宽度修复 & 左右留白 15%**
- 修复 sticky footer 后 `.layout` 宽度塌缩问题：显式设置 `width: 70%`
- 顶栏 `.header-inner`、主体 `.layout`、页脚 `.footer-inner` 统一 `width: 70%; max-width: 1340px`
- 左右各留白 15%，月历 / 四象限 / 番茄钟全部统一在此宽度内
- 超宽屏幕自动居中，最大宽度 1340px

---

## [0.1.6-beta] - 2026-07-18

### 修复

**页脚锁定底部（Sticky Footer）**
- 将 `body` 改为 `flex column` 布局，`#mainApp` 作为 flex item 填充剩余空间
- `.layout` 改为 `flex: 1`，内容区高度自动撑满页眉和页脚之间
- 月历等长内容在 `.content` 内部滚动，页脚始终固定在视口底部，不再被挤出可视区

---

## [0.1.5-beta] - 2026-07-18

### 修复

**周图表柱子溢出**
- 修复柱子高度超出容器的问题（原逻辑 `cnt*100/max` 直接用作 px，最大值 100px 超出 80px 容器）
- 改为列布局 `week-bar-col`，柱子从底部向上长，最大 72px，标签始终在柱子下方
- 容器高度提升至 100px，外框增加 `overflow:hidden` 双重保险

---

## [0.1.4-beta] - 2026-07-18

### 修复 / 美化

**番茄钟周图表优化**
- 为周图表增加标题 "📊 本周专注趋势" 和卡片容器，明确告知用户该区域含义
- 无数据时显示 "暂无本周专注数据，开始一个番茄钟吧！" 提示，避免只剩孤立的 "六" 和色块

**番茄钟关联任务下拉框**
- 为 `pomoTaskSelect` 补齐 select 样式，统一与输入框的边框、圆角、focus 状态

**页脚增强**
- 页脚高度从 40px 提升至 60px，增加 "高效 · 专注 · 简洁" 中间标语
- 三栏布局（logo+版本 / 标语 / 版权），视觉分量更足，与页眉平衡

---

## [0.1.3-beta] - 2026-07-18

### 优化

**Favicon 改用 SVG**
- 删除旧 PNG favicon（文件大、带 AI 水印），替换为手写 SVG
- SVG 体积仅 300+ 字节，矢量无锯齿，蓝底白勾简洁干净

---

## [0.1.2-beta] - 2026-07-18

### 新增 / 美化

**Favicon 图标**
- 添加网站图标（favicon.png），蓝色渐变圆角方块 + 白色勾选标记

**页脚**
- 新增页脚栏，显示应用名称、版本号和版权信息
- 页脚与页眉风格匹配（全宽玻璃效果，内容区 1340px 居中）

**布局统一**
- 页眉新增 `.header-inner` 内层容器，内容区宽度限制为 1340px，与主体和页脚保持一致
- 解决之前页眉全宽、主体留白导致的"头重脚轻"视觉效果

---

## [0.1.1-beta] - 2026-07-18

### 修复

**番茄钟提醒补全**
- 番茄钟完成时现在会触发声音提醒（`playBeep`）、标签闪烁和桌面通知
- 休息结束时同样触发提醒，提示用户可以开始下一轮专注
- 新增 `startTabFlashMsg(msg)` 函数，支持自定义闪烁消息

**时间输入框宽度修复**
- 任务表单时间输入框宽度从 85px 调整为 110px，确保分钟数完整显示

---

## [0.1.0-beta] - 2026-07-18

### 重大更新（生产力工具全面升级 + 界面重设计）

**多主题皮肤系统**
- 新增 6 套皮肤：默认、护眼绿、樱花粉、暗夜模式、海洋蓝、日落橙
- 使用 CSS 自定义属性（`data-theme`）实现即时切换，无需刷新页面
- 主题设置持久化存储到 `user_settings.theme` 字段
- 顶栏主题切换器以彩色圆点展示，点击即可切换

**番茄时钟（Pomodoro Timer）**
- 全新番茄钟面板：可配置工作时长（默认 25 分钟）和休息时长（默认 5 分钟）
- 大号倒计时显示，支持开始/暂停/重置操作
- 自动计入当前选中任务的番茄数（`pomodoro_count`）
- 侧边栏番茄钟小部件：迷你倒计时，任务关联选择器
- 本周番茄统计图表（按天聚合的工作时长）
- 工作完成/休息提醒（音频 + 标签闪烁）

**任务工作流状态**
- `tasks` 表新增 `status` 字段：`todo`（待办）→ `doing`（处理中）→ `done`（已完成）
- 任务列表中每条任务显示可点击状态按钮（📝待办 / 🔄处理中 / ✅完成）
- 处理中的任务左侧边框高亮橙色，视觉上区分进行中任务
- 新增 `update_task_status` API 接口，快速切换工作流状态

**每日回顾（Daily Review）**
- 新增「每日回顾」面板，统计卡片展示：
  - 今日已完成任务数
  - 今日创建任务数
  - 逾期未完成任务数
  - 今日番茄钟完成次数与总时长
  - 今日任务完成率百分比
- 新增 `daily_review` API，一次请求返回所有统计数据

**番茄统计接口**
- `pomodoro_start`：开始番茄会话，保存记录并递增任务番茄数
- `pomodoro_today`：今日番茄统计（次数、总分钟数、任务数、按小时分布）
- `pomodoro_week_stats`：最近 7 天番茄统计数据（用于图表）

**界面美化**
- 全新玻璃拟态（Glass Morphism）设计风格顶栏
- 圆角卡片式布局，柔和阴影与过渡动画
- 响应式网格布局，适配不同屏幕尺寸
- 更精致的按钮、输入框、弹窗样式
- 优化任务列表视觉层次（分组标题、日期标签、分类色条）
- 平滑的页面加载和交互过渡动画

**数据库变更**
- `tasks` 表新增 `status`（TEXT DEFAULT 'todo'）、`pomodoro_count`（INTEGER DEFAULT 0）
- `user_settings` 表新增 `theme`（TEXT DEFAULT 'default'）
- 新增 `pomodoro_sessions` 表：`id`, `user_id`, `task_id`, `work_duration`, `break_duration`, `status`, `started_at`, `completed_at`
- v4→beta 自动迁移：检测列是否存在后 ALTER TABLE 添加

**API 新增**
- `update_task_status`：批量切换任务工作流状态
- `update_theme`：更新用户主题偏好（6 个有效主题名验证）
- `pomodoro_start`：记录番茄会话开始
- `pomodoro_today`：查询今日番茄统计
- `pomodoro_week_stats`：查询近 7 天番茄统计
- `daily_review`：每日回顾综合统计

**API 修改**
- `create_task` / `update_task`：支持 `status` 字段
- `get_settings`：返回 `theme` 字段
- `update_settings`：支持更新 `theme` 字段

### 技术细节
- 番茄钟基于 `setInterval` 每秒轮询实现，精确倒计时
- 主题系统通过 `<body data-theme="X">` + CSS `:root` 变量级联实现
- 所有新增列使用 `ALTER TABLE ... ADD COLUMN`，自动检测列是否存在避免重复迁移
- 番茄钟面板状态通过 JS 局部状态管理，不干扰主任务列表

---

## [4.0.1] - 2026-07-17

### 优化
- 加宽快速添加表单中的日期输入框（130px → 148px）和时间输入框（80px → 90px），避免日期显示不全
- 点击顶部栏用户名可弹出修改密码弹窗，支持修改登录密码

### 新增
- 新增 `change_password` API 接口，验证旧密码后更新为新密码（bcrypt 加密）
- 修改密码成功后自动刷新页面，需用新密码重新登录

---

## [4.0.0] - 2026-07-17

### 重大更新（参考滴答清单重新设计）

**全新导航与布局**
- 左侧边栏重新设计：所有 / 今天 / 最近7天 / 收集箱 / 摘要 / 标签 / 清单分类 / 已完成 / 垃圾桶
- 顶部新增搜索栏，支持按任务标题和备注搜索
- 每个导航项显示未完成任务数徽章

**标签系统**
- 新增 `tags` 表和 `task_tags` 关联表，任务支持多个标签
- 侧边栏标签列表可点击筛选，hover 显示删除按钮
- 快速创建和编辑弹窗中支持标签多选

**任务分组**
- 列表视图按「已过期 / 今天 / 明天 / 未来 / 无日期 / 已完成」自动分组显示
- 分组数据由后端 `list_tasks` 接口一次性返回

**完整月历视图**
- 新增整月月历视图，每日格内直接显示任务条（按分类颜色）
- 已完成的任务半透明 + 删除线
- 支持上月/下月翻页、回到今天
- 点击某日切换到该日的任务列表

**四象限视图（艾森豪威尔矩阵）**
- 按「重要 & 紧急」两个维度将任务划分为四个象限
- 高优先级 或 今天/明天到期 的任务视为紧急/重要
- 每个象限独立显示任务数

**软删除与垃圾桶**
- 任务删除改为软删除（`is_deleted` + `deleted_at`）
- 新增「垃圾桶」视图：可恢复任务、永久删除单条任务、清空垃圾桶
- 移除 v3.0 的 5 秒撤销 Toast（由垃圾桶替代）

**摘要统计**
- 新增「摘要」视图：总待办、今天、已逾期、最近7天、已完成、垃圾桶数量卡片

**数据库变更**
- `tasks` 表新增 `is_deleted`（INTEGER）、`deleted_at`（DATETIME）
- 新增 `tags` 表：`id`, `user_id`, `name`, `color`, `created_at`
- 新增 `task_tags` 关联表：`id`, `task_id`, `tag_id`, `created_at`
- 自动迁移：旧任务表添加软删除字段，兼容 v3.0 数据

### 技术细节
- 后端 `list_tasks` 新增 `search`、`tag_id`、`group=auto` 参数
- 新增 API：`list_tags`、`create_tag`、`update_tag`、`delete_tag`、`restore_task`、`permanent_delete_task`、`empty_trash`、`quadrants`、`summary`
- 所有任务查询默认排除 `is_deleted = 1` 的记录

---

## [3.0.0] - 2026-07-17

### 重大更新

**Bug 修复**
- 修复点击复选框后任务立即消失的 UX 问题：改为 1.5 秒渐隐动画，方便用户确认操作后在当前视图看到反馈
- 修复「已完成」标签页中已完成任务可能查不到的问题（Safari/Firefox 兼容性修正）

**时间精度升级**
- `due_date`（仅日期）→ `due_datetime`（日期+时间），支持精确到分钟的截止时间
- 任务表单新增独立时间选择器，默认 `23:59`
- 任务列表中显示具体时间：今天显示"今天 HH:MM"，逾期显示"日期+时间 (已逾期)"，未来显示"日期+时间"

**提醒偏移量**
- 新增 `reminder_offset` 字段：支持提前 N 分钟提醒
- 可选偏移：准时 / 5分钟前 / 15分钟前 / 30分钟前 / 1小时前 / 1天前
- 后端提醒查询基于 `due_datetime - reminder_offset` 精确计算触发时机

**月历视图**
- 左侧边栏新增月历卡片，按月展示每日任务分布
- 每一天显示最多 3 个彩色圆点（按分类颜色），已完成的半透明显示
- 点击某日期自动筛选该日的任务列表
- 支持月份前后翻页导航

**任务备注**
- 新增 `notes` 字段（任务描述/备注），在编辑弹窗中填写和查看
- 任务列表中有备注的任务显示 📝 图标

**任务编辑弹窗**
- 点击任务标题/内容区域打开编辑弹窗
- 支持修改全部字段：标题、清单、优先级、日期时间、提醒偏移、备注

**撤销删除**
- 删除任务后弹出 5 秒倒计时 Toast，提供「撤销」按钮
- 撤销后恢复任务所有数据（标题、分类、优先级、备注、完成状态等）

**分类任务计数**
- 侧边栏每个分类显示未完成任务数徽章（蓝色圆角标签）
- 创建/完成/删除/撤销任务后自动更新

**新增筛选视图**
- 「明天」：明天截止的未完成任务
- 「即将到来」：明天及以后截止的所有未完成任务

**排序选项**
- 新增排序下拉框：默认排序 / 截止时间升序 / 截止时间降序 / 按优先级

**数据库变更**
- `tasks` 表新增 `due_datetime`（DATETIME）、`reminder_offset`（INTEGER）、`notes`（TEXT）
- 自动迁移：旧 `due_date` 数据迁移至 `due_datetime`（时间默认 23:59）

### 技术细节
- 日历数据通过 `calendar_tasks` API 按月批量查询，减少请求次数
- 完成动画使用 CSS transition（`opacity` + `transform` + `max-height`），不依赖 JS 动画库

---

## [2.0.0] - 2026-07-17

### 新增功能（重大版本）
- **用户认证系统**：注册 / 登录 / 登出，密码使用 bcrypt 哈希存储，Session 会话管理
- **数据隔离**：所有分类和任务按 user_id 隔离，多用户数据互不干扰，支持 v1.0 数据自动迁移
- **声音提醒**：使用 Web Audio API 生成短促蜂鸣提示音，无需依赖任何音频文件
- **标签闪烁提醒**：动态修改浏览器标签页标题，用户切回页面后自动停止闪烁
- **SMTP 邮件提醒**：用户可自定义 SMTP 服务器配置（支持 TLS/SSL/无加密），纯 PHP 实现无需第三方库
  - 今日截止任务自动发送提醒邮件
  - 支持发送测试邮件验证配置
  - 兼容 QQ邮箱、163、Gmail 等主流邮件服务
- **提醒设置面板**：可视化开关控制声音/闪烁/邮件三种提醒方式

### 修复
- 修复 index.php 中 `$config` 变量未定义的 Warning 报错（增加 `require_once 'config.php'`）

### 数据库变更
- 新增 `users` 表（用户认证）
- 新增 `user_settings` 表（SMTP 配置和提醒偏好）
- `categories` 表和 `tasks` 表新增 `user_id` 字段（数据隔离）
- 自动迁移旧版数据（ALTER TABLE 补充 user_id 列）

---

## [1.0.0] - 2026-07-17

### 新增功能
- **场景隔离**：支持创建多个清单分类（工作、生活、学习），任务绑定到指定分类
- **任务管理**：完整的 CRUD 操作，支持标题、优先级（高/中/低）、截止日期
- **列表筛选**：支持"今天"、"所有任务"、"已完成任务"三种视图，可按分类过滤
- **桌面通知**：页面打开时自动检测今日截止的未完成任务，通过浏览器 Notification API 弹出提醒
- **分类管理**：支持新建、编辑、删除分类，可自定义颜色标识
- **SQLite 数据库**：首次运行自动建表并插入默认分类，无需额外配置数据库服务
- **操作日志**：记录关键操作到 data/app.log，方便排查问题
- **版本管理**：config.php 中定义版本号，遵循语义化版本规范
