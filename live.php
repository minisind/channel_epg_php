<?php
/**
 * live.php - 按参数返回对应格式的频道数据
 * 修复版：SQL注入、JSON脏数据、TVBOX URL拼接、formatTitle、表名安全等
 *
 * 使用示例：
 *   http://127.0.0.1/live.php                 默认返回 diyp txt 格式
 *   http://127.0.0.1/live.php?t=json          返回 json 格式
 *   http://127.0.0.1/live.php?t=m3u           返回 m3u 格式
 *   http://127.0.0.1/live.php?t=tvbox         返回 tvbox 接口 json
 *   http://127.0.0.1/live.php?token=xxx       使用 token 指定表名
 *   http://127.0.0.1/live.php?m=1             仅去重 url（不合并）
 *   http://127.0.0.1/live.php?m=2             去重并以 # 合并 url（默认）
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
date_default_timezone_set('Asia/Shanghai');

$utf8     = false; // 仅对 json 有效：true=UTF-8，false=Unicode
$t        = !empty($_GET['t']) ? strtolower(trim($_GET['t'])) : 'txt';
$optmerge = (!empty($_GET['m']) && $_GET['m'] === '1') ? '1' : '2';
$token    = !empty($_GET['token']) ? trim($_GET['token']) : '';
$debug    = !empty($_GET['debug']);

if ($debug) {
    echo 'utf8=' . var_export($utf8, true) . '<br>';
    echo 't=' . htmlspecialchars($t) . '<br>';
    echo 'optmerge=' . $optmerge . '<br>';
}

class ChannelDB extends SQLite3
{
    public function __construct()
    {
        $dbPath = __DIR__ . '/channel_epg.db';
        if (!file_exists($dbPath)) {
            http_response_code(500);
            die('Database not found.');
        }
        // 日志需要写入，使用读写模式
        $this->open($dbPath, SQLITE3_OPEN_READWRITE);
        $this->busyTimeout(3000);
    }
}

/**
 * 校验表名是否安全（只允许字母、数字、下划线）
 */
function isSafeTableName($name)
{
    return is_string($name) && preg_match('/^[a-zA-Z0-9_]+$/', $name);
}

/**
 * 获取当前请求的基础 URL（协议 + 主机 + 路径前缀）
 */
function getBaseUrl()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
          || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';

    // 去掉当前脚本名，得到目录
    $script = $_SERVER['SCRIPT_NAME'] ?? '/live.php';
    $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '' || $dir === '.') {
        $dir = '';
    }

    return $scheme . '://' . $host . $dir . '/';
}

// ========== 读取 live.json 配置 ==========
$configFile = __DIR__ . '/live.json';
if (!file_exists($configFile)) {
    $defaultConfig = [
        'ipsubnet' => [
            '120.230' => 'list',
            '127.0'   => 'list',
            '223.73'  => 'list',
            '120.25'  => 'list_ct',
            '14.45'   => 'list_ct',
        ],
        'token' => [
            'hldshf932dfs' => 'list',
            'owerejf921ls' => 'list_ct',
        ],
    ];
    file_put_contents($configFile, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$liveConfig = @json_decode(@file_get_contents($configFile), true);
if (!is_array($liveConfig)) {
    $liveConfig = [];
}

// 计算表名（token 优先，其次 IP 子网）
$tableName = 'list';
$currentIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// IPv4 取前两段，IPv6 简单处理为整段或回退
$ipSubnet = '';
if (filter_var($currentIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $parts = explode('.', $currentIP);
    if (count($parts) >= 2) {
        $ipSubnet = $parts[0] . '.' . $parts[1];
    }
} else {
    // IPv6 可按需要扩展，这里先不匹配子网
    $ipSubnet = '';
}

if (!empty($token) && isset($liveConfig['token'][$token]) && isSafeTableName($liveConfig['token'][$token])) {
    $tableName = $liveConfig['token'][$token];
} elseif ($ipSubnet !== '' && isset($liveConfig['ipsubnet'][$ipSubnet]) && isSafeTableName($liveConfig['ipsubnet'][$ipSubnet])) {
    $tableName = $liveConfig['ipsubnet'][$ipSubnet];
}

// 最终再校验一次
if (!isSafeTableName($tableName)) {
    $tableName = 'list';
}

// ========== 数据库操作 ==========
$channel = new ChannelDB();

// 记录访问日志（预处理，防注入）
$time       = date('Y-m-d H:i:s');
$currentURL = ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
$referer    = !empty($_SERVER['HTTP_REFERER']) ? urldecode($_SERVER['HTTP_REFERER']) : $currentURL;

try {
    $stmt = $channel->prepare('INSERT OR IGNORE INTO access_log (ip_address, access_time, url) VALUES (?, ?, ?)');
    if ($stmt) {
        $stmt->bindValue(1, $currentIP, SQLITE3_TEXT);
        $stmt->bindValue(2, $time, SQLITE3_TEXT);
        $stmt->bindValue(3, $referer, SQLITE3_TEXT);
        $stmt->execute();
    }
} catch (Exception $e) {
    // 忽略日志失败
}

// ========== TVBOX 接口 ==========
if ($t === 'tvbox') {
    $base = getBaseUrl();
    $config = [
        'spider' => '',
        'lives'  => [
            [
                'name' => '直播',
                'type' => 0,
                'url'  => $base . 'live.php',
                'epg'  => $base . 'epg.php?ch={name}&date={date}',
            ],
            [
                'group'    => 'redirect',
                'channels' => [
                    [
                        'name'  => 'live',
                        'urls'  => [],
                    ],
                ],
                'epg' => $base . 'epg.php',
            ],
        ],
        'rules'     => [],
        'sites'     => [],
        'parses'    => [],
        'flags'     => [],
        'wallpaper' => '',
    ];

    if (!$debug) {
        header_remove();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=tvbox.json');
    }
    echo json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $channel->close();
    exit;
}

// ========== 检查/创建表 ==========
$tableExists = $channel->querySingle(
    "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='" . SQLite3::escapeString($tableName) . "'"
);

if ((int)$tableExists === 0) {
    // 表名已通过白名单，可安全使用
    $channel->exec("CREATE TABLE `{$tableName}` (
        item text,
        title text,
        epg text,
        url text,
        isdel integer null default 120,
        constraint name_pk primary key (item, title, url)
    )");
    // 注意：使用单引号，避免 $高清 被 PHP 解析
    $channel->exec("INSERT INTO `{$tableName}` VALUES (
        'IPTV组播',
        'CCTV-1',
        '',
        'http://192.168.1.201:4022/rtp/239.10.0.114:1025\$高清',
        95
    )");
}

// 读取数据
$result = $channel->query("SELECT item, title, epg, url, isdel FROM `{$tableName}` WHERE isdel > 0 ORDER BY isdel");
$config = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $config[] = [
        'item'  => $row['item'],
        'title' => trim($row['title'] ?? ''),
        'epg'   => maxstr($row['epg'] ?? ''),
        'url'   => trim($row['url'] ?? ''),
        'isdel' => $row['isdel'],
    ];
}

// 按分组合并
$groupconfig = [];
foreach ($config as $item) {
    $gender = $item['item'];
    if (!isset($groupconfig[$gender])) {
        $groupconfig[$gender] = [];
    }
    $groupconfig[$gender][] = $item;
}

// 同分组下按 title 合并 url / epg
$config = [];
foreach ($groupconfig as $item => $titles) {
    $channels = [];
    foreach ($titles as $row) {
        $title = $row['title'];
        if (!isset($channels[$title])) {
            $channels[$title] = [
                'title' => $title,
                'url'   => [],
                'epg'   => [],
                'isdel' => [],
            ];
        }
        $channels[$title]['url'][]   = $row['url'];
        $channels[$title]['epg'][]   = $row['epg'];
        $channels[$title]['isdel'][] = $row['isdel'];
    }
    $config[$item] = $channels;
}
$groupconfig = $config;
unset($config);

// ========== 工具函数 ==========
function maxstr($string)
{
    $string = trim((string)$string);
    if ($string === '') {
        return '';
    }
    $array = array_filter(explode('#', $string), 'strlen');
    if (empty($array)) {
        return '';
    }
    $frequency = array_count_values($array);
    arsort($frequency);
    return (string)key($frequency);
}

function formatTitle($title, $mode = 1)
{
    $text = strtoupper(trim((string)$title));
    if ($mode == 1) {
        if (strpos($text, 'CCTV') !== false) {
            if (preg_match('/^[A-Za-z0-9\-+]+/', $text, $matches)) {
                $text = $matches[0];
            }
        }
        $pos = strpos($text, '[');
        if ($pos !== false) {
            $text = substr($text, 0, $pos);
        }
        $text = str_replace('-', '', $text);
        if ($text === '') {
            $text = strtoupper(trim((string)$title));
        }
    } else {
        $pos = strpos($text, '[');
        if ($pos !== false) {
            $text = substr($text, 0, $pos);
        }
    }
    return trim($text);
}

function formatUrls($urlstr)
{
    $urls = array_filter(explode('#', (string)$urlstr), 'strlen');
    $urls = array_unique($urls);
    return implode('#', $urls);
}

function gentxt($groupconfig, $optmerge = '2')
{
    $lines = [];
    foreach ($groupconfig as $item => $titles) {
        $lines[] = $item . ',#genre#';
        foreach ($titles as $v) {
            $epg = maxstr(implode('#', $v['epg']));
            $epg = ($epg === '') ? formatTitle($v['title']) : $epg;
            $title = formatTitle($v['title'], 2);
            $url = formatUrls(implode('#', $v['url']));

            if ($optmerge === '2') {
                $lines[] = $title . ',' . $url;
            } else {
                foreach (explode('#', $url) as $u) {
                    if ($u !== '') {
                        $lines[] = $title . ',' . $u;
                    }
                }
            }
        }
    }
    return implode(PHP_EOL, $lines);
}

function genm3u($groupconfig, $optmerge = '2')
{
    $base = getBaseUrl();
    $lines = [];
    $lines[] = '#EXTM3U x-tvg-url="' . $base . 'epg.php"';

    foreach ($groupconfig as $item => $titles) {
        foreach ($titles as $v) {
            $epg = maxstr(implode('#', $v['epg']));
            $epg = ($epg === '') ? formatTitle($v['title']) : $epg;
            $title = formatTitle($v['title'], 2);
            $url = formatUrls(implode('#', $v['url']));

            if ($optmerge === '2') {
                $lines[] = sprintf(
                    '#EXTINF:-1 tvg-id="" tvg-name="%s" tvg-logo="" group-title="%s",%s',
                    $epg,
                    $item,
                    $title
                );
                $lines[] = $url;
            } else {
                foreach (explode('#', $url) as $u) {
                    if ($u === '') continue;
                    $lines[] = sprintf(
                        '#EXTINF:-1 tvg-id="" tvg-name="%s" tvg-logo="" group-title="%s",%s',
                        $epg,
                        $item,
                        $title
                    );
                    $lines[] = $u;
                }
            }
        }
    }
    return implode(PHP_EOL, $lines);
}

function genjson($groupconfig, $optmerge = '2')
{
    global $utf8;
    $config = [];
    foreach ($groupconfig as $item => $titles) {
        $config[$item] = [];
        foreach ($titles as $v) {
            $epg = maxstr(implode('#', $v['epg']));
            $epg = ($epg === '') ? formatTitle($v['title']) : $epg;
            $title = formatTitle($v['title'], 2);
            $url = formatUrls(implode('#', $v['url']));

            if ($optmerge === '2') {
                $config[$item][] = [
                    'epg'   => $epg,
                    'title' => $title,
                    'url'   => $url,
                ];
            } else {
                foreach (explode('#', $url) as $u) {
                    if ($u === '') continue;
                    $config[$item][] = [
                        'epg'   => $epg,
                        'title' => $title,
                        'url'   => $u,
                    ];
                }
            }
        }
    }

    $flags = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE;
    if (!$utf8) {
        $flags |= JSON_UNESCAPED_UNICODE;
    }
    return json_encode($config, $flags);
}

// ========== 输出 ==========
if ($t === 'm3u') {
    $str = genm3u($groupconfig, $optmerge);
    if (!$debug) {
        header_remove();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename=live.m3u');
    }
    echo $str;
} elseif ($t === 'json') {
    // 已删除错误的 var_dump
    $str = genjson($groupconfig, $optmerge);
    if (!$debug) {
        header_remove();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=live.json');
    }
    echo $str;
} else {
    // 默认 txt
    $str = gentxt($groupconfig, $optmerge);
    if (!$debug) {
        header_remove();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename=live.txt');
    }
    echo $str;
}

$channel->close();
?>
