<?php
// lib.php — 共用逻辑：配置、读写数据、分类、GitHub 同步
declare(strict_types=1);

function wx_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $cfg = [];
    $file = __DIR__ . '/config.php';
    if (is_file($file)) {
        $loaded = include $file;
        if (is_array($loaded)) {
            $cfg = $loaded;
        }
    }

    // 环境变量优先（Abasthan 控制台 → Environment 里配置）
    // 这样即使重新部署、config.php 被覆盖，密钥也不会丢
    $envMap = [
        'WXLINK_TOKEN'  => 'token',
        'WXLINK_USER'   => 'page_user',
        'WXLINK_PASS'   => 'page_pass',
        'WXLINK_DATA'   => 'data_file',
        'WXLINK_SEED'   => 'seed_file',
        'GITHUB_TOKEN'  => 'github_token',
        'GITHUB_REPO'   => 'github_repo',   // 例：user/vault
        'GITHUB_PATH'   => 'github_path',   // 例：WXLink/Inbox
        'GITHUB_BRANCH' => 'github_branch', // 例：main
    ];
    foreach ($envMap as $env => $key) {
        $v = getenv($env);
        if ($v !== false && $v !== '') {
            $cfg[$key] = $v;
        }
    }

    $defaults = [
        'token'         => '',
        'page_user'     => '',
        'page_pass'     => '',
        'data_file'     => __DIR__ . '/../wxdata/articles.json',
        'notes_dir'     => __DIR__ . '/../wxdata/notes',
        'seed_file'     => __DIR__ . '/../wxdata/seed_articles.json',
        'github_token'  => '',
        'github_repo'   => '',
        'github_path'   => 'WXLink/Inbox',
        'github_branch' => 'main',
        'max_items'     => 3000,
    ];

    foreach ($defaults as $k => $v) {
        if (!isset($cfg[$k]) || $cfg[$k] === '') {
            $cfg[$k] = $v;
        }
    }

    return $cfg;
}

/**
 * 展示页访问密码（HTTP Basic）。
 * 配置了 page_user + page_pass 才生效；未配置则页面公开。
 */
function wx_page_auth(): void
{
    $cfg = wx_config();
    if ($cfg['page_user'] === '' || $cfg['page_pass'] === '') {
        return;
    }

    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';

    if ($user !== '' && hash_equals($cfg['page_user'], (string)$user) && hash_equals($cfg['page_pass'], (string)$pass)) {
        return;
    }

    header('WWW-Authenticate: Basic realm="wxlink", charset="UTF-8"');
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>需要登录</title>'
        . '<body style="font-family:system-ui,sans-serif;padding:48px;line-height:1.7">'
        . '<h2>需要登录</h2><p>这个页面是私人的，请输入账号与密码。</p></body>';
    exit;
}

/** 读全部文章 */
function wx_load(): array
{
    $cfg = wx_config();
    $dataFile = $cfg['data_file'];

    if (is_file($dataFile)) {
        $raw = @file_get_contents($dataFile);
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return $data;
            }
        }
    }

    // 首次运行用种子数据：先看 wxdata 里的那份，再退回仓库自带的 seed_articles.json
    // （重新部署后 wxdata 可能被清空，靠这一条不至于白页）
    foreach ([$cfg['seed_file'], __DIR__ . '/seed_articles.json'] as $seed) {
        if ($seed === '' || !is_file($seed)) {
            continue;
        }
        $raw = @file_get_contents($seed);
        if ($raw === false) {
            continue;
        }
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
    }

    return [];
}

/** 写回全部文章（带文件锁，原子写） */
function wx_store(array $articles): bool
{
    $cfg = wx_config();
    $dataFile = $cfg['data_file'];
    $dir = dirname($dataFile);

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode(array_values($articles), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    $tmp = $dataFile . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return @rename($tmp, $dataFile);
}

/** 关键词分类 */
function wx_classify(array $a): string
{
    $text = mb_strtolower(
        ($a['title'] ?? '') . ' ' . ($a['desc'] ?? '') . ' ' . ($a['summary'] ?? '') . ' ' . ($a['text'] ?? '')
    );

    $rules = [
        'AI / Agent' => '/ai\b|agent|大模型|deepseek|grok|opencode|claude|codex|cursor|llm|模型|灵光|qwen|gpt|智能体|提示词|prompt|ollama|gemini/iu',
        '开发 / 部署' => '/vps|服务器|主机|ssh|nas|docker|内网穿透|部署|php|node|python|开发|开源|编程|github|数据库|nginx|linux|api|代码|前端|后端|运维/iu',
        '工具 / 效率' => '/工具|插件|浏览器|效率|办公|文件|下载|管理|笔记|系统|app|脚本|自动化|快捷|输入法|截图|转录|obsidian/iu',
        '阅读 / 思考' => '/思考|认知|观点|复盘|读书|成长|方法|趋势|商业|经济|创业|投资/iu',
    ];

    foreach ($rules as $cat => $re) {
        if (preg_match($re, $text)) {
            return $cat;
        }
    }
    return '其他';
}

/** slug：保留中文与字母数字，其它转 - */
function wx_slug(string $title): string
{
    $s = mb_strtolower(trim($title));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?? '';
    $s = trim($s, '-');
    if ($s === '') {
        $s = 'note-' . date('Ymd-His');
    }
    if (mb_strlen($s) > 60) {
        $s = mb_substr($s, 0, 60);
        $s = rtrim($s, '-');
    }
    return $s;
}

/** 生成 Obsidian Markdown */
function wx_markdown(array $a): string
{
    $title   = $a['title'] ?? '未命名';
    $url     = $a['url'] ?? '';
    $account = $a['account'] ?? '';
    $date    = $a['date'] ?? date('Y-m-d');
    $cat     = $a['category'] ?? '其他';
    $body    = trim((string)($a['summary'] ?? ''));
    if ($body === '') {
        $body = trim((string)($a['desc'] ?? ''));
    }
    if ($body === '') {
        $body = trim((string)($a['text'] ?? ''));
    }

    $yaml = "---\n";
    $yaml .= 'title: ' . json_encode($title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if ($url !== '') {
        $yaml .= 'url: ' . $url . "\n";
    }
    if ($account !== '') {
        $yaml .= 'account: ' . json_encode($account, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    $yaml .= "date: {$date}\n";
    $yaml .= 'category: ' . json_encode($cat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $yaml .= "source: wxlink\n";
    $yaml .= "---\n\n";

    $out = $yaml . "# {$title}\n\n";
    if ($account !== '' || $url !== '') {
        $out .= '> ' . trim($account . ($url !== '' ? ' · ' . $url : ''), " ·") . "\n\n";
    }
    $out .= $body . "\n";

    return $out;
}

function wx_note_filename(array $a): string
{
    $date = preg_replace('/[^0-9-]/', '', (string)($a['date'] ?? '')) ?: date('Y-m-d');
    return $date . '-' . wx_slug((string)($a['title'] ?? 'note')) . '.md';
}

/** GitHub contents API 写入单个文件（失败不抛异常） */
function wx_github_put(string $path, string $content, string $message): array
{
    $cfg = wx_config();
    if ($cfg['github_token'] === '' || $cfg['github_repo'] === '') {
        return ['ok' => false, 'skipped' => true, 'error' => 'github not configured'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl not available'];
    }

    $repo   = $cfg['github_repo'];
    $branch = $cfg['github_branch'];
    $path   = trim($path, '/');
    $api    = "https://api.github.com/repos/{$repo}/contents/" . rawurlencode($path);

    $headers = [
        'Authorization: Bearer ' . $cfg['github_token'],
        'Accept: application/vnd.github+json',
        'User-Agent: wxlink-ingest',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    // 取已有 sha（存在则更新）
    $sha = null;
    $ch = curl_init($api . '?ref=' . rawurlencode($branch));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200) {
        $meta = json_decode((string)$resp, true);
        $sha = $meta['sha'] ?? null;
    }

    $payload = [
        'message' => $message,
        'content' => base64_encode($content),
        'branch'  => $branch,
    ];
    if ($sha) {
        $payload['sha'] = $sha;
    }

    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => array_merge($headers, ['Content-Type: application/json']),
        CURLOPT_TIMEOUT        => 25,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'path' => $path];
    }
    return ['ok' => false, 'status' => $code, 'error' => $err ?: mb_substr((string)$resp, 0, 200)];
}

/** 入库主流程 */
function wx_ingest(array $input): array
{
    $cfg = wx_config();

    $url     = trim((string)($input['url'] ?? ''));
    $title   = trim((string)($input['title'] ?? ''));
    $summary = trim((string)($input['summary'] ?? ''));
    $desc    = trim((string)($input['desc'] ?? ''));
    $text    = trim((string)($input['text'] ?? ''));
    $account = trim((string)($input['account'] ?? ''));

    if ($url === '' && $title === '' && $text === '') {
        return ['ok' => false, 'error' => 'url 或 title 至少给一个'];
    }

    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'error' => 'url 必须是 http(s) 开头'];
    }

    // 标题兜底：从 URL 或正文取
    if ($title === '') {
        if ($text !== '') {
            $first = preg_split('/\r?\n/', $text)[0] ?? '';
            $title = mb_substr(trim($first), 0, 80);
        } else {
            $title = $url;
        }
    }

    if ($summary === '') {
        $summary = $desc !== '' ? $desc : mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 300);
    }

    $date = trim((string)($input['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $articles = wx_load();

    // 去重：同 URL 或同标题+同账号
    foreach ($articles as $i => $a) {
        $sameUrl = $url !== '' && ($a['url'] ?? '') === $url;
        $sameTitle = $url === '' && ($a['title'] ?? '') === $title && ($a['account'] ?? '') === $account;
        if ($sameUrl || $sameTitle) {
            // 补全空字段后返回
            $merged = $a;
            foreach (['summary' => $summary, 'desc' => $desc, 'account' => $account] as $k => $v) {
                if (empty($merged[$k]) && $v !== '') {
                    $merged[$k] = $v;
                }
            }
            $merged['category'] = ($input['category'] ?? '') !== '' ? $input['category'] : ($merged['category'] ?? wx_classify($merged));
            $articles[$i] = $merged;

            $notePath = wx_note_filename($merged);
            $md = wx_markdown($merged);
            $notesDir = $cfg['notes_dir'];
            if (!is_dir($notesDir)) {
                @mkdir($notesDir, 0775, true);
            }
            @file_put_contents($notesDir . '/' . $notePath, $md, LOCK_EX);

            wx_store($articles);
            $gh = wx_github_put(rtrim($cfg['github_path'], '/') . '/' . $notePath, $md, 'wxlink: update ' . $title);

            return ['ok' => true, 'duplicate' => true, 'title' => $merged['title'], 'category' => $merged['category'], 'note' => $notePath, 'github' => $gh];
        }
    }

    $item = [
        'title'   => $title,
        'account' => $account,
        'url'     => $url,
        'date'    => $date,
        'desc'    => $desc,
        'summary' => $summary,
    ];
    if ($text !== '') {
        $item['text'] = mb_substr($text, 0, 20000);
    }
    $item['category'] = ($input['category'] ?? '') !== '' ? $input['category'] : wx_classify($item);
    $item['added_at'] = date('c');

    array_unshift($articles, $item);

    if (count($articles) > (int)$cfg['max_items']) {
        $articles = array_slice($articles, 0, (int)$cfg['max_items']);
    }

    $saved = wx_store($articles);

    $notePath = wx_note_filename($item);
    $md = wx_markdown($item);
    $notesDir = $cfg['notes_dir'];
    if (!is_dir($notesDir)) {
        @mkdir($notesDir, 0775, true);
    }
    $noteSaved = @file_put_contents($notesDir . '/' . $notePath, $md, LOCK_EX) !== false;

    $gh = wx_github_put(rtrim($cfg['github_path'], '/') . '/' . $notePath, $md, 'wxlink: add ' . $title);

    return [
        'ok'       => $saved,
        'duplicate' => false,
        'title'    => $item['title'],
        'category' => $item['category'],
        'note'     => $noteSaved ? $notePath : null,
        'total'    => count($articles),
        'github'   => $gh,
    ];
}

function wx_require_token(): bool
{
    $cfg = wx_config();
    if ($cfg['token'] === '') {
        return false; // 未配置 token 时禁止写入
    }
    $given = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? $_SERVER['HTTP_X_WXLINK_TOKEN'] ?? '';
    if ($given === '') {
        $given = (string)($_GET['token'] ?? $_POST['token'] ?? '');
        if ($given === '') {
            $body = file_get_contents('php://input');
            if ($body) {
                $j = json_decode($body, true);
                if (is_array($j) && isset($j['token'])) {
                    $given = (string)$j['token'];
                }
            }
        }
    }
    return $given !== '' && hash_equals($cfg['token'], $given);
}

function wx_json($data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
