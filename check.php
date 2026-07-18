<?php
/**
 * 环境诊断脚本 — 上传到 todo 目录后，浏览器访问 check.php 即可查看结果
 */
header('Content-Type: text/html; charset=utf-8');
echo '<h2>📋 任务管理系统 — 环境诊断</h2>';
echo '<pre>';

// --- 1. PHP 版本 ---
echo '<b>[1] PHP 版本</b>: ' . phpversion() . "\n";
echo '   最低要求: PHP 7.4，当前' . (version_compare(phpversion(), '7.4', '>=') ? '✅ 满足' : '❌ 不满足') . "\n";
echo '   php.ini 路径: ' . php_ini_loaded_file() . "\n\n";

// --- 2. 关键扩展 ---
$requiredExtensions = ['pdo', 'pdo_sqlite', 'json', 'mbstring', 'session'];
echo "<b>[2] 关键扩展检查</b>:\n";
$missing = [];
foreach ($requiredExtensions as $ext) {
    $loaded = extension_loaded($ext);
    echo "   {$ext}: " . ($loaded ? '✅' : '❌ 缺少！') . "\n";
    if (!$loaded) $missing[] = $ext;
}
if (!empty($missing)) {
    echo "\n   ⚠️ 以下扩展缺失：【" . implode('、', $missing) . "】\n";
    echo "   ├─ 如果群晖「套件中心 → PHP 7.4」里已打勾，那说明你还差一步 ↓\n";
    echo "   └─ 必须进「Web Station → PHP 设置 → 点击你的 PHP 配置文件 → 扩展名」里重新打勾！\n";
    echo "      这是群晖特有的\"双层配置\"：套件中心只影响 CLI，Web Station 用独立的 php.ini。\n";
}
echo "\n";

// 2.1 显示当前实际加载的扩展列表（前 40 个）
echo "<b>[2.1] 已加载扩展列表</b> (共 " . count(get_loaded_extensions()) . " 个):\n";
$loadedExts = get_loaded_extensions();
sort($loadedExts, SORT_STRING | SORT_FLAG_CASE);
echo '   ' . implode(', ', array_slice($loadedExts, 0, 50)) . "\n";
echo "\n";

// --- 3. SQLite 驱动 ---
echo "<b>[3] PDO 驱动列表</b>:\n";
foreach (PDO::getAvailableDrivers() as $driver) {
    echo "   - {$driver}" . ($driver === 'sqlite' ? ' ✅' : '') . "\n";
}
if (!in_array('sqlite', PDO::getAvailableDrivers())) {
    echo "   ⚠️ 缺少 sqlite 驱动！请在群晖 WebStation → PHP 设置中启用 pdo_sqlite\n";
}
echo "\n";

// --- 4. 目录权限 ---
echo "<b>[4] 目录与文件权限</b>:\n";
echo '   __DIR__ = ' . __DIR__ . "\n";
echo '   是否可读: ' . (is_readable(__DIR__) ? '✅' : '❌') . "\n";
echo '   是否可写: ' . (is_writable(__DIR__) ? '✅' : '❌') . "\n\n";

$dataDir = __DIR__ . '/data';
echo "   data/ 目录: {$dataDir}\n";
if (is_dir($dataDir)) {
    echo '   目录存在: ✅' . "\n";
    echo '   是否可读: ' . (is_readable($dataDir) ? '✅' : '❌') . "\n";
    echo '   是否可写: ' . (is_writable($dataDir) ? '✅' : '❌') . "\n";
    echo '   权限: ' . substr(sprintf('%o', fileperms($dataDir)), -4) . "\n";
} else {
    echo '   目录不存在: ❌ (首次访问 index.php 时会自动创建)' . "\n";
    // 尝试创建
    if (@mkdir($dataDir, 0755, true)) {
        echo '   ✅ 尝试创建成功！';
        rmdir($dataDir);
    } else {
        echo '   ❌ 尝试创建失败！请手动创建 data/ 目录并给予 http 用户读写权限';
    }
}
echo "\n\n";

// --- 5. 数据库创建测试 ---
echo "<b>[5] SQLite 数据库创建测试</b>:\n";
if (in_array('sqlite', PDO::getAvailableDrivers())) {
    try {
        $testDir = __DIR__ . '/data';
        if (!is_dir($testDir)) {
            @mkdir($testDir, 0755, true);
        }
        $testDb = new PDO('sqlite:' . $testDir . '/_test_connect.db');
        $testDb->exec('CREATE TABLE IF NOT EXISTS test (id INTEGER PRIMARY KEY)');
        $testDb->exec("INSERT INTO test VALUES (1)");
        $result = $testDb->query("SELECT COUNT(*) FROM test")->fetchColumn();
        echo "   ✅ 数据库读写正常 (测试结果: {$result})\n";
        // 清理
        $testDb = null;
        @unlink($testDir . '/_test_connect.db');
    } catch (Exception $e) {
        echo "   ❌ 数据库测试失败: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ⛔ 跳过（缺少 PDO SQLite 驱动）\n";
}
echo "\n";

// --- 5.5. SQLite 版本 & NULLS LAST 兼容性 ---
echo "<b>[5.5] SQLite 详细信息</b>:\n";
if (in_array('sqlite', PDO::getAvailableDrivers())) {
    try {
        $testDir = __DIR__ . '/data';
        if (!is_dir($testDir)) @mkdir($testDir, 0755, true);
        $chkDb = new PDO('sqlite:' . $testDir . '/_chk.db');
        $sv = $chkDb->query("SELECT sqlite_version()")->fetchColumn();
        echo "   SQLite 版本: {$sv}\n";
        $needNulls = version_compare($sv, '3.30.0', '>=');
        echo "   NULLS LAST 支持: " . ($needNulls ? '✅ (≥ 3.30.0)' : '⚠️ 不支持！') . "\n";
        // 测试 NULLS LAST 是否真的能用
        try {
            $chkDb->exec("CREATE TABLE IF NOT EXISTS _tn (v TEXT)");
            $chkDb->query("SELECT v FROM _tn ORDER BY v ASC NULLS LAST");
            echo "   实测可用: ✅\n";
        } catch (\Exception $e) {
            echo "   实测: ❌ " . $e->getMessage() . "\n";
        }
        $chkDb = null;
        @unlink($testDir . '/_chk.db');
    } catch (\Exception $e) {
        echo "   ❌ " . $e->getMessage() . "\n";
    }
} else {
    echo "   ⛔ 跳过\n";
}
echo "\n";

// --- 6. Session ---
echo "<b>[6] Session 测试</b>:\n";
echo '   session.save_path: ' . ini_get('session.save_path') . "\n";
$defaultSessionPath = session_save_path();
echo '   默认 session 路径: ' . ($defaultSessionPath ?: '(空)') . "\n";
if ($defaultSessionPath) {
    echo '   是否可写: ' . (is_writable($defaultSessionPath) ? '✅' : '❌ (可能需要修改 session.save_path)') . "\n";
}
echo "\n";

// --- 6.5. DateTime / 日期计算测试 ---
echo "<b>[6.5] DateTime 日期计算测试</b>:\n";
try {
    $testIsoDate = '2026-10-15';
    $dt = new DateTime($testIsoDate);
    echo "   new DateTime('{$testIsoDate}'): " . $dt->format('Y-m-d') . " ✅\n";

    $dt->modify('+1 day');
    $nextDay = $dt->format('Y-m-d');
    echo "   +1 day: {$nextDay} " . ($nextDay === '2026-10-16' ? '✅' : '❌ 异常！') . "\n";

    // strtotime 测试
    $st = strtotime($testIsoDate . ' +1 day');
    if ($st === false) {
        echo "   ⚠️ strtotime('{$testIsoDate} +1 day') 返回 false！（这会导致日视图不显示任务）\n";
    } else {
        $stDay = date('Y-m-d', $st);
        echo "   strtotime('{$testIsoDate} +1 day'): {$stDay} " . ($stDay === '2026-10-16' ? '✅' : '⚠️ 结果异常') . "\n";
    }
} catch (\Exception $e) {
    echo "   ❌ 日期测试异常: " . $e->getMessage() . "\n";
}
echo "\n";

// --- 7. PHP 配置 ---
echo "<b>[7] PHP 关键配置</b>:\n";
echo '   open_basedir: ' . (ini_get('open_basedir') ?: '(未限制)') . "\n";
echo '   display_errors: ' . ini_get('display_errors') . " (❌ 建议在 config.php 中临时开启调试)\n";
echo '   error_reporting: ' . ini_get('error_reporting') . "\n";
echo "\n";

// --- 8. open_basedir 检查 ---
echo "<b>[8] open_basedir 路径检查</b>:\n";
$openBasedir = ini_get('open_basedir');
if ($openBasedir) {
    $allowedDirs = explode(PATH_SEPARATOR, $openBasedir);
    $currentDir = __DIR__;
    $isAllowed = false;
    foreach ($allowedDirs as $dir) {
        if (strpos($currentDir, trim($dir)) === 0) {
            $isAllowed = true;
            break;
        }
    }
    echo '   当前目录: ' . ($isAllowed ? '✅ 在允许范围内' : '❌ 不在 open_basedir 范围内！') . "\n";
    echo '   允许的路径: ' . implode(', ', $allowedDirs) . "\n";
} else {
    echo '   ✅ 无 open_basedir 限制' . "\n";
}

// --- 9. 重复任务诊断 ---
echo "<b>[9] 重复任务诊断 — 数据库中的循环任务</b>:\n";
try {
    // 直接连接数据库，不依赖 api.php（避免路由冲突）
    $dbPath = __DIR__ . '/data/todolist.db';
    if (!file_exists($dbPath)) {
        echo "   ❌ 数据库文件不存在: {$dbPath}\n";
    } else {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 检查表是否存在
        $tableCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tasks'")->fetch();
        if (!$tableCheck) {
            echo "   ⚠️ tasks 表不存在！数据库可能为空。\n";
        } else {
            // 列出所有有 recurrence_type 的任务
            $recurTasks = $db->query("
                SELECT id, title, due_datetime, recurrence_type, recurrence_rule,
                       recurrence_end, recurrence_start, is_completed, is_deleted, completion_count, created_at
                FROM tasks
                WHERE recurrence_type != '' AND recurrence_type IS NOT NULL
                ORDER BY due_datetime ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($recurTasks)) {
                echo "   ❌ 数据库中没有找到任何循环重复任务！\n";
                echo "   → 你的「每周四」任务可能在上传新版本时被覆盖/丢失了。\n\n";
                echo "   📊 数据库概况:\n";
                $totalTasks = $db->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
                echo "   tasks 表总记录数: {$totalTasks}\n";
            } else {
                echo "   ✅ 找到 " . count($recurTasks) . " 条循环任务:\n";
                foreach ($recurTasks as $rt) {
                    $status = $rt['is_deleted'] ? '已删除' : ($rt['is_completed'] ? '已完成' : '进行中');
                    $ruleDesc = '';
                    try {
                        $r = json_decode($rt['recurrence_rule'], true);
                        if (is_array($r)) {
                            if ($rt['recurrence_type'] === 'weekly' && !empty($r['days'])) {
                                $dn = [1=>'周一',2=>'周二',3=>'周三',4=>'周四',5=>'周五',6=>'周六',7=>'周日'];
                                $ruleDesc = implode('、', array_map(function($d) use ($dn) { return $dn[$d] ?? $d; }, $r['days']));
                            } elseif ($rt['recurrence_type'] === 'daily') {
                                $ruleDesc = '每' . ($r['interval'] ?? 1) . '天';
                            } elseif ($rt['recurrence_type'] === 'monthly') {
                                $ruleDesc = '每月' . ($r['day'] ?? '?') . '号';
                            } elseif ($rt['recurrence_type'] === 'yearly') {
                                $ruleDesc = '每年' . ($r['month'] ?? '?') . '月' . ($r['day'] ?? '?') . '日';
                            }
                        }
                    } catch (\Exception $e) {}
                    $rStartInfo = $rt['recurrence_start'] ?: ($rt['created_at'] ? $rt['created_at'] . ' (默认/created_at)' : '无');
                    echo "   [#{$rt['id']}] {$rt['title']} | 到期: {$rt['due_datetime']} | 开始: {$rStartInfo} | 重复: {$rt['recurrence_type']} {$ruleDesc} | 状态: {$status} | 完成{$rt['completion_count']}次\n";
                }

                // 筛选活跃的（未完成+未删除）
                $activeRecur = array_filter($recurTasks, function($t) { return !$t['is_deleted'] && !$t['is_completed']; });

                echo "\n   🔍 模拟虚拟展开（当前月 " . date('Y-m') . "）:\n";
                $start = date('Y-m-01');
                $end   = date('Y-m-d', strtotime($start . ' +1 month'));
                echo "   范围: {$start} ~ {$end}\n";

                if (empty($activeRecur)) {
                    echo "   ⚠️ 所有循环任务都已完成或已删除，没有活跃的重复任务可供展开！\n";
                    echo "   → 这说明你之前标记完成了所有该任务的实例，但没有下一期待办。\n";
                    echo "   → 请检查列表视图中是否有该任务，并取消完成状态以恢复重复展开。\n";
                } else {
                    // 内联简化的展开逻辑（避免依赖 api.php）
                    $virtuals = [];
                    $seen = [];
                    $rs = new DateTime($start);
                    $re = new DateTime($end);

                    foreach ($activeRecur as $ar) {
                        try {
                            $cur = new DateTime($ar['due_datetime']);
                        } catch (\Exception $e) { continue; }

                        $recurType = $ar['recurrence_type'];
                        $recurRule = json_decode($ar['recurrence_rule'], true) ?: [];
                        $recurEndDt = !empty($ar['recurrence_end']) ? @new DateTime($ar['recurrence_end']) : null;
                        // recurrence_start: 未设置则用 created_at
                        $rStartSrc = !empty($ar['recurrence_start']) ? $ar['recurrence_start'] : ($ar['created_at'] ?? null);
                        $recurStartDt = $rStartSrc ? @new DateTime($rStartSrc) : null;

                        // --- 如果 due_datetime 超出视图范围，先逆向回退 ---
                        if ($cur >= $re) {
                            for ($bi = 0; $bi < 300; $bi++) {
                                $prev = null;
                                try {
                                    $pt = clone $cur;
                                    switch ($recurType) {
                                        case 'daily':
                                            $interval = max(1, intval($recurRule['interval'] ?? 1));
                                            $pt->modify("-{$interval} days");
                                            $prev = $pt;
                                            break;
                                        case 'weekly':
                                            $rDays = $recurRule['days'] ?? [];
                                            if (!is_array($rDays) || empty($rDays)) {
                                                $pt->modify('-1 week');
                                                $prev = $pt;
                                            } else {
                                                $rDays = array_map('intval', $rDays);
                                                sort($rDays);
                                                $curDay = (int)$pt->format('N');
                                                $found = false;
                                                for ($di = count($rDays) - 1; $di >= 0; $di--) {
                                                    if ($rDays[$di] < $curDay) {
                                                        $pt->modify('-' . ($curDay - $rDays[$di]) . ' days');
                                                        $found = true; break;
                                                    }
                                                }
                                                if (!$found) $pt->modify('-' . (7 - $rDays[count($rDays)-1] + $curDay) . ' days');
                                                $prev = $pt;
                                            }
                                            break;
                                        case 'monthly':
                                            $day = max(1, min(31, intval($recurRule['day'] ?? 1)));
                                            $pt->modify('last day of previous month');
                                            $lastDay = (int)$pt->format('t');
                                            $pt->setDate($pt->format('Y'), $pt->format('m'), min($day, $lastDay));
                                            $prev = $pt;
                                            break;
                                        case 'yearly':
                                            $month = max(1, min(12, intval($recurRule['month'] ?? 1)));
                                            $day = max(1, min(31, intval($recurRule['day'] ?? 1)));
                                            $pt->modify('-1 year');
                                            $lastDay = (int)$pt->setDate($pt->format('Y'), $month, 1)->format('t');
                                            $pt->setDate($pt->format('Y'), $month, min($day, $lastDay));
                                            $prev = $pt;
                                            break;
                                    }
                                } catch (\Exception $e) { break; }
                                if (!$prev) break;
                                // v2.2.5: 停止于开始日期之前
                                if ($recurStartDt && $prev < $recurStartDt) break;
                                if ($prev < $rs) { $cur = $prev; break; }
                                $cur = $prev;
                            }
                        }

                        // --- 正向迭代 ---
                        for ($i = 0; $i < 300; $i++) {
                            // 内联 computeNextOccurrence 逻辑
                            $next = null;
                            try {
                                $dt = clone $cur;
                                switch ($recurType) {
                                    case 'daily':
                                        $interval = max(1, intval($recurRule['interval'] ?? 1));
                                        $dt->modify("+{$interval} days");
                                        $next = $dt;
                                        break;
                                    case 'weekly':
                                        $days = $recurRule['days'] ?? [];
                                        if (!is_array($days) || empty($days)) {
                                            $dt->modify('+1 week');
                                            $next = $dt;
                                        } else {
                                            $days = array_map('intval', $days);
                                            sort($days);
                                            $curDay = (int)$dt->format('N');
                                            $found = false;
                                            foreach ($days as $d) {
                                                if ($d > $curDay) { $dt->modify('+' . ($d - $curDay) . ' days'); $found = true; break; }
                                            }
                                            if (!$found) $dt->modify('+' . (7 - $curDay + $days[0]) . ' days');
                                            $next = $dt;
                                        }
                                        break;
                                    case 'monthly':
                                        $day = max(1, min(31, intval($recurRule['day'] ?? 1)));
                                        $dt->modify('first day of next month');
                                        $lastDay = (int)$dt->format('t');
                                        $dt->setDate($dt->format('Y'), $dt->format('m'), min($day, $lastDay));
                                        $next = $dt;
                                        break;
                                    case 'yearly':
                                        $month = max(1, min(12, intval($recurRule['month'] ?? 1)));
                                        $day = max(1, min(31, intval($recurRule['day'] ?? 1)));
                                        $dt->modify('+1 year');
                                        $lastDay = (int)$dt->setDate($dt->format('Y'), $month, 1)->format('t');
                                        $dt->setDate($dt->format('Y'), $month, min($day, $lastDay));
                                        $next = $dt;
                                        break;
                                }
                            } catch (\Exception $e) { break; }

                            if (!$next) break;
                            if ($recurEndDt && $next > $recurEndDt) break;
                            if ($next >= $re) break;

                            if ($next >= $rs) {
                                $key = $ar['title'] . '|' . $next->format('Y-m-d');
                                if (!isset($seen[$key])) {
                                    $seen[$key] = true;
                                    $virtuals[] = ['title' => $ar['title'], 'date' => $next->format('Y-m-d'), 'type' => $recurType];
                                }
                            }
                            $cur = $next;
                        }
                    }

                    if (empty($virtuals)) {
                        echo "   ⚠️ 虚拟展开结果: 0 条\n";
                        echo "   → 当前月范围内没有预估的重复实例。\n";
                        echo "   对每条活跃任务进行逐项诊断（逆向回退 + 正向推算）:\n";
                        foreach ($activeRecur as $ar) {
                            $recurType = $ar['recurrence_type'];
                            $recurRule = json_decode($ar['recurrence_rule'], true) ?: [];
                            $rStartInfo = $ar['recurrence_start'] ?: ($ar['created_at'] ?? '无');
                            echo "     任务 ID={$ar['id']}「{$ar['title']}」due_datetime={$ar['due_datetime']} recurrence_start={$rStartInfo} type={$recurType} rule=" . json_encode($recurRule) . "\n";
                            try {
                                $dt = new DateTime($ar['due_datetime']);
                                $inRange = $dt->format('Y-m-d') >= $start && $dt->format('Y-m-d') < substr($end, 0, 10);
                                echo "       当前到期日: {$dt->format('Y-m-d H:i')}" . ($inRange ? ' ✅ 在本月范围内' : ' ❌ 不在本月范围内') . "\n";

                                // 逆向回退 5 期
                                $backDts = [];
                                $bdt = clone $dt;
                                for ($j = 0; $j < 5; $j++) {
                                    switch ($recurType) {
                                        case 'daily':
                                            $bdt->modify('-' . max(1, intval($recurRule['interval'] ?? 1)) . ' days');
                                            break;
                                        case 'weekly':
                                            $rDays = isset($recurRule['days']) ? array_map('intval', $recurRule['days']) : [];
                                            if (!is_array($rDays) || empty($rDays)) {
                                                $bdt->modify('-1 week');
                                            } else {
                                                sort($rDays);
                                                $curDay = (int)$bdt->format('N');
                                                $fd = false;
                                                for ($di = count($rDays) - 1; $di >= 0; $di--) {
                                                    if ($rDays[$di] < $curDay) { $bdt->modify('-' . ($curDay - $rDays[$di]) . ' days'); $fd = true; break; }
                                                }
                                                if (!$fd) $bdt->modify('-' . (7 - $rDays[count($rDays)-1] + $curDay) . ' days');
                                            }
                                            break;
                                        case 'monthly':
                                            $day = max(1, min(31, intval($recurRule['day'] ?? 1)));
                                            $bdt->modify('last day of previous month');
                                            $lastDay = (int)$bdt->format('t');
                                            $bdt->setDate($bdt->format('Y'), $bdt->format('m'), min($day, $lastDay));
                                            break;
                                        case 'yearly':
                                            $month = max(1, min(12, intval($recurRule['month'] ?? 1)));
                                            $day = max(1, min(31, intval($recurRule['day'] ?? 1)));
                                            $bdt->modify('-1 year');
                                            $lastDay = (int)$bdt->setDate($bdt->format('Y'), $month, 1)->format('t');
                                            $bdt->setDate($bdt->format('Y'), $month, min($day, $lastDay));
                                            break;
                                    }
                                    $inR = $bdt->format('Y-m-d') >= $start && $bdt->format('Y-m-d') < substr($end, 0, 10);
                                    $backDts[] = ['dt' => clone $bdt, 'inR' => $inR];
                                }
                                echo "       🔙 逆向回退（往过去找）:\n";
                                foreach ($backDts as $k => $bd) {
                                    echo "        ← 第" . ($k+1) . "次回退: {$bd['dt']->format('Y-m-d H:i')}" . ($bd['inR'] ? ' ✅ 在范围内（应显示！）' : '') . "\n";
                                }

                                // 正向推算 5 期
                                echo "       🔜 正向推算（往未来找）:\n";
                                $fdt = clone $dt;
                                for ($j = 0; $j < 5; $j++) {
                                    switch ($recurType) {
                                        case 'daily':
                                            $fdt->modify('+' . max(1, intval($recurRule['interval'] ?? 1)) . ' days');
                                            break;
                                        case 'weekly':
                                            $rDays = isset($recurRule['days']) ? array_map('intval', $recurRule['days']) : [];
                                            if (!is_array($rDays) || empty($rDays)) {
                                                $fdt->modify('+1 week');
                                            } else {
                                                sort($rDays);
                                                $curDay = (int)$fdt->format('N');
                                                $fd = false;
                                                foreach ($rDays as $d) {
                                                    if ($d > $curDay) { $fdt->modify('+' . ($d - $curDay) . ' days'); $fd = true; break; }
                                                }
                                                if (!$fd) $fdt->modify('+' . (7 - $curDay + $rDays[0]) . ' days');
                                            }
                                            break;
                                        case 'monthly':
                                            $day = max(1, min(31, intval($recurRule['day'] ?? 1)));
                                            $fdt->modify('first day of next month');
                                            $lastDay = (int)$fdt->format('t');
                                            $fdt->setDate($fdt->format('Y'), $fdt->format('m'), min($day, $lastDay));
                                            break;
                                        case 'yearly':
                                            $month = max(1, min(12, intval($recurRule['month'] ?? 1)));
                                            $day = max(1, min(31, intval($recurRule['day'] ?? 1)));
                                            $fdt->modify('+1 year');
                                            $lastDay = (int)$fdt->setDate($fdt->format('Y'), $month, 1)->format('t');
                                            $fdt->setDate($fdt->format('Y'), $month, min($day, $lastDay));
                                            break;
                                    }
                                    $inR = $fdt->format('Y-m-d') >= $start && $fdt->format('Y-m-d') < substr($end, 0, 10);
                                    echo "        → 第" . ($j+1) . "期: {$fdt->format('Y-m-d H:i')}" . ($inR ? ' ✅ 在范围内（应显示）' : '') . "\n";
                                }
                            } catch (\Exception $e) {
                                echo "        → 日期解析失败: " . $e->getMessage() . "\n";
                            }
                        }
                    } else {
                        echo "   ✅ 虚拟展开成功，共生成 " . count($virtuals) . " 个虚拟实例:\n";
                        foreach ($virtuals as $v) {
                            echo "   📅 {$v['date']} — {$v['title']} ({$v['type']})\n";
                        }
                    }
                }
                echo "\n   💡 诊断结论 (v2.2.5):\n";
                echo "   ├─ 如果上面列出了你的「每周四」任务 → 后端逻辑正常\n";
                echo "   ├─ 如果开始日期 == created_at → 未手动设置，使用默认创建时间\n";
                echo "   ├─ 向前切换月历时不会生成早于 recurrence_start 的实例\n";
                echo "   ├─ 如果「所有循环任务都已完成」→ 取消完成，重新测试\n";
                echo "   ├─ 如果虚拟展开为 0 但逐项天数在范围内 → 计算逻辑BUG，请反馈\n";
                echo "   └─ 如果「数据库中没有找到」→ 数据丢失，请重新创建\n";
            }
        }
    }
} catch (\Exception $e) {
    echo "   ❌ 诊断执行失败: " . $e->getMessage() . "\n";
}

echo '</pre>';
echo '<p style="color:#666;">查完后请删除此文件以保安全。</p>';
