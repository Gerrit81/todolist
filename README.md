# TodoList — 任务管理系统

一个轻量级、功能完备的个人任务管理系统，基于 PHP + SQLite，零外部依赖，开箱即用。

---

## 功能特性

### 任务管理
- **CRUD 操作** — 创建 / 阅读 / 编辑 / 删除任务
- **三态工作流** — 待办 → 处理中 → 已完成
- **优先级** — 高 / 中 / 低
- **重复任务** — 每日/每周/每月/每年自动循环，支持设置截止日期和开始日期
- **截止日期 + 精确时间** — 支持到期提醒偏移（5分钟 / 15分钟 / 1小时 / 1天前）
- **自定义提醒** — 可指定任意日期和时间，跨周末/节假日使用
- **描述 + 备注** — 详细描述与补充说明双字段
- **附件上传** — 支持图片、PDF、文档，PDF 在线预览
- **子任务** — 任务拆分为阶段性子任务，独立截止日期
- **软删除** — 垃圾桶机制，误删可恢复
- **快速添加** — 顶部分类/标签/优先级一键创建
- **详细创建** — 弹窗模式，填写完整任务信息

### 多视图
| 视图 | 说明 |
|------|------|
| 📝 列表 | 智能分组：已过期 / 今天 / 明天 / 未来 / 无日期 |
| 📅 日历 | 月视图 / 周视图（含 ISO 周数）/ 日视图，撑满全高度自适应 |
| ➕ 四象限 | 艾森豪威尔矩阵：重要紧急 / 重要不紧急 / 紧急不重要 / 不重要不紧急 |
| 🍅 番茄钟 | 可配置工作时长/休息时长，关联任务，本周专注趋势柱状图 |
| ✅ 打卡 | 自定义习惯项目，每日打卡，连续天数/完成率/趋势热力图 |
| 📊 每日回顾 | 当日完成/新增/未完成统计 |

### 组织与查找
- **清单（分类）** — 自定义名称与颜色，侧边栏快速筛选
- **标签** — 多对多标签系统，颜色区分
- **搜索** — 实时搜索标题 / 备注 / 标签
- **导航** — 所有任务 / 今天 / 最近7天 / 收集箱 / 已完成 / 垃圾桶

### 效率工具
- **番茄钟** — 25 分钟专注 + 5 分钟休息（可自定义），本周趋势图表
- **桌面通知** — 任务到期浏览器通知
- **声音提醒** — 番茄钟结束音效
- **标签页闪烁** — 任务到期时浏览器标签闪烁

### 个性化
- **6 套主题皮肤** — 默认 / 护眼绿 / 樱花粉 / 暗夜模式 / 海洋蓝 / 日落橙
- **邮件提醒** — 纯 PHP SMTP 发送，支持 QQ/Gmail/企业邮箱

### 多用户
- 注册 / 登录 / 修改密码
- 用户数据完全隔离

---

## 技术栈

| 层级 | 技术 |
|------|------|
| 后端 | PHP 7.4+ |
| 数据库 | SQLite 3（文件型，无需安装） |
| 前端 | 原生 JavaScript（无框架） + CSS3 变量 |
| 邮件 | 纯 PHP SMTP（无需 PHPMailer） |
| 会话 | PHP Session |

零 Composer 依赖，零 Node.js 构建流程。

---

## 环境要求

- **PHP** ≥ 7.4
- **PHP 扩展**：`pdo`、`pdo_sqlite`、`json`、`mbstring`、`session`
- **Web 服务器**：Apache / Nginx / 群晖 Web Station / 任意支持 PHP 的环境
- **`data/` 目录可写**（用于 SQLite 数据库和会话存储）

---

## 快速安装

### 方法一：直接部署

```bash
# 1. 克隆仓库
git clone https://github.com/your-username/todolist.git

# 2. 将文件放入 Web 服务器目录
cp -r todolist /var/www/html/

# 3. 确保 data/ 目录可写
chmod 755 data/
chown -R www-data:www-data data/

# 4. 浏览器访问
# http://your-server/todolist/
```

### 方法二：群晖 Web Station

1. Web Station → 虚拟主机 → 新建 → 选择 PHP 版本（≥ 7.4）
2. 将项目文件上传到对应目录
3. PHP 设置中启用扩展：`pdo`、`pdo_sqlite`、`json`、`mbstring`
4. 确保 `data/` 目录对 `http` 用户可读写
5. 访问 `check.php` 验证环境 → 确认无误后删除此文件

### 首次使用

1. 打开页面 → 注册账号（用户名 + 密码）
2. 系统自动创建 4 个默认清单：收集箱 / 工作 / 生活 / 学习
3. 即可开始使用！

---

## 外网部署 — 安全必读

### 数据库防护

项目使用 SQLite 文件型数据库，**务必防止 `.db` 文件被直接下载**：

| 场景 | 方案 |
|------|------|
| **Apache** | 项目自带 `.htaccess`，自动阻止 `data/` 目录和 `.db`/`.log` 文件访问 |
| **Nginx** | 在 `server` 块中手动添加：`location /data/ { deny all; return 403; }` |
| **最佳实践** | 将 `data/` 目录移出 Web 根目录，修改 `config.php` 中 `db_path` 为绝对路径 |

### 敏感信息加密

- SMTP 邮箱密码使用 AES-256-CBC 加密存储，不再明文写入数据库
- 部署后务必修改 `config.php` 中的 `encrypt_key` 为随机字符串
- 生成方法：`php -r "echo bin2hex(random_bytes(32));"`

### 部署后检查清单

- [ ] 删除 `check.php`（环境诊断工具）
- [ ] 修改 `config.php` 中 `encrypt_key` 为随机值
- [ ] 确认 `data/` 目录无法通过浏览器直接访问
- [ ] 确保 `data/` 目录对 PHP 进程可读写
- [ ] 配置 HTTPS（生产环境必须）

---

## 目录结构

```
todolist/
├── index.php          # 应用入口 + 前端页面
├── api.php            # REST API（所有后端接口）
├── config.php         # 配置 / 数据库 / 认证 / SMTP / 加密
├── check.php          # 环境诊断工具（部署后建议删除）
├── .htaccess          # Apache 安全规则（v2.2.7+）
├── favicon.svg        # 网站图标
├── CHANGELOG.md       # 更新日志（完整版）
├── README.md          # 本文件
├── css/
│   └── style.css      # 全局样式 + 6 套主题皮肤
├── js/
│   └── app.js         # 前端应用逻辑
└── data/              # 运行时数据（自动创建）
    ├── .htaccess       # 禁止 HTTP 直接访问
    ├── todolist.db     # SQLite 数据库
    ├── app.log         # 应用日志
    ├── sessions/       # PHP Session 文件
    └── uploads/        # 附件上传目录
```

---

## API 接口

| Action | 方法 | 说明 |
|--------|------|------|
| `list_tasks` | GET | 获取任务列表（支持 filter / search / calendar_date） |
| `create_task` | POST | 创建任务 |
| `update_task` | POST | 更新任务 |
| `delete_task` | POST | 软删除任务 |
| `restore_task` | POST | 从垃圾桶恢复 |
| `permanent_delete` | POST | 永久删除 |
| `complete_task` | POST | 标记完成 / 取消完成 |
| `get_task` | GET | 获取单个任务详情 |
| `list_categories` | GET | 获取分类列表 |
| `create_category` | POST | 创建分类 |
| `update_category` | POST | 更新分类 |
| `delete_category` | POST | 删除分类 |
| `list_tags` | GET | 获取标签列表 |
| `create_tag` | POST | 创建标签 |
| `update_tag` | POST | 更新标签 |
| `delete_tag` | POST | 删除标签 |
| `quadrants` | GET | 四象限任务数据 |
| `calendar_tasks` | GET | 月历任务数据 |
| `pomodoro_today` | GET | 今日番茄钟统计 |
| `pomodoro_week_stats` | GET | 本周番茄钟趋势 |
| `pomodoro_record` | POST | 记录番茄钟完成 |
| `notifications` | GET | 待提醒任务 |
| `mark_notified` | POST | 标记已提醒 |
| `summary` | GET | 统计摘要 |
| `daily_review` | GET | 每日回顾 |
| `upload_attachment` | POST | 上传任务附件（multipart） |
| `download_attachment` | GET | 下载/预览附件（PDF inline） |
| `delete_attachment` | POST | 删除附件 |
| `list_attachments` | GET | 获取任务附件列表 |
| `list_subtasks` | GET | 获取子任务列表 |
| `list_habits` | GET | 获取打卡习惯列表 |
| `create_habit` | POST | 创建打卡习惯 |
| `update_habit` | POST | 更新打卡习惯 |
| `delete_habit` | POST | 删除打卡习惯 |
| `toggle_habit` | POST | 打卡/取消打卡 |
| `habit_stats` | GET | 单个习惯统计 |
| `all_habits_stats` | GET | 全部习惯概览统计 |
| `settings` | GET/POST | 用户设置 |
| `register` | POST | 用户注册 |
| `login` | POST | 用户登录 |
| `logout` | POST | 用户登出 |
| `change_password` | POST | 修改密码 |

所有接口返回统一 JSON 格式：

```json
{
  "success": true,
  "message": "",
  "data": {}
}
```

---

## 版本演进

从 v1.0.0 到 v2.2.7，横跨 20+ 个版本的关键节点一览：

| 版本 | 核心变化 |
|------|----------|
| **v1.0.0** | 任务 CRUD + 分类筛选 + 桌面通知 + SQLite 建表 |
| **v2.0.0** *(v2 认证版)* | 用户注册/登录、多用户数据隔离、SMTP 邮件提醒、声音/标签闪烁 |
| **v3.0.0** | 时间精度升级（`due_datetime`）、提醒偏移、撤销删除、编辑弹窗 |
| **v4.0.0** | 标签系统、软删除/垃圾桶、四象限、完整月历、搜索、分组视图 |
| **v5.0.0** *(v0.1.0-beta)* | 6 套主题皮肤、番茄钟、工作流三态、每日回顾 |
| **v2.0.0** *(v2 大版本)* | 子任务、附件（PDF 预览）、打卡系统、自定义提醒时间 |
| **v2.1.x** | 子任务列表标识、导航重排、滚动条统一、环境兼容修复 |
| **v2.2.x** | 重复任务（每日/每周/每月/每年）、虚拟展开、开始日期约束、安全加固 |
| **v2.2.7** | 数据库防盗链、SMTP 密码 AES-256 加密、安全响应头 |

完整记录见 [CHANGELOG.md](./CHANGELOG.md)。

---

## 配置说明

主要配置项在 `config.php` 顶部 `$config` 数组中：

```php
$config = [
    'app_name'    => '任务管理系统',
    'app_version' => '2.2.7',
    'db_path'     => __DIR__ . '/data/todolist.db',
    'log_path'    => __DIR__ . '/data/app.log',
];
```

数据库路径和日志路径可按需修改。

---

## 邮件提醒配置

1. 打开设置面板（顶栏 ⚙️ 按钮）
2. 开启「邮件提醒」
3. 填写 SMTP 信息：

| 邮箱 | SMTP 服务器 | 端口 | 加密 | 密码 |
|------|------------|------|------|------|
| QQ 邮箱 | smtp.qq.com | 587 | TLS | 授权码 |
| Gmail | smtp.gmail.com | 587 | TLS | 应用专用密码 |
| 163 邮箱 | smtp.163.com | 465 | SSL | 授权码 |

4. 点击「测试邮件」验证配置
5. 保存

---

## 数据库迁移

系统首次运行时自动创建所有表结构。后续版本升级时，`config.php` 中的 `initDatabase()` 函数会自动执行增量迁移，确保旧数据平滑升级，无需手动干预。

---

## 开发

```bash
# 本地快速启动（PHP 内置服务器）
cd todolist
php -S localhost:8080
# 浏览器访问 http://localhost:8080
```

文件修改说明：

- **CSS**：编辑 `css/style.css`，所有主题色通过 CSS 变量 `:root` / `[data-theme="xxx"]` 控制
- **JS**：编辑 `js/app.js`，视图切换 / API 调用 / UI 更新逻辑集中在此
- **后端**：`api.php` 路由分发 → `config.php` 工具函数

---

## License

MIT License

---

## 截图

> 可在项目中添加 `screenshots/` 目录存放截图，在此处引用。
