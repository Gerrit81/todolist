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
 * @version  2.4.1
 * @date     2026-07-18
 * =============================================================================
 */

require_once __DIR__ . '/config.php';

date_default_timezone_set('Asia/Shanghai');

// -------------------- 重复任务辅助函数 --------------------

/**
 * 根据重复规则计算下一次执行日期
 *
 * @param string $dueDatetime  当前到期时间 (YYYY-MM-DD HH:MM 或 YYYY-MM-DD)
 * @param string $recurType    重复类型: daily / weekly / monthly / yearly
 * @param string $recurRule    JSON 规则字符串
 *   - daily:   {"interval":2}    每N天
 *   - weekly:  {"days":[4,7]}    每周四和周日 (1=周一, 7=周日)
 *   - monthly: {"day":5}         每月5号
 *   - yearly:  {"month":3,"day":15}  每年3月15日
 * @return DateTime|null  下一次到期时间，计算失败返回 null
 */
function computeNextOccurrence($dueDatetime, $recurType, $recurRule) {
    if (empty($dueDatetime) || empty($recurType)) return null;

    try {
        $dt   = new DateTime($dueDatetime);
        $rule = json_decode($recurRule, true);
        if (!is_array($rule)) $rule = [];

        switch ($recurType) {
            case 'daily':
                $interval = max(1, intval($rule['interval'] ?? 1));
                $dt->modify("+{$interval} days");
                break;

            case 'weekly':
                $days = $rule['days'] ?? [];
                if (!is_array($days) || empty($days)) {
                    $dt->modify('+1 week');
                } else {
                    $days = array_map('intval', $days);
                    sort($days);
                    $curDay = (int)$dt->format('N'); // 1=Mon ... 7=Sun
                    $found = false;
                    foreach ($days as $d) {
                        if ($d > $curDay) {
                            $dt->modify('+' . ($d - $curDay) . ' days');
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $dt->modify('+' . (7 - $curDay + $days[0]) . ' days');
                    }
                }
                break;

        case 'monthly':
            $day = max(1, min(31, intval($rule['day'] ?? 1)));
            $currentDay = (int)$dt->format('j');
            if ($day > $currentDay) {
                // 本月目标日还在未来 → 留在本月
                $lastDay = (int)$dt->format('t');
                $dt->setDate($dt->format('Y'), $dt->format('m'), min($day, $lastDay));
            } else {
                // 本月目标日已过 → 跳到下个月
                $dt->modify('first day of next month');
                $lastDay = (int)$dt->format('t');
                $dt->setDate($dt->format('Y'), $dt->format('m'), min($day, $lastDay));
            }
            break;

        case 'yearly':
            $month = max(1, min(12, intval($rule['month'] ?? 1)));
            $day   = max(1, min(31, intval($rule['day'] ?? 1)));
            $currentMonth = (int)$dt->format('m');
            $currentDay   = (int)$dt->format('j');
            $currentYear  = (int)$dt->format('Y');
            if ($month > $currentMonth || ($month == $currentMonth && $day > $currentDay)) {
                // 今年目标日还在未来 → 留在今年
                $dt->setDate($currentYear, $month, 1);
                $lastDay = (int)$dt->format('t');
                $dt->setDate($currentYear, $month, min($day, $lastDay));
            } else {
                // 今年目标日已过 → 跳到下一年
                $dt->setDate($currentYear + 1, $month, 1);
                $lastDay = (int)$dt->format('t');
                $dt->setDate($currentYear + 1, $month, min($day, $lastDay));
            }
            break;

            default:
                return null;
        }

        return $dt;
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * 根据重复规则计算上一次执行日期（computeNextOccurrence 的逆向）
 *
 * @param string $dueDatetime  当前到期时间 (YYYY-MM-DD HH:MM 或 YYYY-MM-DD)
 * @param string $recurType    重复类型: daily / weekly / monthly / yearly
 * @param string $recurRule    JSON 规则字符串
 * @return DateTime|null  上一次到期时间，计算失败返回 null
 */
function computePrevOccurrence($dueDatetime, $recurType, $recurRule) {
    if (empty($dueDatetime) || empty($recurType)) return null;

    try {
        $dt   = new DateTime($dueDatetime);
        $rule = json_decode($recurRule, true);
        if (!is_array($rule)) $rule = [];

        switch ($recurType) {
            case 'daily':
                $interval = max(1, intval($rule['interval'] ?? 1));
                $dt->modify("-{$interval} days");
                break;

            case 'weekly':
                $days = $rule['days'] ?? [];
                if (!is_array($days) || empty($days)) {
                    $dt->modify('-1 week');
                } else {
                    $days = array_map('intval', $days);
                    sort($days);
                    $curDay = (int)$dt->format('N'); // 1=Mon ... 7=Sun
                    $found = false;
                    // 找最近的前一个匹配日
                    for ($i = count($days) - 1; $i >= 0; $i--) {
                        if ($days[$i] < $curDay) {
                            $dt->modify('-' . ($curDay - $days[$i]) . ' days');
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        // 回退到上一周的最后一天
                        $lastDayOfWeek = $days[count($days) - 1];
                        $dt->modify('-' . (7 - $lastDayOfWeek + $curDay) . ' days');
                    }
                }
                break;

        case 'monthly':
            $day = max(1, min(31, intval($rule['day'] ?? 1)));
            $currentDay = (int)$dt->format('j');
            if ($day < $currentDay) {
                // 本月目标日已过 → 上一次就是本月
                $lastDay = (int)$dt->format('t');
                $dt->setDate($dt->format('Y'), $dt->format('m'), min($day, $lastDay));
            } else {
                // 本月目标日还未到 → 上一次是上个月
                $dt->modify('last day of previous month');
                $lastDay = (int)$dt->format('t');
                $dt->setDate($dt->format('Y'), $dt->format('m'), min($day, $lastDay));
            }
            break;

        case 'yearly':
            $month = max(1, min(12, intval($rule['month'] ?? 1)));
            $day   = max(1, min(31, intval($rule['day'] ?? 1)));
            $currentMonth = (int)$dt->format('m');
            $currentDay   = (int)$dt->format('j');
            $currentYear  = (int)$dt->format('Y');
            if ($month < $currentMonth || ($month == $currentMonth && $day < $currentDay)) {
                // 今年目标日已过 → 上一次就是今年
                $dt->setDate($currentYear, $month, 1);
                $lastDay = (int)$dt->format('t');
                $dt->setDate($currentYear, $month, min($day, $lastDay));
            } else {
                // 今年目标日还未到 → 上一次是去年
                $dt->setDate($currentYear - 1, $month, 1);
                $lastDay = (int)$dt->format('t');
                $dt->setDate($currentYear - 1, $month, min($day, $lastDay));
            }
            break;

            default:
                return null;
        }

        return $dt;
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * 将重复任务在指定日期范围内虚拟展开
 * 从每条任务当前的 due_datetime 开始逐次推算后续发生日期，
 * 落在 $rangeStart ~ $rangeEnd 之间的生成虚拟实例（_virtual=1）。
 *
 * 如果 due_datetime 超出视图范围（未来），会先逆向回退查找，
 * 确保过去已发生的重复实例也能在月历/周历中显示。
 *
 * 这样月历/周历/日历视图就能看到重复任务的所有过去和未来实例，
 * 而不必等到用户完成当前实例后才生成下一期。
 *
 * @param array  $tasks      已加载的未完成重复任务数组
 * @param string $rangeStart 范围起始 Y-m-d（含）
 * @param string $rangeEnd   范围结束 Y-m-d（不含）
 * @param array  &$seenKeys  去重集合引用 {title|due_date => true}，会就地更新
 * @return array 虚拟实例数组
 */
function expandRecurringTasks($tasks, $rangeStart, $rangeEnd, &$seenKeys = []) {
    $virtuals = [];
    $maxGlobal = 600;

    try {
        $rs = new DateTime($rangeStart);
        $re = new DateTime($rangeEnd);
    } catch (\Exception $e) {
        return [];
    }

    foreach ($tasks as $task) {
        if (empty($task['recurrence_type'])) continue;
        if (!empty($task['is_completed'])) continue;
        if (empty($task['due_datetime'])) continue;

        // 安全解析 recurrence_end
        $recurEnd = null;
        if (!empty($task['recurrence_end'])) {
            try { $recurEnd = new DateTime($task['recurrence_end']); } catch (\Exception $e) {}
        }
        // 安全解析 recurrence_start（开始日期），未设置则回退用 created_at
        $recurStart = null;
        $recurStartSrc = !empty($task['recurrence_start']) ? $task['recurrence_start'] : ($task['created_at'] ?? null);
        if ($recurStartSrc) {
            try { $recurStart = new DateTime($recurStartSrc); } catch (\Exception $e) {}
        }
        // 安全解析当前 due_datetime
        try {
            $current = new DateTime($task['due_datetime']);
        } catch (\Exception $e) {
            continue; // 日期格式异常，跳过该任务
        }

        // v2.3.1: 用 recurrence_start 作为展开锚点，保证第一个命中日不会因
        // due_datetime 正好落在匹配日而被跳过（例如：开始日期 7/18、每周一，
        // 若 due_datetime=7/20(周一) 则 computeNextOccurrence 会跳到 7/27）
        if ($recurStart && $recurStart < $current) {
            $current = clone $recurStart;
        }

        // 如果 due_datetime 超出视图范围（在未来），逆向回退找到范围附近的锚点
        if ($current >= $re) {
            $backIter = 0;
            while ($backIter < 300) {
                $prev = computePrevOccurrence(
                    $current->format('Y-m-d H:i'),
                    $task['recurrence_type'],
                    $task['recurrence_rule']
                );
                if (!$prev) break;
                // v2.2.5: 停止于开始日期之前（不再无限往前展开）
                if ($recurStart && $prev < $recurStart) break;
                if ($prev < $rs) {
                    // $prev 是范围前的一次发生，从它开始正向推算，
                    // 第一个 computeNextOccurrence 就会命中范围内最早的一次
                    $current = $prev;
                    break;
                }
                $current = $prev;
                $backIter++;
            }
            // 此时 $current 是刚好在 $rs 之前的一次发生，
            // 正向迭代时第一个 next 就会落在范围内
        }

        // 正向迭代：从锚点开始推算未来发生日期
        $iter = 0;
        while ($iter < 300) {
            $next = computeNextOccurrence(
                $current->format('Y-m-d H:i'),
                $task['recurrence_type'],
                $task['recurrence_rule']
            );
            if (!$next) break;
            if ($recurEnd && $next > $recurEnd) break;
            if ($next >= $re) break;  // 超出查询范围

            if ($next >= $rs) {
                $key = ($task['title'] ?? '') . '|' . $next->format('Y-m-d');
                if (!isset($seenKeys[$key])) {
                    $seenKeys[$key] = true;
                    $vt = $task;
                    $vt['due_datetime']  = $next->format('Y-m-d H:i');
                    $vt['due_date']      = $next->format('Y-m-d');
                    $vt['_virtual']      = 1;
                    $vt['is_completed']  = 0; // 虚拟实例始终未完成
                    $virtuals[]          = $vt;
                }
            }

            $current = $next;
            $iter++;
            if (count($virtuals) >= $maxGlobal) break;
        }
        if (count($virtuals) >= $maxGlobal) break;
    }

    return $virtuals;
}

// ---- 每日自动备份（惰性触发，同一天仅执行一次） ----
autoBackupDaily($config);

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
            $data       = getJsonInput();
            $username   = trim($data['username'] ?? '');
            $password   = $data['password'] ?? '';
            $rememberMe = !empty($data['remember_me']);

            if (empty($username) || empty($password)) {
                jsonResponse(null, 400, '请输入用户名和密码');
            }

            $stmt = $db->prepare("SELECT id, username, password FROM users WHERE username = :u");
            $stmt->execute(['u' => $username]);
            $user = $stmt->fetch();

            if (!$user) jsonResponse(null, 401, '用户名或密码错误');
            if (!password_verify($password, $user['password'])) jsonResponse(null, 401, '用户名或密码错误');

            // 安全：登录成功后重新生成 Session ID，防止 Session 固定攻击
            session_regenerate_id(true);

            $_SESSION['user_id']       = $user['id'];
            $_SESSION['username']      = $user['username'];
            $_SESSION['persist_login'] = $rememberMe;  // 标记是否「保持登录」

            // 「保持登录」：设置持久化 Cookie（30 天有效）
            if ($rememberMe) {
                $lifetime = 30 * 24 * 3600;
                setcookie(session_name(), session_id(), time() + $lifetime, '/', '', false, true);
            }

            recordLoginHistory($db, $user['id']);
            writeLog("用户登录: {$username}" . ($rememberMe ? ' [保持登录]' : ''), ['user_id' => $user['id']], $config);
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
                $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$currentUserId]);
                $role = $stmt->fetchColumn() ?: 'user';
                jsonResponse(['user_id' => $currentUserId, 'username' => getCurrentUsername(), 'role' => $role]);
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
            $decrypted = decryptSensitive($settings['smtp_password'], $config);
            $settings['smtp_password'] = !empty($decrypted) ? '******' : '';
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
                // 用户未修改密码：保留原有值（可能已加密的 AES:... 或旧版明文）
                $finalPassword = $existing['smtp_password'] ?? '';
                // 迁移旧版明文密码 → 自动加密
                if (!empty($finalPassword) && substr($finalPassword, 0, 4) !== 'AES:') {
                    $finalPassword = encryptSensitive($finalPassword, $config);
                }
            } else {
                // 用户输入了新密码 → 加密存储
                $finalPassword = encryptSensitive($newPassword, $config);
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
                'password'   => decryptSensitive($settings['smtp_password'], $config),
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

            // v6.0: 使用 reminder_offset + reminder_custom 计算提醒时间，并排除已删除任务
            $now = date('Y-m-d H:i');
            $stmt = $db->prepare("
                SELECT t.title, t.priority, t.due_datetime, t.reminder_offset, t.reminder_custom,
                       c.name AS category_name 
                FROM tasks t 
                LEFT JOIN categories c ON t.category_id = c.id 
                WHERE t.user_id = :uid AND t.is_completed = 0 AND t.is_deleted = 0
                  AND (
                    (t.due_datetime IS NOT NULL AND t.reminder_custom IS NULL
                     AND datetime(t.due_datetime, '-' || t.reminder_offset || ' minutes') <= :now
                     AND t.due_datetime >= :today_rem)
                    OR
                    (t.reminder_custom IS NOT NULL AND t.reminder_custom <= :now
                     AND t.reminder_custom >= :today_rem)
                  )
                ORDER BY COALESCE(t.reminder_custom, t.due_datetime) ASC
            ");
            $stmt->execute(['uid' => $currentUserId, 'now' => $now, 'today_rem' => date('Y-m-d')]);
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
                'password'   => decryptSensitive($settings['smtp_password'], $config),
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
                // 使用 DateTime 计算次日，避免 strtotime('YYYY-MM-DD +1 day') 在 Windows 某些 PHP 版本下解析失败返回 false，导致 date('Y-m-d', false) = '1970-01-01' 使范围筛选永远为空
                try {
                    $dt = new DateTime($calendar_date);
                    $dt->modify('+1 day');
                    $nextDay = $dt->format('Y-m-d');
                } catch (\Exception $e) {
                    // 极端降级：手动拆分计算
                    $parts = explode('-', $calendar_date);
                    $nextDay = date('Y-m-d', mktime(0, 0, 0, (int)$parts[1], (int)$parts[2] + 1, (int)$parts[0]));
                }
                $sql .= " AND t.due_datetime >= :cal_start AND t.due_datetime < :cal_end";
                $params['cal_start'] = $calendar_date;
                $params['cal_end']   = $nextDay;
            } elseif ($filter === 'today') {
                $today_php = date('Y-m-d');
                $sql .= " AND t.is_completed = 0 AND date(t.due_datetime) <= :today_php";
                $params['today_php'] = $today_php;
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
                $tomorrow_php = date('Y-m-d', strtotime('+1 day'));
                $sql .= " AND t.is_completed = 0 AND date(t.due_datetime) >= :tomorrow_php";
                $params['tomorrow_php'] = $tomorrow_php;
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
                $sql .= " ORDER BY CASE WHEN t.due_datetime IS NULL THEN 1 ELSE 0 END, t.due_datetime ASC, {$priorityOrder}";
            } elseif ($sort_by === 'date_desc') {
                $sql .= " ORDER BY CASE WHEN t.due_datetime IS NULL THEN 1 ELSE 0 END, t.due_datetime DESC, {$priorityOrder}";
            } elseif ($sort_by === 'priority') {
                $sql .= " ORDER BY {$priorityOrder}, CASE WHEN t.due_datetime IS NULL THEN 1 ELSE 0 END, t.due_datetime ASC";
            } else {
                $sql .= " ORDER BY t.is_completed ASC, {$priorityOrder}, CASE WHEN t.due_datetime IS NULL THEN 1 ELSE 0 END, t.due_datetime ASC, t.created_at DESC";
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $tasks = $stmt->fetchAll();

            // 附加标签
            attachTagsToTasks($tasks, $db);

            // v6.1: 附加子任务计数（父任务在列表中时显示子任务进度）
            if (!empty($tasks)) {
                $taskIds = array_map(function($t) { return intval($t['id']); }, $tasks);
                $idList = implode(',', $taskIds);
                if (!empty($idList)) {
                    $subCountStmt = $db->query("
                        SELECT parent_id, COUNT(*) AS subtask_count,
                               SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) AS subtask_done
                        FROM tasks
                        WHERE parent_id IN ($idList) AND is_deleted = 0
                        GROUP BY parent_id
                    ");
                    $subCountMap = [];
                    while ($row = $subCountStmt->fetch()) {
                        $subCountMap[intval($row['parent_id'])] = $row;
                    }
                    foreach ($tasks as &$t) {
                        $tid = intval($t['id']);
                        if (isset($subCountMap[$tid])) {
                            $t['subtask_count'] = intval($subCountMap[$tid]['subtask_count']);
                            $t['subtask_done'] = intval($subCountMap[$tid]['subtask_done']);
                        } else {
                            $t['subtask_count'] = 0;
                            $t['subtask_done'] = 0;
                        }
                    }
                    unset($t);
                }
            }

            // v2.2.1: 虚拟展开重复任务 — 日期筛选视图显示未来所有重复实例
            $dateFilters = ['calendar_date','today','tomorrow','next7days','upcoming'];
            if (in_array($filter, $dateFilters, true) || !empty($calendar_date)) {
                // 确定需要展开的日期范围
                $todayStr = date('Y-m-d');
                $expandStart = $todayStr;
                $expandEnd   = date('Y-m-d', strtotime('+1 year'));
                if (!empty($calendar_date)) {
                    // 单天视图
                    try {
                        $cd = new DateTime($calendar_date);
                        $expandStart = $cd->format('Y-m-d');
                        $expandEnd   = $cd->modify('+1 day')->format('Y-m-d');
                    } catch (\Exception $e) {
                        $expandStart = $calendar_date;
                        $expandEnd   = $calendar_date;
                    }
                } elseif ($filter === 'today') {
                    $expandEnd = date('Y-m-d', strtotime('+1 day'));
                } elseif ($filter === 'tomorrow') {
                    $expandStart = date('Y-m-d', strtotime('+1 day'));
                    $expandEnd   = date('Y-m-d', strtotime('+2 days'));
                } elseif ($filter === 'next7days') {
                    $expandEnd = date('Y-m-d', strtotime('+8 days'));
                } elseif ($filter === 'upcoming') {
                    $expandStart = date('Y-m-d', strtotime('+1 day'));
                }

                // 获取所有活跃的重复任务
                $recurStmt = $db->prepare("
                    SELECT t.*, c.name AS category_name, c.color AS category_color
                    FROM tasks t
                    LEFT JOIN categories c ON t.category_id = c.id
                    WHERE t.user_id = :uid AND t.is_deleted = 0 AND t.is_completed = 0
                      AND t.recurrence_type != '' AND t.recurrence_type IS NOT NULL
                      AND t.due_datetime IS NOT NULL
                    ORDER BY t.due_datetime ASC
                ");
                $recurStmt->execute(['uid' => $currentUserId]);
                $allRecur = $recurStmt->fetchAll();

                // 构建去重键
                $seenKeys = [];
                foreach ($tasks as $t) {
                    $dd = !empty($t['due_datetime']) ? substr($t['due_datetime'], 0, 10) : '';
                    $seenKeys[($t['title'] ?? '') . '|' . $dd] = true;
                }
                $virtuals = expandRecurringTasks($allRecur, $expandStart, $expandEnd, $seenKeys);

                if (!empty($virtuals)) {
                    attachTagsToTasks($virtuals, $db);
                    // 虚拟实例附加到 tasks 数组
                    $tasks = array_merge($tasks, $virtuals);
                    // 重新排序：按 due_datetime
                    usort($tasks, function($a, $b) {
                        $da = $a['due_datetime'] ?? '9999';
                        $db = $b['due_datetime'] ?? '9999';
                        return strcmp($da, $db);
                    });
                }
            }

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
            // 附加附件列表
            $attStmt = $db->prepare("SELECT * FROM task_attachments WHERE task_id = :tid");
            $attStmt->execute(['tid' => $taskId]);
            $task['attachments'] = $attStmt->fetchAll();
            // 附加子任务列表
            $subStmt = $db->prepare("
                SELECT t.*, c.name AS category_name, c.color AS category_color
                FROM tasks t LEFT JOIN categories c ON t.category_id = c.id
                WHERE t.parent_id = :pid AND t.is_deleted = 0
                ORDER BY CASE WHEN t.due_datetime IS NULL THEN 1 ELSE 0 END, t.due_datetime ASC
            ");
            $subStmt->execute(['pid' => $taskId]);
            $subtasks = $subStmt->fetchAll();
            attachTagsToTasks($subtasks, $db);
            $task['subtasks'] = $subtasks;
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

            $parentId = $data['parent_id'] ?? null;
            if ($parentId !== null) $parentId = intval($parentId);
            if ($parentId === 0) $parentId = null;

            $reminderCustom = $data['reminder_custom'] ?? null;
            if ($reminderCustom !== null && $reminderCustom !== '') {
                // 验证格式
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $reminderCustom)) {
                    jsonResponse(null, 400, '自定义提醒时间格式无效，应为 YYYY-MM-DD HH:MM');
                }
            }

            // 重复任务字段
            $recurType = trim($data['recurrence_type'] ?? '');
            $recurType = in_array($recurType, ['daily','weekly','monthly','yearly'], true) ? $recurType : '';
            $recurRule = $recurType ? (trim($data['recurrence_rule'] ?? '')) : '';
            $recurEnd  = $recurType ? ($data['recurrence_end'] ?? null) : null;
            if ($recurEnd !== null && $recurEnd === '') $recurEnd = null;
            $recurStart = $recurType ? ($data['recurrence_start'] ?? null) : null;
            if ($recurStart !== null && $recurStart === '') $recurStart = null;

            $stmt = $db->prepare("
                INSERT INTO tasks (user_id, title, category_id, priority, due_datetime, reminder_offset, reminder_custom, notes, description, status, parent_id, recurrence_type, recurrence_rule, recurrence_end, recurrence_start) 
                VALUES (:uid, :title, :category_id, :priority, :due_datetime, :reminder_offset, :reminder_custom, :notes, :description, :status, :parent_id, :recurrence_type, :recurrence_rule, :recurrence_end, :recurrence_start)
            ");
            $stmt->execute([
                'uid'              => $currentUserId,
                'title'            => $title,
                'category_id'      => $catId,
                'priority'         => $data['priority'] ?? 'medium',
                'due_datetime'     => $data['due_datetime'] ?? null,
                'reminder_offset'  => intval($data['reminder_offset'] ?? 0),
                'reminder_custom'  => $reminderCustom,
                'notes'            => trim($data['notes'] ?? ''),
                'description'      => trim($data['description'] ?? ''),
                'status'           => $data['status'] ?? 'todo',
                'parent_id'        => $parentId,
                'recurrence_type'  => $recurType,
                'recurrence_rule'  => $recurRule,
                'recurrence_end'   => $recurEnd,
                'recurrence_start' => $recurStart,
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

            $parentId = $data['parent_id'] ?? null;
            if ($parentId !== null) $parentId = intval($parentId);
            if ($parentId === 0) $parentId = null;

            $reminderCustom = $data['reminder_custom'] ?? null;
            if ($reminderCustom !== null && $reminderCustom !== '') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $reminderCustom)) {
                    jsonResponse(null, 400, '自定义提醒时间格式无效');
                }
            }

            // 重复任务字段
            $recurType = trim($data['recurrence_type'] ?? '');
            $recurType = in_array($recurType, ['daily','weekly','monthly','yearly'], true) ? $recurType : '';
            $recurRule = $recurType ? (trim($data['recurrence_rule'] ?? '')) : '';
            $recurEnd  = $recurType ? ($data['recurrence_end'] ?? null) : null;
            if ($recurEnd !== null && $recurEnd === '') $recurEnd = null;
            $recurStart = $recurType ? ($data['recurrence_start'] ?? null) : null;
            if ($recurStart !== null && $recurStart === '') $recurStart = null;

            $stmt = $db->prepare("
                UPDATE tasks 
                SET title=:title, category_id=:category_id, priority=:priority, 
                    due_datetime=:due_datetime, reminder_offset=:reminder_offset, 
                    reminder_custom=:reminder_custom, notes=:notes, description=:description,
                    status=:status, parent_id=:parent_id,
                    recurrence_type=:recurrence_type, recurrence_rule=:recurrence_rule, recurrence_end=:recurrence_end, recurrence_start=:recurrence_start,
                    updated_at=datetime('now','localtime')
                WHERE id=:id AND user_id=:uid
            ");
            $stmt->execute([
                'title'           => $title,
                'category_id'     => intval($data['category_id'] ?? 0),
                'priority'        => $data['priority'] ?? 'medium',
                'due_datetime'    => $data['due_datetime'] ?? null,
                'reminder_offset' => intval($data['reminder_offset'] ?? 0),
                'reminder_custom' => $reminderCustom,
                'notes'           => trim($data['notes'] ?? ''),
                'description'     => trim($data['description'] ?? ''),
                'status'          => $data['status'] ?? 'todo',
                'parent_id'       => $parentId,
                'recurrence_type' => $recurType,
                'recurrence_rule' => $recurRule,
                'recurrence_end'  => $recurEnd,
                'recurrence_start'=> $recurStart,
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
                // 先获取任务信息，判断是否有重复规则
                $task = $db->query("SELECT * FROM tasks WHERE id = $taskId AND user_id = $currentUserId")->fetch();
                if (!$task) jsonResponse(null, 404, '任务不存在');

                // 循环任务：推进 due_datetime 到下一期，不标记 is_completed
                if (!empty($task['recurrence_type']) && !empty($task['due_datetime'])) {
                    $nextDt = computeNextOccurrence($task['due_datetime'], $task['recurrence_type'], $task['recurrence_rule']);
                    if ($nextDt) {
                        $nextDue = $nextDt->format('Y-m-d H:i');
                        $exceedEnd = false;
                        if (!empty($task['recurrence_end'])) {
                            $endDt = new DateTime($task['recurrence_end']);
                            if ($nextDt > $endDt) $exceedEnd = true;
                        }
                        if (!$exceedEnd) {
                            // 推进到下一期：更新 due_datetime + recurrence_start（防止虚拟展开重复生成已完成的次），
                            // 重置 reminder_custom，increment completion_count，保持 is_completed=0
                            $newCount = intval($task['completion_count'] ?? 0) + 1;
                            $db->prepare("UPDATE tasks SET due_datetime=:due, recurrence_start=:rstart, completion_count=:cnt, reminder_custom=NULL, updated_at=datetime('now','localtime') WHERE id=:id AND user_id=:uid")
                               ->execute(['due' => $nextDue, 'rstart' => $nextDue, 'cnt' => $newCount, 'id' => $taskId, 'uid' => $currentUserId]);
                            writeLog("循环任务推进", ['id' => $taskId, 'next' => $nextDue, 'count' => $newCount], $config);
                            jsonResponse(['due_datetime' => $nextDue, 'completion_count' => $newCount], 200, '任务已推进到下一期');
                        }
                        // exceedEnd：超过截止日期 → 正常标记完成
                    }
                    // nextDt 为空（无法计算）或无更多次 → 正常标记完成
                }

                // 普通任务 / 循环已到期：正常标记完成
                $db->prepare("UPDATE tasks SET is_completed=1, completed_at=datetime('now','localtime'), updated_at=datetime('now','localtime') WHERE id=:id AND user_id=:uid")
                   ->execute(['id' => $taskId, 'uid' => $currentUserId]);
                writeLog("任务标记完成", ['id' => $taskId], $config);
                jsonResponse(null, 200, '任务已完成');
            } else {
                $db->prepare("UPDATE tasks SET is_completed=0, completed_at=NULL, updated_at=datetime('now','localtime') WHERE id=:id AND user_id=:uid")
                   ->execute(['id' => $taskId, 'uid' => $currentUserId]);
                writeLog("任务取消完成", ['id' => $taskId], $config);
                jsonResponse(null, 200, '任务已恢复');
            }

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
                ORDER BY CASE WHEN t.due_datetime IS NULL THEN 1 ELSE 0 END, t.due_datetime ASC
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

            // v2.3.3: 循环任务的虚拟实例补充计数
            // 基础查询只统计了 real tasks 的 due_datetime；循环任务完成推进后
            // due_datetime 已移到未来，但仍会在今天/7天内产生虚拟实例，需补入
            $recurStmt = $db->query("SELECT t.*, c.name AS category_name, c.color AS category_color
                FROM tasks t LEFT JOIN categories c ON t.category_id = c.id
                WHERE t.user_id = $currentUserId AND t.is_deleted = 0 AND t.is_completed = 0
                AND t.recurrence_type IS NOT NULL AND t.recurrence_type != ''");
            $recurringTasks = $recurStmt->fetchAll();

            if (!empty($recurringTasks)) {
                $origDueMap = [];
                foreach ($recurringTasks as $rt) {
                    $origDueMap[$rt['id']] = substr($rt['due_datetime'] ?? '', 0, 10);
                }

                // Today: 展开当天虚拟实例
                $todayEnd = date('Y-m-d', strtotime('+1 day'));
                $seens = [];
                $todayVirts = expandRecurringTasks($recurringTasks, $today, $todayEnd, $seens);
                $todayExtra = 0;
                $seenIds = [];
                foreach ($todayVirts as $v) {
                    $vd = substr($v['due_datetime'], 0, 10);
                    if ($vd >= $today && $vd < $todayEnd) {
                        $oid = $v['id'];
                        $origDue = $origDueMap[$oid] ?? '';
                        // 原任务 due_date 不在今天/之前 → 基础查询没计入，补 +1
                        if ((!$origDue || $origDue > $today) && !isset($seenIds[$oid])) {
                            $seenIds[$oid] = true;
                            $todayExtra++;
                        }
                    }
                }
                $stats['today'] += $todayExtra;

                // Next7days: 展开 7 天虚拟实例
                $weekEnd = date('Y-m-d', strtotime('+8 days'));
                $seens7 = [];
                $weekVirts = expandRecurringTasks($recurringTasks, $today, $weekEnd, $seens7);
                $weekExtra = 0;
                $seenIds7 = [];
                foreach ($weekVirts as $v) {
                    $vd = substr($v['due_datetime'], 0, 10);
                    if ($vd >= $today && $vd <= $weekLater) {
                        $oid = $v['id'];
                        $origDue = $origDueMap[$oid] ?? '';
                        // 原任务 due_date 不在 7 日范围内 → 基础查询没计入，补 +1
                        if ((!$origDue || $origDue < $today || $origDue > $weekLater) && !isset($seenIds7[$oid])) {
                            $seenIds7[$oid] = true;
                            $weekExtra++;
                        }
                    }
                }
                $stats['next7days'] += $weekExtra;
            }

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
                       c.color AS category_color, c.name AS category_name,
                       t.recurrence_type, t.recurrence_rule, t.recurrence_end, t.recurrence_start, t.created_at, t.completion_count
                FROM tasks t
                LEFT JOIN categories c ON t.category_id = c.id
                WHERE t.user_id = :uid AND t.is_deleted = 0
                  AND t.due_datetime >= :start AND t.due_datetime < :end
                ORDER BY t.due_datetime ASC
            ");
            $stmt->execute(['uid' => $currentUserId, 'start' => $startDate, 'end' => $endDate]);
            $tasks = $stmt->fetchAll();
            attachTagsToTasks($tasks, $db);

            // v2.2.1: 虚拟展开重复任务 —— 月历中显示所有未来重复实例
            $recurStmt = $db->prepare("
                SELECT t.id, t.title, t.priority, t.is_completed, t.due_datetime,
                       c.color AS category_color, c.name AS category_name,
                       t.recurrence_type, t.recurrence_rule, t.recurrence_end, t.recurrence_start, t.created_at, t.completion_count
                FROM tasks t
                LEFT JOIN categories c ON t.category_id = c.id
                WHERE t.user_id = :uid AND t.is_deleted = 0 AND t.is_completed = 0
                  AND t.recurrence_type != '' AND t.recurrence_type IS NOT NULL
                  AND t.due_datetime IS NOT NULL
                ORDER BY t.due_datetime ASC
            ");
            $recurStmt->execute(['uid' => $currentUserId]);
            $allRecur = $recurStmt->fetchAll();

            // 构建去重键：已有任务标题+日期不会重复生成
            $seenKeys = [];
            foreach ($tasks as $t) {
                $seenKeys[($t['title'] ?? '') . '|' . ($t['due_date'] ?? '')] = true;
            }
            $virtuals = expandRecurringTasks($allRecur, $startDate, $endDate, $seenKeys);
            $tasks = array_merge($tasks, $virtuals);

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
            $now   = date('Y-m-d H:i');
            $min30 = date('Y-m-d H:i', strtotime('-30 minutes'));
            $min24h = date('Y-m-d H:i', strtotime('-24 hours'));
            $stmt = $db->prepare("
                SELECT t.id, t.title, t.priority, t.due_datetime, t.reminder_offset, t.reminder_custom,
                       c.name AS category_name 
                FROM tasks t 
                LEFT JOIN categories c ON t.category_id = c.id 
                WHERE t.user_id = :uid AND t.is_completed = 0 AND t.is_deleted = 0
                  AND (
                    (t.due_datetime IS NOT NULL AND t.reminder_custom IS NULL
                     AND datetime(t.due_datetime, '-' || t.reminder_offset || ' minutes') <= :now
                     AND t.due_datetime >= :min30)
                    OR
                    (t.reminder_custom IS NOT NULL AND t.reminder_custom <= :now
                     AND t.reminder_custom >= :min24h)
                  )
                ORDER BY COALESCE(t.reminder_custom, t.due_datetime) ASC
            ");
            $stmt->execute(['uid' => $currentUserId, 'now' => $now, 'min30' => $min30, 'min24h' => $min24h]);
            $reminders = $stmt->fetchAll();
            jsonResponse($reminders);

        /**
         * 懒人模式：延时提醒
         * POST: id, minutes (5/10/15/30)
         */
        case 'snooze_reminder':
            requireAuth();
            $taskId  = intval($_POST['id'] ?? 0);
            $minutes = intval($_POST['minutes'] ?? 5);
            if ($taskId <= 0 || !in_array($minutes, [5, 10, 15, 30])) {
                jsonResponse(null, 400, '参数错误');
            }
            $stmt = $db->prepare("SELECT id, due_datetime FROM tasks WHERE id = :id AND user_id = :uid");
            $stmt->execute(['id' => $taskId, 'uid' => $currentUserId]);
            $task = $stmt->fetch();
            if (!$task) jsonResponse(null, 400, '任务不存在');

            // 设置 reminder_custom = now + delay，due 路径由 reminder_custom IS NULL 守卫自动排除
            $newReminder = date('Y-m-d H:i', strtotime('+' . $minutes . ' minutes'));
            $stmt = $db->prepare("UPDATE tasks SET reminder_custom = :rc WHERE id = :id AND user_id = :uid");
            $stmt->execute(['rc' => $newReminder, 'id' => $taskId, 'uid' => $currentUserId]);
            if ($stmt->rowCount() === 0) jsonResponse(null, 400, '更新失败，请重试');
            jsonResponse(['reminder_custom' => $newReminder, 'minutes' => $minutes]);

        /**
         * 不再提醒：清除该任务所有提醒设置
         * POST: id
         */
        case 'dismiss_reminder':
            requireAuth();
            $taskId = intval($_POST['id'] ?? 0);
            if ($taskId <= 0) jsonResponse(null, 400, '参数错误');
            $stmt = $db->prepare("UPDATE tasks SET reminder_custom = NULL, reminder_offset = 0 WHERE id = :id AND user_id = :uid");
            $stmt->execute(['id' => $taskId, 'uid' => $currentUserId]);
            if ($stmt->rowCount() === 0) jsonResponse(null, 400, '任务不存在');
            jsonResponse(['dismissed' => true]);

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
            if (!in_array($theme, ['default','green','pink','dark','ocean','sunset','stone','coffee','midnight','frost','sand','lavender'])) {
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

        // ==================== v6.0 附件管理 ====================

        case 'upload_attachment':
            requireAuth();
            $taskId = intval($_POST['task_id'] ?? 0);
            if ($taskId <= 0) jsonResponse(null, 400, '缺少任务ID');

            // 验证任务归属
            $task = $db->query("SELECT id FROM tasks WHERE id = $taskId AND user_id = $currentUserId")->fetch();
            if (!$task) jsonResponse(null, 404, '任务不存在');

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                jsonResponse(null, 400, '文件上传失败');
            }

            $file = $_FILES['file'];
            $maxSize = 20 * 1024 * 1024; // 20MB
            if ($file['size'] > $maxSize) {
                jsonResponse(null, 400, '文件大小不能超过 20MB');
            }

            $uploadDir = __DIR__ . '/data/uploads/' . $currentUserId . '/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $origName = $file['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $safeName = $taskId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $uploadDir . $safeName;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                jsonResponse(null, 500, '文件保存失败');
            }

            $stmt = $db->prepare("
                INSERT INTO task_attachments (user_id, task_id, filename, orig_name, file_size, file_type)
                VALUES (:uid, :tid, :fn, :oname, :fs, :ft)
            ");
            $stmt->execute([
                'uid'   => $currentUserId,
                'tid'   => $taskId,
                'fn'    => $safeName,
                'oname' => $origName,
                'fs'    => $file['size'],
                'ft'    => $file['type'],
            ]);
            $attId = $db->lastInsertId();

            writeLog("上传附件: {$origName}", ['task_id' => $taskId, 'attachment_id' => $attId], $config);
            jsonResponse(['id' => $attId, 'orig_name' => $origName, 'file_size' => $file['size']], 201, '文件上传成功');

        case 'list_attachments':
            requireAuth();
            $taskId = intval($_GET['task_id'] ?? 0);
            if ($taskId <= 0) jsonResponse(null, 400, '缺少任务ID');

            $stmt = $db->prepare("
                SELECT * FROM task_attachments 
                WHERE task_id = :tid AND user_id = :uid 
                ORDER BY created_at DESC
            ");
            $stmt->execute(['tid' => $taskId, 'uid' => $currentUserId]);
            jsonResponse($stmt->fetchAll());

        case 'download_attachment':
            $attId = intval($_GET['id'] ?? 0);
            if ($attId <= 0) { http_response_code(400); exit('缺少附件ID'); }

            $stmt = $db->prepare("SELECT * FROM task_attachments WHERE id = :id");
            $stmt->execute(['id' => $attId]);
            $att = $stmt->fetch();
            if (!$att) { http_response_code(404); exit('附件不存在'); }

            $filePath = __DIR__ . '/data/uploads/' . $att['user_id'] . '/' . $att['filename'];
            if (!file_exists($filePath)) { http_response_code(404); exit('文件不存在'); }

            // PDF 在线预览（inline）vs 其他下载（attachment）
            $disposition = (stripos($att['file_type'], 'pdf') !== false || stripos($att['orig_name'], '.pdf') !== false) ? 'inline' : 'attachment';

            header('Content-Type: ' . ($att['file_type'] ?: 'application/octet-stream'));
            header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($att['orig_name']) . '"');
            header('Content-Length: ' . $att['file_size']);
            header('Cache-Control: private, max-age=3600');
            readfile($filePath);
            exit;

        case 'delete_attachment':
            requireAuth();
            $data  = getJsonInput();
            $attId = intval($data['id'] ?? 0);
            if ($attId <= 0) jsonResponse(null, 400, '缺少附件ID');

            $stmt = $db->prepare("SELECT * FROM task_attachments WHERE id = :id AND user_id = :uid");
            $stmt->execute(['id' => $attId, 'uid' => $currentUserId]);
            $att = $stmt->fetch();
            if (!$att) jsonResponse(null, 404, '附件不存在');

            $filePath = __DIR__ . '/data/uploads/' . $att['user_id'] . '/' . $att['filename'];
            if (file_exists($filePath)) @unlink($filePath);

            $db->prepare("DELETE FROM task_attachments WHERE id = :id")->execute(['id' => $attId]);
            writeLog("删除附件: " . $att['orig_name'], ['id' => $attId], $config);
            jsonResponse(null, 200, '附件已删除');

        // ==================== v6.0 子任务 ====================

        case 'list_subtasks':
            requireAuth();
            $parentId = intval($_GET['parent_id'] ?? 0);
            if ($parentId <= 0) jsonResponse(null, 400, '缺少父任务ID');

            $stmt = $db->prepare("
                SELECT t.*, c.name AS category_name, c.color AS category_color
                FROM tasks t 
                LEFT JOIN categories c ON t.category_id = c.id 
                WHERE t.parent_id = :pid AND t.user_id = :uid AND t.is_deleted = 0
                ORDER BY CASE WHEN t.due_datetime IS NULL THEN 1 ELSE 0 END, t.due_datetime ASC, t.created_at ASC
            ");
            $stmt->execute(['pid' => $parentId, 'uid' => $currentUserId]);
            $subtasks = $stmt->fetchAll();
            attachTagsToTasks($subtasks, $db);
            jsonResponse($subtasks);

        // ==================== v6.0 打卡模块 ====================

        case 'list_habits':
            requireAuth();
            $stmt = $db->prepare("
                SELECT * FROM habits 
                WHERE user_id = :uid AND is_archived = 0
                ORDER BY sort_order ASC, id ASC
            ");
            $stmt->execute(['uid' => $currentUserId]);
            $habits = $stmt->fetchAll();

            $today = date('Y-m-d');
            foreach ($habits as &$h) {
                $h['checked_today'] = $db->query("
                    SELECT COUNT(*) FROM habit_logs 
                    WHERE habit_id = {$h['id']} AND check_date = '$today'
                ")->fetchColumn() > 0;
            }
            jsonResponse($habits);

        case 'create_habit':
            requireAuth();
            $data = getJsonInput();
            $name = trim($data['name'] ?? '');
            if ($name === '') jsonResponse(null, 400, '习惯名称不能为空');

            $stmt = $db->prepare("
                INSERT INTO habits (user_id, name, icon, color, target_days, sort_order)
                VALUES (:uid, :name, :icon, :color, :td, :so)
            ");
            $stmt->execute([
                'uid'   => $currentUserId,
                'name'  => $name,
                'icon'  => $data['icon'] ?? '📌',
                'color' => $data['color'] ?? '#4A90D9',
                'td'    => $data['target_days'] ?? '1,2,3,4,5,6,7',
                'so'    => $data['sort_order'] ?? 0,
            ]);
            $newId = $db->lastInsertId();
            writeLog("创建打卡习惯: {$name}", ['id' => $newId], $config);
            jsonResponse(['id' => $newId, 'name' => $name], 201, '习惯创建成功');

        case 'update_habit':
            requireAuth();
            $data = getJsonInput();
            $id   = intval($data['id'] ?? 0);
            $name = trim($data['name'] ?? '');
            if ($id <= 0 || $name === '') jsonResponse(null, 400, '参数不完整');

            $stmt = $db->prepare("
                UPDATE habits SET name=:name, icon=:icon, color=:color, target_days=:td
                WHERE id=:id AND user_id=:uid
            ");
            $stmt->execute([
                'id'   => $id,
                'uid'  => $currentUserId,
                'name' => $name,
                'icon' => $data['icon'] ?? '📌',
                'color'=> $data['color'] ?? '#4A90D9',
                'td'   => $data['target_days'] ?? '1,2,3,4,5,6,7',
            ]);
            jsonResponse(null, 200, '习惯已更新');

        case 'delete_habit':
            requireAuth();
            $data = getJsonInput();
            $id   = intval($data['id'] ?? 0);
            if ($id <= 0) jsonResponse(null, 400, '缺少习惯ID');

            $db->prepare("DELETE FROM habit_logs WHERE habit_id = :id")->execute(['id' => $id]);
            $db->prepare("DELETE FROM habits WHERE id = :id AND user_id = :uid")->execute(['id' => $id, 'uid' => $currentUserId]);
            writeLog("删除打卡习惯", ['id' => $id], $config);
            jsonResponse(null, 200, '习惯已删除');

        case 'toggle_habit':
            requireAuth();
            $data     = getJsonInput();
            $habitId  = intval($data['id'] ?? 0);
            $date     = $data['date'] ?? date('Y-m-d');
            if ($habitId <= 0) jsonResponse(null, 400, '缺少习惯ID');

            $existing = $db->query("
                SELECT id FROM habit_logs 
                WHERE habit_id = $habitId AND check_date = '$date'
            ")->fetch();

            if ($existing) {
                $db->prepare("DELETE FROM habit_logs WHERE id = :id")->execute(['id' => $existing['id']]);
                jsonResponse(['checked' => false], 200, '已取消打卡');
            } else {
                $stmt = $db->prepare("
                    INSERT INTO habit_logs (habit_id, user_id, check_date, note)
                    VALUES (:hid, :uid, :date, :note)
                ");
                $stmt->execute([
                    'hid'  => $habitId,
                    'uid'  => $currentUserId,
                    'date' => $date,
                    'note' => $data['note'] ?? '',
                ]);
                jsonResponse(['checked' => true], 200, '打卡成功！');
            }

        case 'habit_stats':
            requireAuth();
            $habitId = intval($_GET['habit_id'] ?? 0);
            if ($habitId <= 0) jsonResponse(null, 400, '缺少习惯ID');

            $allLogs = $db->query("
                SELECT check_date FROM habit_logs 
                WHERE habit_id = $habitId 
                ORDER BY check_date DESC
            ")->fetchAll(PDO::FETCH_COLUMN);

            $totalChecks = count($allLogs);
            $today = date('Y-m-d');

            // 计算连续打卡天数
            $streak = 0;
            $checkSet = array_flip($allLogs);
            $d = new DateTime($today);
            if (!isset($checkSet[$today])) {
                $d->modify('-1 day');
            }
            while (isset($checkSet[$d->format('Y-m-d')])) {
                $streak++;
                $d->modify('-1 day');
            }

            // 本周完成率
            $weekStart = date('Y-m-d', strtotime('monday this week'));
            $weekChecks = $db->query("
                SELECT COUNT(*) FROM habit_logs 
                WHERE habit_id = $habitId AND check_date >= '$weekStart'
            ")->fetchColumn();

            // 本月完成率
            $monthStart = date('Y-m-01');
            $monthChecks = $db->query("
                SELECT COUNT(*) FROM habit_logs 
                WHERE habit_id = $habitId AND check_date >= '$monthStart'
            ")->fetchColumn();

            // 近30天数据
            $days30ago = date('Y-m-d', strtotime('-29 days'));
            $recentLogs = $db->query("
                SELECT check_date FROM habit_logs 
                WHERE habit_id = $habitId AND check_date >= '$days30ago'
                ORDER BY check_date ASC
            ")->fetchAll(PDO::FETCH_COLUMN);

            $dailyMap = [];
            $dayNames = ['日', '一', '二', '三', '四', '五', '六'];
            for ($i = 29; $i >= 0; $i--) {
                $dt = date('Y-m-d', strtotime("-{$i} days"));
                $dailyMap[] = [
                    'date'  => $dt,
                    'day'   => $dayNames[date('w', strtotime($dt))],
                    'checked' => in_array($dt, $recentLogs),
                ];
            }

            // 日期的周数（本周 7 天）
            $daysInWeek = (new DateTime())->format('N'); // 1=Mon...7=Sun

            jsonResponse([
                'total_checks'  => $totalChecks,
                'streak'        => $streak,
                'week_checks'   => intval($weekChecks),
                'week_total'    => intval($daysInWeek),
                'week_rate'     => $daysInWeek > 0 ? round($weekChecks / $daysInWeek * 100) : 0,
                'month_checks'  => intval($monthChecks),
                'month_total'   => intval(date('d')),
                'month_rate'    => date('d') > 0 ? round($monthChecks / intval(date('d')) * 100) : 0,
                'daily_30'      => $dailyMap,
            ]);

        case 'all_habits_stats':
            requireAuth();
            $today = date('Y-m-d');
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT hl.habit_id) as today_count
                FROM habit_logs hl
                JOIN habits h ON hl.habit_id = h.id
                WHERE h.user_id = :uid AND hl.check_date = :today
            ");
            $stmt->execute(['uid' => $currentUserId, 'today' => $today]);
            $todayCount = intval($stmt->fetch()['today_count'] ?? 0);

            $totalHabits = $db->query("
                SELECT COUNT(*) FROM habits WHERE user_id = $currentUserId AND is_archived = 0
            ")->fetchColumn();

            // 近7天趋势
            $trend = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                $cnt = $db->query("
                    SELECT COUNT(DISTINCT hl.habit_id)
                    FROM habit_logs hl
                    JOIN habits h ON hl.habit_id = h.id
                    WHERE h.user_id = $currentUserId AND hl.check_date = '$d'
                ")->fetchColumn();
                $weekdays = ['日', '一', '二', '三', '四', '五', '六'];
                $trend[] = [
                    'date'  => $d,
                    'day'   => $weekdays[date('w', strtotime($d))],
                    'count' => intval($cnt),
                ];
            }

            jsonResponse([
                'today_count'  => $todayCount,
                'total_habits' => intval($totalHabits),
                'trend'        => $trend,
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
