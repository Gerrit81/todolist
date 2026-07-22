<?php
/**
 * =============================================================================
 * 任务管理系统 - 密码重置页面
 * =============================================================================
 *
 * 由后台管理生成的密码重置链接跳转到此页面。
 * 校验 URL 中的 token 参数，通过后允许用户设置新密码。
 *
 * @version 3.0.0
 * @date    2026-07-20
 * =============================================================================
 */

require_once __DIR__ . '/config.php';

$token  = trim($_GET['token'] ?? '');
$error  = '';
$success = false;
$username = '';

// ── 处理表单提交 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token       = trim($_POST['token'] ?? '');
    $newPassword = $_POST['password'] ?? '';
    $confirmPw   = $_POST['password_confirm'] ?? '';

    if (empty($token)) {
        $error = '缺少令牌';
    } elseif (empty($newPassword)) {
        $error = '请输入新密码';
    } elseif (strlen($newPassword) < 6) {
        $error = '密码长度不能少于 6 位';
    } elseif ($newPassword !== $confirmPw) {
        $error = '两次输入的密码不一致';
    } else {
        $db   = getDB($config);
        $stmt = $db->prepare("SELECT pr.*, u.username FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token = ?");
        $stmt->execute([$token]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reset) {
            $error = '无效的重置链接';
        } elseif ($reset['used'] == 1) {
            $error = '该重置链接已被使用';
        } elseif (strtotime($reset['expires_at']) < time()) {
            $error = '该重置链接已过期（过期时间：' . $reset['expires_at'] . '）';
        } else {
            // 更新密码
            $hashedPw = password_hash($newPassword, PASSWORD_BCRYPT);
            $db->exec("UPDATE users SET password = '{$hashedPw}' WHERE id = {$reset['user_id']}");

            // 标记令牌已使用
            $db->exec("UPDATE password_resets SET used = 1 WHERE id = {$reset['id']}");

            $username = $reset['username'];
            $success  = true;

            writeLog("用户通过重置链接修改密码: {$username} (id={$reset['user_id']})", [], $config);
        }
    }
} elseif (!empty($token)) {
    // GET 请求时验证令牌有效性
    $db   = getDB($config);
    $stmt = $db->prepare("SELECT pr.*, u.username FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token = ?");
    $stmt->execute([$token]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reset) {
        $error = '无效的重置链接';
    } elseif ($reset['used'] == 1) {
        $error = '该重置链接已被使用';
    } elseif (strtotime($reset['expires_at']) < time()) {
        $error = '该重置链接已过期（过期时间：' . $reset['expires_at'] . '）';
    } else {
        $username = $reset['username'];
    }
} else {
    $error = '缺少必要参数';
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>重置密码 - 任务管理系统</title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.card { background: #fff; border-radius: 18px; padding: 36px 32px; width: 400px; max-width: 92vw; box-shadow: 0 20px 60px rgba(0,0,0,.15); text-align: center; }
.card h2 { font-size: 22px; color: #333; margin-bottom: 6px; }
.card .sub { font-size: 13px; color: #999; margin-bottom: 20px; }
.card .form-group { margin-bottom: 14px; text-align: left; }
.card label { font-size: 13px; font-weight: 600; color: #666; display: block; margin-bottom: 4px; }
.card input { width: 100%; padding: 10px 14px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border .2s; }
.card input:focus { border-color: #667eea; }
.card .btn { width: 100%; padding: 11px; background: #667eea; color: #fff; border: none; border-radius: 8px; font-size: 15px; cursor: pointer; font-weight: 600; transition: background .2s; margin-top: 4px; }
.card .btn:hover { background: #5a6fd6; }
.card .error { color: #e74c3c; font-size: 13px; margin-bottom: 10px; background: #fef2f2; padding: 10px; border-radius: 8px; }
.card .success { color: #059669; font-size: 14px; margin-bottom: 10px; }
.card .success-icon { font-size: 48px; margin-bottom: 10px; }
.card .link { display: inline-block; margin-top: 16px; font-size: 14px; color: #667eea; text-decoration: none; }
.card .link:hover { text-decoration: underline; }
</style>
</head>
<body>

<div class="card">
    <?php if ($success): ?>
        <div class="success-icon">✅</div>
        <h2>密码重置成功</h2>
        <p class="sub">用户 <b><?php echo htmlspecialchars($username); ?></b> 的密码已更新。</p>
        <p class="success">现在可以使用新密码登录了。</p>
        <a class="link" href="index.php">返回登录 →</a>

    <?php elseif ($error): ?>
        <h2>⚠️ 无法重置密码</h2>
        <p class="sub">重置链接验证失败</p>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <a class="link" href="index.php">返回登录 →</a>

    <?php else: ?>
        <h2>🔐 重置密码</h2>
        <p class="sub">用户：<b><?php echo htmlspecialchars($username); ?></b></p>
        <form method="POST">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <div class="form-group">
                <label>新密码</label>
                <input type="password" name="password" placeholder="至少 6 位" minlength="6" required autofocus>
            </div>
            <div class="form-group">
                <label>确认新密码</label>
                <input type="password" name="password_confirm" placeholder="再次输入新密码" minlength="6" required>
            </div>
            <button type="submit" class="btn">重置密码</button>
        </form>
        <a class="link" href="index.php">← 返回登录</a>
    <?php endif; ?>
</div>

</body>
</html>
