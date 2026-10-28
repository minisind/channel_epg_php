<?php
/**
 * 安全增强版 EPG 接口
 * 支持 XMLTV / DIYP / SPTV 三种格式
 * 已修复：SQL 注入防护、跨天逻辑、时区、日期校验、showTime 计算、结果集过大等问题
 * 适配 OpenWrt 路由器环境
 */

    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING); // 生产环境可改为 0
    
    // ========== 配置 ==========
    $utf8      = false;          // true: UTF-8, false: Unicode (JSON_UNESCAPED_UNICODE)
    $iscache   = false;          // 是否启用缓存（OpenWrt 通常无 memcached，建议 false）
    $cachehour = 2;              // 未来数据缓存小时数
    $debug     = !empty($_GET["debug"]);
    $isw_log   = false;          // 是否写入 access_log（建议 false，保护闪存）
    $xml_days  = 7;              // XMLTV 最多返回未来几天的数据（防止结果集过大）
    $timezone  = '+0800';         // XMLTV 时区（中国常用 +0800）
    
    // ========== 输入处理 ==========
    $riqi = trim($_GET["date"] ?? '');
    $ch   = trim($_GET["ch"] ?? $_GET["channel"] ?? 'CCTV1');
    
    // 自动转换 YYYYMMDD → YYYY-MM-DD
    if ($riqi && strlen($riqi) === 8 && ctype_digit($riqi)) {
        $riqi = substr($riqi, 0, 4) . '-' . substr($riqi, 4, 2) . '-' . substr($riqi, 6, 2);
    }
    
    // 严格日期校验
    $dateObj = DateTime::createFromFormat('Y-m-d', $riqi);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $riqi) {
        $riqi = date('Y-m-d');
        $dateObj = new DateTime($riqi);
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
            $mode = self::$log_enabled ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY;
            $this->open($db_path, $mode);
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
    $memcache  = null;
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
        $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $time    = date('Y-m-d H:i:s');
        $url     = ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
        $referer = $_SERVER['HTTP_REFERER'] ?? $url;
        $referer = urldecode($referer);
    
        try {
            $db = new ChannelDB();
            $stmt = $db->prepare("INSERT OR IGNORE INTO access_log (ip_address, access_time, url) VALUES (?, ?, ?)");
            $stmt->bindValue(1, $ip, SQLITE3_TEXT);
            $stmt->bindValue(2, $time, SQLITE3_TEXT);
            $stmt->bindValue(3, $referer, SQLITE3_TEXT);
            $stmt->execute();
            $db->close();
        } catch (Exception $e) {
            // 忽略日志写入失败
        }
    }
    
    // ========== 辅助函数 ==========
    /**
     * 计算开始/结束时间戳（正确处理跨天）
     */
    function calcTimestamps(string $date, string $start, string $stop): array {
        $start_ts = strtotime($date . ' ' . $start);
        $stop_ts  = strtotime($date . ' ' . $stop);
    
        if ($start_ts === false || $stop_ts === false) {
            return [0, 0];
        }
    
        // 跨天：结束时间 <= 开始时间
        if ($stop_ts <= $start_ts) {
            $stop_ts += 86400;
        }
        return [$start_ts, $stop_ts];
    }
    
    /**
     * 将秒数格式化为 HH:MM（用于 SPTV showTime）
     */
    function formatDuration(int $seconds): string {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        return sprintf('%02d:%02d', $h, $m);
    }
    
    /**
     * DIYP 结束时间字符串处理（跨天时返回 00:00 或实际时间）
     * 多数 DIYP 客户端支持 end < start 表示跨天，这里统一用实际结束时间
     */
    function getDiypEndTime(string $stop, bool $is_cross_day): string {
        if ($is_cross_day) {
            // 跨天时直接返回原始 stop（客户端一般能识别 end < start）
            // 若需要强制 00:00，可改为 return '00:00';
            return $stop;
        }
        return $stop === '23:59' ? '00:00' : $stop;
    }
    
    // ========== 主逻辑 ==========
    $db = new ChannelDB();
    
    if ($ch === 'xml') {
        // ========== XMLTV 格式 ==========
        $channels   = [];
        $programmes = [];
    
        // 获取所有频道信息
        $stmt = $db->prepare("SELECT channel_id, name FROM epg_channel");
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $channels[$row['channel_id']] = $row['name'];
        }
    
        // 限制查询范围：从指定日期到 $xml_days 天后
        $end_date = (clone $dateObj)->modify("+{$xml_days} days")->format('Y-m-d');
    
        $stmt = $db->prepare("SELECT e.channel, e.title, e.sdate, e.sstart, e.sstop
                              FROM epg_programme e
                              WHERE e.sdate >= ? AND e.sdate <= ?
                              AND e.channel IN (SELECT DISTINCT channel_id FROM epg_channel)");
        $stmt->bindValue(1, $riqi, SQLITE3_TEXT);
        $stmt->bindValue(2, $end_date, SQLITE3_TEXT);
        $result = $stmt->execute();
    
        $used_channels = [];
    
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            list($start_ts, $stop_ts) = calcTimestamps($row['sdate'], $row['sstart'], $row['sstop']);
            if ($start_ts === 0) continue;
    
            $start_str = date('YmdHis', $start_ts) . ' ' . $timezone;
            $stop_str  = date('YmdHis', $stop_ts)  . ' ' . $timezone;
    
            $title = htmlspecialchars($row['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $programmes[] = "\t<programme channel=\"{$row['channel']}\" start=\"{$start_str}\" stop=\"{$stop_str}\">\n" .
                            "\t\t<title lang=\"zh\">{$title}</title>\n" .
                            "\t</programme>";
    
            $used_channels[$row['channel']] = true;
        }
    
        // 构建 channel 列表（仅包含有节目的频道）
        $channel_xml = [];
        foreach ($channels as $id => $name) {
            if (isset($used_channels[$id])) {
                $name_esc = htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $channel_xml[] = "\t<channel id=\"{$id}\">\n" .
                                 "\t\t<display-name lang=\"zh\">{$name_esc}</display-name>\n" .
                                 "\t</channel>";
            }
        }
    
        $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<tv generator-info-name=\"epg.php\" generator-info-url=\"http://your-router/epg.php?ch=xml\">\n";
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
        $epg_datas  = [];
        $epg_data2s = [];
        $is_found   = false;
    
        // 查询频道 ID（精确匹配，忽略大小写）
        $stmt = $db->prepare("SELECT channel_id FROM epg_channel WHERE upper(name) = upper(?) LIMIT 1");
        $stmt->bindValue(1, $ch, SQLITE3_TEXT);
        $result = $stmt->execute();
        $channel_row = $result->fetchArray(SQLITE3_ASSOC);
    
        if ($channel_row) {
            $channel_id = $channel_row['channel_id'];
    
            // 查询指定日期的节目
            $stmt = $db->prepare("SELECT sstart, sstop, title FROM epg_programme 
                                  WHERE channel = ? AND sdate = ?
                                  ORDER BY sstart ASC");
            $stmt->bindValue(1, $channel_id, SQLITE3_TEXT);
            $stmt->bindValue(2, $riqi, SQLITE3_TEXT);
            $result = $stmt->execute();
    
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $is_found = true;
                list($start_ts, $stop_ts) = calcTimestamps($riqi, $row['sstart'], $row['sstop']);
                if ($start_ts === 0) continue;
    
                $is_cross_day = ($stop_ts - $start_ts) > 0 && date('Y-m-d', $stop_ts) !== $riqi;
                $duration     = $stop_ts - $start_ts;
    
                if ($tvapp === 'sptv') {
                    $epg_data2s[] = [
                        "eventId"   => "",
                        "showTime"  => formatDuration($duration),
                        "eventType" => "",
                        "st"        => $start_ts,
                        "t"         => $row['title'],
                        "duration"  => $duration,
                        "et"        => $stop_ts
                    ];
                } else {
                    // DIYP
                    $epg_datas[] = [
                        "start" => $row['sstart'],
                        "end"   => getDiypEndTime($row['sstop'], $is_cross_day),
                        "title" => $row['title'],
                        "desc"  => ""
                    ];
                }
            }
        }
    
        // 未找到节目时生成 24 小时占位
        if (!$is_found) {
            for ($i = 0; $i < 24; $i++) {
                $start_h  = sprintf('%02d', $i);
                $end_h    = sprintf('%02d', ($i + 1) % 24);
                $start_ts = strtotime($riqi . " {$start_h}:00");
                $end_ts   = $start_ts + 3600;
    
                // 跨天处理
                if ($end_h === '00') {
                    $end_ts += 86400;
                }
    
                if ($tvapp === 'sptv') {
                    $epg_data2s[] = [
                        "eventId"   => "",
                        "showTime"  => "01:00",
                        "eventType" => "",
                        "st"        => $start_ts,
                        "t"         => "精彩节目",
                        "duration"  => 3600,
                        "et"        => $end_ts
                    ];
                } else {
                    $epg_datas[] = [
                        "start" => "{$start_h}:00",
                        "end"   => "{$end_h}:00",
                        "title" => "精彩节目",
                        "desc"  => ""
                    ];
                }
            }
        }
    
        // 构建输出
        if ($tvapp === 'sptv') {
            $output = [
                $ch => [
                    "isLive"      => "",
                    "liveSt"      => "",
                    "program"     => $epg_data2s,
                    "channelName" => $ch,
                    "lvUrl"       => ""
                ]
            ];
        } else {
            $output = [
                "channel_name" => $ch,
                "date"         => $riqi,
                "epg_data"     => $epg_datas
            ];
        }
    
        $json_options = $utf8 ? 0 : JSON_UNESCAPED_UNICODE;
        $json = json_encode($output, $json_options | JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_SLASHES);
    
        if ($use_cache) {
            $is_future = (strtotime($riqi) >= strtotime(date('Y-m-d')));
            $ttl = $is_future ? (3600 * $cachehour) : (3600 * 24 * 8);
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

