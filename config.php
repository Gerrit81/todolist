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
 *   7. 自动数据库迁移（v1→v2→v3）
 *
 * @version  1.0.0
 * @date     2026-07-18
 * =============================================================================
 */

// -------------------- 调试模式：显示所有错误 --------------------
// 遇到 500 错误时取消下面三行的注释，访问页面即可看到具体错误信息
// error_reporting(E_ALL);
// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');

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
    session_start();
}

// -------------------- 应用配置 --------------------

$config = [
    // 应用基本信息
    'app_name'    => '任务管理系统',
    'app_version' => '1.0.0',

    // SQLite 数据库文件路径（存放在 data 目录下，确保该目录可写）
    'db_path'     => __DIR__ . '/data/todolist.db',

    // 日志文件路径
    'log_path'    => __DIR__ . '/data/app.log',
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

$db = getDB($config);
initDatabase($db);
