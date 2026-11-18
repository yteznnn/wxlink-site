# wxlink-ingest

Abasthan 免费 PHP 应用：微信知识库展示 + 链接/文档入库接口。

线上地址：https://wxlink-notes.abasthan.app

## 文件

| 文件 | 作用 |
| --- | --- |
| `index.php` | 展示页（搜索 / 分类 / 深色模式 / 可选访问密码） |
| `ingest.php` | 入库接口，`POST /ingest` 或 `/ingest.php` |
| `lib.php` | 配置、存储、分类、Markdown、GitHub 同步、访问密码 |
| `config.php` | token、访问密码、数据路径（不入库，只在服务器上） |
| `config.sample.php` | 配置模板 |
| `seed_articles.json` | 首次运行用的种子数据 |
| `../wxdata/articles.json` | 运行时数据库（文档根目录之外，外部不可下载） |
| `../wxdata/notes/*.md` | 每篇文章生成的 Obsidian 笔记 |

## 访问控制

- 展示页：`config.php` 里配了 `page_user` + `page_pass` 就启用 HTTP Basic 认证，改密码后即时生效。
- 接口：始终需要 `token`，与页面密码互不影响。
- 数据文件放在 `../wxdata/`（文档根目录之外），浏览器直接请求 `/data/articles.json` 拿不到。

## 接口

鉴权：请求头 `X-Auth-Token: <token>`，或 `?token=`，或 JSON 里的 `token` 字段。

```bash
# 健康检查
curl "https://wxlink-notes.abasthan.app/ingest?ping=1&token=<token>"

# 导出全部文章（本机同步脚本用）
curl "https://wxlink-notes.abasthan.app/ingest?export=1&token=<token>"

# 入库一条链接
curl -X POST "https://wxlink-notes.abasthan.app/ingest" \
  -H "X-Auth-Token: <token>" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://mp.weixin.qq.com/s/xxx","title":"标题","account":"公众号","date":"2026-11-18"}'

# 纯文本（自动抓第一条链接，首行当标题）
curl -X POST "https://wxlink-notes.abasthan.app/ingest" \
  -H "X-Auth-Token: <token>" \
  --data-binary "标题第一行
https://mp.weixin.qq.com/s/xxx 后面随便写"
```

返回：`{"ok":true,"title":"...","category":"AI / Agent","note":"2026-11-18-xxx.md","github":{"ok":true,...}}`

## 分类规则

关键词匹配：`AI / Agent`、`开发 / 部署`、`工具 / 效率`、`阅读 / 思考`、`其他`。
入库时可传 `category` 字段直接指定，绕过推断。

## Obsidian 同步

三种方式（可任选，也能同时开）：
1. **本机脚本拉取（推荐，已可用）**：`sync_wxlink.ps1` 调 `/ingest?export=1`，把文章写成
   `WXLink/articles/<公众号>/<日期> <标题>.md`，frontmatter 为
   `title / source / received_at / account / category / description`。已存在的文件默认跳过。
2. 服务端把每篇写成 `wxdata/notes/<日期>-<标题>.md`，Obsidian 端用 Remotely Save / Syncthing 之类拉取。
3. 配置 `GITHUB_TOKEN` + `GITHUB_REPO`，入库时直接 commit 到私有 vault 仓库的 `WXLink/Inbox/`。

本机定时同步（Windows）：

```powershell
# 立即跑一次（新文章写进 vault，已存在的跳过）
powershell -ExecutionPolicy Bypass -File sync_wxlink.ps1

# 每天 09:00 自动跑（已注册的任务名为 wxlink-sync）
schtasks /Create /TN wxlink-sync /TR 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "<路径>\sync_wxlink.ps1"' /SC DAILY /ST 09:00 /F
```

日志写在 `sync_wxlink.log`（每行一次运行：时间、总数、新建、跳过）。

## 数据持久性（重要）

Abasthan 免费档**每次部署都会重建容器**：`~/app`（仓库文件）和 `~/wxdata` 都会重置。
所以：

- `~/wxdata` 只是运行时缓存，别当成长期存储。
- 部署后展示页自动回退到仓库里的 `seed_articles.json`（当前 18 篇），不会白页。
- 真正可靠的那份在本机 vault（`sync_wxlink.ps1` 每天写入），部署后跑一次同步就能对照补回。
- 需要立刻回灌：用 `backup\articles-*.json` 循环 `POST /ingest`（实测 18 篇约 1 分钟）。

## 更新部署

方式一：自动部署（已开启）
1. 把改动提交到 GitHub 仓库 `yteznnn/wxlink-site` 的 `main` 分支。
2. Abasthan 会自动重新部署。
3. 注意：重建容器会清空 `~/wxdata`，展示页会回退到仓库自带的 `seed_articles.json`；
   用 `backup\articles-*.json` 通过 `/ingest` 批量回灌即可。

方式二：手工替换单个文件
1. 改完文件传到 GitHub 仓库。
2. 服务器上 `cd ~/app && curl -s -o <文件> https://raw.githubusercontent.com/<repo>/main/<文件>?n=$RANDOM`。
3. `md5sum` 与本地比对，`php -l` 过一遍。

## 密钥（环境变量优先）

Abasthan 控制台 → Manage Enviroment 里配置，优先级高于 `config.php`，
重新部署后也不会丢：

| 变量 | 含义 |
| --- | --- |
| `WXLINK_TOKEN` | 接口 token |
| `WXLINK_USER` | 展示页用户名 |
| `WXLINK_PASS` | 展示页密码 |
| `WXLINK_DATA` / `WXLINK_SEED` | 可选，自定义数据/种子文件路径 |

`config.php` 只在服务器上手工维护，不要放进仓库。

