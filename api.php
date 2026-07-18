<?php
/**
 * =============================================================================
 * 任务管理系统 (Todo List) - API 接口层
 * =============================================================================
 *
 * 路由方式：通过 URL 参数 ?action=xxx 分发，所有响应均为 JSON 格式。
 *
 * v4.0 更新：
 *   - 新增标签系统（tags / task_tags）
 *   - 任务改为软删除，新增垃圾桶与恢复
 *   - 新增视图：收集箱、最近7天、四象限、完整月历、摘要统计
 *   - 新增搜索接口（任务标题/备注/标签）
 *   - 列表任务按「已过期 / 今天 / 明天 / 未来 / 无日期」分组
 *
 * @version  1.0.0
 * @date     2026-07-18
 * =============================================================================
 */

require_once __DIR__ . '/config.php';

date_default_timezone_set('Asia/Shanghai');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$currentUserId = getCurrentUserId();

// -------------------- 标签辅助函数 --------------------

/**
 * 为多个任务附加标签数组
 * 每个任务增加 'tags' => [ {id, name, color}, ... ]
 */
function attachTagsToTasks(&$tasks, $db) {
    if (empty($tasks)) return;
    $ids = array_map(function($t) { return intval($t['id']); }, $tasks);
    $in  = implode(',', $ids);
    if (empty($in)) return;

    $stmt = $db->query("
        SELECT tt.task_id, tg.id, tg.name, tg.color
        FROM task_tags tt
        JOIN tags tg ON tt.tag_id = tg.id
        WHERE tt.task_id IN ($in)
        ORDER BY tg.name ASC
    ");
    $map = [];
    while ($row = $stmt->fetch()) {
        $taskId = $row['task_id'];
        unset($row['task_id']);
        if (!isset($map[$taskId])) $map[$taskId] = [];
        $map[$taskId][] = $row;
    }
    foreach ($tasks as &$t) {
        $t['tags'] = $map[intval($t['id'])] ?? [];
    }
}

/**
 * 重新设置任务关联的标签（先删后插）
 */
function setTaskTags($db, $taskId, $tagIds, $userId) {
    $db->prepare("DELETE FROM task_tags WHERE task_id = :tid")
       ->execute(['tid' => $taskId]);
    if (empty($tagIds)) return;

    $stmt = $db->prepare("
        INSERT INTO task_tags (task_id, tag_id)
        SELECT :tid, id FROM tags WHERE id = :tgid AND user_id = :uid
    ");
    foreach ($tagIds as $tgid) {
        $stmt->execute(['tid' => $taskId, 'tgid' => intval($tgid), 'uid' => $userId]);
    }
}

/**
 * 解析请求中的 tag_ids
 */
function parseTagIds($data) {
    $ids = $data['tag_ids'] ?? [];
    if (is_string($ids)) {
        $ids = $ids === '' ? [] : explode(',', $ids);
    }
    return array_filter(array_map('intval', $ids));
}

// -------------------- 主路由 --------------------

try {
    switch ($action) {

        // ==================== 用户认证接口 ====================

        case 'register':
            $data     = getJsonInput();
            $username = trim($data['username'] ?? '');
            $password = $data['password'] ?? '';
            $email    = trim($data['email'] ?? '');

            if (mb_strlen($username) < 2 || mb_strlen($username) > 30) {
                jsonResponse(null, 400, '用户名需 2-30 个字符');
            }
            if (strlen($password) < 6) {
                jsonResponse(null, 400, '密码至少需要 6 个字符');
            }

            $exist = $db->prepare("SELECT id FROM users WHERE username = :u");
            $exist->execute(['u' => $username]);
            if ($exist->fetch()) {
                jsonResponse(null, 409, '该用户名已被注册');
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $db->prepare("INSERT INTO users (username, password, email) VALUES (:u, :p, :e)");
            $stmt->execute(['u' => $username, 'p' => $hash, 'e' => $email]);
            $userId = $db->lastInsertId();

            createDefaultCategoriesForUser($db, $userId);

            $_SESSION['user_id']  = $userId;
            $_SESSION['username'] = $username;

            writeLog("用户注册: {$username}", ['user_id' => $userId], $config);
            jsonResponse(['user_id' => $userId, 'username' => $username], 201, '注册成功');

        case 'login':
            $data     = getJsonInput();
            $username = trim($data['username'] ?? '');
            $password = $data['password'] ?? '';

            if (empty($username) || empty($password)) {
                jsonResponse(null, 400, '请输入用户名和密码');
            }

            $stmt = $db->prepare("SELECT id, username, password FROM users WHERE username = :u");
            $stmt->execute(['u' => $username]);
            $user = $stmt->fetch();

            if (!$user) jsonResponse(null, 401, '用户名或密码错误');
            if (!password_verify($password, $user['password'])) jsonResponse(null, 401, '用户名或密码错误');

            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];

            writeLog("用户登录: {$username}", ['user_id' => $user['id']], $config);
            jsonResponse(['user_id' => $user['id'], 'username' => $user['username']], 200, '登录成功');

        case 'logout':
            $logoutUser = $_SESSION['username'] ?? 'unknown';
            session_unset();
            session_destroy();
            writeLog("用户登出: {$logoutUser}", [], $config);
            jsonResponse(null, 200, '已退出登录');

        case 'change_password':
            requireAuth();
            $data        = getJsonInput();
            $oldPassword = $data['old_password'] ?? '';
            $newPassword = $data['new_password'] ?? '';

            if (empty($oldPassword) || empty($newPassword)) {
                jsonResponse(null, 400, '请输入当前密码和新密码');
            }
            if (strlen($newPassword) < 6) {
                jsonResponse(null, 400, '新密码至少需要6个字符');
            }

            // 验证旧密码
            $stmt = $db->prepare("SELECT password FROM users WHERE id = :uid");
            $stmt->execute(['uid' => $currentUserId]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($oldPassword, $user['password'])) {
                jsonResponse(null, 401, '当前密码不正确');
            }

            // 更新密码
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $db->prepare("UPDATE users SET password = :pwd WHERE id = :uid");
            $stmt->execute(['pwd' => $newHash, 'uid' => $currentUserId]);

            writeLog("用户修改密码: " . getCurrentUsername(), ['user_id' => $currentUserId], $config);
            jsonResponse(null, 200, '密码修改成功，请重新登录');

        case 'check_auth':
            if ($currentUserId > 0) {
                jsonResponse(['user_id' => $currentUserId, 'username' => getCurrentUsername()]);
            } else {
                jsonResponse(null, 401, '未登录');
            }

        // ==================== 用户设置接口 ====================

        case 'get_settings':
            requireAuth();
            $stmt = $db->prepare("SELECT * FROM user_settings WHERE user_id = :uid");
            $stmt->execute(['uid' => $currentUserId]);
            $settings = $stmt->fetch();

            if (!$settings) {
                $settings = [
                    'smtp_host'              => '',
                    'smtp_port'              => 587,
                    'smtp_username'          => '',
                    'smtp_password'          => '',
                    'smtp_encryption'        => 'tls',
                    'sound_enabled'          => 1,
                    'tab_flash_enabled'      => 1,
                    'email_reminder_enabled' => 0,
                    'email_recipient'        => '',
                    'theme'                  => 'default',
                ];
            }
            unset($settings['id'], $settings['user_id'], $settings['updated_at']);
            $settings['smtp_password'] = !empty($settings['smtp_password']) ? '******' : '';
            jsonResponse($settings);

        case 'update_settings':
            requireAuth();
            $data = getJsonInput();

            $stmt = $db->prepare("SELECT id, smtp_password FROM user_settings WHERE user_id = :uid");
            $stmt->execute(['uid' => $currentUserId]);
            $existing = $stmt->fetch();

            $newPassword = $data['smtp_password'] ?? '';
            $finalPassword = '';
            if ($newPassword === '******' || $newPassword === '') {
                $finalPassword = $existing['smtp_password'] ?? '';
            } else {
                $finalPassword = $newPassword;
            }

            if ($existing) {
                $stmt = $db->prepare("
                    UPDATE user_settings SET
                        smtp_host=:h, smtp_port=:p, smtp_username=:u, smtp_password=:pw,
                        smtp_encryption=:e, sound_enabled=:s, tab_flash_enabled=:t,
                        email_reminder_enabled=:er, email_recipient=:ert, theme=:theme,
                        updated_at=datetime('now','localtime')
                    WHERE user_id=:uid
                ");
            } else {
                $stmt = $db->prepare("
                    INSERT INTO user_settings 
                        (user_id, smtp_host, smtp_port, smtp_username, smtp_password,
                         smtp_encryption, sound_enabled, tab_flash_enabled,
                         email_reminder_enabled, email_recipient, theme)
                    VALUES (:uid, :h, :p, :u, :pw, :e, :s, :t, :er, :ert, :theme)
                ");
            }

            $stmt->execute([
                'uid'   => $currentUserId,
                'h'     => $data['smtp_host']   ?? '',
                'p'     => intval($data['smtp_port'] ?? 587),
                'u'     => $data['smtp_username'] ?? '',
                'pw'    => $finalPassword,
                'e'     => $data['smtp_encryption'] ?? 'tls',
                's'     => intval($data['sound_enabled'] ?? 1),
                't'     => intval($data['tab_flash_enabled'] ?? 1),
                'er'    => intval($data['email_reminder_enabled'] ?? 0),
                'ert'   => $data['email_recipient'] ?? '',
                'theme' => $data['theme'] ?? 'default',
            ]);

            writeLog("更新设置", ['user_id' => $currentUserId], $config);
            jsonResponse(null, 200, '设置已保存');

        case 'send_test_email':
            requireAuth();

            $stmt = $db->prepare("SELECT * FROM user_settings WHERE user_id = :uid");
            $stmt->execute(['uid' => $currentUserId]);
            $settings = $stmt->fetch();

            if (!$settings || empty($settings['smtp_host'])) {
                jsonResponse(null, 400, '请先在设置中配置 SMTP 服务器信息');
            }

            $data = getJsonInput();
            $to   = $data['to_email'] ?? '';
            if (empty($to)) {
                $to = $settings['email_recipient'] ?: $settings['smtp_username'];
            }
            if (empty($to)) {
                jsonResponse(null, 400, '请填写收件人邮箱地址');
            }

            $subject = '【任务管理系统】SMTP 测试邮件';
            $body    = '
                <div style="font-family: sans-serif; max-width: 500px; margin: 0 auto; padding: 20px;">
                    <h2 style="color: #4A90D9;">&#10004; SMTP 配置测试成功</h2>
                    <p>恭喜！您的邮件发送配置已生效。</p>
                    <p style="color: #7F8C8D; font-size: 13px;">
                        发送时间：' . date('Y-m-d H:i:s') . '<br>
                        发件服务器：' . htmlspecialchars($settings['smtp_host']) . '
                    </p>
                    <hr style="border: none; border-top: 1px solid #E8ECF1;">
                    <p style="color: #95A5A6; font-size: 12px;">此邮件由任务管理系统自动发送</p>
                </div>
            ';

            $result = sendMailSMTP([
                'host'       => $settings['smtp_host'],
                'port'       => intval($settings['smtp_port']),
                'username'   => $settings['smtp_username'],
                'password'   => $settings['smtp_password'],
                'encryption' => $settings['smtp_encryption'],
            ], $to, $subject, $body);

            if ($result['success']) {
                writeLog("测试邮件发送成功 -> {$to}", ['user_id' => $currentUserId], $config);
                jsonResponse(null, 200, '测试邮件已发送至 ' . $to);
            } else {
                writeLog("测试邮件发送失败: " . $result['message'], ['user_id' => $currentUserId], $config);
                jsonResponse(null, 500, '发送失败: ' . $result['message']);
            }

        case 'send_reminder_email':
            requireAuth();

            $stmt = $db->prepare("SELECT * FROM user_settings WHERE user_id = :uid");
            $stmt->execute(['uid' => $currentUserId]);
            $settings = $stmt->fetch();

            if (!$settings || !$settings['email_reminder_enabled']) {
                jsonResponse(null, 400, '邮件提醒未开启');
            }
            if (empty($settings['smtp_host']) || empty($settings['smtp_password'])) {
                jsonResponse(null, 400, 'SMTP 配置不完整');
            }

            $to = $settings['email_recipient'] ?: $settings['smtp_username'];
            if (empty($to)) {
                jsonResponse(null, 400, '未设置收件人邮箱');
            }

            // v4.0: 使用 reminder_offset 计算实际提醒时间，并排除已删除任务
            $now = date('Y-m-d H:i');
            $stmt = $db->prepare("
                SELECT t.title, t.priority, t.due_datetime, t.reminder_offset,
                       c.name AS category_name 
                FROM tasks t 
                LEFT JOIN categories c ON t.category_id = c.id 
                WHERE t.user_id = :uid AND t.is_completed = 0 AND t.is_deleted = 0
                  AND t.due_datetime IS NOT NULL
                  AND datetime(t.due_datetime, '-' || t.reminder_offset || ' minutes') <= :now
                  AND t.due_datetime >= date('now', 'localtime')
                ORDER BY t.due_datetime ASC
            ");
            $stmt->execute(['uid' => $currentUserId, 'now' => $now]);
            $reminders = $stmt->fetchAll();

            if (empty($reminders)) {
                jsonResponse(null, 200, '没有需要提醒的任务');
            }

            $count   = count($reminders);
            $subject = "【任务提醒】您有 {$count} 项任务需要关注";
            $rows    = '';
            foreach ($reminders as $i => $r) {
                $pEmoji = ['high' => '&#x1F534;', 'medium' => '&#x1F7E1;', 'low' => '&#x1F7E2;'][$r['priority']] ?? '&#x26AA;';
                $dueStr = substr($r['due_datetime'], 0, 16);
                $rows  .= "<tr><td style='padding:8px 12px;border-bottom:1px solid #E8ECF1;'>{$pEmoji}</td>
                            <td style='padding:8px 12px;border-bottom:1px solid #E8ECF1;'>" . htmlspecialchars($r['title']) . "</td>
                            <td style='padding:8px 12px;border-bottom:1px solid #E8ECF1;color:#7F8C8D;'>" . htmlspecialchars($r['category_name']) . "</td>
                            <td style='padding:8px 12px;border-bottom:1px solid #E8ECF1;font-size:12px;color:#95A5A6;'>{$dueStr}</td></tr>";
            }

            $body = "
                <div style='font-family: sans-serif; max-width: 500px; margin: 0 auto; padding: 20px;'>
                    <h2 style='color: #E74C3C;'>&#x1F4CB; 待办任务提醒</h2>
                    <p>您好，以下任务即将截止，请及时处理：</p>
                    <table style='width:100%;border-collapse:collapse;'>
                        <thead><tr style='background:#F5F7FA;'>
                            <th style='padding:8px 12px;text-align:left;'>优先级</th>
                            <th style='padding:8px 12px;text-align:left;'>任务</th>
                            <th style='padding:8px 12px;text-align:left;'>分类</th>
                            <th style='padding:8px 12px;text-align:left;'>截止时间</th>
                        </tr></thead>
                        <tbody>{$rows}</tbody>
                    </table>
                    <p style='color: #95A5A6; font-size: 12px; margin-top: 20px;'>
                        此邮件由任务管理系统自动发送 &middot; " . date('Y-m-d H:i:s') . "
                    </p>
                </div>
            ";

            $result = sendMailSMTP([
                'host'       => $settings['smtp_host'],
                'port'       => intval($settings['smtp_port']),
                'username'   => $settings['smtp_username'],
                'password'   => $settings['smtp_password'],
                'encryption' => $settings['smtp_encryption'],
            ], $to, $subject, $body);

            if ($result['success']) {
                writeLog("提醒邮件发送成功 -> {$to}", ['count' => $count], $config);
                jsonResponse(['sent' => true, 'count' => $count], 200, "提醒邮件已发送 ({$count} 项任务)");
            } else {
                writeLog("提醒邮件发送失败: " . $result['message'], [], $config);
                jsonResponse(null, 500, '邮件发送失败: ' . $result['message']);
            }

        // ==================== 分类接口 ====================

        case 'list_categories':
            requireAuth();
            // v4.0: 未完成任务数排除已删除任务
            $stmt = $db->prepare("
                SELECT c.*, 
                       (SELECT COUNT(*) FROM tasks t WHERE t.category_id = c.id AND t.user_id = c.user_id AND t.is_completed = 0 AND t.is_deleted = 0) AS task_count
                FROM categories c 
                WHERE c.user_id = :uid 
                ORDER BY c.sort_order ASC, c.id ASC
            ");
            $stmt->execute(['uid' => $currentUserId]);
            $cats = $stmt->fetchAll();
            jsonResponse($cats);

        case 'create_category':
            requireAuth();
            $data = getJsonInput();
            $name = trim($data['name'] ?? '');
            if ($name === '') jsonResponse(null, 400, '分类名称不能为空');

            $stmt = $db->prepare("INSERT INTO categories (user_id, name, color, sort_order) VALUES (:uid, :name, :color, :sort_order)");
            $stmt->execute([
                'uid'        => $currentUserId,
                'name'       => $name,
                'color'      => $data['color'] ?? '#4A90D9',
                'sort_order' => $data['sort_order'] ?? 0,
            ]);
            $newId = $db->lastInsertId();
            $row   = $db->query("SELECT * FROM categories WHERE id = $newId")->fetch();
            writeLog("创建分类: {$name}", ['id' => $newId, 'user_id' => $currentUserId], $config);
            jsonResponse($row, 201, '分类创建成功');

        case 'update_category':
            requireAuth();
            $data = getJsonInput();
            $id   = intval($data['id'] ?? 0);
            $name = trim($data['name'] ?? '');
            if ($id <= 0 || $name === '') jsonResponse(null, 400, '参数不完整');

            $stmt = $db->prepare("UPDATE categories SET name=:name, color=:color, sort_order=:sort_order WHERE id=:id AND user_id=:uid");
            $stmt->execute([
                'name'       => $name,
                'color'      => $data['color'] ?? '#4A90D9',
                'sort_order' => $data['sort_order'] ?? 0,
                'id'         => $id,
                'uid'        => $currentUserId,
            ]);
            writeLog("更新分类: {$name}", ['id' => $id], $config);
            jsonResponse(null, 200, '分类更新成功');

        case 'delete_category':
            requireAuth();
            $data = getJsonInput();
            $id   = intval($data['id'] ?? 0);
            if ($id <= 0) jsonResponse(null, 400, '缺少分类ID');

            $cat = $db->query("SELECT name FROM categories WHERE id = $id AND user_id = $currentUserId")->fetch();
            $db->prepare("DELETE FROM categories WHERE id = :id AND user_id = :uid")->execute(['id' => $id, 'uid' => $currentUserId]);
            writeLog("删除分类: " . ($cat['name'] ?? '未知'), ['id' => $id], $config);
            jsonResponse(null, 200, '分类已删除');

        // ==================== 任务接口 ====================

        /**
         * 获取任务列表（v4.0 支持分组、标签、搜索、软删除）
         */
        case 'list_tasks':
            requireAuth();
            $filter        = $_GET['filter']        ?? 'today';
            $category_id   = intval($_GET['category_id'] ?? 0);
            $tag_id        = intval($_GET['tag_id'] ?? 0);
            $calendar_date = $_GET['calendar_date'] ?? '';
            $sort_by       = $_GET['sort_by']       ?? 'default';
            $search        = trim($_GET['search']   ?? '');
            $group         = $_GET['group']         ?? 'auto'; // auto / none / overdue-today-tomorrow-future-nodate

            $sql    = "SELECT t.*, c.name AS category_name, c.color AS category_color
                       FROM tasks t
                       LEFT JOIN categories c ON t.category_id = c.id
                       WHERE t.user_id = :uid AND t.is_deleted = 0";
            $params = ['uid' => $currentUserId];

            // 垃圾桶视图单独处理
            if ($filter === 'trash') {
                $sql = "SELECT t.*, c.name AS category_name, c.color AS category_color
                        FROM tasks t
                        LEFT JOIN categories c ON t.category_id = c.id
                        WHERE t.user_id = :uid AND t.is_deleted = 1
                        ORDER BY t.deleted_at DESC";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $tasks = $stmt->fetchAll();
                attachTagsToTasks($tasks, $db);
                jsonResponse($tasks);
            }

            // 搜索：标题、备注
            if ($search !== '') {
                $sql .= " AND (t.title LIKE :search OR t.notes LIKE :search)";
                $params['search'] = '%' . $search . '%';
            }

            // 标签筛选
            if ($tag_id > 0) {
                $sql .= " AND t.id IN (SELECT task_id FROM task_tags WHERE tag_id = :tag_id)";
                $params['tag_id'] = $tag_id;
            }

            // 分类筛选
            if ($category_id > 0) {
                $sql .= " AND t.category_id = :category_id";
                $params['category_id'] = $category_id;
            }

            // 视图筛选
            if (!empty($calendar_date)) {
                $sql .= " AND date(t.due_datetime) = :cal_date";
                $params['cal_date'] = $calendar_date;
            } elseif ($filter === 'today') {
                $sql .= " AND t.is_completed = 0 AND date(t.due_datetime) <= date('now', 'localtime')";
            } elseif ($filter === 'tomorrow') {
                $tomorrow = date('Y-m-d', strtotime('+1 day'));
                $sql .= " AND t.is_completed = 0 AND date(t.due_datetime) = :tomorrow";
                $params['tomorrow'] = $tomorrow;
            } elseif ($filter === 'next7days') {
                $today = date('Y-m-d');
                $day7  = date('Y-m-d', strtotime('+7 days'));
                $sql .= " AND t.is_completed = 0 AND date(t.due_datetime) >= :d1 AND date(t.due_datetime) <= :d2";
                $params['d1'] = $today;
                $params['d2'] = $day7;
            } elseif ($filter === 'upcoming') {
                $sql .= " AND t.is_completed = 0 AND date(t.due_datetime) >= date('now', 'localtime', '+1 day')";
            } elseif ($filter === 'all') {
                $sql .= " AND t.is_completed = 0";
            } elseif ($filter === 'completed') {
                $sql .= " AND t.is_completed = 1";
            } elseif ($filter === 'inbox') {
                // 收集箱：未分类（category_id 为默认第一个分类）或没有截止日期的未完成任务
                // 这里简化为：未完成任务且 (无截止日期 或 category_id 在默认分类中)
                // 更精确：返回没有截止日期 或 分类为默认"收集箱"的任务
                $sql .= " AND t.is_completed = 0 AND (t.due_datetime IS NULL OR t.category_id IN (SELECT id FROM categories WHERE user_id = :uid AND name = '收集箱'))";
            } elseif ($filter === 'nodate') {
                $sql .= " AND t.is_completed = 0 AND t.due_datetime IS NULL";
            }

            // 排序
            $priorityOrder = "CASE t.priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 WHEN 'low' THEN 2 END";
            if ($sort_by === 'date_asc') {
                $sql .= " ORDER BY t.due_datetime ASC NULLS LAST, {$priorityOrder}";
            } elseif ($sort_by === 'date_desc') {
                $sql .= " ORDER BY t.due_datetime DESC NULLS LAST, {$priorityOrder}";
            } elseif ($sort_by === 'priority') {
                $sql .= " ORDER BY {$priorityOrder}, t.due_datetime ASC NULLS LAST";
            } else {
                $sql .= " ORDER BY t.is_completed ASC, {$priorityOrder}, t.due_datetime ASC NULLS LAST, t.created_at DESC";
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $tasks = $stmt->fetchAll();

            // 附加标签
            attachTagsToTasks($tasks, $db);

            // 自动分组：按逾期/今天/明天/未来/无日期
            if ($group === 'auto' && in_array($filter, ['all', 'today', 'next7days', 'upcoming', 'inbox', 'search']) && empty($calendar_date)) {
                $today = date('Y-m-d');
                $tomorrow = date('Y-m-d', strtotime('+1 day'));
                $groups = [
                    'overdue'   => ['label' => '已过期', 'items' => []],
                    'today'     => ['label' => '今天',   'items' => []],
                    'tomorrow'  => ['label' => '明天',   'items' => []],
                    'future'    => ['label' => '未来',   'items' => []],
                    'nodate'    => ['label' => '无日期', 'items' => []],
                    'completed' => ['label' => '已完成', 'items' => []],
                ];
                foreach ($tasks as $t) {
                    if ($t['is_completed'] == 1) {
                        $groups['completed']['items'][] = $t;
                    } elseif (empty($t['due_datetime'])) {
                        $groups['nodate']['items'][] = $t;
                    } else {
                        $d = substr($t['due_datetime'], 0, 10);
                        if ($d < $today) $groups['overdue']['items'][] = $t;
                        elseif ($d === $today) $groups['today']['items'][] = $t;
                        elseif ($d === $tomorrow) $groups['tomorrow']['items'][] = $t;
                        else $groups['future']['items'][] = $t;
                    }
                }
                jsonResponse(['grouped' => true, 'groups' => $groups]);
            }

            jsonResponse($tasks);


        /**
         * 获取单条任务详情（用于编辑弹窗）
         */
        case 'get_task':
            requireAuth();
            $taskId = intval($_GET['id'] ?? 0);
            if ($taskId <= 0) jsonResponse(null, 400, '缺少任务ID');

            $stmt = $db->prepare("
                SELECT t.*, c.name AS category_name, c.color AS category_color 
                FROM tasks t 
                LEFT JOIN categories c ON t.category_id = c.id 
                WHERE t.id = :id AND t.user_id = :uid
            ");
            $stmt->execute(['id' => $taskId, 'uid' => $currentUserId]);
            $task = $stmt->fetch();
            if (!$task) jsonResponse(null, 404, '任务不存在');
            $rows = [$task];
            attachTagsToTasks($rows, $db);
            $task = $rows[0];
            // 额外返回标签 ID 列表便于编辑弹窗回显
            $task['tag_ids'] = array_map(function($t){ return intval($t['id']); }, $task['tags'] ?? []);
            jsonResponse($task);

        /**
         * 创建新任务
         * v3.0: due_date → due_datetime + reminder_offset + notes
         */
        case 'create_task':
            requireAuth();
            $data   = getJsonInput();
            $title  = trim($data['title'] ?? '');
            $catId  = intval($data['category_id'] ?? 0);

            if ($title === '') jsonResponse(null, 400, '任务标题不能为空');
            if ($catId <= 0) jsonResponse(null, 400, '请选择所属清单');

            $stmt = $db->prepare("
                INSERT INTO tasks (user_id, title, category_id, priority, due_datetime, reminder_offset, notes, status) 
                VALUES (:uid, :title, :category_id, :priority, :due_datetime, :reminder_offset, :notes, :status)
            ");
            $stmt->execute([
                'uid'             => $currentUserId,
                'title'           => $title,
                'category_id'     => $catId,
                'priority'        => $data['priority'] ?? 'medium',
                'due_datetime'    => $data['due_datetime'] ?? null,
                'reminder_offset' => intval($data['reminder_offset'] ?? 0),
                'notes'           => trim($data['notes'] ?? ''),
                'status'          => $data['status'] ?? 'todo',
            ]);

            $newId = $db->lastInsertId();
            setTaskTags($db, $newId, parseTagIds($data), $currentUserId);

            $row = $db->query("
                SELECT t.*, c.name AS category_name, c.color AS category_color 
                FROM tasks t 
                LEFT JOIN categories c ON t.category_id = c.id 
                WHERE t.id = $newId
            ")->fetch();
            $rows = [$row];
            attachTagsToTasks($rows, $db);
            $row = $rows[0];

            writeLog("创建任务: {$title}", ['id' => $newId], $config);
            jsonResponse($row, 201, '任务创建成功');

        /**
         * 更新任务（完整编辑）
         * v3.0: 支持 datetime / reminder_offset / notes
         */
        case 'update_task':
            requireAuth();
            $data   = getJsonInput();
            $taskId = intval($data['id'] ?? 0);
            $title  = trim($data['title'] ?? '');

            if ($taskId <= 0 || $title === '') jsonResponse(null, 400, '参数不完整');

            $stmt = $db->prepare("
                UPDATE tasks 
                SET title=:title, category_id=:category_id, priority=:priority, 
                    due_datetime=:due_datetime, reminder_offset=:reminder_offset, notes=:notes,
                    status=:status,
                    updated_at=datetime('now','localtime')
                WHERE id=:id AND user_id=:uid
            ");
            $stmt->execute([
                'title'           => $title,
                'category_id'     => intval($data['category_id'] ?? 0),
                'priority'        => $data['priority'] ?? 'medium',
                'due_datetime'    => $data['due_datetime'] ?? null,
                'reminder_offset' => intval($data['reminder_offset'] ?? 0),
                'notes'           => trim($data['notes'] ?? ''),
                'status'          => $data['status'] ?? 'todo',
                'id'              => $taskId,
                'uid'             => $currentUserId,
            ]);

            setTaskTags($db, $taskId, parseTagIds($data), $currentUserId);

            writeLog("更新任务: {$title}", ['id' => $taskId], $config);
            jsonResponse(null, 200, '任务更新成功');

        /**
         * 切换任务完成状态
         */
        case 'toggle_task':
            requireAuth();
            $data        = getJsonInput();
            $taskId      = intval($data['id'] ?? 0);
            $isCompleted = intval($data['is_completed'] ?? 0);

            if ($taskId <= 0) jsonResponse(null, 400, '缺少任务ID');

            if ($isCompleted) {
                $db->prepare("UPDATE tasks SET is_completed=1, completed_at=datetime('now','localtime'), updated_at=datetime('now','localtime') WHERE id=:id AND user_id=:uid")
                   ->execute(['id' => $taskId, 'uid' => $currentUserId]);
                writeLog("任务标记完成", ['id' => $taskId], $config);
            } else {
                $db->prepare("UPDATE tasks SET is_completed=0, completed_at=NULL, updated_at=datetime('now','localtime') WHERE id=:id AND user_id=:uid")
                   ->execute(['id' => $taskId, 'uid' => $currentUserId]);
                writeLog("任务取消完成", ['id' => $taskId], $config);
            }

            jsonResponse(null, 200, $isCompleted ? '任务已完成' : '任务已恢复');

        /**
         * v4.0 删除任务：软删除（移入垃圾桶）
         */
        case 'delete_task':
            requireAuth();
            $data   = getJsonInput();
            $taskId = intval($data['id'] ?? 0);
            if ($taskId <= 0) jsonResponse(null, 400, '缺少任务ID');

            $task = $db->query("
                SELECT t.title FROM tasks t
                WHERE t.id = $taskId AND t.user_id = $currentUserId AND t.is_deleted = 0
            ")->fetch();
            if (!$task) jsonResponse(null, 404, '任务不存在或已在垃圾桶');

            $db->prepare("
                UPDATE tasks SET is_deleted=1, deleted_at=datetime('now','localtime'), updated_at=datetime('now','localtime')
                WHERE id=:id AND user_id=:uid
            ")->execute(['id' => $taskId, 'uid' => $currentUserId]);

            writeLog("软删除任务: " . ($task['title'] ?? '未知'), ['id' => $taskId], $config);
            jsonResponse(null, 200, '任务已移入垃圾桶');

        /**
         * v4.0 恢复任务：从垃圾桶还原
         */
        case 'restore_task':
            requireAuth();
            $data   = getJsonInput();
            $taskId = intval($data['id'] ?? 0);
            if ($taskId <= 0) jsonResponse(null, 400, '缺少任务ID');

            $db->prepare("
                UPDATE tasks SET is_deleted=0, deleted_at=NULL, updated_at=datetime('now','localtime')
                WHERE id=:id AND user_id=:uid
            ")->execute(['id' => $taskId, 'uid' => $currentUserId]);
            writeLog("恢复任务", ['id' => $taskId], $config);
            jsonResponse(null, 200, '任务已恢复');

        /**
         * v4.0 永久删除任务
         */
        case 'permanent_delete_task':
            requireAuth();
            $data   = getJsonInput();
            $taskId = intval($data['id'] ?? 0);
            if ($taskId <= 0) jsonResponse(null, 400, '缺少任务ID');

            $db->prepare("DELETE FROM task_tags WHERE task_id = :tid")->execute(['tid' => $taskId]);
            $db->prepare("DELETE FROM tasks WHERE id=:id AND user_id=:uid AND is_deleted=1")
               ->execute(['id' => $taskId, 'uid' => $currentUserId]);
            writeLog("永久删除任务", ['id' => $taskId], $config);
            jsonResponse(null, 200, '任务已永久删除');

        /**
         * v4.0 清空垃圾桶
         */
        case 'empty_trash':
            requireAuth();
            $stmt = $db->prepare("
                SELECT id FROM tasks WHERE user_id = :uid AND is_deleted = 1
            ");
            $stmt->execute(['uid' => $currentUserId]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($ids)) {
                $in = implode(',', $ids);
                $db->exec("DELETE FROM task_tags WHERE task_id IN ($in)");
                $db->prepare("DELETE FROM tasks WHERE user_id = :uid AND is_deleted = 1")
                   ->execute(['uid' => $currentUserId]);
            }
            writeLog("清空垃圾桶", ['count' => count($ids)], $config);
            jsonResponse(['count' => count($ids)], 200, '垃圾桶已清空');

        /**
         * v4.0 新增：标签 CRUD
         */
        case 'list_tags':
            requireAuth();
            $stmt = $db->prepare("
                SELECT tg.*, (SELECT COUNT(*) FROM task_tags tt JOIN tasks t ON tt.task_id = t.id WHERE tt.tag_id = tg.id AND t.is_deleted = 0) AS task_count
                FROM tags tg
                WHERE tg.user_id = :uid
                ORDER BY tg.name ASC
            ");
            $stmt->execute(['uid' => $currentUserId]);
            jsonResponse($stmt->fetchAll());

        case 'create_tag':
            requireAuth();
            $data = getJsonInput();
            $name = trim($data['name'] ?? '');
            if ($name === '') jsonResponse(null, 400, '标签名称不能为空');
            try {
                $stmt = $db->prepare("INSERT INTO tags (user_id, name, color) VALUES (:uid, :name, :color)");
                $stmt->execute(['uid' => $currentUserId, 'name' => $name, 'color' => $data['color'] ?? '#95A5A6']);
                $id = $db->lastInsertId();
                writeLog("创建标签: {$name}", ['id' => $id], $config);
                jsonResponse(['id' => $id, 'name' => $name], 201, '标签创建成功');
            } catch (PDOException $e) {
                jsonResponse(null, 409, '标签已存在');
            }

        case 'update_tag':
            requireAuth();
            $data = getJsonInput();
            $id   = intval($data['id'] ?? 0);
            $name = trim($data['name'] ?? '');
            if ($id <= 0 || $name === '') jsonResponse(null, 400, '参数不完整');
            try {
                $stmt = $db->prepare("UPDATE tags SET name=:name, color=:color WHERE id=:id AND user_id=:uid");
                $stmt->execute(['id' => $id, 'uid' => $currentUserId, 'name' => $name, 'color' => $data['color'] ?? '#95A5A6']);
                writeLog("更新标签: {$name}", ['id' => $id], $config);
                jsonResponse(null, 200, '标签更新成功');
            } catch (PDOException $e) {
                jsonResponse(null, 409, '标签名已存在');
            }

        case 'delete_tag':
            requireAuth();
            $data = getJsonInput();
            $id   = intval($data['id'] ?? 0);
            if ($id <= 0) jsonResponse(null, 400, '缺少标签ID');
            $db->prepare("DELETE FROM task_tags WHERE tag_id = :id")->execute(['id' => $id]);
            $db->prepare("DELETE FROM tags WHERE id = :id AND user_id = :uid")->execute(['id' => $id, 'uid' => $currentUserId]);
            writeLog("删除标签", ['id' => $id], $config);
            jsonResponse(null, 200, '标签已删除');

        /**
         * v4.0 新增：四象限视图（重要/紧急矩阵）
         */
        case 'quadrants':
            requireAuth();
            $stmt = $db->prepare("
                SELECT t.*, c.name AS category_name, c.color AS category_color
                FROM tasks t
                LEFT JOIN categories c ON t.category_id = c.id
                WHERE t.user_id = :uid AND t.is_deleted = 0 AND t.is_completed = 0
                ORDER BY t.due_datetime ASC NULLS LAST
            ");
            $stmt->execute(['uid' => $currentUserId]);
            $tasks = $stmt->fetchAll();
            attachTagsToTasks($tasks, $db);

            $today = date('Y-m-d');
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $result = [
                'important_urgent'      => [],
                'important_not_urgent'    => [],
                'not_important_urgent'    => [],
                'not_important_not_urgent' => []
            ];
            foreach ($tasks as $t) {
                $important = $t['priority'] === 'high';
                $urgent = false;
                if (!empty($t['due_datetime'])) {
                    $d = substr($t['due_datetime'], 0, 10);
                    if ($d <= $today || $d === $tomorrow) $urgent = true;
                }
                if ($important && $urgent) $result['important_urgent'][] = $t;
                elseif ($important && !$urgent) $result['important_not_urgent'][] = $t;
                elseif (!$important && $urgent) $result['not_important_urgent'][] = $t;
                else $result['not_important_not_urgent'][] = $t;
            }
            jsonResponse($result);

        /**
         * v4.0 新增：摘要统计
         */
        case 'summary':
            requireAuth();
            $today = date('Y-m-d');
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $weekLater = date('Y-m-d', strtotime('+7 days'));

            $stats = [];
            $stats['total'] = $db->query("SELECT COUNT(*) FROM tasks WHERE user_id = $currentUserId AND is_deleted = 0 AND is_completed = 0")->fetchColumn();
            $stats['today'] = $db->query("SELECT COUNT(*) FROM tasks WHERE user_id = $currentUserId AND is_deleted = 0 AND is_completed = 0 AND date(due_datetime) <= '$today'")->fetchColumn();
            $stats['tomorrow'] = $db->query("SELECT COUNT(*) FROM tasks WHERE user_id = $currentUserId AND is_deleted = 0 AND is_completed = 0 AND date(due_datetime) = '$tomorrow'")->fetchColumn();
            $stats['next7days'] = $db->query("SELECT COUNT(*) FROM tasks WHERE user_id = $currentUserId AND is_deleted = 0 AND is_completed = 0 AND date(due_datetime) >= '$today' AND date(due_datetime) <= '$weekLater'")->fetchColumn();
            $stats['overdue'] = $db->query("SELECT COUNT(*) FROM tasks WHERE user_id = $currentUserId AND is_deleted = 0 AND is_completed = 0 AND date(due_datetime) < '$today'")->fetchColumn();
            $stats['completed'] = $db->query("SELECT COUNT(*) FROM tasks WHERE user_id = $currentUserId AND is_deleted = 0 AND is_completed = 1")->fetchColumn();
            $stats['trash'] = $db->query("SELECT COUNT(*) FROM tasks WHERE user_id = $currentUserId AND is_deleted = 1")->fetchColumn();
            $stats['no_due'] = $db->query("SELECT COUNT(*) FROM tasks WHERE user_id = $currentUserId AND is_deleted = 0 AND is_completed = 0 AND due_datetime IS NULL")->fetchColumn();
            jsonResponse($stats);


        /**
         * v3.0 新增：日历月视图 - 获取指定月份所有任务的汇总数据
         * GET ?action=calendar_tasks&year=2026&month=7
         */
        case 'calendar_tasks':
            requireAuth();
            $year  = intval($_GET['year']  ?? date('Y'));
            $month = intval($_GET['month'] ?? date('m'));

            $startDate = sprintf('%04d-%02d-01', $year, $month);
            $endDate   = date('Y-m-d', strtotime($startDate . ' +1 month'));

            $stmt = $db->prepare("
                SELECT t.id, t.title, t.priority, t.is_completed, t.due_datetime,
                       date(t.due_datetime) AS due_date,
                       c.color AS category_color, c.name AS category_name
                FROM tasks t
                LEFT JOIN categories c ON t.category_id = c.id
                WHERE t.user_id = :uid AND t.is_deleted = 0
                  AND t.due_datetime >= :start AND t.due_datetime < :end
                ORDER BY t.due_datetime ASC
            ");
            $stmt->execute(['uid' => $currentUserId, 'start' => $startDate, 'end' => $endDate]);
            $tasks = $stmt->fetchAll();
            attachTagsToTasks($tasks, $db);

            // 按日期分组
            $calendar = [];
            foreach ($tasks as $task) {
                $d = $task['due_date'];
                if (!isset($calendar[$d])) {
                    $calendar[$d] = [];
                }
                $calendar[$d][] = $task;
            }

            jsonResponse([
                'year'     => $year,
                'month'    => $month,
                'calendar' => $calendar,
            ]);


        /**
         * 获取今日提醒（基于 reminder_offset 计算）
         * v3.0: 检查 due_datetime - reminder_offset <= now
         */
        case 'today_reminders':
            requireAuth();
            $now = date('Y-m-d H:i');
            $stmt = $db->prepare("
                SELECT t.id, t.title, t.priority, t.due_datetime, t.reminder_offset,
                       c.name AS category_name 
                FROM tasks t 
                LEFT JOIN categories c ON t.category_id = c.id 
                WHERE t.user_id = :uid AND t.is_completed = 0 AND t.is_deleted = 0
                  AND t.due_datetime IS NOT NULL
                  AND datetime(t.due_datetime, '-' || t.reminder_offset || ' minutes') <= :now
                  AND t.due_datetime >= datetime('now', 'localtime', '-30 minutes')
                ORDER BY t.due_datetime ASC
            ");
            $stmt->execute(['uid' => $currentUserId, 'now' => $now]);
            $reminders = $stmt->fetchAll();
            jsonResponse($reminders);

        // ==================== v5.0 任务状态流转 ====================

        case 'update_task_status':
            requireAuth();
            $data   = getJsonInput();
            $taskId = intval($data['id'] ?? 0);
            $status = $data['status'] ?? '';
            if ($taskId <= 0 || !in_array($status, ['todo','doing','done'])) {
                jsonResponse(null, 400, '参数不完整或状态无效');
            }
            $isCompleted = ($status === 'done') ? 1 : 0;
            $completedAt = ($status === 'done') ? "datetime('now','localtime')" : 'NULL';
            $db->prepare("
                UPDATE tasks SET status=:status, is_completed=:comp, 
                    completed_at=CASE WHEN :status='done' THEN datetime('now','localtime') ELSE NULL END,
                    updated_at=datetime('now','localtime')
                WHERE id=:id AND user_id=:uid
            ")->execute(['status' => $status, 'comp' => $isCompleted, 'id' => $taskId, 'uid' => $currentUserId]);
            writeLog("任务状态更新: {$status}", ['id' => $taskId], $config);
            jsonResponse(['id' => $taskId, 'status' => $status], 200, '状态已更新');

        // ==================== v5.0 主题皮肤 ====================

        case 'update_theme':
            requireAuth();
            $data  = getJsonInput();
            $theme = $data['theme'] ?? 'default';
            if (!in_array($theme, ['default','green','pink','dark','ocean','sunset'])) {
                jsonResponse(null, 400, '无效的主题');
            }
            $stmt = $db->prepare("SELECT id FROM user_settings WHERE user_id = :uid");
            $stmt->execute(['uid' => $currentUserId]);
            if ($stmt->fetch()) {
                $db->prepare("UPDATE user_settings SET theme=:t, updated_at=datetime('now','localtime') WHERE user_id=:uid")
                   ->execute(['t' => $theme, 'uid' => $currentUserId]);
            } else {
                $db->prepare("INSERT INTO user_settings (user_id, theme) VALUES (:uid, :t)")
                   ->execute(['uid' => $currentUserId, 't' => $theme]);
            }
            jsonResponse(['theme' => $theme], 200, '主题已切换');

        // ==================== v5.0 番茄钟 ====================

        case 'pomodoro_start':
            requireAuth();
            $data   = getJsonInput();
            $taskId = intval($data['task_id'] ?? 0);
            $work   = intval($data['work_duration'] ?? 25);
            $break  = intval($data['break_duration'] ?? 5);
            $stmt = $db->prepare("
                INSERT INTO pomodoro_sessions (user_id, task_id, work_duration, break_duration, status)
                VALUES (:uid, :tid, :w, :b, 'completed')
            ");
            $stmt->execute(['uid' => $currentUserId, 'tid' => $taskId > 0 ? $taskId : null, 'w' => $work, 'b' => $break]);
            $sid = $db->lastInsertId();
            // 任务番茄数 +1
            if ($taskId > 0) {
                $db->prepare("UPDATE tasks SET pomodoro_count=pomodoro_count+1 WHERE id=:id AND user_id=:uid")
                   ->execute(['id' => $taskId, 'uid' => $currentUserId]);
            }
            writeLog("番茄钟完成: {$work}分钟", ['task_id' => $taskId, 'session_id' => $sid], $config);
            jsonResponse(['session_id' => $sid, 'work_duration' => $work], 201, '番茄钟记录已保存');

        case 'pomodoro_today':
            requireAuth();
            $today = date('Y-m-d');
            $stmt = $db->prepare("
                SELECT COUNT(*) AS total, COALESCE(SUM(work_duration), 0) AS total_minutes,
                       COUNT(DISTINCT task_id) AS task_count
                FROM pomodoro_sessions
                WHERE user_id = :uid AND date(started_at) = :today AND status = 'completed'
            ");
            $stmt->execute(['uid' => $currentUserId, 'today' => $today]);
            $stats = $stmt->fetch();
            // 今日已完成的番茄按小时分布
            $stmt2 = $db->prepare("
                SELECT strftime('%H', started_at) AS hour, COUNT(*) AS cnt
                FROM pomodoro_sessions
                WHERE user_id = :uid AND date(started_at) = :today AND status = 'completed'
                GROUP BY hour ORDER BY hour
            ");
            $stmt2->execute(['uid' => $currentUserId, 'today' => $today]);
            $hourly = $stmt2->fetchAll();
            jsonResponse([
                'total'         => intval($stats['total']),
                'total_minutes' => intval($stats['total_minutes']),
                'task_count'    => intval($stats['task_count']),
                'hourly'        => $hourly,
            ]);

        case 'pomodoro_week_stats':
            requireAuth();
            $weekAgo = date('Y-m-d', strtotime('-6 days'));
            $stmt = $db->prepare("
                SELECT date(started_at) AS day, COUNT(*) AS cnt, COALESCE(SUM(work_duration), 0) AS minutes
                FROM pomodoro_sessions
                WHERE user_id = :uid AND date(started_at) >= :wk AND status = 'completed'
                GROUP BY day ORDER BY day
            ");
            $stmt->execute(['uid' => $currentUserId, 'wk' => $weekAgo]);
            jsonResponse($stmt->fetchAll());

        // ==================== v5.0 每日回顾 ====================

        case 'daily_review':
            requireAuth();
            $today = date('Y-m-d');
            // 今日完成的任务
            $done = $db->query("SELECT COUNT(*) FROM tasks WHERE user_id = $currentUserId AND is_deleted = 0 AND is_completed = 1 AND date(completed_at) = '$today'")->fetchColumn();
            // 今日创建的任务
            $created = $db->query("SELECT COUNT(*) FROM tasks WHERE user_id = $currentUserId AND is_deleted = 0 AND date(created_at) = '$today'")->fetchColumn();
            // 今日逾期未完成
            $overdue = $db->query("SELECT COUNT(*) FROM tasks WHERE user_id = $currentUserId AND is_deleted = 0 AND is_completed = 0 AND date(due_datetime) < '$today'")->fetchColumn();
            // 今日番茄
            $pomo = $db->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(work_duration),0) AS mins FROM pomodoro_sessions WHERE user_id = $currentUserId AND date(started_at) = '$today' AND status = 'completed'")->fetch();
            jsonResponse([
                'done_today'    => intval($done),
                'created_today' => intval($created),
                'overdue'       => intval($overdue),
                'pomo_count'    => intval($pomo['cnt']),
                'pomo_minutes'  => intval($pomo['mins']),
            ]);

        // 未知 action
        default:
            jsonResponse(null, 404, '未知的接口: ' . $action);

    }

} catch (PDOException $e) {
    writeLog("数据库错误: " . $e->getMessage(), [], $config);
    jsonResponse(null, 500, '服务器内部错误，请稍后重试');
} catch (Exception $e) {
    writeLog("系统错误: " . $e->getMessage(), [], $config);
    jsonResponse(null, 500, '服务器内部错误');
}
