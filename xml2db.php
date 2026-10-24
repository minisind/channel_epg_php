<?php 
// xml2db.php 联网获取xml格式的节目表存如sqlite数据库,为本地提供DIYP epg接口服务
// author: aming.ou
// http://127.0.0.1/xml2db.php       同步xml数据
// http://127.0.0.1/xml2db.php?db=1  仅创建数据库,不同步xml数据

ini_set('memory_limit', '80M');
ini_set('max_execution_time', 60);

$save_all = 1;       // 1 保存全量节目单, 0 仅保存list中频道相关的节目单
$empty_tmp = 1;      // 1 入库后清除临时表的数据, 0 保留临时表的数据,建议调测期间设置为0, 调测完毕后修改为1,减少存储空间
$deleteoffset = -2;  // 清理xx天前的节目数据
$deletetoday = 1;    // 更新数据前先删除当天节目数据
error_reporting(1);  // 禁止输出错误提示
$displayname = 'display-name';
$n = 0;
$inserttype = 'ignore'; // replace  or ignore
$db = !empty($_GET["db"]) ? $_GET["db"] : '0';
$start = microtime(true);

class ChannelDB extends SQLite3
{
	function __construct()
	{
		$isnew = 1;
		$f = 'channel_epg.db';
		if (file_exists($f))
		{
			$isnew = 0;
		}
		$this->open($f);
		$this->busyTimeout(60000); // 10 seconds
		if ($isnew > 0)
		{ 
			// 初始化数据库
			$this->exec("BEGIN TRANSACTION;");
			$this->exec("CREATE TABLE 'list' (item text, title text, epg text, url text, isdel integer null default 120,constraint name_pk primary key (item,title,url))");
			$this->exec("CREATE TABLE if not exists 'access_log' (ip_address text, access_time text,url text)");
			$this->exec("CREATE TABLE if not exists 'epg_channel' ( `name` text, `channel_id` text,constraint name_pk primary key (name))");
			$this->exec("CREATE TABLE if not exists 'epg_programme' ( `title` text, `sdate` text, `sstart` text, `sstop` text, `channel` text, `sdesc` text, 'inserttime' text,constraint name_pk primary key (channel,sdate,sstart))");
			$this->exec("CREATE TABLE if not exists 'tmp_epg_channel' ( `name` text, `channel_id` text)");
			$this->exec("CREATE TABLE if not exists 'tmp_epg_programme' ( `title` text, `sdate` text, `sstart` text, `sstop` text, `channel` text, `sdesc` text, 'inserttime' text)"); 
			// 初始化频道表样例数据
			//$this->exec("INSERT INTO `list` (`item`,`title`,`epg`,`url`,`isdel`) VALUES ('广东频道','广州综合','','http://nas.jdshipin.com:8801/gztv.php?id=zhonghe','90');");
			//$this->exec("INSERT INTO `list` (`item`,`title`,`epg`,`url`,`isdel`) VALUES ('广东频道','广州新闻','','http://nas.jdshipin.com:8801/gztv.php?id=xinwen#http://113.100.193.10:9901/tsfile/live/1000_1.m3u8','90');");
			//$this->exec("INSERT INTO `list` (`item`,`title`,`epg`,`url`,`isdel`) VALUES ('直播频道','CCTV2','','http://dbiptv.sn.chinamobile.com/PLTV/88888893/224/3221226195/index.m3u8?0.smil','120');");
			$this->exec("COMMIT;");
		}
	}
}
// 连接数据库
$config = array();
$channel = new ChannelDB();
// 开始事务处理
$channel->exec("BEGIN TRANSACTION;");
// ==========写入访问日志开始=========
// 当前IP
$ip = $_SERVER['REMOTE_ADDR'];
$time = date("Y-m-d H:i:s"); 
// 当前url
$url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; 
// 获取最后来源地址
if (empty($_SERVER['HTTP_REFERER']))
{
	$source_link = $url;
}
else
{
	$source_link = $_SERVER['HTTP_REFERER'];
}
$source_link = urldecode($source_link);
// 将IP地址记录到日志文件或数据库中
$channel->exec("INSERT or ignore INTO access_log (ip_address,access_time,url) VALUES ('{$ip}','{$time}','{$source_link}');");
// ==========写入访问日志结束=========
if ( $db != '0' ){
	echo "only create db. please run again.";
	$channel->exec("INSERT or ignore INTO access_log (ip_address,access_time,url) VALUES ('{$ip}','{$time}','Create database');");
	exit;	
}


// 获取网页数据
function getContent($url)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (EPG Bot)');
    
    // 支持重定向（解决 302 问题）
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    
    // SSL 设置（即使 HTTP 也建议保留，以防重定向到 HTTPS）
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    curl_setopt($ch, CURLOPT_ENCODING, ""); // 自动解压

    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); // 实际请求的 URL
    $error_no = curl_errno($ch);
    curl_close($ch);

    if ($error_no) {
        echo "cURL Error [{$error_no}]: " . curl_error($ch) . " | URL: {$url}<br>";
        return false;
    }
    
    // 检查最终响应是否成功（允许 200, 可能重定向后是 200）
    if ($http_code !== 200) {
        echo "HTTP {$http_code} (final: {$final_url}) | Original URL: {$url}<br>";
        return false;
    }

    // 手动解压 gzip（如果需要）
    if (substr($data, 0, 2) === "\x1f\x8b") {
        if (!function_exists('gzdecode')) {
            echo "Error: gzdecode not available.<br>";
            return false;
        }
        $decoded = @gzdecode($data);
        if ($decoded === false) {
            echo "Error: Failed to decode gzip data from {$url}<br>";
            return false;
        }
        $data = $decoded;
    }

    return $data;
}


// 节目总表地址列表
//   https://raw.githubusercontent.com/sparkssssssssss/epg/main/pp.xml.gz, 
// $xmlurl = array("http://127.0.0.1/diyp/epg-28768806.xml"); //本地测试节目单
// $xmlurl = array("http://e.erw.cc/cc.xml.gz", "https://gitee.com/taksssss/tv/raw/main/epg/epgpw_cn.xml.gz", "https://gitee.com/taksssss/tv/blob/main/epg/erw.xml.gz"); //https gzip模式

//$xmlurl = array("https://gitee.com/taksssss/tv/raw/main/epg/erw.xml.gz", "https://gitee.com/taksssss/tv/raw/main/epg/epgpw_cn.xml.gz" , "https://e.erw.cc/cc.xml.gz" );

//$xmlurl = array("https://e.erw.cc/e.xml", "http://epg.51zmt.top:8000/e.xml", "https://epg.136605.xyz/3days.xml");

$xmlurl = array(
        //  "https://epg.v1.mk/fy.xml.gz", 
        "https://live.zhi35.com/epg.xml.gz",
        "https://e.erw.cc/cc.xml.gz"     //,             // 你已成功下载这个（说明它小）
	//"https://raw.githubusercontent.com/CCSH/IPTV/main/e.xml.gz"  
);
// 遍历xmlurl,同步epg xml数据源
$oknum = 1;
foreach ($xmlurl as $url)
{ 
	// 清空临时表
	$channel->exec("delete from tmp_epg_channel");
	$channel->exec("delete from tmp_epg_programme"); 
	// 获取xml格式的节目单

	$data = getContent($url);
	if (!$data || !is_string($data)) {
    		echo "Failed to get valid data from: {$url}<br><br>";
    		continue;
	}

	// 尝试解析 XML
	libxml_use_internal_errors(true);
	$xml = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA);
	if ($xml === false) {
		echo "XML parse failed for: {$url}<br>";
	    	foreach (libxml_get_errors() as $error) {
        		echo "XML Error: " . $error->message . "<br>";
    		}
    		libxml_clear_errors();
    		continue; // 跳过这个 URL
	}
       	// 尝试检测是否是二进制 gzip（虽然理论上不会出现）
	if (substr($data, 0, 2) === "\x1f\x8b") {
    		echo "Warning: Data still gzipped! Check cURL encoding setting. URL: {$url}<br>";
    		$data = gzdecode($data);
    		if ($data === false) {
        		echo "Failed to manually decode gzip. Skip.<br>";
        		continue;
         	}
         }

        ////////////////////////////////////////////  20260224
	// 尝试解析 XML
	$xml = simplexml_load_string($data);

	if ($xml === false) {
		// 获取解析错误
		$errors = libxml_get_errors();
		
		echo "XML error";
		echo $errors;
		foreach ($errors as $error) {
			echo "error level: " . $error->level . "\n";
			echo "error code: " . $error->code . "\n";
			echo "error msg: " . $error->message . "\n";
			echo "error row: " . $error->line . "\n";
			echo "error col: " . $error->column . "\n\n";
		}
		
		libxml_clear_errors();
		exit();
	}

	foreach ($xml->children() as $xmldata)
	{
		if ($xmldata->getName() == "channel")
		{
			$result = $channel->query("INSERT INTO tmp_epg_channel(name,channel_id) VALUES ('" . $xmldata->$displayname . "','" . $xmldata->attributes()->id . "')");

			if (!$result)
			{
				echo $n . ' - ' . $channel->lastErrorMsg() . '<br>';
			}
		}

		if ($xmldata->getName() == "programme")
		{
			// 获取完整的start和stop时间字符串
			$start_attr = $xmldata->attributes()->start;
			$stop_attr = $xmldata->attributes()->stop;
			
			// 提取日期部分
			$b_date = substr($start_attr, 0, 4) . "-" . substr($start_attr, 4, 2) . "-" . substr($start_attr, 6, 2);
			
			// 处理start时间 - 直接提取小时和分钟
			$start_time = substr($start_attr, 8, 2) . ":" . substr($start_attr, 10, 2);
			$start_hour = (int)substr($start_attr, 8, 2);
			
			// 处理stop时间 - 先解析完整时间，再向上取整到下一分钟
			// 解析完整的stop时间字符串 (格式：YYYYMMDDHHMMSS)
			$stop_datetime_str = substr($stop_attr, 0, 14);
			$stop_datetime = DateTime::createFromFormat('YmdHis', $stop_datetime_str);
			
			// 只有当秒数大于0时才向上取整到下一分钟
			if ((int)$stop_datetime->format('s') > 0) {
				$stop_datetime->add(new DateInterval('PT1M'));
			}
			// 确保秒数为0
			$stop_datetime->setTime($stop_datetime->format('H'), $stop_datetime->format('i'), 0);
			
			// 获取格式化后的结束日期和时间
			$e_date = $stop_datetime->format('Y-m-d');
			$stop_time = $stop_datetime->format('H:i');
			$stop_hour = (int)$stop_datetime->format('H');
			
			// 处理脏数据：如果stop的小时比start的小时小，说明可能存在跨天情况
			if ($stop_hour < $start_hour && $b_date == $e_date) {
				// 将e_date修正为b_date + 1天
				$e_date = date('Y-m-d', strtotime($b_date . ' +1 day'));
			}
			
			$replacement = str_replace("'", " ", $xmldata->title);
			
			// 播放器支持跨1天，且支持结束时间比开始时间小，按原逻辑插入一条记录
			$n ++ ;
			$sql = "INSERT INTO tmp_epg_programme(channel,sdate,sstart,sstop,title,sdesc,inserttime) VALUES ('" . $xmldata->attributes()->channel . "','" . $b_date . "','" . $start_time . "','" . $stop_time . "','" . $replacement . "','','" . $time . "')";
			$result = $channel->query($sql);

			if (!$result)
			{
				echo $n . ' = ' . $channel->lastErrorMsg() . '<br>'; 
			}
		}
	} 
	// 更新节目数据
	if ($n > 0)
	{ 
		// 写入日志
		$channel->exec("INSERT INTO access_log (ip_address,access_time,url) VALUES ('xml2db_ini','{$time}','{$n}');"); 
		// 把直播源list的频道追加/更新到epg频道表
		$channel->exec("insert or ignore into epg_channel select upper(a.name) as name ,upper(a.name) as channel_id from tmp_epg_channel a where upper(name) in (select upper(title) from list);"); 
		// 根据条件是否保存全量epg频道表
		$count = $channel->querySingle("SELECT count(*) FROM 'list'");
		if ($count == 0 or $save_all == 1)
		{
			$channel->exec("insert or ignore into epg_channel SELECT upper(a.name) as name ,upper(a.name) as channel_id FROM tmp_epg_channel a;");
		} 
		if ($oknum == 1)
		{
			// 清理历史数据
			$currentDate = $channel->querySingle("SELECT max(sdate) FROM 'epg_programme'");
			$currentDate = (strtotime($currentDate) > strtotime(date("Y-m-d"))) ? date("Y-m-d") : $currentDate;
			$newDate = strtotime($currentDate) + ($deleteoffset * 24 * 60 * 60);
			$formattedNewDate = date('Y-m-d', $newDate);
			echo 'epg_programme dates:' . $currentDate . ' <- ' . $formattedNewDate . '<br>';
			$channel->exec("delete from `epg_programme` where sdate < '{$formattedNewDate}';");
			echo 'Delete ' . $channel->changes() . ' records from epg_programme before ' . $formattedNewDate . '<br>';
			
			// 清理当天及未来的节目单数据
			if ($deletetoday == 1)
			{
				$currentDate = date('Y-m-d'); // 获取当前日期
				$channel->exec("delete from `epg_programme` where sdate >= '{$currentDate}';");
				echo 'Delete ' . $channel->changes() . ' records from epg_programme after ' . $currentDate . '<br>';
			}
			$oknum = 0;
		}
		
		// 统计原节目单条目数
		$count = $channel->querySingle("SELECT count(*) FROM 'epg_programme'"); 
		// 追加节目单到epg_programme
		$channel->exec("insert or {$inserttype} into epg_programme SELECT c.title,c.sdate,c.sstart,c.sstop,upper(a.name) as channel,c.sdesc,c.inserttime FROM epg_channel a join (select * from tmp_epg_channel where channel_id not in (select channel from epg_programme where sdate>='". date('Y-m-d') ."')) b on upper(a.name) = upper(b.name) join tmp_epg_programme c on b.channel_id = c.channel;"); 
		// 统计节目单增加条目数
		$count = $channel->querySingle("SELECT count(*) FROM 'epg_programme'") - $count;
		$channel->exec("INSERT INTO access_log (ip_address,access_time,url) VALUES ('xml2db_add','{$time}','{$count}');");
		//echo "done, add " . $count . '<br><br>'; 
		echo "done, add {$count} from " . basename($url) . "<br><br>";       // log 20260224
		if ($empty_tmp == 1)
		{ 
			// 清空临时表
			$channel->exec("delete from tmp_epg_channel");
			$channel->exec("delete from tmp_epg_programme");
		}
	}
	else
	{
		echo 'none.<br>';
	}
}
$executionTime = number_format(microtime(true) - $start, 4);
$channel->exec("INSERT INTO access_log (ip_address,access_time,url) VALUES ('xml2db_cost','{$time}','{$executionTime}');");
// 写入硬盘
$channel->exec("COMMIT;");
$channel->close();
echo 'fetch xml epg data total cost ' . $executionTime . ' s.';

?>