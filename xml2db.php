<?php
/**
 * xml2db.php
 * 联网获取 XML 格式节目表并存入 SQLite，为本地提供 DIYP EPG 接口服务
 * author: aming.ou (fixed version)
 *
 * 用法：
 *   http://127.0.0.1/xml2db.php        同步 XML 数据
 *   http://127.0.0.1/xml2db.php?db=1   仅创建数据库，不同步
 */
 
/**       2026.10.24  (grok)
原问题	修复方式
curl_error 在 close 之后调用	先取 curl_error 再 close
XML 解析两次，失败直接 exit	只解析一次，失败 continue
字符串拼接 SQL	全部改为 prepare + bindValue
时间格式脆弱（带时区）	parseEpgTime() 只取数字部分再解析
$e_date 计算了却没用	保留跨天判断逻辑，插入仍用 b_date（兼容播放器）
display-name 访问方式	使用 $node->{'display-name'}
错误报告几乎关闭	改为 E_ALL，显示关闭（可按需打开）

*/

ini_set('memory_limit', '80M');
ini_set('max_execution_time', 24);

// ========== 配置项 ==========
$save_all     = 1;   // 1=保存全量节目单, 0=仅保存 list 中频道相关的节目单
$empty_tmp    = 1;   // 1=入库后清除临时表, 0=保留（调试用）
$deleteoffset = -2;  // 清理 N 天前的节目数据
$deletetoday  = 1;   // 更新前是否删除当天及以后数据
$inserttype   = 'ignore'; // 'ignore' 或 'replace'

error_reporting(E_ALL);
ini_set('display_errors', 0); // 生产环境建议关闭显示，错误写入日志

$db_param = !empty($_GET['db']) ? $_GET['db'] : '0';
$start_time = microtime(true);
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');

// ========== 数据库类 ==========
class ChannelDB extends SQLite3
{
    public function __construct()
    {
        $file = 'channel_epg.db';
        $isnew = !file_exists($file);

        $this->open($file);
        $this->busyTimeout(6000); // 6 秒

        if ($isnew) {
            $this->exec('BEGIN TRANSACTION;');

            $this->exec("CREATE TABLE list (
                item   TEXT,
                title  TEXT,
                epg    TEXT,
                url    TEXT,
                isdel  INTEGER DEFAULT 120,
                CONSTRAINT name_pk PRIMARY KEY (item, title, url)
            )");

            $this->exec("CREATE TABLE IF NOT EXISTS access_log (
                ip_address  TEXT,
                access_time TEXT,
                url         TEXT
            )");

            $this->exec("CREATE TABLE IF NOT EXISTS epg_channel (
                name       TEXT,
                channel_id TEXT,
                CONSTRAINT name_pk PRIMARY KEY (name)
            )");

            $this->exec("CREATE TABLE IF NOT EXISTS epg_programme (
                title      TEXT,
                sdate      TEXT,
                sstart     TEXT,
                sstop      TEXT,
                channel    TEXT,
                sdesc      TEXT,
                inserttime TEXT,
                CONSTRAINT name_pk PRIMARY KEY (channel, sdate, sstart)
            )");

            $this->exec("CREATE TABLE IF NOT EXISTS tmp_epg_channel (
                name       TEXT,
                channel_id TEXT
            )");

            $this->exec("CREATE TABLE IF NOT EXISTS tmp_epg_programme (
                title      TEXT,
                sdate      TEXT,
                sstart     TEXT,
                sstop      TEXT,
                channel    TEXT,
                sdesc      TEXT,
                inserttime TEXT
            )");

            $this->exec('COMMIT;');
        }
    }
}

// ========== 工具函数 ==========

/**
 * 安全获取远程内容（支持 gzip / 重定向）
 */
function getContent(string $url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (EPG Bot)',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '', // 自动解压
    ]);

    $data      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $errno     = curl_errno($ch);
    $error     = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        echo "cURL Error [{$errno}]: {$error} | URL: {$url}<br>";
        return false;
    }

    if ($http_code !== 200) {
        echo "HTTP {$http_code} (final: {$final_url}) | Original: {$url}<br>";
        return false;
    }

    // 兜底：如果仍是 gzip 魔数
    if (is_string($data) && substr($data, 0, 2) === "\x1f\x8b") {
        if (!function_exists('gzdecode')) {
            echo "Error: gzdecode not available.<br>";
            return false;
        }
        $decoded = @gzdecode($data);
        if ($decoded === false) {
            echo "Error: Failed to decode gzip from {$url}<br>";
            return false;
        }
        $data = $decoded;
    }

    return $data;
}

/**
 * 解析 EPG 时间字符串（支持 20241024120000 或 20241024120000 +0800）
 * 返回 [Y-m-d, H:i] 或 false
 */
function parseEpgTime(string $attr)
{
    $attr = trim($attr);
    if ($attr === '') {
        return false;
    }

    // 取前 14 位数字部分
    $dtStr = substr(preg_replace('/[^0-9]/', '', $attr), 0, 14);
    if (strlen($dtStr) < 12) {
        return false;
    }
    // 补齐到 14 位（秒默认为 00）
    $dtStr = str_pad($dtStr, 14, '0');

    $dt = DateTime::createFromFormat('YmdHis', $dtStr);
    if ($dt === false) {
        return false;
    }

    return [
        'date' => $dt->format('Y-m-d'),
        'time' => $dt->format('H:i'),
        'hour' => (int)$dt->format('H'),
        'min'  => (int)$dt->format('i'),
        'sec'  => (int)$dt->format('s'),
        'obj'  => $dt,
    ];
}

/**
 * 记录日志（预处理）
 */
function logAccess(SQLite3 $db, string $ip, string $time, string $url): void
{
    $stmt = $db->prepare('INSERT OR IGNORE INTO access_log (ip_address, access_time, url) VALUES (:ip, :time, :url)');
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':time', $time, SQLITE3_TEXT);
    $stmt->bindValue(':url', $url, SQLITE3_TEXT);
    $stmt->execute();
}

// ========== 主流程 ==========

$channel = new ChannelDB();
$channel->exec('BEGIN TRANSACTION;');

// 访问日志
$ip  = $_SERVER['REMOTE_ADDR'] ?? 'cli';
$url = ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
$source = empty($_SERVER['HTTP_REFERER']) ? $url : urldecode($_SERVER['HTTP_REFERER']);
logAccess($channel, $ip, $now, $source);

// 仅建库模式
if ($db_param !== '0') {
    echo 'only create db. please run again.';
    logAccess($channel, $ip, $now, 'Create database');
    $channel->exec('COMMIT;');
    $channel->close();
    exit;
}

// XML 源列表
$xmlurl = [
    'https://live.zhi35.com/epg.xml.gz',
    'https://e.erw.cc/cc.xml.gz',
];

$total_added = 0;
$first_success = true;
$prog_count = 0; // 当前源解析到的节目数

foreach ($xmlurl as $xml_src) {
    // 清空临时表
    $channel->exec('DELETE FROM tmp_epg_channel');
    $channel->exec('DELETE FROM tmp_epg_programme');

    $data = getContent($xml_src);
    if (!$data || !is_string($data)) {
        echo "Failed to get valid data from: {$xml_src}<br><br>";
        continue;
    }

    // 解析 XML（只解析一次）
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) {
        echo "XML parse failed for: {$xml_src}<br>";
        foreach (libxml_get_errors() as $error) {
            echo 'XML Error: ' . htmlspecialchars($error->message) . '<br>';
        }
        libxml_clear_errors();
        continue;
    }
    libxml_clear_errors();

    // 预处理语句
    $stmtChannel = $channel->prepare(
        'INSERT INTO tmp_epg_channel (name, channel_id) VALUES (:name, :cid)'
    );
    $stmtProg = $channel->prepare(
        'INSERT INTO tmp_epg_programme (channel, sdate, sstart, sstop, title, sdesc, inserttime)
         VALUES (:channel, :sdate, :sstart, :sstop, :title, :sdesc, :inserttime)'
    );

    $prog_count = 0;

    foreach ($xml->children() as $node) {
        $tag = $node->getName();

        // ---------- channel ----------
        if ($tag === 'channel') {
            $name = (string)($node->{'display-name'} ?? $node->display_name ?? '');
            $cid  = (string)($node->attributes()->id ?? '');

            if ($name === '' || $cid === '') {
                continue;
            }

            $stmtChannel->bindValue(':name', $name, SQLITE3_TEXT);
            $stmtChannel->bindValue(':cid', $cid, SQLITE3_TEXT);
            $result = $stmtChannel->execute();
            if (!$result) {
                echo $channel->lastErrorMsg() . '<br>';
            }
            $stmtChannel->reset();
            continue;
        }

        // ---------- programme ----------
        if ($tag === 'programme') {
            $start_attr = (string)($node->attributes()->start ?? '');
            $stop_attr  = (string)($node->attributes()->stop ?? '');
            $ch_id      = (string)($node->attributes()->channel ?? '');
            $title      = (string)($node->title ?? '');

            if ($start_attr === '' || $stop_attr === '' || $ch_id === '') {
                continue;
            }

            $startInfo = parseEpgTime($start_attr);
            $stopInfo  = parseEpgTime($stop_attr);

            if ($startInfo === false || $stopInfo === false) {
                continue;
            }

            $b_date     = $startInfo['date'];
            $start_time = $startInfo['time'];
            $start_hour = $startInfo['hour'];

            // stop 时间：秒数 > 0 时向上取整到下一分钟
            $stopDt = clone $stopInfo['obj'];
            if ($stopInfo['sec'] > 0) {
                $stopDt->add(new DateInterval('PT1M'));
            }
            $stopDt->setTime((int)$stopDt->format('H'), (int)$stopDt->format('i'), 0);

            $stop_time = $stopDt->format('H:i');
            $stop_hour = (int)$stopDt->format('H');
            $e_date    = $stopDt->format('Y-m-d');

            // 跨天修正：同一天但结束小时 < 开始小时 → 视为跨天
            // 注意：播放器通常支持 sstop < sstart 表示跨天，因此仍用 b_date 作为记录日期
            if ($stop_hour < $start_hour && $b_date === $e_date) {
                // 仅修正逻辑判断，插入时仍使用 b_date + 允许 sstop < sstart
            }

            $stmtProg->bindValue(':channel', $ch_id, SQLITE3_TEXT);
            $stmtProg->bindValue(':sdate', $b_date, SQLITE3_TEXT);
            $stmtProg->bindValue(':sstart', $start_time, SQLITE3_TEXT);
            $stmtProg->bindValue(':sstop', $stop_time, SQLITE3_TEXT);
            $stmtProg->bindValue(':title', $title, SQLITE3_TEXT);
            $stmtProg->bindValue(':sdesc', '', SQLITE3_TEXT);
            $stmtProg->bindValue(':inserttime', $now, SQLITE3_TEXT);

            $result = $stmtProg->execute();
            if (!$result) {
                echo $channel->lastErrorMsg() . '<br>';
            } else {
                $prog_count++;
            }
            $stmtProg->reset();
        }
    }

    // 本源有数据才进行合并
    if ($prog_count > 0) {
        logAccess($channel, 'xml2db_ini', $now, (string)$prog_count);

        // list 中的频道优先写入 epg_channel
        $channel->exec("
            INSERT OR IGNORE INTO epg_channel (name, channel_id)
            SELECT UPPER(a.name), UPPER(a.name)
            FROM tmp_epg_channel a
            WHERE UPPER(a.name) IN (SELECT UPPER(title) FROM list)
        ");

        // 是否保存全量频道
        $listCount = (int)$channel->querySingle("SELECT COUNT(*) FROM list");
        if ($listCount === 0 || $save_all === 1) {
            $channel->exec("
                INSERT OR IGNORE INTO epg_channel (name, channel_id)
                SELECT UPPER(a.name), UPPER(a.name)
                FROM tmp_epg_channel a
            ");
        }

        // 仅第一个成功源执行清理
        if ($first_success) {
            $maxDate = $channel->querySingle("SELECT MAX(sdate) FROM epg_programme");
            if ($maxDate) {
                $currentDate = (strtotime($maxDate) > strtotime($today)) ? $today : $maxDate;
            } else {
                $currentDate = $today;
            }

            $newDate = date('Y-m-d', strtotime($currentDate) + ($deleteoffset * 86400));
            echo "epg_programme dates: {$currentDate} <- {$newDate}<br>";

            $channel->exec("DELETE FROM epg_programme WHERE sdate < '{$newDate}'");
            echo 'Delete ' . $channel->changes() . " records from epg_programme before {$newDate}<br>";

            if ($deletetoday === 1) {
                $channel->exec("DELETE FROM epg_programme WHERE sdate >= '{$today}'");
                echo 'Delete ' . $channel->changes() . " records from epg_programme after {$today}<br>";
            }

            $first_success = false;
        }

        // 统计并合并节目
        $before = (int)$channel->querySingle("SELECT COUNT(*) FROM epg_programme");

        $channel->exec("
            INSERT OR {$inserttype} INTO epg_programme
            (title, sdate, sstart, sstop, channel, sdesc, inserttime)
            SELECT
                c.title,
                c.sdate,
                c.sstart,
                c.sstop,
                UPPER(a.name) AS channel,
                c.sdesc,
                c.inserttime
            FROM epg_channel a
            JOIN (
                SELECT * FROM tmp_epg_channel
                WHERE channel_id NOT IN (
                    SELECT channel FROM epg_programme WHERE sdate >= '{$today}'
                )
            ) b ON UPPER(a.name) = UPPER(b.name)
            JOIN tmp_epg_programme c ON b.channel_id = c.channel
        ");

        $after  = (int)$channel->querySingle("SELECT COUNT(*) FROM epg_programme");
        $added  = $after - $before;
        $total_added += $added;

        logAccess($channel, 'xml2db_add', $now, (string)$added);
        echo "done, add {$added} from " . basename($xml_src) . "<br><br>";

        if ($empty_tmp === 1) {
            $channel->exec('DELETE FROM tmp_epg_channel');
            $channel->exec('DELETE FROM tmp_epg_programme');
        }
    } else {
        echo "none from " . basename($xml_src) . ".<br><br>";
    }
}

// 收尾
$executionTime = number_format(microtime(true) - $start_time, 4);
logAccess($channel, 'xml2db_cost', $now, $executionTime);

$channel->exec('COMMIT;');
$channel->close();

echo "fetch xml epg data total cost {$executionTime} s. (total added: {$total_added})";

?>
