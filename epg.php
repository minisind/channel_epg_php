<?php
/**
 * 安全增强版 EPG 接口
 * 支持 XMLTV / DIYP / SPTV 三种格式
 * 修复 SQL 注入、跨天逻辑、时区等问题
 * 适配 OpenWrt 路由器环境
 */

error_reporting(0);

// ========== 配置 ==========
$utf8 = false;           // true: UTF-8, false: Unicode (JSON_UNESCAPED_UNICODE)
$iscache = false;        // 是否启用缓存（OpenWrt 通常无 memcached，建议 false）
$cachehour = 2;          // 未来数据缓存小时数
$debug = !empty($_GET["debug"]);
$isw_log = false;        // 是否写入 access_log（建议 false，保护闪存）

// ========== 输入处理 ==========
$riqi = trim($_GET["date"] ?? '');
$ch    = trim($_GET["ch"] ?? $_GET["channel"] ?? 'CCTV1');

// 自动转换 YYYYMMDD → YYYY-MM-DD
if ($riqi && strlen($riqi) === 8 && ctype_digit($riqi)) {
    $riqi = substr($riqi, 0, 4) . '-' . substr($riqi, 4, 2) . '-' . substr($riqi, 6, 2);
}
if (!$riqi) {
    $riqi = date('Y-m-d');
}

// 格式判断：sptv 还是 diyp？
$tvapp = isset($_GET["channel"]) ? 'sptv' : 'diyp';

if ($debug) {
    echo "Debug Info:<br>";
    echo "ch={$ch}, date={$riqi}, format={$tvapp}<br><br>";
    $iscache = false;
}

// ========== 数据库类 ==========
class ChannelDB extends SQLite3 {
    private static $log_enabled = false;

    public function __construct() {
        $db_path = __DIR__ . '/channel_epg.db';
        if (!file_exists($db_path)) {
            http_response_code(500);
            die('Database not found.');
        }
        $this->open($db_path, self::$log_enabled ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY);
    }

    public static function setLogEnabled($enabled) {
        self::$log_enabled = (bool)$enabled;
    }

    public static function isLogEnabled() {
        return self::$log_enabled;
    }
}

ChannelDB::setLogEnabled($isw_log);

// ========== 缓存初始化 ==========
$use_cache = false;
$memcache = null;
$cache_key = '';

if ($iscache && class_exists('Memcache')) {
    $memcache = new Memcache();
    if (@$memcache->connect('localhost', 11211)) {
        $cache_key = md5($ch . '|' . $riqi . '|' . $tvapp);
        $cached = $memcache->get($cache_key);
        if ($cached !== false) {
            if (!$debug) {
                if ($ch === 'xml') {
                    header('Content-Type: text/xml; charset=utf-8');
                    header('Content-Disposition: attachment; filename=epg.xml');
                } else {
                    header('Content-Type: application/json; charset=utf-8');
                    header('Content-Disposition: attachment; filename=epg.json');
                }
            }
            echo $cached;
            exit;
        }
        $use_cache = true;
    }
}

// ========== 记录访问日志（可选）==========
if ($isw_log) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $time = date('Y-m-d H:i:s');
    $url = ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
    $referer = $_SERVER['HTTP_REFERER'] ?? $url;
    $referer = urldecode($referer);

    try {
        $db = new ChannelDB();
        $stmt = $db->prepare("INSERT OR IGNORE INTO access_log (ip_address, access_time, url) VALUES (?, ?, ?)");
        $stmt->bindValue(1, 'N' . $ip, SQLITE3_TEXT);
        $stmt->bindValue(2, $time, SQLITE3_TEXT);
        $stmt->bindValue(3, $referer, SQLITE3_TEXT);
        $stmt->execute();
        $db->close();
    } catch (Exception $e) {
        // 忽略日志写入失败
    }
}

// ========== 主逻辑 ==========
$db = new ChannelDB();

if ($ch === 'xml') {
    // ========== XMLTV 格式 ==========
    $channels = [];
    $programmes = [];

    // 获取所有频道信息（用于 <channel>）
    $stmt = $db->prepare("SELECT channel_id, name FROM epg_channel");
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $channels[$row['channel_id']] = $row['name'];
    }

    // 获取节目数据（从指定日期开始）
    $stmt = $db->prepare("SELECT e.channel, e.title, e.sdate, e.sstart, e.sstop
                          FROM epg_programme e
                          WHERE e.sdate >= ?
                          AND e.channel IN (SELECT DISTINCT channel_id FROM epg_channel)");
    $stmt->bindValue(1, $riqi, SQLITE3_TEXT);
    $result = $stmt->execute();

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $start_ts = strtotime($row['sdate'] . ' ' . $row['sstart']);
        $stop_ts  = strtotime($row['sdate'] . ' ' . $row['sstop']);
        if ($stop_ts <= $start_ts) {
            $stop_ts += 86400; // 跨天
        }

        $start_str = date('YmdHis', $start_ts);
        $stop_str  = date('YmdHis', $stop_ts);

        $title = htmlspecialchars($row['title'], ENT_XML1, 'UTF-8');
        $programmes[] = "\t<programme channel=\"{$row['channel']}\" start=\"{$start_str}\" stop=\"{$stop_str}\">\n\t\t<title lang=\"zh\">{$title}</title>\n\t</programme>";
    }

    // 构建 channel 列表（仅包含有节目的频道）
    $channel_xml = [];
    foreach ($channels as $id => $name) {
        // 检查是否有该频道的节目
        $has_program = false;
        foreach ($programmes as $p) {
            if (strpos($p, "channel=\"{$id}\"") !== false) {
                $has_program = true;
                break;
            }
        }
        if ($has_program) {
            $name_esc = htmlspecialchars($name, ENT_XML1, 'UTF-8');
            $channel_xml[] = "\t<channel id=\"{$id}\">\n\t\t<display-name lang=\"zh\">{$name_esc}</display-name>\n\t</channel>";
        }
    }

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tv generator-info-name=\"epg.php\" generator-info-url=\"http://your-router/epg.php?ch=xml\">\n";
    $xml .= implode("\n", $channel_xml) . "\n";
    $xml .= implode("\n", $programmes) . "\n</tv>";

    if ($use_cache) {
        $ttl = 3600 * $cachehour;
        $memcache->set($cache_key, $xml, MEMCACHE_COMPRESSED, $ttl);
    }

    if (!$debug) {
        header('Content-Type: text/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename=epg.xml');
    }
    echo $xml;

} else {
    // ========== JSON 格式（DIYP / SPTV）==========
    $epg_datas = [];
    $epg_data2s = [];
    $is_found = false;

    // 查询频道 ID
    $stmt = $db->prepare("SELECT channel_id FROM epg_channel WHERE upper(name) = upper(?) LIMIT 1");
    $stmt->bindValue(1, $ch, SQLITE3_TEXT);
    $result = $stmt->execute();
    $channel_row = $result->fetchArray(SQLITE3_ASSOC);

    if ($channel_row) {
        $channel_id = $channel_row['channel_id'];

        // 查询节目
        $stmt = $db->prepare("SELECT sstart, sstop, title FROM epg_programme 
                              WHERE channel = ? AND sdate = ?");
        $stmt->bindValue(1, $channel_id, SQLITE3_TEXT);
        $stmt->bindValue(2, $riqi, SQLITE3_TEXT);
        $result = $stmt->execute();

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $is_found = true;
            $start_ts = strtotime($riqi . ' ' . $row['sstart']);
            $stop_ts  = strtotime($riqi . ' ' . $row['sstop']);
            if ($stop_ts <= $start_ts) {
                $stop_ts += 86400;
            }

            if ($tvapp === 'sptv') {
                $duration = $stop_ts - $start_ts;
                $epg_data2s[] = [
                    "eventId" => "",
                    "showTime" => gmdate('H:i', $duration),
                    "eventType" => "",
                    "st" => $start_ts,
                    "t" => $row['title'],
                    "duration" => $duration,
                    "et" => $stop_ts
                ];
            } else {
                $end_time = ($row['sstop'] === '23:59') ? '00:00' : $row['sstop'];
                $epg_datas[] = [
                    "start" => $row['sstart'],
                    "end" => $end_time,
                    "title" => $row['title'],
                    "desc" => ""
                ];
            }
        }
    }

    if (!$is_found) {
        // 生成空节目单（24 小时占位）
        for ($i = 0; $i < 24; $i++) {
            $start_h = sprintf('%02d', $i);
            $end_h = sprintf('%02d', ($i + 1) % 24);
            $start_ts = strtotime($riqi . " {$start_h}:00");
            $end_ts = $start_ts + 3600;
            if ($end_h === '00') $end_ts += 86400;

            if ($tvapp === 'sptv') {
                $epg_data2s[] = [
                    "eventId" => "",
                    "showTime" => "01:00",
                    "eventType" => "",
                    "st" => $start_ts,
                    "t" => "精彩节目",
                    "duration" => 3600,
                    "et" => $end_ts
                ];
            } else {
                $epg_datas[] = [
                    "start" => "{$start_h}:00",
                    "end" => "{$end_h}:00",
                    "title" => "精彩节目",
                    "desc" => ""
                ];
            }
        }
    }

    if ($tvapp === 'sptv') {
        $output = [$ch => [
            "isLive" => "",
            "liveSt" => "",
            "program" => $epg_data2s,
            "channelName" => $ch,
            "lvUrl" => ""
        ]];
    } else {
        $output = [
            "channel_name" => $ch,
            "date" => $riqi,
            "epg_data" => $epg_datas
        ];
    }

    $json_options = $utf8 ? 0 : JSON_UNESCAPED_UNICODE;
    $json = json_encode($output, $json_options | JSON_INVALID_UTF8_IGNORE);

    if ($use_cache) {
        $ttl = (strtotime($riqi) >= strtotime(date('Y-m-d'))) ? (3600 * $cachehour) : (3600 * 24 * 8);
        $memcache->set($cache_key, $json, MEMCACHE_COMPRESSED, $ttl);
    }

    if (!$debug) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=epg.json');
    }
    echo $json;
}

$db->close();
?>
