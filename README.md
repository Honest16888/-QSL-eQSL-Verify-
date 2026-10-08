# 电子 QSL 交换验证系统（eQSL Verify）

一套可直接部署到网站的业余无线电 **电子 QSL 交换 + 双向验证** 系统。
PHP 8 + MySQL（PDO），**零第三方依赖、零 Composer、无构建步骤** —— 上传到虚拟主机/宝塔/云服务器即可运行。

---

## 一、它是怎么"验证"的

借鉴 eQSL / LotW 的思路：**一次通联必须在双方日志里都出现，才算确认。**

```
BH1ABC 导入日志 → 记录：BH1ABC ↔ JA1XYZ  2026-01-01 12:30Z  20m  FT8
JA1XYZ 导入日志 → 记录：JA1XYZ ↔ BH1ABC  2026-01-01 12:30Z  20m  FT8
                              ↓
            归一化指纹 match_key = sha1(呼号对 | UTC时间(分) | 波段 | 模式)
                              ↓
                    指纹一致 → 两条记录互相锁定、双向置为「已验证」
```

- **呼号对**排序后计算，谁先上传都一样；`/P` `/M` `/QRP` 等操作后缀自动剥离。
- **时间**精确到分钟、一律 UTC。`USB/LSB` 归一为 `SSB`，`20M`/`20 meters` 归一为 `20m`。
- 双方时间差 ≤ `match_tolerance_min`（默认 15 分钟）时进入 **「待确认」** 列表，由任一方一键人工确认，确认后双方同时置为已验证。
- 每条通联生成独立的 **16 位公开验证码**，任何人可凭码在 `/verify.php?code=...` 核验通联真实性（不泄露 RST、网格、备注）。

只有"双方日志一致"才验证，所以无法单方面伪造 —— 这是本系统和"自己上传卡片图"的本质区别。

---

## 二、功能

| 模块 | 说明 |
|---|---|
| 台站注册/登录 | 呼号即账号，密码 `password_hash` 存储，CSRF 防护，登录失败限速 |
| QSO 管理 | 单条录入 / 编辑 / 删除 / 按呼号·波段·模式·状态·日期筛选，分页 |
| ADIF 导入 | 支持 `.adi` 文件上传或文本粘贴；解析 ADIF 3.1.4；重复自动去重；可强制统一本方呼号 |
| ADIF 导出 | 带 `APP_EQSL_VERIFIED` / `APP_EQSL_CODE` 自定义字段，可回填 HRD / Log4OM / N1MM 等 |
| 待确认匹配 | 列出疑似匹配（时间偏差）与等待对方上传的记录 |
| 统计 | 波段/模式分布、DXCC 实体数、大洲分布、验证率（DXCC 只计已验证，与奖项口径一致） |
| 电子 QSL 卡 | 每呼号一个公开卡片页，支持自定义底图、设备天线、附言 |
| 公开验证页 | 凭验证码核验单次通联，无需登录 |
| 附加呼号 | 登记 `/P`、俱乐部台、旧呼号，导入日志时同样归户 |
| 后台管理 | 用户管理、权限、删除、站点设置、全站统计 |

---

## 三、目录结构

```
.
├── public/                 # ★ 站点文档根必须指向这里
│   ├── index.php           # 总览（含快速录入）
│   ├── login.php register.php logout.php
│   ├── qsos.php qso_edit.php
│   ├── import.php export.php
│   ├── matches.php confirm.php
│   ├── stats.php card.php verify.php profile.php
│   ├── admin.php
│   ├── install.php         # 安装向导（用完请删除）
│   ├── assets/style.css
│   └── uploads/            # 卡片底图（需可写，已禁止执行 PHP）
├── includes/               # 业务逻辑（Web 不可访问）
│   ├── bootstrap.php db.php util.php
│   ├── auth.php qsl.php    # qsl.php = 匹配与验证核心
│   ├── adif.php dxcc.php layout.php
├── config/
│   ├── config.sample.php   # → 复制为 config.php
│   └── config.php          # （自行创建，含数据库口令）
├── database/schema.sql     # 表结构 + 318 条 DXCC 前缀种子
├── deploy/                 # nginx.conf / apache.conf 示例
└── cli/                    # 自测脚本
    ├── selftest.php        # 核心逻辑 43 项自测（无需 MySQL）
    ├── check_schema.php    # schema.sql 静态校验
    └── config.test.php
```

---

## 四、部署

### 1. 环境要求

- PHP **7.4+ / 8.x**（推荐 8.1+），扩展：`pdo_mysql`、`mbstring`、`session`、`fileinfo`
- MySQL **5.7+** 或 MariaDB **10.2+**（utf8mb4）

### 2. 建库

```bash
mysql -u root -p
CREATE DATABASE qsl_db DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'qsl_user'@'localhost' IDENTIFIED BY '换成强口令';
GRANT ALL PRIVILEGES ON qsl_db.* TO 'qsl_user'@'localhost';
FLUSH PRIVILEGES;
```

（也可直接用 `mysql -u root -p qsl_db < database/schema.sql` 建表，跳过网页安装。）

### 3. 配置

```bash
cp config/config.sample.php config/config.php
```

编辑 `config/config.php`，至少改这几项：

```php
'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'qsl_db',
    'user' => 'qsl_user',
    'pass' => '你的强口令',
],
'base_url'    => 'https://qsl.example.com',   // 末尾不要带斜杠
'admin_email' => 'you@example.com',
'debug'       => false,                        // 生产务必 false
```

### 4. 上传代码

把整个目录上传到服务器（如 `/var/www/qsl`），**站点根目录指向 `public/`**。
Nginx 用 `deploy/nginx.conf`，Apache 用 `deploy/apache.conf`（`public/.htaccess` 已内置）。

```bash
chown -R www-data:www-data /var/www/qsl/public/uploads
chmod 755 /var/www/qsl/public/uploads
```

### 5. 初始化

浏览器访问 `https://你的域名/install.php` → 勾选确认 → 填写管理员呼号/邮箱/密码 → 执行。
完成后 **立即删除 `public/install.php`**，然后登录并把管理员标记为管理员（`is_admin`）。

### 宝塔面板

1. 软件商店装 **Nginx + MySQL + PHP 8.x**（PHP 需装 `fileinfo`、`mbstring` 扩展）；
2. 网站 → 添加站点 → 根目录选 `.../public`，数据库选 MySQL，字符集 `utf8mb4`；
3. 上传代码到站点目录（把 `config/`、`includes/`、`database/` 放在 `public` 的**同级**）；
4. 网站设置 → 伪静态：留空即可（本系统不用重写规则）；
5. 访问 `/install.php` 完成初始化，之后删除该文件。

---

## 五、日常使用流程

1. 注册 → 资料页填 QTH / 网格 / 设备，登记附加呼号；
2. 从日志软件导出 ADIF → 「ADIF 导入」；
3. 告诉通联过的朋友也来注册并导入；
4. 双方日志对上 → 自动验证；时间有偏差 → 「待确认」一键确认；
5. 「统计」查看 DXCC 进度，「导出 ADIF」把验证结果写回本地日志。

---

## 六、安全

- 全部 SQL 走 PDO 预处理，无拼接注入点；
- 所有写操作校验 CSRF token；
- 密码 `PASSWORD_DEFAULT`（bcrypt/argon2 自动升级）；
- 会话 Cookie `HttpOnly + SameSite=Lax`，HTTPS 下自动加 `Secure`；
- 输出统一 `htmlspecialchars`；
- 上传目录禁止执行脚本（`.htaccess` / nginx 规则已配置），类型用 `finfo` 校验；
- 公开验证页只暴露通联事实，不含 RST、网格、备注；
- ⚠ 生产环境请务必：`debug=false`、删除 `install.php`、强制 HTTPS、定期备份数据库。

---

## 七、自测

不需要 MySQL 也能验证核心逻辑（用 SQLite 内存库跑）：

```bash
php cli/selftest.php      # 43 项：ADIF 解析/导出、归一化、指纹、双向验证、人工确认、去重、DXCC、统计
php cli/check_schema.php   # schema.sql 结构校验
```

> `qso_suspects()` 使用了 MySQL 的 `TIMESTAMPDIFF`，在 SQLite 下会自动跳过，需在 MySQL 环境验证。

---

## 八、可扩展点

- **DXCC 前缀**：直接往 `dxcc` 表插行即可（按最长前缀匹配），可导入完整 ADIF 官方 cty 数据；
- **邮件通知**：确认成功时给对方发邮件（当前未内置 SMTP，避免部署环境差异）；
- **密码找回**：加 `password_resets` 表 + 邮件发送；
- **奖项**：`qsos` 已按波段/模式/DXCC 建索引，加 WAS / WAC / DXCC Honor Roll 只需写统计 SQL；
- **API**：可加 `public/api/verify.php` 输出 JSON，供第三方查询。

---

73 & Good DX! 📡
