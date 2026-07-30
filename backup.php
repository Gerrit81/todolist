<?php
/**
 * 数据库备份脚本
 * 
 * 用途：对 SQLite 数据库执行快照备份，自动清理旧备份（最多保留 15 份）。
 * 
 * 触发方式：
 *   1. CLI 直接执行：          php backup.php
 *   2. 通过 crontab 每天执行：  0 3 * * * php /path/to/backup.php
 *   3. HTTP 调用（需令牌）：    GET /backup.php?token=YOUR_SECURE_TOKEN
 * 
 * @version 2.2.8
 */

require_once __DIR__ . '/config.php';

// ==================== 备份配置 ====================

define('BACKUP_DIR',     __DIR__ . '/data/backups/');
define('BACKUP_PREFIX',  'todolist_backup_');
define('BACKUP_MAX',     15);
define('HTTP_TOKEN',     '');  // HTTP 触发令牌（留空禁用 HTTP 触发；设为随机字符串以启用）

// ==================== 判断运行模式 ====================

$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    // HTTP 模式：检查令牌
    if (empty(HTTP_TOKEN) || (($_GET['token'] ?? '') !== HTTP_TOKEN)) {
        header('HTTP/1.1 403 Forbidden');
        die('Forbidden: invalid or missing token. Set HTTP_TOKEN in backup.php to enable HTTP trigger.');
    }
}

// ==================== 执行备份 ====================

try {
    $result = performBackup($config);
    
    $msg = sprintf(
        "[%s] %s | DB=%s | Size=%s | Retained=%d | %s",
        date('Y-m-d H:i:s'),
        $result['success'] ? 'OK' : 'FAIL',
        basename($config['db_path']),
        $result['size'] ?? 'N/A',
        $result['retained'] ?? 0,
        $result['file'] ?? ''
    );
    
    echo $msg . PHP_EOL;
    
    exit($result['success'] ? 0 : 1);

} catch (Exception $e) {
    $msg = '[' . date('Y-m-d H:i:s') . '] FAIL | ' . $e->getMessage();
    echo $msg . PHP_EOL;
    exit(1);
}

// ==================== 核心备份函数 ====================

/**
 * 执行一次数据库备份 + 清理旧备份
 *
 * @param array $config 应用配置（需包含 db_path）
 * @return array ['success' => bool, 'file' => string, 'size' => string, 'retained' => int]
 */
function performBackup($config) {
    $sourceFile  = $config['db_path'];
    $backupDir   = BACKUP_DIR;
    $maxCopies   = BACKUP_MAX;
    $prefix      = BACKUP_PREFIX;

    // ---- 检查源数据库是否存在 ----
    if (!file_exists($sourceFile)) {
        throw new RuntimeException("数据库文件不存在: {$sourceFile}");
    }

    // ---- 创建备份目录 ----
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0755, true)) {
            throw new RuntimeException("无法创建备份目录: {$backupDir}");
        }
    }

    // ---- 生成备份文件名 ----
    $timestamp  = date('Y-m-d_His');
    $backupFile = $backupDir . $prefix . $timestamp . '.db';

    // ---- 先将 WAL 日志写入主数据库文件 ----
    walCheckpoint($sourceFile);

    // ---- 直接复制数据库文件（简单可靠，不依赖 SQLite3 扩展） ----
    if (!@copy($sourceFile, $backupFile)) {
        // copy() 失败，回退到 SQLite3::backup() API（如果可用）
        if (class_exists('SQLite3')) {
            $src = new SQLite3($sourceFile);
            $src->exec('PRAGMA wal_checkpoint(FULL)');
            $dst = new SQLite3($backupFile);
            if (!$src->backup($dst)) {
                $src->close();
                $dst->close();
                @unlink($backupFile);
                throw new RuntimeException("数据库备份失败: SQLite3::backup() 返回 false");
            }
            $src->close();
            $dst->close();
        } else {
            throw new RuntimeException("数据库备份失败: copy() 和 SQLite3 均不可用");
        }
    }

    // ---- 验证备份文件 ----
    $backupSize = filesize($backupFile);
    if ($backupSize === false || $backupSize <= 0) {
        @unlink($backupFile);
        throw new RuntimeException("备份文件大小为 0，备份失败");
    }

    // ---- 清理旧备份（保留最新 N 份） ----
    $files = glob($backupDir . $prefix . '*.db');
    if ($files === false) $files = [];

    // 按文件名（含时间戳）倒序排列，最新的在前
    rsort($files);

    $deletedCount = 0;
    foreach ($files as $i => $file) {
        if ($i >= $maxCopies) {
            @unlink($file);
            $deletedCount++;
        }
    }

    $retainedCount = min(count($files), $maxCopies);
    $fileSize      = formatBytes($backupSize);

    return [
        'success'  => true,
        'file'     => basename($backupFile),
        'size'     => $fileSize,
        'retained' => $retainedCount,
        'deleted'  => $deletedCount,
    ];
}

/**
 * 执行 WAL checkpoint，将 WAL 日志写入主数据库文件
 * 优先使用 PDO，回退到 SQLite3，再不济直接尝试 PDO 连接
 */
function walCheckpoint($dbPath) {
    // 尝试通过已有的 PDO 全局连接
    global $db;
    if ($db instanceof PDO) {
        try {
            $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            return;
        } catch (Exception $e) { /* 继续尝试其他方式 */ }
    }

    // 回退：打开临时 PDO 连接执行 checkpoint
    try {
        $tmpDb = new PDO('sqlite:' . $dbPath);
        $tmpDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tmpDb->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $tmpDb = null;  // 关闭连接
    } catch (Exception $e) {
        // 最后尝试 SQLite3 扩展
        if (class_exists('SQLite3')) {
            $s = new SQLite3($dbPath);
            $s->exec('PRAGMA wal_checkpoint(FULL)');
            $s->close();
        }
    }
}

/**
 * 格式化字节数为人类可读的大小
 */
function formatBytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
