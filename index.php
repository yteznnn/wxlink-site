<?php
// index.php — 微信知识库展示页 + /ingest 路由
require_once __DIR__ . '/lib.php';

// 让 /ingest 也能指向 ingest.php（nginx 没有 rewrite 时靠这里兜底）
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$rel = $base !== '' && strpos($path, $base) === 0 ? substr($path, strlen($base)) : $path;
if (preg_match('#^/ingest(/|\.php)?/?$#', $rel)) {
    require __DIR__ . '/ingest.php';
    exit;
}

$articles = wx_load();

// 旧数据没有 category 字段时现场推断
foreach ($articles as &$a) {
    if (empty($a['category'])) {
        $a['category'] = wx_classify($a);
    }
}
unset($a);

// 按日期倒序
usort($articles, function ($a, $b) {
    $c = strcmp($b['date'] ?? '', $a['date'] ?? '');
    return $c !== 0 ? $c : strcmp($b['added_at'] ?? '', $a['added_at'] ?? '');
});

$totalCount = count($articles);
$accounts = [];
foreach ($articles as $a) {
    if (!empty($a['account'])) {
        $accounts[$a['account']] = ($accounts[$a['account']] ?? 0) + 1;
    }
}
$latestDate = $articles[0]['date'] ?? '';

$search   = trim($_GET['q'] ?? '');
$category = trim($_GET['cat'] ?? '');

$categories = [];
foreach ($articles as $a) {
    $cat = $a['category'] ?? '其他';
    $categories[$cat] = ($categories[$cat] ?? 0) + 1;
}
arsort($categories);

$filtered = $articles;
if ($search !== '') {
    $kw = mb_strtolower($search);
    $filtered = array_filter($filtered, function ($a) use ($kw) {
        foreach (['title', 'account', 'summary', 'desc', 'text'] as $field) {
            if ($field === 'text') {
                continue; // 正文太长，不参与搜索
            }
            if (mb_strpos(mb_strtolower($a[$field] ?? ''), $kw) !== false) {
                return true;
            }
        }
        return false;
    });
}
if ($category !== '') {
    $filtered = array_filter($filtered, function ($a) use ($category) {
        return ($a['category'] ?? '') === $category;
    });
}

$showCount = count($filtered);

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>微信知识库</title>
<style>
:root {
  --bg: #f6f7fb;
  --card: #ffffff;
  --text: #1f2937;
  --muted: #6b7280;
  --line: #e5e7eb;
  --accent: #4f46e5;
  --accent-soft: #eef2ff;
  --tag-bg: #f3f4f6;
  --tag-text: #4b5563;
  --shadow: 0 1px 2px rgb(0 0 0 / .04), 0 4px 12px rgb(0 0 0 / .05);
  --radius: 14px;
}
html[data-theme="dark"] {
  --bg: #111315;
  --card: #17191c;
  --text: #e5e7eb;
  --muted: #9ca3af;
  --line: #2a2d31;
  --accent: #818cf8;
  --accent-soft: #1e2130;
  --tag-bg: #232629;
  --tag-text: #d1d5db;
  --shadow: 0 1px 2px rgb(0 0 0 / .3), 0 6px 18px rgb(0 0 0 / .25);
}
@media (prefers-color-scheme: dark) {
  html:not([data-theme="light"]) {
    --bg: #111315;
    --card: #17191c;
    --text: #e5e7eb;
    --muted: #9ca3af;
    --line: #2a2d31;
    --accent: #818cf8;
    --accent-soft: #1e2130;
    --tag-bg: #232629;
    --tag-text: #d1d5db;
    --shadow: 0 1px 2px rgb(0 0 0 / .3), 0 6px 18px rgb(0 0 0 / .25);
  }
}
body {
  margin: 0;
  background: var(--bg);
  color: var(--text);
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
  line-height: 1.6;
}
.wrap { max-width: 1000px; margin: 0 auto; padding: 28px 20px 64px; }
header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
h1 { font-size: 24px; font-weight: 700; margin: 0; letter-spacing: .5px; }
.sub { margin-top: 6px; color: var(--muted); font-size: 13px; }
.theme-btn {
  border: 1px solid var(--line); background: var(--card); color: var(--muted);
  border-radius: 999px; padding: 6px 12px; font-size: 12px; cursor: pointer; transition: .15s;
}
.theme-btn:hover { border-color: var(--accent); color: var(--accent); }
.search-box { margin: 18px 0 14px; }
.search-box input {
  width: 100%; padding: 12px 16px; border-radius: 12px; border: 1px solid var(--line);
  background: var(--card); color: var(--text); font-size: 15px; outline: none;
  transition: border-color .15s, box-shadow .15s; box-sizing: border-box;
}
.search-box input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
.stats { display: flex; gap: 10px; flex-wrap: wrap; margin: 16px 0 18px; }
.badge {
  background: var(--card); border: 1px solid var(--line); color: var(--muted);
  border-radius: 999px; padding: 5px 12px; font-size: 12px;
  display: inline-flex; align-items: center; gap: 5px;
}
.badge b { color: var(--accent); font-weight: 600; }
.filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 22px; }
.chip {
  border: 1px solid var(--line); background: var(--card); color: var(--muted);
  padding: 6px 12px; border-radius: 999px; font-size: 13px; text-decoration: none;
  transition: .15s; display: inline-flex; align-items: center; gap: 6px;
}
.chip:hover { border-color: var(--accent); color: var(--accent); }
.chip.active { background: var(--accent); color: #fff; border-color: var(--accent); }
.chip .count { font-size: 11px; opacity: .75; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 14px; }
.card {
  background: var(--card); border: 1px solid var(--line); border-radius: var(--radius);
  padding: 16px 18px; box-shadow: var(--shadow); display: flex; flex-direction: column;
  gap: 10px; transition: transform .15s, box-shadow .15s, border-color .15s;
}
.card:hover { transform: translateY(-2px); border-color: var(--accent); box-shadow: 0 4px 16px rgb(0 0 0 / .08); }
.meta-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12px; color: var(--muted); }
.tag { background: var(--accent-soft); color: var(--accent); padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 500; }
.account { background: var(--tag-bg); color: var(--tag-text); padding: 2px 8px; border-radius: 999px; font-size: 11px; }
.date { color: #9ca3af; font-size: 11px; }
.title { font-size: 16px; font-weight: 650; color: var(--text); text-decoration: none; line-height: 1.4; margin: 0; }
.title:hover { color: var(--accent); }
.summary {
  font-size: 13px; color: var(--muted); line-height: 1.6; margin: 0;
  display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
}
.actions { display: flex; align-items: center; justify-content: space-between; margin-top: auto; }
.open-link { font-size: 12px; color: var(--accent); text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; gap: 4px; }
.open-link:hover { text-decoration: underline; }
.copy-btn {
  border: none; background: transparent; color: var(--muted); font-size: 11px;
  cursor: pointer; padding: 4px 8px; border-radius: 8px; transition: .15s;
}
.copy-btn:hover { background: var(--tag-bg); color: var(--accent); }
.empty { text-align: center; padding: 60px 0; color: var(--muted); }
footer { margin-top: 40px; text-align: center; font-size: 12px; color: #9ca3af; }
footer a { color: var(--accent); text-decoration: none; }
@media (max-width: 600px) {
  .wrap { padding: 18px 14px 48px; }
  h1 { font-size: 20px; }
  .grid { grid-template-columns: 1fr; }
  header { flex-direction: column; }
}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <h1>微信知识库</h1>
      <div class="sub">自动收藏公众号文章 · 分类整理 · 随时查看</div>
    </div>
    <button class="theme-btn" onclick="toggleTheme()" id="themeBtn">主题</button>
  </header>

  <div class="search-box">
    <form method="get" action="">
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="搜索标题、公众号、摘要关键词…" autofocus>
      <?php if ($category !== ''): ?>
        <input type="hidden" name="cat" value="<?= h($category) ?>">
      <?php endif; ?>
    </form>
  </div>

  <div class="stats">
    <span class="badge">共 <b><?= $totalCount ?></b> 篇</span>
    <span class="badge"><b><?= count($accounts) ?></b> 个公众号</span>
    <?php if ($latestDate): ?>
      <span class="badge">最新 <b><?= h($latestDate) ?></b></span>
    <?php endif; ?>
  </div>

  <div class="filters">
    <a class="chip <?= $category === '' ? 'active' : '' ?>" href="?<?= h(http_build_query(array_filter(['q' => $search]))) ?>">全部</a>
    <?php foreach ($categories as $cat => $count): ?>
      <a class="chip <?= $category === $cat ? 'active' : '' ?>"
         href="?<?= h(http_build_query(array_filter(['q' => $search, 'cat' => $cat]))) ?>">
        <?= h($cat) ?><span class="count"><?= $count ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($search !== ''): ?>
    <div class="sub" style="margin-bottom: 14px;">
      搜索“<b><?= h($search) ?></b>”找到 <?= $showCount ?> 篇
      <a href="?<?= h(http_build_query(array_filter(['cat' => $category]))) ?>" style="color: var(--accent); text-decoration: none;">清空搜索</a>
    </div>
  <?php endif; ?>

  <?php if (empty($filtered)): ?>
    <div class="empty">没有找到符合条件的文章</div>
  <?php endif; ?>

  <div class="grid">
    <?php foreach ($filtered as $a): ?>
      <div class="card">
        <div class="meta-row">
          <span class="tag"><?= h($a['category'] ?? '其他') ?></span>
          <?php if (!empty($a['account'])): ?>
            <span class="account"><?= h($a['account']) ?></span>
          <?php endif; ?>
          <?php if (!empty($a['date'])): ?>
            <span class="date"><?= h($a['date']) ?></span>
          <?php endif; ?>
        </div>
        <?php if (!empty($a['url'])): ?>
          <a class="title" href="<?= h($a['url']) ?>" target="_blank" rel="noopener"><?= h($a['title'] ?? '') ?></a>
        <?php else: ?>
          <span class="title"><?= h($a['title'] ?? '') ?></span>
        <?php endif; ?>
        <?php if (!empty($a['summary'])): ?>
          <p class="summary"><?= h($a['summary']) ?></p>
        <?php elseif (!empty($a['desc'])): ?>
          <p class="summary"><?= h($a['desc']) ?></p>
        <?php endif; ?>
        <div class="actions">
          <?php if (!empty($a['url'])): ?>
            <a class="open-link" href="<?= h($a['url']) ?>" target="_blank" rel="noopener">阅读原文 →</a>
            <button class="copy-btn" onclick="copyLink(this)" data-url="<?= h($a['url']) ?>">复制链接</button>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <footer>
    数据来源：Obsidian · WXLink · 共 <?= $totalCount ?> 篇
  </footer>
</div>

<script>
(function () {
  const saved = localStorage.getItem('theme');
  if (saved) document.documentElement.dataset.theme = saved;
})();
function toggleTheme() {
  const root = document.documentElement;
  const dark = root.dataset.theme === 'dark' ||
    (!root.dataset.theme && window.matchMedia('(prefers-color-scheme: dark)').matches);
  root.dataset.theme = dark ? 'light' : 'dark';
  localStorage.setItem('theme', root.dataset.theme);
}
function copyLink(btn) {
  const url = btn.dataset.url;
  const done = () => {
    const old = btn.textContent;
    btn.textContent = '已复制';
    setTimeout(() => { btn.textContent = old; }, 1200);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(url).then(done).catch(done);
  } else {
    const ta = document.createElement('textarea');
    ta.value = url;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    done();
  }
}
</script>
</body>
</html>
