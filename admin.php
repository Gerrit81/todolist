<?php
/**
 * =============================================================================
 * 任务管理系统 - 后台管理面板
 * =============================================================================
 * 
 * 功能：
 *   1. 仪表盘 - 总览统计、最近登录记录
 *   2. 用户管理 - 用户列表、登录历史、角色管理
 *   3. 密码重置 - 生成带时效的密码重置链接
 *   4. 备份管理 - 查看/创建/删除/下载备份、自动清理设置
 *   5. 系统信息 - PHP/数据库/磁盘概况
 *
 * @version 3.0.0
 * @date    2026-07-20
 * =============================================================================
 */

require_once __DIR__ . '/config.php';

// ──────────── 处理 AJAX API 请求 ────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // 所有 API 操作均需管理员权限
    if (!isAdmin()) {
        jsonResponse(null, 403, '需要管理员权限');
    }

    $action = $_GET['action'];

    switch ($action) {

        // ── 仪表盘数据 ──
        case 'admin_stats':
            $totalUsers    = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $totalTasks    = $db->query("SELECT COUNT(*) FROM tasks WHERE is_deleted = 0")->fetchColumn();
            $dbSize        = filesize($config['db_path']) ?: 0;
            $backupDir     = rtrim($config['backup_path'], '/') . '/';
            $backupFiles   = is_dir($backupDir) ? glob($backupDir . 'todolist_backup_*.db') : [];
            $backupCount   = count($backupFiles ?: []);
            $lastBackup    = '';
            if (!empty($backupFiles)) {
                rsort($backupFiles);
                $lastBackup = date('Y-m-d H:i:s', filemtime($backupFiles[0]));
            }

            $recentLogins = $db->query("
                SELECT lh.login_at, lh.ip_address, u.username
                FROM login_history lh
                JOIN users u ON u.id = lh.user_id
                ORDER BY lh.login_at DESC LIMIT 10
            ")->fetchAll();

            jsonResponse([
                'total_users'   => (int)$totalUsers,
                'total_tasks'   => (int)$totalTasks,
                'db_size'       => (int)$dbSize,
                'backup_count'  => $backupCount,
                'last_backup'   => $lastBackup,
                'recent_logins' => $recentLogins,
            ], 200, 'ok');
            break;

        // ── 用户列表 ──
        case 'list_users':
            $users = $db->query("
                SELECT id, username, email, role, login_count, last_login_at, created_at
                FROM users ORDER BY id ASC
            ")->fetchAll();
            jsonResponse($users, 200, 'ok');
            break;

        // ── 用户登录历史 ──
        case 'user_login_history':
            $uid = intval($_GET['user_id'] ?? 0);
            if ($uid <= 0) jsonResponse(null, 400, '缺少 user_id');
            $history = $db->query("
                SELECT login_at, ip_address FROM login_history
                WHERE user_id = {$uid} ORDER BY login_at DESC LIMIT 100
            ")->fetchAll();
            jsonResponse($history, 200, 'ok');
            break;

        // ── 切换用户管理员角色 ──
        case 'toggle_admin':
            $uid = intval($_GET['user_id'] ?? 0);
            if ($uid <= 0) jsonResponse(null, 400, '缺少 user_id');
            $currentRole = $db->query("SELECT role FROM users WHERE id = {$uid}")->fetchColumn();
            if ($currentRole === false) jsonResponse(null, 404, '用户不存在');

            $myId = getCurrentUserId();
            if ($uid == $myId && $currentRole === 'admin') {
                jsonResponse(null, 400, '不能撤销自己的管理员权限');
            }

            $newRole = ($currentRole === 'admin') ? 'user' : 'admin';
            $db->exec("UPDATE users SET role = '{$newRole}' WHERE id = {$uid}");
            writeLog("管理员角色变更: user_id={$uid} → {$newRole}", ['operator' => getCurrentUserId()], $config);
            jsonResponse(['user_id' => $uid, 'role' => $newRole], 200, '已更新角色');
            break;

        // ── 删除用户 ──
        case 'delete_user':
            $uid = intval($_GET['user_id'] ?? 0);
            if ($uid <= 0) jsonResponse(null, 400, '缺少 user_id');
            $myId = getCurrentUserId();
            if ($uid == $myId) jsonResponse(null, 400, '不能删除自己');

            $target = $db->query("SELECT username FROM users WHERE id = {$uid}")->fetch();
            if (!$target) jsonResponse(null, 404, '用户不存在');

            // 级联删除由外键处理
            $db->exec("DELETE FROM users WHERE id = {$uid}");
            writeLog("管理员删除用户: {$target['username']} (id={$uid})", ['operator' => $myId], $config);
            jsonResponse(['user_id' => $uid], 200, "已删除用户 {$target['username']}");
            break;

        // ── 生成密码重置令牌 ──
        case 'generate_reset_token':
            $uid    = intval($_GET['user_id'] ?? 0);
            $expiry = intval($_GET['expiry_minutes'] ?? 10);
            if ($uid <= 0) jsonResponse(null, 400, '请选择用户');
            if (!in_array($expiry, [10, 30, 180, 480])) jsonResponse(null, 400, '无效的过期时间');

            $user = $db->query("SELECT username, email FROM users WHERE id = {$uid}")->fetch();
            if (!$user) jsonResponse(null, 404, '用户不存在');

            $token     = bin2hex(random_bytes(32));
            $now       = date('Y-m-d H:i:s');
            $expiresAt = date('Y-m-d H:i:s', time() + $expiry * 60);

            $stmt = $db->prepare("INSERT INTO password_resets (user_id, token, expires_at, used, created_at) VALUES (?, ?, ?, 0, ?)");
            $stmt->execute([$uid, $token, $expiresAt, $now]);

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $baseURL  = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $link     = "{$protocol}://{$host}{$baseURL}/reset_password.php?token={$token}";

            writeLog("生成密码重置链接: user={$user['username']} (id={$uid}), 有效期{$expiry}分钟", [], $config);
            jsonResponse([
                'token'      => $token,
                'reset_link' => $link,
                'username'   => $user['username'],
                'email'      => $user['email'],
                'expires_at' => $expiresAt,
                'expiry_min' => $expiry,
            ], 200, '已生成重置链接');
            break;

        // ── 撤销密码重置令牌 ──
        case 'revoke_reset_token':
            $tid = intval($_GET['token_id'] ?? 0);
            if ($tid <= 0) jsonResponse(null, 400, '缺少 token_id');
            $db->exec("UPDATE password_resets SET used = 1 WHERE id = {$tid} AND used = 0");
            jsonResponse(null, 200, '已撤销');
            break;

        // ── 重置令牌列表 ──
        case 'list_reset_tokens':
            $tokens = $db->query("
                SELECT pr.id, pr.token, pr.expires_at, pr.used, pr.created_at, u.username
                FROM password_resets pr
                JOIN users u ON u.id = pr.user_id
                ORDER BY pr.created_at DESC LIMIT 50
            ")->fetchAll();
            jsonResponse($tokens, 200, 'ok');
            break;

        // ── 备份列表 ──
        case 'list_backups':
            $backupDir = rtrim($config['backup_path'], '/') . '/';
            $files = [];
            if (is_dir($backupDir)) {
                $list = glob($backupDir . 'todolist_backup_*.db');
                if ($list) {
                    rsort($list);
                    foreach ($list as $f) {
                        $files[] = [
                            'filename' => basename($f),
                            'size'     => filesize($f),
                            'mtime'    => date('Y-m-d H:i:s', filemtime($f)),
                        ];
                    }
                }
            }
            $autoCleanDays = getAdminSetting($db, 'backup_auto_clean_days', '0');
            $maxCount      = getAdminSetting($db, 'backup_max_count', '15');
            jsonResponse([
                'backups'            => $files,
                'backup_dir'         => $backupDir,
                'auto_clean_days'    => $autoCleanDays,
                'max_count'          => $maxCount,
                'config_backup_max'  => $config['backup_max'],
            ], 200, 'ok');
            break;

        // ── 创建备份 ──
        case 'create_backup':
            $backupDir = rtrim($config['backup_path'], '/') . '/';
            if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

            $timestamp  = date('Y-m-d_His');
            $backupFile = $backupDir . 'todolist_backup_' . $timestamp . '.db';

            try {
                $src = new SQLite3($config['db_path']);
                $src->exec('PRAGMA journal_mode=WAL');
                $src->exec('PRAGMA wal_checkpoint(FULL)');
                $dst = new SQLite3($backupFile);
                $src->backup($dst);
                $src->close();
                $dst->close();
                writeLog("管理员手动备份: {$backupFile}", [], $config);
                jsonResponse(['filename' => basename($backupFile), 'size' => filesize($backupFile)], 200, '备份成功');
            } catch (Exception $e) {
                jsonResponse(null, 500, '备份失败: ' . $e->getMessage());
            }
            break;

        // ── 删除备份 ──
        case 'delete_backup':
            $filename = basename($_GET['file'] ?? '');
            if (empty($filename)) jsonResponse(null, 400, '缺少文件名');
            $backupDir  = rtrim($config['backup_path'], '/') . '/';
            $targetFile = $backupDir . $filename;
            if (!file_exists($targetFile)) jsonResponse(null, 404, '文件不存在');
            if (!preg_match('/^todolist_backup_.*\.db$/', $filename)) jsonResponse(null, 400, '非法文件名');

            unlink($targetFile);
            writeLog("管理员删除备份: {$filename}", [], $config);
            jsonResponse(null, 200, '已删除');
            break;

        // ── 下载备份 ──
        case 'download_backup':
            $filename = basename($_GET['file'] ?? '');
            if (empty($filename)) jsonResponse(null, 400, '缺少文件名');
            $backupDir  = rtrim($config['backup_path'], '/') . '/';
            $targetFile = $backupDir . $filename;
            if (!file_exists($targetFile)) jsonResponse(null, 404, '文件不存在');
            if (!preg_match('/^todolist_backup_.*\.db$/', $filename)) jsonResponse(null, 400, '非法文件名');

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($targetFile));
            readfile($targetFile);
            exit;

        // ── 获取管理设置 ──
        case 'get_admin_settings':
            $cleanDays = getAdminSetting($db, 'backup_auto_clean_days', '0');
            $maxCount  = getAdminSetting($db, 'backup_max_count', '15');
            jsonResponse([
                'backup_auto_clean_days' => $cleanDays,
                'backup_max_count'       => $maxCount,
            ], 200, 'ok');
            break;

        // ── 保存管理设置 ──
        case 'save_admin_settings':
            $input     = getJsonInput();
            $cleanDays = intval($input['backup_auto_clean_days'] ?? 0);
            $maxCount  = intval($input['backup_max_count'] ?? 15);
            if ($maxCount < 1) $maxCount = 1;

            setAdminSetting($db, 'backup_auto_clean_days', (string)$cleanDays);
            setAdminSetting($db, 'backup_max_count', (string)$maxCount);
            writeLog("管理员更新备份设置: auto_clean={$cleanDays}d, max={$maxCount}", [], $config);
            jsonResponse(null, 200, '设置已保存');
            break;

        // ── 系统信息 ──
        case 'system_info':
            $dbPath    = $config['db_path'];
            $dbSize    = file_exists($dbPath) ? filesize($dbPath) : 0;
            $backupDir = rtrim($config['backup_path'], '/') . '/';
            $diskFree  = function_exists('disk_free_space') ? @disk_free_space($backupDir) : 0;
            $diskTotal = function_exists('disk_total_space') ? @disk_total_space($backupDir) : 0;

            $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll();

            jsonResponse([
                'php_version'    => PHP_VERSION,
                'server_os'      => PHP_OS,
                'server_software'=> $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'db_path'        => $dbPath,
                'db_size'        => $dbSize,
                'backup_dir'     => $backupDir,
                'disk_free'      => $diskFree,
                'disk_total'     => $diskTotal,
                'app_version'    => $config['app_version'],
                'table_count'    => count($tables),
                'tables'         => array_column($tables, 'name'),
                'session_path'   => session_save_path(),
                'memory_limit'   => ini_get('memory_limit'),
                'max_exec_time'  => ini_get('max_execution_time'),
                'upload_max'     => ini_get('upload_max_filesize'),
            ], 200, 'ok');
            break;

        // ── 清理过期备份（按设置） ──
        case 'clean_backups':
            $backupDir = rtrim($config['backup_path'], '/') . '/';
            $cleanDays = intval(getAdminSetting($db, 'backup_auto_clean_days', '0'));
            $maxCount  = intval(getAdminSetting($db, 'backup_max_count', '15'));
            $deleted   = 0;

            if (is_dir($backupDir)) {
                $files = glob($backupDir . 'todolist_backup_*.db');
                if ($files) {
                    // 按天数清理
                    if ($cleanDays > 0) {
                        $cutoff = time() - $cleanDays * 86400;
                        foreach ($files as $f) {
                            if (filemtime($f) < $cutoff) {
                                @unlink($f);
                                $deleted++;
                            }
                        }
                    }
                    // 按数量清理（重新获取文件列表）
                    $remaining = glob($backupDir . 'todolist_backup_*.db');
                    if (count($remaining) > $maxCount) {
                        // 按修改时间排序，删除最旧的
                        usort($remaining, function($a, $b) { return filemtime($b) - filemtime($a); });
                        for ($i = $maxCount; $i < count($remaining); $i++) {
                            @unlink($remaining[$i]);
                            $deleted++;
                        }
                    }
                }
            }
            writeLog("管理员手动清理备份: 删除{$deleted}份", [], $config);
            jsonResponse(['deleted' => $deleted], 200, "已清理 {$deleted} 份备份");
            break;

        default:
            jsonResponse(null, 400, '未知操作: ' . $action);
    }
    exit;
}

// ──────────── 页面访问鉴权 ────────────
$isLoggedIn = getCurrentUserId() > 0;
$currentUser = getCurrentUsername();

// 处理登录表单提交
$loginError = '';
if (!$isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 登录逻辑由 api.php 处理，这里只做初始渲染
    // 实际登录通过前端 AJAX 提交到 api.php
}

?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>后台管理 - 任务管理系统</title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="css/style.css?v=<?php echo $config['app_version']; ?>">
<style>
/* ═══════════════════════════════════════════════════════
   后台管理样式 — Glassmorphism UI
   ═══════════════════════════════════════════════════════ */

/* ── 基础 ── */
*,*::before,*::after{box-sizing:border-box}
.admin-body{display:flex;flex-direction:column;height:100vh;overflow:hidden}
.admin-main{flex:1;overflow:auto;position:relative;padding:24px 0}
.admin-wrap{max-width:1240px;margin:0 auto;padding:24px;position:relative;z-index:1;border:1px solid var(--border);border-radius:16px;background:var(--glass);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:var(--shadow);margin-top:0;margin-bottom:0}

/* ── 背景装饰 ── */
.admin-bg{position:fixed;inset:0;z-index:0;overflow:hidden;pointer-events:none}
.admin-bg .orb{position:absolute;border-radius:50%;filter:blur(80px);opacity:.15;animation:orbFloat 20s ease-in-out infinite}
.admin-bg .orb:nth-child(1){width:400px;height:400px;background:var(--primary);top:-10%;right:-5%;animation-delay:0s}
.admin-bg .orb:nth-child(2){width:300px;height:300px;background:var(--info);bottom:-8%;left:-5%;animation-delay:-7s;animation-duration:24s}
.admin-bg .orb:nth-child(3){width:250px;height:250px;background:var(--success);top:40%;left:50%;animation-delay:-14s;animation-duration:18s;opacity:.08}
@keyframes orbFloat{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(30px,-20px) scale(1.05)}66%{transform:translate(-20px,15px) scale(.95)}}

/* ── 管理员登录页 ── */
.admin-login-page{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 50%,#a78bfa 100%);z-index:999;overflow:hidden}
.admin-login-page::before{content:'';position:absolute;width:600px;height:600px;border-radius:50%;background:rgba(167,139,250,.25);filter:blur(120px);top:-20%;left:-10%;animation:loginOrb 8s ease-in-out infinite alternate}
.admin-login-page::after{content:'';position:absolute;width:500px;height:500px;border-radius:50%;background:rgba(99,102,241,.2);filter:blur(100px);bottom:-15%;right:-8%;animation:loginOrb 10s ease-in-out infinite alternate-reverse}
@keyframes loginOrb{from{transform:translate(0,0) scale(1)}to{transform:translate(40px,-30px) scale(1.1)}}
.admin-login-card{position:relative;z-index:1;background:rgba(255,255,255,.18);backdrop-filter:blur(28px);-webkit-backdrop-filter:blur(28px);border:1px solid rgba(255,255,255,.3);border-radius:20px;padding:40px 36px;width:400px;max-width:92vw;box-shadow:0 8px 32px rgba(0,0,0,.1),0 2px 8px rgba(0,0,0,.06),inset 0 1px 0 rgba(255,255,255,.4)}
.admin-login-card h2{margin:0 0 4px;font-size:24px;color:#fff;text-align:center;font-weight:700;text-shadow:0 1px 4px rgba(0,0,0,.15)}
.admin-login-card .sub{text-align:center;color:rgba(255,255,255,.75);font-size:14px;margin-bottom:28px}
.admin-login-card .field{position:relative;margin-bottom:14px}
.admin-login-card .field .icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:16px;color:rgba(255,255,255,.5);pointer-events:none;z-index:1}
.admin-login-card input{width:100%;box-sizing:border-box;padding:12px 14px 12px 40px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);border-radius:12px;font-size:14px;color:#fff;outline:none;transition:all .25s}
.admin-login-card input::placeholder{color:rgba(255,255,255,.45)}
.admin-login-card input:focus{border-color:rgba(255,255,255,.55);background:rgba(255,255,255,.18);box-shadow:0 0 0 4px rgba(255,255,255,.08)}
.admin-login-card button{width:100%;padding:12px;background:rgba(255,255,255,.22);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:12px;font-size:15px;cursor:pointer;font-weight:700;transition:all .25s;letter-spacing:.5px;margin-top:4px}
.admin-login-card button:hover{background:rgba(255,255,255,.35);transform:translateY(-1px);box-shadow:0 6px 20px rgba(0,0,0,.15)}
.admin-login-card .error{color:#fecaca;font-size:13px;text-align:center;margin-bottom:10px;background:rgba(239,68,68,.2);padding:8px 12px;border-radius:8px;border:1px solid rgba(239,68,68,.3)}
.admin-login-card .back-link{display:block;text-align:center;margin-top:18px;font-size:13px;color:rgba(255,255,255,.65);text-decoration:none;transition:color .2s}
.admin-login-card .back-link:hover{color:#fff}



/* ── Tab 导航 ── */
.admin-tabs{display:flex;gap:6px;margin-bottom:24px;padding:5px;background:var(--glass);border:1px solid var(--border);border-radius:14px;flex-wrap:wrap;box-shadow:var(--shadow)}
.admin-tab{padding:10px 22px;cursor:pointer;font-size:13px;font-weight:500;color:var(--text-light);border-radius:10px;transition:all .25s;background:transparent;border:none;white-space:nowrap}
.admin-tab:hover{color:var(--primary);background:var(--primary-light)}
.admin-tab.active{color:#fff;background:linear-gradient(135deg,var(--primary),var(--primary-dark));box-shadow:0 2px 10px rgba(99,102,241,.3);font-weight:600}

/* ── Panel ── */
.admin-panel{display:none;animation:fadeSlide .3s ease}
.admin-panel.active{display:block}
@keyframes fadeSlide{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* ── Stats 卡片 ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px;margin-bottom:28px}
.stat-card{position:relative;background:var(--glass);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:16px;padding:20px 22px;overflow:hidden;transition:all .3s;box-shadow:var(--shadow)}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg)}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:3px 3px 0 0}
.stat-card:nth-child(1)::before{background:linear-gradient(90deg,#6366f1,#8b5cf6)}
.stat-card:nth-child(2)::before{background:linear-gradient(90deg,#f59e0b,#f97316)}
.stat-card:nth-child(3)::before{background:linear-gradient(90deg,#10b981,#34d399)}
.stat-card:nth-child(4)::before{background:linear-gradient(90deg,#3b82f6,#60a5fa)}
.stat-card:nth-child(5)::before{background:linear-gradient(90deg,#ec4899,#f472b6)}
.stat-card .icon-row{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.stat-card .icon-dot{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px}
.stat-card:nth-child(1) .icon-dot{background:rgba(99,102,241,.12)}
.stat-card:nth-child(2) .icon-dot{background:rgba(245,158,11,.12)}
.stat-card:nth-child(3) .icon-dot{background:rgba(16,185,129,.12)}
.stat-card:nth-child(4) .icon-dot{background:rgba(59,130,246,.12)}
.stat-card:nth-child(5) .icon-dot{background:rgba(236,72,153,.12)}
.stat-card .label{font-size:12px;color:var(--text-muted);font-weight:500;text-transform:uppercase;letter-spacing:.8px}
.stat-card .value{font-size:28px;font-weight:800;color:var(--text);line-height:1.2}
.stat-card .value small{font-size:13px;color:var(--text-light);font-weight:400;margin-left:2px}

/* ── 表格 ── */
.admin-table-wrapper{border-radius:14px;overflow:hidden;border:1px solid var(--border);box-shadow:var(--shadow)}
.admin-table{width:100%;border-collapse:collapse;background:var(--glass);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}
.admin-table th,.admin-table td{padding:12px 16px;text-align:left;font-size:13px;border-bottom:1px solid var(--border)}
.admin-table th{background:rgba(0,0,0,.03);font-weight:700;color:var(--text-light);font-size:11px;text-transform:uppercase;letter-spacing:.6px;white-space:nowrap}
.admin-table tr:last-child td{border-bottom:none}
.admin-table tbody tr{transition:background .15s}
.admin-table tbody tr:hover td{background:rgba(99,102,241,.04)}
.admin-table .empty{text-align:center;color:var(--text-muted);padding:36px;font-size:13px}

/* ── Badges ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:600;letter-spacing:.3px}
.badge-admin{background:linear-gradient(135deg,#fef3c7,#fde68a);color:#92400e;border:1px solid #fcd34d}
.badge-user{background:linear-gradient(135deg,#e0e7ff,#c7d2fe);color:#3730a3;border:1px solid #a5b4fc}
.badge-active{background:linear-gradient(135deg,#d1fae5,#a7f3d0);color:#065f46;border:1px solid #6ee7b7}
.badge-expired{background:linear-gradient(135deg,#fee2e2,#fecaca);color:#991b1b;border:1px solid #fca5a5}
.badge-used{background:linear-gradient(135deg,#f3f4f6,#e5e7eb);color:#4b5563;border:1px solid #d1d5db}

/* ── 按钮 ── */
.btn-xs{padding:5px 12px;font-size:11px;border-radius:8px;border:1px solid var(--border);background:var(--glass);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);color:var(--text);cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:4px;font-weight:500}
.btn-xs:hover{background:var(--sidebar-bg);transform:translateY(-1px);box-shadow:var(--shadow)}
.btn-xs.danger{color:#ef4444;border-color:rgba(239,68,68,.3)}
.btn-xs.danger:hover{background:#ef4444;color:#fff;border-color:#ef4444}
.btn-xs.primary{color:var(--primary);border-color:var(--primary)}
.btn-xs.primary:hover{background:var(--primary);color:#fff}
.btn-sm{padding:10px 22px;font-size:13px;border-radius:10px;border:none;cursor:pointer;font-weight:600;transition:all .25s;letter-spacing:.3px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.btn-sm.primary{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff}
.btn-sm.primary:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(99,102,241,.3)}
.btn-sm.danger{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff}
.btn-sm.danger:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(239,68,68,.3)}

/* ── 密码重置区域 ── */
.token-box{background:var(--glass);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border:1px solid var(--border);border-radius:14px;padding:18px 20px;margin-top:16px;display:none;box-shadow:var(--shadow);animation:fadeSlide .3s ease}
.token-box strong{font-size:14px;color:var(--text)}
.token-box .link-row{display:flex;gap:10px;align-items:center;margin-top:10px}
.token-box input[type="text"]{flex:1;padding:10px 14px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg);color:var(--text);font-family:monospace}
.token-box .meta{font-size:12px;color:var(--text-muted);margin-top:10px;line-height:1.6}
.token-box .meta code{background:var(--sidebar-bg);padding:2px 8px;border-radius:5px;font-size:11px;word-break:break-all}

/* ── 表单 ── */
.form-row{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-bottom:18px}
.form-group-admin{display:flex;flex-direction:column;gap:5px}
.form-group-admin label{font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px}
.form-group-admin select,.form-group-admin input{padding:10px 14px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--glass);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);color:var(--text);outline:none;transition:border .2s,box-shadow .2s}
.form-group-admin select:focus,.form-group-admin input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.form-group-admin select{min-width:170px}
.setting-card{background:var(--glass);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:14px;padding:20px 22px;margin-top:20px;box-shadow:var(--shadow)}
.setting-card h4{margin:0 0 16px;font-size:15px;font-weight:700}
.setting-row{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.setting-row label{font-size:13px;color:var(--text);min-width:180px;font-weight:500}
.setting-row input,.setting-row select{padding:9px 14px;border:1px solid var(--border);border-radius:10px;font-size:13px;width:100px;background:var(--bg);color:var(--text);outline:none;transition:border .2s}
.setting-row input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.setting-row .hint{font-size:12px;color:var(--text-muted)}

/* ── 面板标题 ── */
.panel-title{font-size:16px;font-weight:700;margin:0 0 16px;display:flex;align-items:center;gap:8px}
.panel-title .bar{display:inline-block;width:4px;height:18px;background:var(--primary);border-radius:2px}

/* ── Modal ── */
.admin-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);z-index:999;align-items:center;justify-content:center}
.admin-modal-overlay.show{display:flex}
.admin-modal{background:var(--glass);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid var(--border);border-radius:18px;padding:26px;max-width:620px;width:92vw;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15),0 0 0 1px rgba(255,255,255,.5) inset;animation:modalIn .25s ease}
@keyframes modalIn{from{opacity:0;transform:scale(.95) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
.admin-modal h3{margin:0 0 18px;font-size:17px;font-weight:700}
.admin-modal-close{float:right;background:var(--sidebar-bg);border:1px solid var(--border);border-radius:8px;font-size:18px;cursor:pointer;color:var(--text-muted);padding:4px 10px;line-height:1;transition:all .2s}
.admin-modal-close:hover{background:var(--danger);color:#fff;border-color:var(--danger)}

/* ── Toast ── */
.admin-toast{position:fixed;top:24px;right:24px;z-index:9999;padding:14px 22px;border-radius:12px;color:#fff;font-size:13px;font-weight:600;display:none;box-shadow:0 8px 28px rgba(0,0,0,.18);animation:toastIn .35s ease;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}
.admin-toast.success{background:rgba(5,150,105,.9);border:1px solid rgba(255,255,255,.2)}
.admin-toast.error{background:rgba(220,38,38,.9);border:1px solid rgba(255,255,255,.2)}
@keyframes toastIn{from{transform:translateX(120px);opacity:0}to{transform:translateX(0);opacity:1}}

/* ── 无权限页面 ── */
.no-perm{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;text-align:center;padding:40px 20px;background:linear-gradient(135deg,var(--bg),var(--sidebar-bg))}
.no-perm .perm-icon{font-size:64px;margin-bottom:20px;opacity:.6}
.no-perm h2{color:var(--danger);margin:0 0 10px;font-size:22px}
.no-perm p{color:var(--text-muted);margin:0 0 20px}
.no-perm a{color:var(--primary);text-decoration:none;font-weight:600;padding:10px 24px;border:1px solid var(--primary);border-radius:10px;transition:all .2s}
.no-perm a:hover{background:var(--primary);color:#fff}

/* ── 分区卡片 ── */
.section-card{background:var(--glass);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:16px;padding:22px;margin-bottom:20px;box-shadow:var(--shadow)}
</style>
</head>
<body class="admin-body" data-theme="default">

<!-- 共享页眉 -->
<header class="header">
    <div class="header-inner">
        <div class="header-left">
            <span class="header-logo">后台管理</span>
        </div>
        <div class="header-right">
            <?php if ($isLoggedIn): ?>
                <span class="header-user"><?php echo htmlspecialchars($currentUser); ?><?php if (isAdmin()): ?> <span class="badge badge-admin">管理员</span><?php endif; ?></span>
            <?php endif; ?>
            <a href="index.php" class="header-btn" style="text-decoration:none;">前台</a>
            <?php if ($isLoggedIn && isAdmin()): ?>
                <button class="header-btn" onclick="doLogout()">退出</button>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="admin-main">

<?php if (!$isLoggedIn): ?>
<!-- ─── 管理员登录 ─── -->
<div class="admin-login-page">
  <div class="admin-login-card">
    <h2>🔐 管理员登录</h2>
    <p class="sub">任务管理系统 · 管理员登录</p>
    <div class="error" id="loginError" style="display:none"></div>
    <div class="field"><span class="icon">👤</span><input type="text" id="loginUser" placeholder="用户名" autocomplete="username"></div>
    <div class="field"><span class="icon">🔒</span><input type="password" id="loginPass" placeholder="密码" autocomplete="current-password"></div>
    <button onclick="doLogin()">登 录</button>
    <a class="back-link" href="index.php">← 返回前台</a>
  </div>
</div>

<script>
async function doLogin() {
    const u = document.getElementById('loginUser').value.trim();
    const p = document.getElementById('loginPass').value;
    const err = document.getElementById('loginError');
    if (!u || !p) { err.textContent = '请输入用户名和密码'; err.style.display = 'block'; return; }
    try {
        const res = await fetch('api.php?action=login', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: u, password: p })
        });
        const data = await res.json();
        if (data.success) { location.reload(); }
        else { err.textContent = data.message || '登录失败'; err.style.display = 'block'; }
    } catch(e) { err.textContent = '网络错误'; err.style.display = 'block'; }
}
document.getElementById('loginPass').addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
</script>

<?php elseif (!isAdmin()): ?>
<!-- ─── 无权限 ─── -->
<div class="no-perm">
  <div class="perm-icon">⛔</div>
  <h2>无管理员权限</h2>
  <p>当前账号 <b><?php echo htmlspecialchars($currentUser); ?></b> 没有后台管理权限。</p>
  <a href="index.php">← 返回前台</a>
</div>

<?php else: ?>
<!-- ─── 后台面板 ─── -->
<div class="admin-wrap">
  <!-- 背景装饰 -->
  <div class="admin-bg">
    <div class="orb"></div><div class="orb"></div><div class="orb"></div>
  </div>

  <!-- Tabs -->
  <div class="admin-tabs">
    <button class="admin-tab active" data-panel="dashboard">📊 仪表盘</button>
    <button class="admin-tab"        data-panel="users">👥 用户管理</button>
    <button class="admin-tab"        data-panel="reset">🔑 密码重置</button>
    <button class="admin-tab"        data-panel="backups">💾 备份管理</button>
    <button class="admin-tab"        data-panel="system">🖥 系统信息</button>
  </div>

  <!-- Dashboard Panel -->
  <div id="panel-dashboard" class="admin-panel active">
    <div class="stats-grid" id="statsGrid">
      <div class="stat-card"><div class="icon-row"><span class="icon-dot">👥</span><span class="label">用户总数</span></div><div class="value" id="statUsers">-</div></div>
      <div class="stat-card"><div class="icon-row"><span class="icon-dot">📋</span><span class="label">任务总数</span></div><div class="value" id="statTasks">-</div></div>
      <div class="stat-card"><div class="icon-row"><span class="icon-dot">🗄</span><span class="label">数据库大小</span></div><div class="value" id="statDbSize">-</div></div>
      <div class="stat-card"><div class="icon-row"><span class="icon-dot">📦</span><span class="label">备份份数</span></div><div class="value" id="statBackups">-</div></div>
      <div class="stat-card"><div class="icon-row"><span class="icon-dot">🕐</span><span class="label">最近备份</span></div><div class="value" id="statLastBackup" style="font-size: 15px;">-</div></div>
    </div>
    <div class="section-card">
      <h3 class="panel-title"><span class="bar"></span>🕐 最近登录记录</h3>
      <div class="admin-table-wrapper"><table class="admin-table" id="recentLoginsTable">
        <thead><tr><th>时间</th><th>用户</th><th>IP 地址</th></tr></thead>
        <tbody><tr><td class="empty" colspan="3">加载中...</td></tr></tbody>
      </table></div>
    </div>
  </div>

  <!-- Users Panel -->
  <div id="panel-users" class="admin-panel">
    <div class="section-card">
      <h3 class="panel-title"><span class="bar"></span>👥 用户列表</h3>
      <div class="admin-table-wrapper"><table class="admin-table" id="usersTable">
        <thead><tr><th>ID</th><th>用户名</th><th>邮箱</th><th>角色</th><th>登录次数</th><th>最后登录</th><th>注册时间</th><th>操作</th></tr></thead>
        <tbody><tr><td class="empty" colspan="8">加载中...</td></tr></tbody>
      </table></div>
    </div>
  </div>

  <!-- Password Reset Panel -->
  <div id="panel-reset" class="admin-panel">
    <div class="section-card">
      <h3 class="panel-title"><span class="bar"></span>🔑 生成密码重置链接</h3>
    <div class="form-row">
      <div class="form-group-admin">
        <label>选择用户</label>
        <select id="resetUserId"></select>
      </div>
      <div class="form-group-admin">
        <label>链接有效期</label>
        <select id="resetExpiry">
          <option value="10">10 分钟</option>
          <option value="30" selected>30 分钟</option>
          <option value="180">3 小时</option>
          <option value="480">8 小时</option>
        </select>
      </div>
      <button class="btn-sm primary" onclick="generateResetToken()" style="height:36px;">生成重置链接</button>
    </div>
    <div class="token-box" id="tokenBox">
      <strong style="font-size:14px;">重置链接（<span id="tokenUser"></span>）</strong>
      <div class="link-row" style="margin-top:8px;">
        <input type="text" id="tokenLink" readonly onclick="this.select()">
        <button class="btn-xs primary" onclick="copyToken()">复制</button>
      </div>
      <div class="meta">有效期至：<span id="tokenExpiry"></span> | 令牌：<code id="tokenRaw"></code></div>
    </div>
    </div>
    <div class="section-card" style="margin-top:20px;">
      <h3 class="panel-title"><span class="bar"></span>📋 最近生成的令牌</h3>
      <div class="admin-table-wrapper"><table class="admin-table" id="tokensTable">
        <thead><tr><th>用户</th><th>令牌（前16位）</th><th>创建时间</th><th>过期时间</th><th>状态</th><th>操作</th></tr></thead>
        <tbody><tr><td class="empty" colspan="6">加载中...</td></tr></tbody>
      </table></div>
    </div>
  </div>

  <!-- Backups Panel -->
  <div id="panel-backups" class="admin-panel">
    <div class="section-card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
        <h3 class="panel-title" style="margin:0;"><span class="bar"></span>💾 备份列表</h3>
        <div style="display:flex; gap:8px;">
          <button class="btn-sm primary" onclick="createBackup()">📦 立即备份</button>
          <button class="btn-sm danger" onclick="cleanBackups()">🧹 清理过期</button>
        </div>
      </div>
      <div class="admin-table-wrapper"><table class="admin-table" id="backupsTable">
        <thead><tr><th>文件名</th><th>大小</th><th>修改时间</th><th>操作</th></tr></thead>
        <tbody><tr><td class="empty" colspan="4">加载中...</td></tr></tbody>
      </table></div>
    </div>
    <div class="setting-card">
      <h4>⚙️ 自动清理设置</h4>
      <div class="setting-row">
        <label>自动删除超过 N 天的备份</label>
        <input type="number" id="settingCleanDays" min="0" value="0"> <span class="hint">天（0 = 不自动清理）</span>
      </div>
      <div class="setting-row">
        <label>最多保留备份份数</label>
        <input type="number" id="settingMaxCount" min="1" value="15"> <span class="hint">份（超过则删除旧备份）</span>
      </div>
      <button class="btn-sm primary" onclick="saveBackupSettings()">💾 保存设置</button>
      <span class="hint" style="margin-left:12px;">这些设置也影响每日自动备份的清理策略</span>
    </div>
  </div>

  <!-- System Info Panel -->
  <div id="panel-system" class="admin-panel">
    <div class="section-card">
      <h3 class="panel-title"><span class="bar"></span>🖥 系统信息</h3>
      <div class="admin-table-wrapper"><table class="admin-table" id="sysInfoTable">
        <tbody><tr><td class="empty" colspan="2">加载中...</td></tr></tbody>
      </table></div>
    </div>
  </div>
</div>

<!-- Login History Modal -->
<div class="admin-modal-overlay" id="loginHistoryModal">
  <div class="admin-modal">
    <button class="admin-modal-close" onclick="closeModal('loginHistoryModal')">&times;</button>
    <h3>登录历史 — <span id="loginHistoryUser"></span></h3>
    <table class="admin-table" style="margin-top:12px;">
      <thead><tr><th>时间</th><th>IP 地址</th></tr></thead>
      <tbody id="loginHistoryBody"></tbody>
    </table>
  </div>
</div>

<!-- Toast -->
<div class="admin-toast" id="adminToast"></div>

<script>
// ── Utilities ──
const $ = id => document.getElementById(id);

function toast(msg, type) {
    const t = $('adminToast');
    t.textContent = msg; t.className = 'admin-toast ' + type; t.style.display = 'block';
    setTimeout(() => t.style.display = 'none', 3000);
}

async function api(action, params) {
    const qs = new URLSearchParams({ action, ...params });
    const res = await fetch('admin.php?' + qs.toString());
    return res.json();
}

function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(2) + ' MB';
}

function fmtTime(t) { return t ? t.replace('T', ' ') : '-'; }

function closeModal(id) { $(id).classList.remove('show'); }
function openModal(id)  { $(id).classList.add('show'); }

// ── Tabs ──
document.querySelectorAll('.admin-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.admin-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        $('panel-' + tab.dataset.panel).classList.add('active');
        // 切换时刷新对应面板
        const loader = { dashboard: loadDashboard, users: loadUsers, reset: loadResetPanel, backups: loadBackups, system: loadSystem }[tab.dataset.panel];
        if (loader) loader();
    });
});

// ── Logout ──
async function doLogout() {
    await fetch('api.php?action=logout');
    location.reload();
}

// ── Dashboard ──
async function loadDashboard() {
    const data = await api('admin_stats');
    if (!data.success) return;
    $('statUsers').textContent = data.data.total_users;
    $('statTasks').textContent = data.data.total_tasks;
    $('statDbSize').textContent = formatSize(data.data.db_size);
    $('statBackups').textContent = data.data.backup_count;
    $('statLastBackup').textContent = data.data.last_backup || '暂无';
    const tbody = $('recentLoginsTable').querySelector('tbody');
    if (!data.data.recent_logins || data.data.recent_logins.length === 0) {
        tbody.innerHTML = '<tr><td class="empty" colspan="3">暂无登录记录</td></tr>';
    } else {
        tbody.innerHTML = data.data.recent_logins.map(l =>
            `<tr><td>${fmtTime(l.login_at)}</td><td>${l.username}</td><td>${l.ip_address}</td></tr>`
        ).join('');
    }
}

// ── Users ──
async function loadUsers() {
    const data = await api('list_users');
    const tbody = $('usersTable').querySelector('tbody');
    if (!data.success || !data.data.length) {
        tbody.innerHTML = '<tr><td class="empty" colspan="8">暂无用户</td></tr>';
    } else {
        tbody.innerHTML = data.data.map(u =>
            `<tr>
                <td>${u.id}</td>
                <td><b>${u.username}</b></td>
                <td>${u.email || '-'}</td>
                <td><span class="badge ${u.role==='admin'?'badge-admin':'badge-user'}">${u.role==='admin'?'管理员':'用户'}</span></td>
                <td>${u.login_count}</td>
                <td>${u.last_login_at || '从未登录'}</td>
                <td>${u.created_at}</td>
                <td>
                    <button class="btn-xs" onclick="viewLoginHistory(${u.id},'${u.username}')">📋</button>
                    <button class="btn-xs primary" onclick="toggleAdmin(${u.id})">${u.role==='admin'?'⬇降级':'⬆提权'}</button>
                    <button class="btn-xs danger" onclick="deleteUser(${u.id},'${u.username}')">🗑</button>
                </td>
            </tr>`
        ).join('');
    }
    // 同时更新密码重置的用户下拉
    $('resetUserId').innerHTML = data.data.map(u =>
        `<option value="${u.id}">${u.username}${u.email ? ' ('+u.email+')' : ''}</option>`
    ).join('');
}

async function viewLoginHistory(uid, uname) {
    $('loginHistoryUser').textContent = uname;
    const data = await api('user_login_history', { user_id: uid });
    const tbody = $('loginHistoryBody');
    if (!data.success || !data.data.length) {
        tbody.innerHTML = '<tr><td class="empty" colspan="2">暂无登录记录</td></tr>';
    } else {
        tbody.innerHTML = data.data.map(h =>
            `<tr><td>${fmtTime(h.login_at)}</td><td>${h.ip_address}</td></tr>`
        ).join('');
    }
    openModal('loginHistoryModal');
}

async function toggleAdmin(uid) {
    if (!confirm('确认要切换该用户的角色吗？')) return;
    const data = await api('toggle_admin', { user_id: uid });
    toast(data.message, data.success ? 'success' : 'error');
    if (data.success) loadUsers();
}

async function deleteUser(uid, uname) {
    if (!confirm(`确定要删除用户「${uname}」吗？\n该用户的所有数据将被永久删除，不可恢复。`)) return;
    const data = await api('delete_user', { user_id: uid });
    toast(data.message, data.success ? 'success' : 'error');
    if (data.success) loadUsers();
}

// ── Password Reset ──
async function loadResetPanel() {
    loadUsers(); // 更新用户下拉
    const data = await api('list_reset_tokens');
    const tbody = $('tokensTable').querySelector('tbody');
    if (!data.success || !data.data.length) {
        tbody.innerHTML = '<tr><td class="empty" colspan="6">暂无记录</td></tr>';
    } else {
        tbody.innerHTML = data.data.map(t => {
            let badge = '';
            const now = new Date();
            const exp = new Date(t.expires_at);
            if (t.used == 1) { badge = '<span class="badge badge-used">已使用</span>'; }
            else if (exp < now) { badge = '<span class="badge badge-expired">已过期</span>'; }
            else { badge = '<span class="badge badge-active">有效</span>'; }
            const revokeBtn = (t.used == 0 && exp >= now)
                ? ` <button class="btn-xs danger" onclick="revokeToken(${t.id})">撤销</button>` : '';
            return `<tr>
                <td>${t.username}</td>
                <td><code>${t.token.substring(0,16)}...</code></td>
                <td>${t.created_at}</td>
                <td>${t.expires_at}</td>
                <td>${badge}</td>
                <td>${revokeBtn}</td>
            </tr>`;
        }).join('');
    }
}

async function generateResetToken() {
    const uid    = $('resetUserId').value;
    const expiry = $('resetExpiry').value;
    if (!uid) { toast('请先选择用户', 'error'); return; }
    const data = await api('generate_reset_token', { user_id: uid, expiry_minutes: expiry });
    if (!data.success) { toast(data.message, 'error'); return; }
    $('tokenBox').style.display = 'block';
    $('tokenLink').value = data.data.reset_link;
    $('tokenUser').textContent = data.data.username;
    $('tokenExpiry').textContent = data.data.expires_at;
    $('tokenRaw').textContent = data.data.token;
    toast('重置链接已生成', 'success');
    loadResetPanel();
}

function copyToken() {
    $('tokenLink').select();
    document.execCommand('copy');
    toast('链接已复制到剪贴板', 'success');
}

async function revokeToken(tid) {
    if (!confirm('确认撤销该重置令牌？')) return;
    const data = await api('revoke_reset_token', { token_id: tid });
    toast(data.message, data.success ? 'success' : 'error');
    if (data.success) loadResetPanel();
}

// ── Backups ──
async function loadBackups() {
    const data = await api('list_backups');
    const tbody = $('backupsTable').querySelector('tbody');
    if (!data.success || !data.data.backups.length) {
        tbody.innerHTML = '<tr><td class="empty" colspan="4">暂无备份文件</td></tr>';
    } else {
        tbody.innerHTML = data.data.backups.map(b =>
            `<tr>
                <td>${b.filename}</td>
                <td>${formatSize(b.size)}</td>
                <td>${b.mtime}</td>
                <td>
                    <button class="btn-xs" onclick="window.open('admin.php?action=download_backup&file=${encodeURIComponent(b.filename)}')">⬇下载</button>
                    <button class="btn-xs danger" onclick="deleteBackup('${b.filename}')">🗑删除</button>
                </td>
            </tr>`
        ).join('');
    }
    $('settingCleanDays').value = data.data.auto_clean_days;
    $('settingMaxCount').value = data.data.max_count;
}

async function createBackup() {
    const data = await api('create_backup');
    toast(data.message, data.success ? 'success' : 'error');
    if (data.success) loadBackups();
}

async function deleteBackup(filename) {
    if (!confirm(`确认删除备份「${filename}」？`)) return;
    const data = await api('delete_backup', { file: filename });
    toast(data.message, data.success ? 'success' : 'error');
    if (data.success) loadBackups();
}

async function cleanBackups() {
    if (!confirm('确认按当前设置清理过期备份？')) return;
    const data = await api('clean_backups');
    toast(data.message, data.success ? 'success' : 'error');
    if (data.success) loadBackups();
}

async function saveBackupSettings() {
    const cleanDays = $('settingCleanDays').value;
    const maxCount  = $('settingMaxCount').value;
    const res = await fetch('admin.php?action=save_admin_settings', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ backup_auto_clean_days: cleanDays, backup_max_count: maxCount })
    });
    const data = await res.json();
    toast(data.message, data.success ? 'success' : 'error');
}

// ── System ──
async function loadSystem() {
    const data = await api('system_info');
    if (!data.success) return;
    const d = data.data;
    const rows = [
        ['应用版本', d.app_version],
        ['PHP 版本', d.php_version],
        ['服务器系统', d.server_os],
        ['Web 服务器', d.server_software],
        ['内存限制', d.memory_limit],
        ['最大执行时间', d.max_exec_time + 's'],
        ['上传限制', d.upload_max],
        ['数据库路径', d.db_path],
        ['数据库大小', formatSize(d.db_size)],
        ['数据表数量', d.table_count + ' 个（' + d.tables.join(', ') + '）'],
        ['备份目录', d.backup_dir],
        ['磁盘可用', d.disk_free > 0 ? formatSize(d.disk_free) : '未知'],
        ['磁盘总容量', d.disk_total > 0 ? formatSize(d.disk_total) : '未知'],
        ['Session 路径', d.session_path],
    ];
    $('sysInfoTable').querySelector('tbody').innerHTML = rows.map(r =>
        `<tr><td style="font-weight:600;width:180px;">${r[0]}</td><td>${r[1]}</td></tr>`
    ).join('');
}

// ── Init ──
loadDashboard();
loadUsers(); // 预加载用户列表给密码重置下拉

// 关闭弹窗（点击遮罩）
$('loginHistoryModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal('loginHistoryModal');
});
</script>
<?php endif; ?>
</main>
<?php include '_foot.php'; ?>
</body>
</html>
