<?php
// ingest.php — 微信 / clawbot 入库接口
// 用法：POST JSON，头部 X-Auth-Token: <WXLINK_TOKEN>
// 支持字段：url, title, account, desc, summary, text, date, category
require_once __DIR__ . '/lib.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---- 健康检查 ----
if (isset($_GET['ping'])) {
    if (!wx_require_token()) {
        wx_json(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    $articles = wx_load();
    $cfg = wx_config();
    wx_json([
        'ok'    => true,
        'service' => 'wxlink-ingest',
        'count' => count($articles),
        'writable' => is_writable(dirname($cfg['data_file'])),
        'github' => $cfg['github_repo'] !== '' && $cfg['github_token'] !== '',
        'php'   => PHP_VERSION,
    ]);
}

if (!wx_require_token()) {
    wx_json(['ok' => false, 'error' => 'unauthorized: 缺少或错误的 token'], 401);
}

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

    if ($raw !== false && $raw !== '') {
        $trim = ltrim($raw);
        if (isset($trim[0]) && ($trim[0] === '{' || $trim[0] === '[')) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $input = $parsed;
            }
        } else {
            // 纯文本分享：整段当正文
            $input = ['text' => $raw];
        }
    }

    if (!empty($_POST)) {
        $input = array_merge($_POST, $input);
    }

    // 从正文里提取第一条链接
    if (empty($input['url']) && !empty($input['text']) && preg_match('#https?://\S+#', $input['text'], $m)) {
        $input['url'] = rtrim($m[0], " \t\r\n，。、；）)】]");
    }
} else {
    $input = $_GET;
}

unset($input['token']);

$result = wx_ingest($input);
wx_json($result, $result['ok'] ? 200 : 400);
