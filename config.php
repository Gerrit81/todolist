<?php
/**
 * =============================================================================
 * 任务管理系统 (Todo List) - 配置文件
 * =============================================================================
 *
 * 功能说明：
 *   1. 定义应用全局配置（版本号、数据库路径等）
 *   2. 建立 SQLite 数据库连接（PDO 单例模式）
 *   3. 首次运行时自动创建数据表及默认分类数据
 *   4. 用户认证（注册/登录/登出/PHP Session）
 *   5. 纯 PHP 实现的 SMTP 邮件发送（无需第三方依赖）
 *   6. 提供统一的 JSON 响应、日志记录等工具函数
 *   7. 自动数据库迁移（v1→v2→…→v9）
 *   8. 管理员辅助函数（isAdmin / requireAdmin / 登录记录 / 管理设置）
 *
 * @version  3.0.0
 * @date     2026-07-18
 * =============================================================================
 */

// -------------------- 调试模式：显示所有错误 --------------------
// 遇到 500/白屏 时取消下面三行的注释，访问页面即可看到具体错误信息
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// 设置默认时区（必须在任何 date() 调用之前）
date_default_timezone_set('Asia/Shanghai');

// -------------------- 环境检查 --------------------

// 检查必要扩展
if (!extension_loaded('pdo')) {
    http_response_code(500);
    die('<h2>错误：缺少 PHP 扩展</h2><p>请启用 <b>PDO</b> 扩展。</p><p>群晖 WebStation → PHP 设置 → 扩展 → 勾选 "pdo"</p>');
}
if (!extension_loaded('pdo_sqlite')) {
    http_response_code(500);
    die('<h2>错误：缺少 PHP 扩展</h2><p>请启用 <b>PDO_SQLite</b> 扩展（注意：不是 sqlite3）。</p><p>群晖 WebStation → PHP 设置 → 扩展 → 勾选 "pdo_sqlite"</p>');
}

// 检查 JSON 扩展
if (!extension_loaded('json')) {
    http_response_code(500);
    die('<h2>错误：缺少 PHP 扩展</h2><p>请启用 <b>JSON</b> 扩展。</p>');
}

// 检查 mbstring 扩展（用户名验证用到）
if (!extension_loaded('mbstring')) {
    http_response_code(500);
    die('<h2>错误：缺少 PHP 扩展</h2><p>请启用 <b>mbstring</b> 扩展。</p>');
}

// -------------------- 会话管理 --------------------

/**
 * 设置 Session 保存路径到当前目录，避免群晖默认路径权限问题
 */
$sessionSavePath = __DIR__ . '/data/sessions';
if (!is_dir($sessionSavePath)) {
    @mkdir($sessionSavePath, 0755, true);
}
if (is_dir($sessionSavePath) && is_writable($sessionSavePath)) {
    session_save_path($sessionSavePath);
}

// 启动 PHP Session（用于用户登录状态保持）
if (session_status() === PHP_SESSION_NONE) {
    // 设置 Session 生命周期：默认 30 天，避免服务器端过早清理导致"刷新跳回登录"
    $sessionLifetime = 30 * 24 * 3600;

    // 【关键】在 session_start() 之前设置 cookie_lifetime
    // 这样 PHP 发送的 session cookie 本身就带 30 天过期时间
    // 避免 IIS 上先发短期 cookie 再被后续 setcookie() 覆盖不生效的问题
    ini_set('session.cookie_lifetime', $sessionLifetime);
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    // 服务器端 GC 设置
    ini_set('session.gc_maxlifetime', $sessionLifetime);
    // IIS 上 gc 可能触发频繁，降低清理概率（1/100 的请求触发 gc）
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 100);

    session_name('TODOSESSID');
    session_start();
}

// 「保持登录」持久化 Cookie：勾选「保持登录」的用户，每次请求刷新 30 天有效期
// 使用 PHP 7.3+ 数组语法，确保 cookie 属性在 IIS / Apache 上表现一致
if (!empty($_SESSION['user_id']) && !empty($_SESSION['persist_login'])) {
    $lifetime = 30 * 24 * 3600;
    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        // 降级兼容 PHP < 7.3
        setcookie(session_name(), session_id(), time() + $lifetime, '/', '', false, true);
    }
}

// -------------------- 应用配置 --------------------

$config = [
    // 应用基本信息
    'app_name'    => '任务管理系统',
    'app_version' => '3.2.2', // 修复预排实例完成后循环系列被误终结问题

    // SQLite 数据库文件路径（存放在 data 目录下，确保该目录可写）
    // 外网部署建议移到 Web 根目录之外，例如：
    // 'db_path'  => '/home/www-data/todolist_data/todolist.db',
    'db_path'     => __DIR__ . '/data/todolist.db',

    // 日志文件路径
    'log_path'    => __DIR__ . '/data/app.log',

    // SMTP 密码加密密钥（部署后务必修改为随机字符串！）
    // 生成方法：php -r "echo bin2hex(random_bytes(32));"
    'encrypt_key' => 'todolist-default-key-change-on-deploy',

    // 数据库自动备份配置
    'backup_path'  => __DIR__ . '/data/backups/',   // 备份存放目录
    'backup_max'   => 15,                             // 最多保留份数
];


// -------------------- 数据库连接（PDO 单例） --------------------

/**
 * 获取 PDO 数据库连接实例
 *
 * 使用静态变量实现单例模式，同一请求内复用同一个连接。
 *
 * @param array $config 应用配置数组
 * @return PDO
 */
function getDB($config) {
    static $db = null;
    if ($db === null) {
        try {
            $dataDir = dirname($config['db_path']);
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }

            $db = new PDO('sqlite:' . $config['db_path']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            // WAL 模式在网络/群晖共享目录下可能失败，降级为 DELETE 模式
            try {
                $db->exec('PRAGMA journal_mode=WAL');
            } catch (Exception $walEx) {
                // 降级使用默认 journal 模式，不影响正常使用
            }
            $db->exec('PRAGMA foreign_keys=ON');

        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode([
                'success' => false,
                'message' => '数据库连接失败: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
        }
    }
    return $db;
}

// -------------------- 数据库初始化 + 自动迁移 --------------------

/**
 * 初始化数据库表结构
 *
 * 包含完整的建表逻辑 + 跨版本数据迁移：
 *   - users         用户表（v2.0 新增）
 *   - user_settings 用户设置表（v2.0 新增）
 *   - categories    分类表
 *   - tasks         任务表
 *     v3.0: due_date → due_datetime（支持精确时间）、reminder_offset、notes
 *
 * @param PDO $db 数据库连接实例
 */
function initDatabase($db) {
    // ========================
    // 用户表
    // ========================
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            username     TEXT    NOT NULL UNIQUE,
            password     TEXT    NOT NULL,
            email        TEXT    DEFAULT '',
            created_at   DATETIME NOT NULL DEFAULT (datetime('now', 'localtime'))
        )
    ");

    // ========================
    // 用户设置表
    // ========================
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_settings (
            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id                 INTEGER NOT NULL UNIQUE,
            smtp_host               TEXT    DEFAULT '',
            smtp_port               INTEGER DEFAULT 587,
            smtp_username           TEXT    DEFAULT '',
            smtp_password           TEXT    DEFAULT '',
            smtp_encryption         TEXT    DEFAULT 'tls',
            sound_enabled           INTEGER DEFAULT 1,
            tab_flash_enabled       INTEGER DEFAULT 1,
            email_reminder_enabled  INTEGER DEFAULT 0,
            email_recipient         TEXT    DEFAULT '',
            theme                   TEXT    DEFAULT 'default',
            updated_at              DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // ========================
    // 分类表
    // ========================
    $db->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL DEFAULT 0,
            name        TEXT    NOT NULL,
            color       TEXT    NOT NULL DEFAULT '#4A90D9',
            sort_order  INTEGER NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // ========================
    // 任务表（v4.0: 增加软删除 is_deleted/deleted_at + 与 tags 多对多关联）
    // ========================
    $db->exec("
        CREATE TABLE IF NOT EXISTS tasks (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id         INTEGER NOT NULL DEFAULT 0,
            title           TEXT    NOT NULL,
            category_id     INTEGER NOT NULL,
            priority        TEXT    NOT NULL DEFAULT 'medium' CHECK(priority IN ('high','medium','low')),
            due_datetime    DATETIME DEFAULT NULL,
            reminder_offset INTEGER NOT NULL DEFAULT 0,
            notes           TEXT    DEFAULT '',
            is_completed    INTEGER NOT NULL DEFAULT 0 CHECK(is_completed IN (0,1)),
            completed_at    DATETIME DEFAULT NULL,
            is_deleted      INTEGER NOT NULL DEFAULT 0 CHECK(is_deleted IN (0,1)),
            deleted_at      DATETIME DEFAULT NULL,
            created_at      DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            updated_at      DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
        )
    ");

    // ========================
    // 标签表（v4.0 新增）
    // ========================
    $db->exec("
        CREATE TABLE IF NOT EXISTS tags (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL,
            name        TEXT    NOT NULL,
            color       TEXT    NOT NULL DEFAULT '#95A5A6',
            created_at  DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            UNIQUE(user_id, name)
        )
    ");

    // ========================
    // 任务-标签关联表（v4.0 新增）
    // ========================
    $db->exec("
        CREATE TABLE IF NOT EXISTS task_tags (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id     INTEGER NOT NULL,
            tag_id      INTEGER NOT NULL,
            created_at  DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (tag_id)  REFERENCES tags(id)  ON DELETE CASCADE,
            UNIQUE(task_id, tag_id)
        )
    ");

    // ========================
    // 番茄钟会话表（v5.0 新增）
    // ========================
    $db->exec("
        CREATE TABLE IF NOT EXISTS pomodoro_sessions (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id         INTEGER NOT NULL,
            task_id         INTEGER DEFAULT NULL,
            work_duration   INTEGER NOT NULL DEFAULT 25,
            break_duration  INTEGER NOT NULL DEFAULT 5,
            status          TEXT    NOT NULL DEFAULT 'completed' CHECK(status IN ('completed','interrupted','abandoned')),
            started_at      DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            ended_at        DATETIME DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
        )
    ");

    // ========================
    // 任务附件表（v6.0 新增）
    // ========================
    $db->exec("
        CREATE TABLE IF NOT EXISTS task_attachments (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL,
            task_id     INTEGER NOT NULL,
            filename    TEXT    NOT NULL,
            orig_name   TEXT    NOT NULL,
            file_size   INTEGER NOT NULL DEFAULT 0,
            file_type   TEXT    NOT NULL DEFAULT '',
            created_at  DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        )
    ");

    // ========================
    // 打卡习惯表（v6.0 新增）
    // ========================
    $db->exec("
        CREATE TABLE IF NOT EXISTS habits (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL,
            name        TEXT    NOT NULL,
            icon        TEXT    NOT NULL DEFAULT '📌',
            color       TEXT    NOT NULL DEFAULT '#4A90D9',
            target_days TEXT    NOT NULL DEFAULT '1,2,3,4,5,6,7',
            sort_order  INTEGER NOT NULL DEFAULT 0,
            is_archived INTEGER NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // ========================
    // 打卡日志表（v6.0 新增）
    // ========================
    $db->exec("
        CREATE TABLE IF NOT EXISTS habit_logs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            habit_id    INTEGER NOT NULL,
            user_id     INTEGER NOT NULL,
            check_date  DATE    NOT NULL,
            note        TEXT    DEFAULT '',
            created_at  DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            FOREIGN KEY (habit_id) REFERENCES habits(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(habit_id, check_date)
        )
    ");


    // ========================
    // 跨版本自动迁移
    // ========================

    // v2.0 迁移：为旧表补充 user_id 列
    try {
        $cols = $db->query("PRAGMA table_info(categories)")->fetchAll();
        $hasUserId = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'user_id') { $hasUserId = true; break; }
        }
        if (!$hasUserId) {
            $db->exec("ALTER TABLE categories ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0");
        }
    } catch (Exception $e) { /* 列已存在则忽略 */ }

    try {
        $cols = $db->query("PRAGMA table_info(tasks)")->fetchAll();
        $hasUserId = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'user_id') { $hasUserId = true; break; }
        }
        if (!$hasUserId) {
            $db->exec("ALTER TABLE tasks ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0");
        }
    } catch (Exception $e) { /* 列已存在则忽略 */ }

    // v3.0 迁移：due_date → due_datetime + reminder_offset + notes
    try {
        $cols = $db->query("PRAGMA table_info(tasks)")->fetchAll();
        $hasDueDatetime = false;
        $hasDueDate = false;
        $hasReminderOffset = false;
        $hasNotes = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'due_datetime')    { $hasDueDatetime = true; }
            if ($col['name'] === 'due_date')        { $hasDueDate = true; }
            if ($col['name'] === 'reminder_offset') { $hasReminderOffset = true; }
            if ($col['name'] === 'notes')           { $hasNotes = true; }
        }

        // 添加新列
        if (!$hasDueDatetime) {
            $db->exec("ALTER TABLE tasks ADD COLUMN due_datetime DATETIME DEFAULT NULL");
        }
        if (!$hasReminderOffset) {
            $db->exec("ALTER TABLE tasks ADD COLUMN reminder_offset INTEGER NOT NULL DEFAULT 0");
        }
        if (!$hasNotes) {
            $db->exec("ALTER TABLE tasks ADD COLUMN notes TEXT DEFAULT ''");
        }

        // 将旧 due_date 迁移到 due_datetime（时间默认 23:59）
        if ($hasDueDate && $hasDueDatetime) {
            $dueDateTasks = $db->query("SELECT id, due_date FROM tasks WHERE due_date IS NOT NULL AND due_datetime IS NULL")->fetchAll();
            if (!empty($dueDateTasks)) {
                $updateStmt = $db->prepare("UPDATE tasks SET due_datetime = :dt WHERE id = :id");
                foreach ($dueDateTasks as $t) {
                    $updateStmt->execute([
                        'id' => $t['id'],
                        'dt' => $t['due_date'] . ' 23:59'
                    ]);
                }
            }
        }
    } catch (Exception $e) { /* 迁移已处理 */ }

    // v4.0 迁移：为 tasks 添加软删除字段 + 新建 tags / task_tags 表
    try {
        $cols = $db->query("PRAGMA table_info(tasks)")->fetchAll();
        $hasIsDeleted = false;
        $hasDeletedAt = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'is_deleted') { $hasIsDeleted = true; }
            if ($col['name'] === 'deleted_at') { $hasDeletedAt = true; }
        }
        if (!$hasIsDeleted) {
            $db->exec("ALTER TABLE tasks ADD COLUMN is_deleted INTEGER NOT NULL DEFAULT 0");
        }
        if (!$hasDeletedAt) {
            $db->exec("ALTER TABLE tasks ADD COLUMN deleted_at DATETIME DEFAULT NULL");
        }
    } catch (Exception $e) { /* 迁移已处理 */ }

    // v5.0 迁移：添加 theme（主题皮肤）、tasks.status（任务工作流状态）、pomodoro_count
    try {
        $cols = $db->query("PRAGMA table_info(user_settings)")->fetchAll();
        $hasTheme = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'theme') { $hasTheme = true; break; }
        }
        if (!$hasTheme) {
            $db->exec("ALTER TABLE user_settings ADD COLUMN theme TEXT DEFAULT 'default'");
        }
    } catch (Exception $e) { /* 迁移已处理 */ }

    try {
        $cols = $db->query("PRAGMA table_info(tasks)")->fetchAll();
        $hasStatus = false;
        $hasPomoCount = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'status') { $hasStatus = true; }
            if ($col['name'] === 'pomodoro_count') { $hasPomoCount = true; }
        }
        if (!$hasStatus) {
            $db->exec("ALTER TABLE tasks ADD COLUMN status TEXT NOT NULL DEFAULT 'todo' CHECK(status IN ('todo','doing','done'))");
            $db->exec("UPDATE tasks SET status='done' WHERE is_completed=1");
        }
        if (!$hasPomoCount) {
            $db->exec("ALTER TABLE tasks ADD COLUMN pomodoro_count INTEGER NOT NULL DEFAULT 0");
        }
    } catch (Exception $e) { /* 迁移已处理 */ }

    // v6.0 迁移：添加 parent_id（子任务）、description（描述）、reminder_custom（自定义提醒时间）
    try {
        $cols = $db->query("PRAGMA table_info(tasks)")->fetchAll();
        $hasParentId = false;
        $hasDescription = false;
        $hasReminderCustom = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'parent_id') { $hasParentId = true; }
            if ($col['name'] === 'description') { $hasDescription = true; }
            if ($col['name'] === 'reminder_custom') { $hasReminderCustom = true; }
        }
        if (!$hasParentId) {
            $db->exec("ALTER TABLE tasks ADD COLUMN parent_id INTEGER DEFAULT NULL");
        }
        if (!$hasDescription) {
            $db->exec("ALTER TABLE tasks ADD COLUMN description TEXT DEFAULT ''");
        }
        if (!$hasReminderCustom) {
            $db->exec("ALTER TABLE tasks ADD COLUMN reminder_custom DATETIME DEFAULT NULL");
        }
    } catch (Exception $e) { /* 迁移已处理 */ }

    // v7.0 迁移：添加 recurrence_type（重复类型）、recurrence_rule（重复规则）、recurrence_end（重复截止日期）、completion_count（完成次数）
    try {
        $cols = $db->query("PRAGMA table_info(tasks)")->fetchAll();
        $hasRecurType = false;
        $hasRecurRule = false;
        $hasRecurEnd = false;
        $hasCompCount = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'recurrence_type') { $hasRecurType = true; }
            if ($col['name'] === 'recurrence_rule') { $hasRecurRule = true; }
            if ($col['name'] === 'recurrence_end') { $hasRecurEnd = true; }
            if ($col['name'] === 'completion_count') { $hasCompCount = true; }
        }
        if (!$hasRecurType) {
            $db->exec("ALTER TABLE tasks ADD COLUMN recurrence_type TEXT DEFAULT ''");
        }
        if (!$hasRecurRule) {
            $db->exec("ALTER TABLE tasks ADD COLUMN recurrence_rule TEXT DEFAULT ''");
        }
        if (!$hasRecurEnd) {
            $db->exec("ALTER TABLE tasks ADD COLUMN recurrence_end DATETIME DEFAULT NULL");
        }
        if (!$hasCompCount) {
            $db->exec("ALTER TABLE tasks ADD COLUMN completion_count INTEGER NOT NULL DEFAULT 0");
        }
    } catch (Exception $e) { /* 迁移已处理 */ }

    // v8.0 迁移：添加 recurrence_start（重复任务开始日期），已有任务的开始日期回填为 created_at
    try {
        $cols = $db->query("PRAGMA table_info(tasks)")->fetchAll();
        $hasRecurStart = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'recurrence_start') { $hasRecurStart = true; break; }
        }
        if (!$hasRecurStart) {
            $db->exec("ALTER TABLE tasks ADD COLUMN recurrence_start DATETIME DEFAULT NULL");
            // 已有重复任务的开始日期回填为 created_at
            $db->exec("UPDATE tasks SET recurrence_start = created_at WHERE recurrence_type != '' AND recurrence_type IS NOT NULL AND recurrence_start IS NULL");
        }
    } catch (Exception $e) { /* 迁移已处理 */ }

    // v9.0 迁移：后台管理功能 —— 角色、登录历史、密码重置令牌、管理设置
    try {
        $cols = $db->query("PRAGMA table_info(users)")->fetchAll();
        $hasRole = false; $hasLastLogin = false; $hasLoginCount = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'role')          $hasRole = true;
            if ($col['name'] === 'last_login_at') $hasLastLogin = true;
            if ($col['name'] === 'login_count')   $hasLoginCount = true;
        }
        if (!$hasRole)        $db->exec("ALTER TABLE users ADD COLUMN role TEXT DEFAULT 'user'");
        if (!$hasLastLogin)   $db->exec("ALTER TABLE users ADD COLUMN last_login_at TEXT DEFAULT ''");
        if (!$hasLoginCount)  $db->exec("ALTER TABLE users ADD COLUMN login_count INTEGER DEFAULT 0");

        // 自动提权：第一个注册的用户默认为管理员
        $hasAdmin = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($hasAdmin == 0) {
            $db->exec("UPDATE users SET role = 'admin' WHERE id = (SELECT MIN(id) FROM users) AND id > 0");
        }
    } catch (Exception $e) { /* 迁移已处理 */ }

    // 登录历史表
    $db->exec("
        CREATE TABLE IF NOT EXISTS login_history (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL,
            login_at    TEXT NOT NULL,
            ip_address  TEXT DEFAULT '',
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_login_history_uid ON login_history(user_id)");

    // 密码重置令牌表
    $db->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL,
            token       TEXT NOT NULL UNIQUE,
            expires_at  TEXT NOT NULL,
            used        INTEGER DEFAULT 0,
            created_at  TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pw_resets_token ON password_resets(token)");

    // 管理设置表
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_settings (
            key   TEXT PRIMARY KEY,
            value TEXT
        )
    ");
    $existing = $db->query("SELECT COUNT(*) FROM admin_settings")->fetchColumn();
    if ($existing == 0) {
        $db->exec("INSERT INTO admin_settings (key, value) VALUES ('backup_auto_clean_days', '0')");
        $db->exec("INSERT INTO admin_settings (key, value) VALUES ('backup_max_count', '15')");
    }


    // ========================
    // 创建索引
    // ========================
    $db->exec("CREATE INDEX IF NOT EXISTS idx_tasks_category       ON tasks(category_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_tasks_due_datetime    ON tasks(due_datetime)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_tasks_completed       ON tasks(is_completed)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_tasks_priority        ON tasks(priority)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_tasks_user            ON tasks(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_tasks_deleted        ON tasks(is_deleted)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_categories_user       ON categories(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_user_settings_uid     ON user_settings(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_tags_user             ON tags(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_task_tags_task        ON task_tags(task_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_task_tags_tag         ON task_tags(tag_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pomodoro_user         ON pomodoro_sessions(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pomodoro_task         ON pomodoro_sessions(task_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_attachments_task      ON task_attachments(task_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_attachments_user      ON task_attachments(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_tasks_parent          ON tasks(parent_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_habits_user           ON habits(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_habit_logs_habit      ON habit_logs(habit_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_habit_logs_date       ON habit_logs(check_date)");

    // ========================
    // 插入默认分类（仅在分类表为空时）
    // ========================
    // 注意：默认分类的 user_id=0 用于未登录用户的公共分类，
    // 插入时需临时关闭外键检查，因为 users 表中不存在 id=0 的记录
    $count = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($count == 0) {
        $defaultCategories = [
            ['name' => '收集箱', 'color' => '#95A5A6', 'sort_order' => 0],
            ['name' => '工作',   'color' => '#4A90D9', 'sort_order' => 1],
            ['name' => '生活',   'color' => '#7ED321', 'sort_order' => 2],
            ['name' => '学习',   'color' => '#F5A623', 'sort_order' => 3],
        ];
        $db->exec('PRAGMA foreign_keys=OFF');
        $stmt = $db->prepare("INSERT INTO categories (name, color, sort_order) VALUES (:name, :color, :sort_order)");
        foreach ($defaultCategories as $cat) {
            $stmt->execute($cat);
        }
        $db->exec('PRAGMA foreign_keys=ON');
    }
}

// -------------------- 用户认证函数 --------------------

function getCurrentUserId() {
    return intval($_SESSION['user_id'] ?? 0);
}

function getCurrentUsername() {
    return $_SESSION['username'] ?? '';
}

function requireAuth() {
    if (getCurrentUserId() <= 0) {
        jsonResponse(null, 401, '请先登录');
    }
}

// -------------------- 管理员辅助函数 --------------------

function isAdmin() {
    $userId = getCurrentUserId();
    if ($userId <= 0) return false;
    global $config;
    $db = getDB($config);
    $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && $row['role'] === 'admin';
}

function requireAdmin() {
    if (!isAdmin()) {
        jsonResponse(null, 403, '需要管理员权限');
    }
}

function recordLoginHistory($db, $userId) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("INSERT INTO login_history (user_id, login_at, ip_address) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $now, $ip]);
    $db->exec("UPDATE users SET last_login_at = '{$now}', login_count = login_count + 1 WHERE id = {$userId}");
}

function getAdminSetting($db, $key, $default = '') {
    $stmt = $db->prepare("SELECT value FROM admin_settings WHERE key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['value'] : $default;
}

function setAdminSetting($db, $key, $value) {
    $stmt = $db->prepare("INSERT OR REPLACE INTO admin_settings (key, value) VALUES (?, ?)");
    $stmt->execute([$key, $value]);
}

function createDefaultCategoriesForUser($db, $userId) {
    $defaults = [
        ['name' => '收集箱', 'color' => '#95A5A6', 'sort_order' => 0],
        ['name' => '工作',   'color' => '#4A90D9', 'sort_order' => 1],
        ['name' => '生活',   'color' => '#7ED321', 'sort_order' => 2],
        ['name' => '学习',   'color' => '#F5A623', 'sort_order' => 3],
    ];
    $stmt = $db->prepare("INSERT INTO categories (user_id, name, color, sort_order) VALUES (:user_id, :name, :color, :sort_order)");
    foreach ($defaults as $cat) {
        $cat['user_id'] = $userId;
        $stmt->execute($cat);
    }
}

// -------------------- SMTP 邮件发送（纯 PHP 实现） --------------------

function sendMailSMTP($smtp, $to, $subject, $body) {
    $host       = $smtp['host']       ?? '';
    $port       = intval($smtp['port'] ?? 587);
    $username   = $smtp['username']   ?? '';
    $password   = $smtp['password']   ?? '';
    $encryption = $smtp['encryption']  ?? 'tls';
    $fromName   = '任务管理系统';

    if (empty($host) || empty($username) || empty($password)) {
        return ['success' => false, 'message' => 'SMTP 配置不完整'];
    }
    if (empty($to)) {
        return ['success' => false, 'message' => '收件人地址为空'];
    }

    $timeout = 15;

    try {
        if ($encryption === 'ssl') {
            $socket = @fsockopen('ssl://' . $host, $port, $errno, $errstr, $timeout);
        } else {
            $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        }

        if (!$socket) {
            return ['success' => false, 'message' => "SMTP 连接失败: {$errstr} ({$errno})"];
        }

        $readLine = function() use ($socket) {
            $line = '';
            while ($chunk = @fgets($socket, 515)) {
                $line .= $chunk;
                if (isset($chunk[3]) && $chunk[3] === ' ') break;
            }
            return $line;
        };

        $sendCmd = function($cmd, $expectedCode) use ($socket, $readLine) {
            @fwrite($socket, $cmd . "\r\n");
            $response = $readLine();
            $code = intval(substr($response, 0, 3));
            if ($code !== $expectedCode) {
                throw new Exception("SMTP 命令失败 [{$cmd}]: {$response}");
            }
            return $response;
        };

        $readLine();
        $sendCmd('EHLO localhost', 250);

        if ($encryption === 'tls') {
            $sendCmd('STARTTLS', 220);
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return ['success' => false, 'message' => 'TLS 加密升级失败'];
            }
            $sendCmd('EHLO localhost', 250);
        }

        $sendCmd('AUTH LOGIN', 334);
        $sendCmd(base64_encode($username), 334);
        $sendCmd(base64_encode($password), 235);

        $sendCmd("MAIL FROM:<{$username}>", 250);
        $sendCmd("RCPT TO:<{$to}>", 250);

        $sendCmd('DATA', 354);

        $headers  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$username}>\r\n";
        $headers .= "To: <{$to}>\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "X-Mailer: TodoList-PHP-SMTP\r\n";

        $message = $headers . "\r\n" . chunk_split(base64_encode($body));

        @fwrite($socket, $message . "\r\n.\r\n");
        $sendResponse = $readLine();
        $sendCode = intval(substr($sendResponse, 0, 3));
        if ($sendCode !== 250) {
            return ['success' => false, 'message' => "邮件发送被拒绝: {$sendResponse}"];
        }

        @fwrite($socket, "QUIT\r\n");
        @fclose($socket);

        return ['success' => true, 'message' => '邮件发送成功'];

    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) {
            @fclose($socket);
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// -------------------- 数据加密（SMTP 密码等敏感信息） --------------------

/**
 * AES-256-CBC 加密敏感数据
 *
 * 加密结果携带 "AES:" 前缀以区分明文数据，兼容旧版明文存储。
 *
 * @param string $data 明文数据
 * @param array  $config 应用配置（需包含 encrypt_key）
 * @return string 加密后的字符串（"AES:" + base64）
 */
function encryptSensitive($data, $config) {
    if (empty($data)) return '';
    $key = hash('sha256', $config['encrypt_key'], true);
    $iv  = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) return $data; // 加密失败则回退明文（不应发生）
    return 'AES:' . base64_encode($iv . $encrypted);
}

/**
 * 解密敏感数据
 *
 * 自动检测 "AES:" 前缀，若无则按明文返回（兼容旧数据）。
 *
 * @param string $data 加密或明文数据
 * @param array  $config 应用配置
 * @return string 明文数据
 */
function decryptSensitive($data, $config) {
    if (empty($data)) return '';
    // 兼容旧版明文存储（没有 "AES:" 前缀的视为明文）
    if (substr($data, 0, 4) !== 'AES:') return $data;
    $key  = hash('sha256', $config['encrypt_key'], true);
    $data = base64_decode(substr($data, 4));
    if ($data === false || strlen($data) < 16) return '';
    $iv        = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    $result    = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return ($result === false) ? '' : $result;
}

// -------------------- 数据库自动备份 --------------------

/**
 * 每日自动备份数据库（惰性触发）
 *
 * 在每次 API 请求时调用，检查今天是否已备份：
 *   - 已备份 → 直接返回
 *   - 未备份 → 执行 SQLite 在线备份，保留最近 N 份（默认 15）
 *
 * 备份文件命名：todolist_backup_YYYY-MM-DD_HHmmss.db
 * 同一天多次调用只执行一次（以日期判断）。
 *
 * @param array $config 应用配置（需包含 db_path / backup_path / backup_max）
 */
function autoBackupDaily($config) {
    $dbPath     = $config['db_path'];
    $backupDir  = rtrim($config['backup_path'] ?? (dirname($dbPath) . '/backups/'), '/') . '/';
    $maxCopies  = intval($config['backup_max'] ?? 15);

    if (!file_exists($dbPath)) return;

    // ---- 检查今天是否已有有效备份（过滤掉 0 字节的无效备份） ----
    $todayPattern = $backupDir . 'todolist_backup_' . date('Y-m-d') . '_*.db';
    $todayBackups = glob($todayPattern);
    if (!empty($todayBackups)) {
        $hasValid = false;
        foreach ($todayBackups as $f) {
            $sz = @filesize($f);
            if ($sz > 0) { $hasValid = true; break; }
            @unlink($f); // 删除无效的 0 字节假备份
        }
        if ($hasValid) return;  // 今天已有有效备份
    }

    // ---- 创建备份目录 ----
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
        if (!is_dir($backupDir)) return;
    }

    $timestamp  = date('Y-m-d_His');
    $backupFile = $backupDir . 'todolist_backup_' . $timestamp . '.db';

    try {
        // ---- 通过 PDO 将 WAL 日志写入主数据库文件 ----
        global $db;
        if ($db instanceof PDO) {
            try { $db->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Exception $e) {}
        }

        // ---- 直接复制数据库文件（简单可靠，不依赖 SQLite3 扩展） ----
        $copied = @copy($dbPath, $backupFile);

        // copy() 失败则回退到 SQLite3::backup()
        if (!$copied && class_exists('SQLite3')) {
            $src = new SQLite3($dbPath);
            $src->exec('PRAGMA wal_checkpoint(FULL)');
            $dst = new SQLite3($backupFile);
            if ($src->backup($dst)) {
                $copied = true;
            }
            $src->close();
            $dst->close();
        }

        // ---- 验证备份文件是否有效 ----
        $backupSize = @filesize($backupFile);
        if (!$copied || $backupSize === false || $backupSize <= 0) {
            @unlink($backupFile);
            if (function_exists('writeLog')) {
                writeLog("自动备份失败: 备份文件大小为 0 或复制失败", [], $config);
            }
            return;
        }

        // ---- 清理旧备份（优先使用后台管理设置） ----
        $adminDb = getDB($config);
        $adminMaxCount = intval(getAdminSetting($adminDb, 'backup_max_count', strval($maxCopies)));
        $adminCleanDays = intval(getAdminSetting($adminDb, 'backup_auto_clean_days', '0'));

        $files = glob($backupDir . 'todolist_backup_*.db');
        if ($files === false) $files = [];
        rsort($files);

        // 按天数清理
        if ($adminCleanDays > 0) {
            $cutoff = time() - $adminCleanDays * 86400;
            $files = array_values(array_filter($files, function($f) use ($cutoff) {
                if (filemtime($f) < $cutoff) { @unlink($f); return false; }
                return true;
            }));
            rsort($files);
        }
        // 按数量清理
        foreach ($files as $i => $file) {
            if ($i >= $adminMaxCount) @unlink($file);
        }

    } catch (Throwable $e) {
        // 备份静默失败，不影响正常请求
        @unlink($backupFile); // 清理可能产生的空文件
        if (function_exists('writeLog')) {
            writeLog("自动备份失败: " . $e->getMessage(), [], $config);
        }
    }
}

// -------------------- 工具函数 --------------------

function jsonResponse($data = null, $code = 200, $message = '') {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');

    $response = [
        'success' => $code >= 200 && $code < 300,
        'message' => $message,
        'data'    => $data,
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function writeLog($action, $context = [], $config = []) {
    $logPath = $config['log_path'] ?? __DIR__ . '/data/app.log';
    $logDir  = dirname($logPath);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $time    = date('Y-m-d H:i:s');
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userId  = $_SESSION['user_id'] ?? 'guest';
    $context = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';

    $line = "[{$time}] [{$ip}] [user:{$userId}] {$action} {$context}" . PHP_EOL;
    file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

function getJsonInput() {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// -------------------- 初始化执行 --------------------

try {
    $db = getDB($config);
    initDatabase($db);
} catch (Throwable $e) {
    // 初始化失败时输出详细错误信息，而不是白屏
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>初始化错误</title>';
        echo '<style>body{font-family:sans-serif;padding:40px;background:#1a1a2e;color:#eee}';
        echo '.err{background:#2d2d44;padding:20px;border-radius:8px;border-left:4px solid #e74c3c}';
        echo 'code{color:#f39c12;word-break:break-all}</style></head><body>';
        echo '<h1>系统初始化失败</h1><div class="err"><h3>'
             . htmlspecialchars(get_class($e)) . '</h3>';
        echo '<p>' . nl2br(htmlspecialchars($e->getMessage())) . '</p>';
        echo '<p><small>文件：<code>' . htmlspecialchars($e->getFile()) . '</code> 第 '
             . $e->getLine() . ' 行</small></p>';
        echo '<details><summary>调用栈</summary><pre><code>'
             . htmlspecialchars($e->getTraceAsString()) . '</code></pre></details>';
        echo '</div></body></html>';
    }
    // 始终记录到日志
    error_log('[' . date('Y-m-d H:i:s') . '] FATAL: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
}
