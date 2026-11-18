<?php
// config.sample.php — 复制成 config.php 再改；config.php 不要提交到公开仓库
return [
    // 入库接口鉴权 token（随机长串）
    'token'     => 'CHANGE_ME',

    // 展示页访问密码（HTTP Basic）。留空则页面公开
    'page_user' => 'wxlink',
    'page_pass' => 'CHANGE_ME',

    // 数据放在文档根目录之外，外部无法直接下载
    'data_file' => __DIR__ . '/../wxdata/articles.json',
    'notes_dir' => __DIR__ . '/../wxdata/notes',
    'seed_file' => __DIR__ . '/../wxdata/seed_articles.json',

    // 可选：把笔记直接提交到私有 GitHub vault 仓库
    'github_token'  => '',
    'github_repo'   => '',
    'github_path'   => 'WXLink/Inbox',
    'github_branch' => 'main',
];
