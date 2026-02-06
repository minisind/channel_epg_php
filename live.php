<?php 
// live.php 按参数返回对应格式的频道数据
// author: aming.ou 
// 2024年1月14日
// 2026-02-05 升级：根据访客IP地址或token参数自动从live.json检索对应表名，支持多网络环境频道数据管理
// 使用示例：
//   http://127.0.0.1/live.php 默认返回diyp的txt格式
//   http://127.0.0.1/live.php?t=json 返回json格式
//   http://127.0.0.1/live.php?t=m3u 返回m3u格式
//   http://127.0.0.1/live.php?t=tvbox 返回tvbox接口所需json格式
//   http://127.0.0.1/live.php?token=hldshf932dfs 使用token参数指定表名
//   http://127.0.0.1/live.php?m=1 输出仅去重url，m=2输出去重且以#分隔合并url（默认）
// 参数说明：
//   t: 输出格式（txt/json/m3u/tvbox）
//   m: url合并方式（1=仅去重，2=去重且以#分隔合并）
//   token: 访问令牌，优先级高于IP地址检索

error_reporting(0);
$utf8 = false; // 仅对json有效, true输出utf8编码, false输出unicode编码
$t = !empty($_GET["t"]) ? strtolower($_GET["t"]) : 'txt'; //如要跟旧版本兼容，可以改名本php文件名，然后修改 $t 默认值
$optmerge = !empty($_GET["m"]) ? strtolower($_GET["m"]) : '2'; // '1'输出仅去重url; '2'输出去重且以#分隔合并url
if ($optmerge !== '1') $optmerge = '2';
$token = !empty($_GET["token"]) ? $_GET["token"] : '';
$debug = !empty($_GET["debug"]) ? true : false;
if ($debug){
	echo var_dump($utf8) . $t . "<br>" . $optmerge . "<br>"  ;
}
class ChannelDB extends SQLite3
{
	function __construct()
	{
		$this->open("channel_epg.db");
	}
}

$channel = new ChannelDB();
$currentIP = $_SERVER['REMOTE_ADDR'];
$ip = $currentIP;
$time = date("Y-m-d H:i:s");
$currentURL = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$source_link = empty($_SERVER['HTTP_REFERER']) ? $currentURL : urldecode($_SERVER['HTTP_REFERER']);

$ipParts = explode('.', $currentIP);
$ipSubnet = isset($ipParts[0]) && isset($ipParts[1]) ? $ipParts[0] . '.' . $ipParts[1] : '';

if (!file_exists('live.json')) {
    $defaultConfig = array(
        'ipsubnet' => array(
            '120.230' => 'list',
            '127.0' => 'list',
            '223.73' => 'list',
            '120.25' => 'list_ct',
            '14.45' => 'list_ct'
        ),
        'token' => array(
            'hldshf932dfs' => 'list',
            'owerejf921ls' => 'list_ct'
        )
    );
    file_put_contents('live.json', json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$liveConfig = @json_decode(@file_get_contents('live.json'), true);
$tableName = 'list';
if ($liveConfig && !empty($token) && isset($liveConfig['token'][$token])) {
    $tableName = $liveConfig['token'][$token];
} elseif ($liveConfig && isset($liveConfig['ipsubnet'][$ipSubnet])) {
    $tableName = $liveConfig['ipsubnet'][$ipSubnet];
}
// 将IP地址记录到日志文件或数据库中
$result = $channel->query("INSERT or ignore INTO access_log (ip_address,access_time,url) VALUES ('{$ip}','{$time}','{$source_link}');");

// 返回TVBOX直播源接口文件
if ($t ==='tvbox')
{
	$config = array(
		"spider" =>  "",
		"lives" =>  [[
				"name" =>  "直播",
				"type" =>  0,
				"url" =>  'http://' . substr($source_link,0,strripos($source_link,'/')+1) . 'live.php', //"http://home.jundie.top:81/Cat/tv/live.txt",
				"epg" =>  'http://' . substr($source_link,0,strripos($source_link,'/')+1) . 'epg.php?ch={name}&date={date}' //"http://epg.51zmt.top:8000/api/diyp/?ch={name}&date={date}"
			], [
				"group" => "redirect",
				"channels" =>  [[
						"name" =>  "live",
						"urls" =>  [
						]
					]
				],
				"epg" =>  'http://' . substr($source_link,0,strripos($source_link,'/')+1) . 'epg.php' //"http://epg.erw.cc/api/diyp/"
			]
		],
		"rules" => [
		],
		"sites" =>  [
		],
		"parses" =>  [
		],
		"flags" =>  [
		],
		"wallpaper" =>  ""
	);

	header_remove();
	header('Content-type: application/json');
	header('Content-Disposition: attachment; filename=tvbox.json');
	echo json_encode($config, JSON_UNESCAPED_UNICODE);
	exit;
}
//开始获取频道数据
$tableExists = $channel->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='{$tableName}'");
if ($tableExists == 0) {
	$channel->exec("CREATE TABLE `{$tableName}` (item text, title text, epg text, url text, isdel integer null default 120, constraint name_pk primary key (item,title,url))");
	$channel->exec("INSERT INTO `{$tableName}` VALUES ('IPTV组播','CCTV-1','','http://192.168.1.201:4022/rtp/239.10.0.114:1025$高清',95)");
}
$result = $channel->query("SELECT * from `{$tableName}` where isdel > 0 order by isdel;");
$config = array();
while ($row = $result->fetchArray())
{
	$config[] = ['item' => $row[0], 'title' => sprintf("%s", trim($row[1])), 'epg' => sprintf("%s", maxstr($row[2])), 'url' => trim(sprintf("%s", $row[3])), 'isdel' => $row[4]];
}
// 合并分组
$groupconfig = array_reduce($config, function($result, $item)
	{
		$gender = $item['item'];
		if (!isset($result[$gender]))
		{
			$result[$gender] = [];
		}
		$result[$gender][] = $item;
		return $result;
	}, []);
	
// 合并频道url
$config = array();
foreach ($groupconfig as $item => $titles)
{
	$channels = array_reduce($titles, function($result, $item)
		{
			$gender = $item['title'];
			if (!isset($result[$gender]))
			{
				$result[$gender] = array('title' => $gender,'url' => array(),'epg' => array(),'isdel' => array());
			}
			$result[$gender]['url'][] = $item['url'];
			$result[$gender]['epg'][] = $item['epg'];
			$result[$gender]['isdel'][] = $item['isdel'];
			return $result;
		}, []);
	$config[$item] = $channels;
}
$groupconfig = $config;
unset($config);

// 拆分字符串后取数组中出现次数最多的元素
function maxstr($string)
{
	$string = trim($string);
	if (strlen($string) === 0)
	{
		$more_value = '';
	}else{
		$array = array_filter(explode("#", $string)); 
		$frequency = array_count_values($array);
		arsort($frequency);
		$max_number = reset($frequency);
		$more_value = key($frequency);
	}
	return $more_value;
}

// 对title进行格式化的函数
function formatTitle($title,$t=1)
{
	$text = strtoupper($title);
	if ($t==1)
		{if (preg_match("/CCTV/", $text))
		{
			preg_match_all("/^[A-Za-z0-9-+]+/", $text, $matches);
			$text = $matches[0][0];
		}
		if (strpos($text, "[") != false)
		{
			$text = substr($text, 0, strpos($text, "["));
		}
		$text = str_replace('-', '', $text);
		if (strlen($text) == 0) $text = strtoupper($title);
	}else{
		if (strpos($text, "[") != false)
		{
			$text = substr($text, 0, strpos($text, "["));
		}
	}
	return $text;
} 
// 去重
function formatUrls($urlstr)
{
	$urls = array_filter(explode("#", $urlstr)); 
	$result = array_unique($urls);
	return implode("#", $result);
}

function gentxt($groupconfig,$optmerge = 2)
{
	$config = array();
	foreach ($groupconfig as $item => $titles)
	{
		$config[] = sprintf("%s,#genre#", $item);
		foreach ($titles as $k => $v)
		{					
			$epg = maxstr(implode('#',$v['epg']));
			$epg = $epg === '' ? formatTitle($v['title']) : $epg;
			$title =  formatTitle($v['title'],2);
			$url = formatUrls(implode('#',$v['url']));
			if ($optmerge === '2'){
				$config[] = $title . ',' . $url;
			}else{
				$urls = explode("#", $url);
				foreach($urls as $url)
				{
					$config[] = $title . ',' . $url;
				}
			}
		}
	}
	return implode(PHP_EOL, $config);
}

function genm3u($groupconfig,$optmerge = 2)
{
	$config = array();
	$config[] = '#EXTM3U x-tvg-url="http://127.0.0.1/diyp/epg.php"';
	foreach ($groupconfig as $item => $titles)
	{
		foreach ($titles as $k => $v)
		{
			$epg = maxstr(implode('#',$v['epg']));
			$epg = $epg === '' ? formatTitle($v['title']) : $epg;
			$title =  formatTitle($v['title'],2);
			$url = formatUrls(implode('#',$v['url']));
			if ($optmerge === '2'){
				$config[] = sprintf('#EXTINF:-1 tvg-id="" tvg-name="%s" tvg-logo="" group-title="%s",%s', $epg, $item, $title);
				$config[] = $url;
			}else{
				$urls = explode("#", $url);
				foreach($urls as $url)
				{
					$config[] = sprintf('#EXTINF:-1 tvg-id="" tvg-name="%s" tvg-logo="" group-title="%s",%s', $epg, $item, $title);
					$config[] = $url;
				}
			}
		}
	}
	return implode(PHP_EOL, $config);
}

function genjson($groupconfig,$optmerge = 2)
{
	global $utf8;
	$config = array();
	foreach ($groupconfig as $item => $titles)
	{
		$config[$item] = array();
		foreach ($titles as $k => $v)
		{
			$epg = maxstr(implode('#',$v['epg']));
			$epg = $epg === '' ? formatTitle($v['title']) : $epg;
			$title =  formatTitle($v['title'],2);
			$url = formatUrls(implode('#',$v['url']));

			if ($optmerge === '2'){
				array_push($config[$item], array('epg' => $epg,
						'title' => $title,
						'url' => $url
						));
			}else{
				$urls = explode("#", $url);
				foreach($urls as $url)
				{
					array_push($config[$item], array('epg' => $epg,
							'title' => $title,
							'url' => $url
							));
				}
			}
		}
	}
	return $utf8 ? json_encode($config) : json_encode($config, JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES);
}

// 返回直播源
if ($t === 'm3u'){
	$str = genm3u($groupconfig,$optmerge);
	if (!$debug){
	header_remove();
	header('Content-type: text/plain');
	header('Content-Disposition: attachment; filename=live.m3u');}
	echo $str;
}elseif ($t === 'json'){
	echo var_dump($utf8);
	$str = genjson($groupconfig,$optmerge);
	if (!$debug){
	header_remove();
	header('Content-type: application/json');
	header('Content-Disposition: attachment; filename=live.json');}
	echo $str;
}else{
	$str = gentxt($groupconfig,$optmerge);
	if (!$debug){
	header_remove();
	header('Content-type: text/plain;charset=utf-8');
	header('Content-Disposition: attachment; filename=live.txt');}
	echo $str;
}
?>