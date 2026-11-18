# wxlink-ingest

Abasthan 免费 PHP 应用：微信知识库展示 + 链接/文档入库接口。

## 文件

| 文件 | 作用 |
| --- | --- |
| `index.php` | 展示页（搜索 / 分类 / 深色模式） |
| `ingest.php` | 入库接口，`POST /ingest` 或 `/ingest.php` |
| `lib.php` | 配置、存储、分类、Markdown、GitHub 同步 |
| `config.php` | token 与可选 GitHub 配置 |
| `seed_articles.json` | 首次运行用的种子数据 |
| `data/articles.json` | 运行时数据库（自动写入） |
| `data/notes/*.md` | 每篇文章生成的 Obsidian 笔记 |

## 接口

鉴权：请求头 `X-Auth-Token: <token>`，或 `?token=`，或 JSON 里的 `token` 字段。

```bash
# 健康检查
curl "https://<app>.abasthan.app/ingest?ping=1&token=<token>"

# 导出全部文章（本机同步脚本用）
curl "https://<app>.abasthan.app/ingest?export=1&token=<token>"

# 入库一条链接
curl -X POST "https://<app>.abasthan.app/ingest" \
  -H "X-Auth-Token: <token>" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://mp.weixin.qq.com/s/xxx","title":"标题","account":"公众号","date":"2026-11-18"}'

# 纯文本（自动抓第一条链接，首行当标题）
curl -X POST "https://<app>.abasthan.app/ingest" \
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
2. 服务端把每篇写成 `data/notes/<日期>-<标题>.md`，Obsidian 端用 Remotely Save / Syncthing 之类拉取。
3. 配置 `GITHUB_TOKEN` + `GITHUB_REPO`，入库时直接 commit 到私有 vault 仓库的 `WXLink/Inbox/`。
