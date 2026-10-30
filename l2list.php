<!DOCTYPE html>
<html>
<head>
<title>直播源数据转化240123v7</title>
<script>
	function disableRadios(radio) {
		var checkboxes = document.getElementsByTagName('input');
		if (radio.value == 'deb') {
			for (var i = 0; i < checkboxes.length; i++) {
				if (checkboxes[i].name == 'gender2') {
					checkboxes[i].disabled = true;
				}
			}
		} else {
			for (var i = 0; i < checkboxes.length; i++) {
				if (checkboxes[i].name == 'gender2') {
					checkboxes[i].disabled = false;
				}
			}
		}
	}
	function trim(str) {
		return str.replace(/(^\s*)|(\s*$)/g, "");
	}
	function disablebt(textArea) {
		var t = trim(textArea.value);
		if (t.length > 12) {
			t = t.substring(0, 4).toUpperCase();
			if (t == 'HTTP') {
				document.getElementById("button1").disabled = true;
				document.getElementById("button2").disabled = false;
			} else {
				document.getElementById("button1").disabled = false;
				document.getElementById("button2").disabled = true;
			}
		} else {
			document.getElementById("button1").disabled = true;
			document.getElementById("button2").disabled = true;
		}
	}
	function bt1() {
		document.getElementById("button1").disabled = false;
		document.getElementById("button2").disabled = true;
	}
	function bt2() {
		document.getElementById("button1").disabled = true;
		document.getElementById("button2").disabled = false;
	}
	function saveFile() {
		var inValue = document.getElementById("outContent").value;
		var st = document.getElementById("savetype").value;
		const now = new Date().getTime();
		const fileName = "live_" + now + "." + st;
		var tempLink = document.createElement("a");
		var taBlob = new Blob([inValue], { type: "text/plain;charset=utf-8" });
		tempLink.setAttribute('href', URL.createObjectURL(taBlob));
		tempLink.setAttribute('download', fileName);
		tempLink.click();
		URL.revokeObjectURL(tempLink.href);
	}
	function importFile() {
		document.getElementById("_ef").click();
	}
	function beforeUpload(event) {
		var fileInput = document.getElementById("_ef");
		if (fileInput.files && fileInput.files[0]) {
			var file = fileInput.files[0];
			const reader = new FileReader();
			reader.onload = function(event) {
				const content = event.target.result;
				var varId = document.getElementById("m3uContent");
				varId.value = content;
				disablebt(varId);
			};
			reader.readAsText(file);
		}
	}
	// 页面加载时根据当前选中状态初始化
	window.onload = function() {
		var radios = document.getElementsByName('gender');
		for (var i = 0; i < radios.length; i++) {
			if (radios[i].checked) {
				disableRadios(radios[i]);
				break;
			}
		}
		// 初始化按钮状态
		var ta = document.getElementById("m3uContent");
		if (ta) disablebt(ta);
	};
</script>
<style type="text/css">
.tex {
	background: #efefef;
	resize: none;
}
</style>
</head>
<body>
<?php
error_reporting(0);

// 初始化默认值（防止未定义变量）
$tosql       = 'deb';
$optmerge    = '2';
$merge       = false;
$debug       = true;
$isdelCounter = 1;
$db          = null;

// 判断是否有表单提交
if ($_SERVER["REQUEST_METHOD"] == "POST") {
	if (isset($_POST['gender'])) {
		$tosql = $_POST['gender'];
	} else {
		$tosql = 'deb';
		$_POST['gender'] = $tosql;
	}
	if (isset($_POST['gender2'])) {
		$optmerge = $_POST['gender2'];
		$merge = ($optmerge != '3');
	} else {
		$optmerge = '3';
		$_POST['gender2'] = '3';
		$merge = false;
	}
	if ($tosql == 'deb' || $tosql == '') {
		$debug = true;
		$merge = false;
	} else {
		$debug = false;
	}
	if ($optmerge == '3') $merge = false;

	if (class_exists("SQLite3")) {
		$db = new SQLite3(':memory:');
		$db->exec("CREATE TABLE if not exists 'tmp_list' (item text, title text, epg text, url text, isdel integer null default 120);");
		if ($debug) $db = null;
	} else {
		$db = null;
	}
} else {
	$_POST['gender']  = 'deb';
	$_POST['gender2'] = '2';
}
?>
<h2>请输入M3U/TXT文本或者直播源网址URL列表：</h2>
<form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
	<textarea id="m3uContent" name="m3uContent" rows="10" style="width:1024px;" placeholder="这里输入M3U/TXT文本内容，或者每行一条直播源网址URL" onInput="disablebt(this)"><?php if (isset($_POST['m3uContent'])) echo htmlspecialchars($_POST['m3uContent']); ?></textarea>
	<div style="width:1024px">
		<input type="file" id="_ef" name="_ef" style="visibility:hidden;" onchange="beforeUpload()">
		<button id="openf" type="button" style="float:right" onclick="importFile()">打开文件</button>
	</div>
	<b>输出：</b>
	<input type="radio" name="gender" value="deb" <?php if ($_POST['gender'] == 'deb') echo 'checked'; ?> onchange="disableRadios(this)"> 调试模式
	<input type="radio" name="gender" value="sql" <?php if ($_POST['gender'] == 'sql') echo 'checked'; ?> onchange="disableRadios(this)"> 生成SQL
	<input type="radio" name="gender" value="txt" <?php if ($_POST['gender'] == 'txt') echo 'checked'; ?> onchange="disableRadios(this)"> 生成TXT
	<input type="radio" name="gender" value="m3u" <?php if ($_POST['gender'] == 'm3u') echo 'checked'; ?> onchange="disableRadios(this)"> 生成M3U
	<br>
	<b style="line-height:3;">清洗：</b>
	<input type="radio" name="gender2" value="1" <?php if ($_POST['gender2'] == '1') echo 'checked'; ?> <?php if ($_POST['gender'] == 'deb') echo 'disabled'; ?>> 去重
	<input type="radio" name="gender2" value="2" <?php if ($_POST['gender2'] == '2') echo 'checked'; ?> <?php if ($_POST['gender'] == 'deb') echo 'disabled'; ?>> 合并
	<input type="radio" name="gender2" value="3" <?php if ($_POST['gender2'] == '3') echo 'checked'; ?> <?php if ($_POST['gender'] == 'deb') echo 'disabled'; ?>> 不处理
	<br>
	<input type="submit" id="button1" name="button1" value="转换M3U/TXT文本" disabled>
	-
	<input type="submit" id="button2" name="button2" value="解析M3U/TXT网址" disabled>
</form>

<?php
$start = microtime(true);

// ==================== 工具函数 ====================
function maxstr($string) {
	$string = trim($string);
	if (strlen($string) === 0) return '';
	$array = array_filter(explode("#", $string));
	$frequency = array_count_values($array);
	arsort($frequency);
	return key($frequency);
}

function formatm3u($string) {
	preg_match_all('/(?P<tag>#EXTINF:-1)|(?:(?P<prop_key>[-a-z]+)=\"(?P<prop_val>[^"]+)")|(?<something>,[^\n]+)|(?<url>http[^\s]+)/', $string, $match);
	$count = count($match[0]);
	$result = [];
	$index = -1;
	for ($i = 0; $i < $count; $i++) {
		if (!empty($match['tag'][$i])) {
			++$index;
		} elseif (!empty($match['prop_key'][$i])) {
			$result[$index][$match['prop_key'][$i]] = $match['prop_val'][$i];
		} elseif (!empty($match['something'][$i])) {
			$result[$index]['something'] = $match[0][$i];
		} elseif (!empty($match['url'][$i])) {
			$result[$index]['url'] = $match[0][$i];
		}
	}
	return $result;
}

function formatTitle($title, $t = 1) {
	$text = strtoupper($title);
	if ($t == 1) {
		if (preg_match("/CCTV/", $text)) {
			preg_match_all("/^[A-Za-z0-9-+]+/", $text, $matches);
			$text = isset($matches[0][0]) ? $matches[0][0] : $text;
		}
		if (strpos($text, "[") !== false) {
			$text = substr($text, 0, strpos($text, "["));
		}
		$text = str_replace('-', '', $text);
		if (strlen($text) == 0) $text = strtoupper($title);
	} else {
		if (strpos($text, "[") !== false) {
			$text = substr($text, 0, strpos($text, "["));
		}
	}
	return $text;
}

function formatUrls($urlstr) {
	$urls = array_filter(explode("#", $urlstr));
	return implode("#", array_unique($urls));
}

function formattxt($string) {
	$lines = explode("\n", $string);
	$currentItem = '';
	$result = [];
	foreach ($lines as $line) {
		$line = trim($line);
		if (empty($line)) continue;
		if (strpos($line, '#genre#') !== false) {
			list($currentItem) = explode(',', $line);
			$currentItem = trim(str_replace('#genre#', '', $currentItem));
		} else {
			if ($currentItem === '') $currentItem = '直播频道';
			$parts = explode(',', $line, 2);
			if (count($parts) < 2) continue;
			list($title, $url) = $parts;
			$url = trim($url);
			if (empty($url) || strtoupper(substr($url, 0, 4)) != 'HTTP') continue;
			$result[] = [
				'group-title' => $currentItem,
				'tvg-name'    => '',
				'something'   => ',' . trim($title),
				'url'         => $url
			];
		}
	}
	return $result;
}

function get_rand_useragent() {
	$arr = [
		'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/536.11 (KHTML, like Gecko) Chrome/20.0.1132.11 TaoBrowser/2.0 Safari/536.11',
		'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.71 Safari/537.1 LBBROWSER',
		'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.11 (KHTML, like Gecko) Chrome/17.0.963.84 Safari/535.11 LBBROWSER',
		'Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.89 Safari/537.1',
		'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.89 Safari/537.1',
		'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; QQDownload 732; .NET4.0C; .NET4.0E)',
		'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; WOW64; Trident/5.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729; Media Center PC 6.0; .NET4.0C; .NET4.0E)',
		'Mozilla/5.0 (Windows NT 5.1) AppleWebKit/535.11 (KHTML, like Gecko) Chrome/17.0.963.84 Safari/535.11 SE 2.X MetaSr 1.0',
		'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; Trident/4.0; SV1; QQDownload 732; .NET4.0C; .NET4.0E; SE 2.X MetaSr 1.0)',
		'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:16.0) Gecko/20121026 Firefox/16.0',
		'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:2.0b13pre) Gecko/20110307 Firefox/4.0b13pre',
		'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:16.0) Gecko/20100101 Firefox/16.0',
		'Mozilla/5.0 (Windows; U; Windows NT 6.1; zh-CN; rv:1.9.2.15) Gecko/20110303 Firefox/3.6.15',
		'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.64 Safari/537.11',
		'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.16 (KHTML, like Gecko) Chrome/10.0.648.133 Safari/534.16',
		'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.221 Safari/537.36 SE 2.X MetaSr 1.0'
	];
	return $arr[array_rand($arr)];
}

function FormatHeader($url, $useragent) {
	$temp = parse_url($url);
	$query = isset($temp['query']) ? $temp['query'] : '';
	$path  = isset($temp['path']) ? $temp['path'] : '/';
	return [
		"POST {$path}?{$query} HTTP/1.1",
		"Host: {$temp['host']}",
		"Referer: http://{$temp['host']}/",
		"Content-Type: text/xml; charset=utf-8",
		'Accept: application/json, text/javascript, */*; q=0.01',
		'Accept-Encoding: gzip, deflate, br',
		'Accept-Language: zh-CN,zh;q=0.8,zh-TW;q=0.7,zh-HK;q=0.5,en-US;q=0.3,en;q=0.2',
		'Connection: keep-alive',
		'X-Requested-With: XMLHttpRequest',
		'User-Agent: ' . $useragent,
	];
}

function getContent($url) {
	$useragent = get_rand_useragent();
	$header = FormatHeader($url, $useragent);
	$process = curl_init($url);
	curl_setopt($process, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($process, CURLOPT_HTTPHEADER, $header);
	curl_setopt($process, CURLOPT_HEADER, 0);
	curl_setopt($process, CURLOPT_CONNECTTIMEOUT, 5);
	curl_setopt($process, CURLOPT_TIMEOUT, 45);
	curl_setopt($process, CURLOPT_MAXREDIRS, 10);
	if (strtoupper(substr($url, 0, 5)) == 'HTTPS') {
		curl_setopt($process, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($process, CURLOPT_SSL_VERIFYHOST, 0);
	}
	$isgz = false;
	if (strtoupper(substr($url, -3)) == '.GZ') {
		curl_setopt($process, CURLOPT_ENCODING, 'gzip');
		$isgz = true;
	}
	curl_setopt($process, CURLOPT_USERAGENT, $useragent);
	$data = curl_exec($process);
	if (curl_errno($process)) {
		echo curl_errno($process) . curl_error($process) . "<br>";
	}
	curl_close($process);
	if ($isgz) {
		$data = @gzdecode($data);
	}
	return $data;
}

// ==================== 处理提交 ====================
if (isset($_POST['button1'])) {
	echo "<script>bt1();</script>";
	echo "<h3>转换文本内容如下：</h3>";
	if ($debug) echo "频道分组|频道名称|EPG名称|直播源网址<br>";
	$data = trim($_POST['m3uContent']);
	$result = formatm3u($data);
	if (count($result) <= 1) {
		$result = formattxt($data);
	}
	if ($debug) echo "-- fetch data from Content<br>";
	$currentItem = '';
	foreach ($result as $tv) {
		if (count($tv) === 1) {
			if ($debug) echo (isset($tv['x-tvg-url']) ? $tv['x-tvg-url'] : '') . "<br>";
		} elseif (count($tv) >= 3) {
			$urls = explode('#', $tv['url']);
			foreach ($urls as $url) {
				if (isset($db) && $merge) {
					$db->exec("INSERT OR REPLACE INTO tmp_list VALUES ('" . SQLite3::escapeString($tv['group-title']) . "','" . SQLite3::escapeString(trim(substr($tv['something'], 1))) . "','" . SQLite3::escapeString($tv['tvg-name']) . "','" . SQLite3::escapeString($url) . "','" . $isdelCounter . "');");
				} else {
					if ($debug) {
						echo $tv['group-title'] . "|" . substr($tv['something'], 1) . "|" . $tv['tvg-name'] . "|" . $url . "|" . $isdelCounter . "<br>";
					} elseif ($tosql == 'sql') {
						echo "INSERT or replace INTO `list` VALUES ('" . $tv['group-title'] . "','" . trim(substr($tv['something'], 1)) . "','" . $tv['tvg-name'] . "','" . $url . "','" . $isdelCounter . "');<br>";
					} elseif ($tosql == 'txt') {
						if ($currentItem != $tv['group-title']) {
							$currentItem = $tv['group-title'];
							echo $tv['group-title'] . ",#genre#<br>";
						}
						echo trim(substr($tv['something'], 1)) . "," . $url . "<br>";
					} elseif ($tosql == 'm3u') {
						if ($isdelCounter === 1) {
							echo "#EXTM3U x-tvg-url=\"http://127.0.0.1/diyp/epg.php\"<br>";
						}
						if (empty($tv['tvg-name'])) {
							$tv['tvg-name'] = formatTitle(trim(substr($tv['something'], 1)));
						}
						echo '#EXTINF:-1 tvg-id="" tvg-logo="" tvg-name="' . $tv['tvg-name'] . '" group-title="' . $tv['group-title'] . '",' . trim(substr($tv['something'], 1)) . "<br>";
						echo $url . "<br>";
					}
				}
				$isdelCounter++;
			}
		} else {
			print_r($tv);
		}
	}
} elseif (isset($_POST['button2'])) {
	echo "<script>bt2();</script>";
	echo "<h3>解析网址内容如下：</h3>";
	if ($debug) echo "频道分组|频道名称|EPG名称|直播源网址<br>";
	$dataSources = explode("\n", $_POST['m3uContent']);
	foreach ($dataSources as $dataSource) {
		$dataSource = trim(str_replace("\r", "", $dataSource));
		if (empty($dataSource)) continue;
		$data = getContent($dataSource);
		if ($data === false || empty($data)) {
			echo "Error: Unable to fetch data from $dataSource<br>";
			continue;
		}
		$result = formatm3u($data);
		if (count($result) <= 1) {
			$result = formattxt($data);
		}
		if ($debug) echo "-- fetch data from $dataSource<br>";
		$currentItem = '';
		foreach ($result as $tv) {
			if (count($tv) === 1) {
				if ($debug) echo (isset($tv['x-tvg-url']) ? $tv['x-tvg-url'] : '') . "<br>";
			} elseif (count($tv) >= 3) {
				$urls = explode('#', $tv['url']);
				foreach ($urls as $url) {
					if (isset($db) && $merge) {
						$db->exec("INSERT OR REPLACE INTO tmp_list VALUES ('" . SQLite3::escapeString($tv['group-title']) . "','" . SQLite3::escapeString(trim(substr($tv['something'], 1))) . "','" . SQLite3::escapeString($tv['tvg-name']) . "','" . SQLite3::escapeString($url) . "','" . $isdelCounter . "');");
					} else {
						if ($debug) {
							echo $tv['group-title'] . "|" . substr($tv['something'], 1) . "|" . $tv['tvg-name'] . "|" . $url . "|" . $isdelCounter . "<br>";
						} elseif ($tosql == 'sql') {
							echo "INSERT or replace INTO `list` VALUES ('" . $tv['group-title'] . "','" . trim(substr($tv['something'], 1)) . "','" . $tv['tvg-name'] . "','" . $url . "','" . $isdelCounter . "');<br>";
						} elseif ($tosql == 'txt') {
							if ($currentItem != $tv['group-title']) {
								$currentItem = $tv['group-title'];
								echo $tv['group-title'] . ",#genre#<br>";
							}
							echo trim(substr($tv['something'], 1)) . "," . $url . "<br>";
						} elseif ($tosql == 'm3u') {
							if ($isdelCounter === 1) {
								echo "#EXTM3U x-tvg-url=\"http://127.0.0.1/diyp/epg.php\"<br>";
							}
							echo '#EXTINF:-1 tvg-id="" tvg-logo="" tvg-name="' . $tv['tvg-name'] . '" group-title="' . $tv['group-title'] . '",' . trim(substr($tv['something'], 1)) . "<br>";
							echo $url . "<br>";
						}
					}
					$isdelCounter++;
				}
			} else {
				print_r($tv);
			}
		}
	}
}

// ==================== 合并输出部分 ====================
try {
	if (isset($db) && $db) {
		$count = $db->querySingle("SELECT count(*) FROM tmp_list");
		if ($debug) {
			echo " -- " . $count;
		} elseif ($merge) {
			$config = [];
			$result = $db->query("SELECT * FROM tmp_list WHERE isdel > 0 ORDER BY isdel");
			while ($row = $result->fetchArray()) {
				$config[] = [
					'item'  => $row[0],
					'title' => trim($row[1]),
					'epg'   => maxstr($row[2]),
					'url'   => trim($row[3]),
					'isdel' => $row[4]
				];
			}

			// 按分组归类
			$groupconfig = [];
			foreach ($config as $item) {
				$groupconfig[$item['item']][] = $item;
			}

			// 合并同名频道
			$config = [];
			foreach ($groupconfig as $item => $titles) {
				$channels = [];
				foreach ($titles as $t) {
					$title = $t['title'];
					if (!isset($channels[$title])) {
						$channels[$title] = ['title' => $title, 'url' => [], 'epg' => [], 'isdel' => []];
					}
					$channels[$title]['url'][]  = $t['url'];
					$channels[$title]['epg'][]  = $t['epg'];
					$channels[$title]['isdel'][] = $t['isdel'];
				}
				$config[$item] = $channels;
			}
			$groupconfig = $config;

			function gentxt($groupconfig, $optmerge) {
				$out = [];
				foreach ($groupconfig as $item => $titles) {
					$out[] = $item . ",#genre#";
					foreach ($titles as $v) {
						$epg   = maxstr(implode('#', $v['epg']));
						$epg   = $epg === '' ? formatTitle($v['title']) : $epg;
						$title = formatTitle($v['title'], 2);
						$url   = formatUrls(implode('#', $v['url']));
						if ($optmerge === '2') {
							$out[] = $title . ',' . $url;
						} else {
							foreach (explode("#", $url) as $u) {
								$out[] = $title . ',' . $u;
							}
						}
					}
				}
				return $out;
			}

			function genm3u($groupconfig, $optmerge) {
				$out = ['#EXTM3U x-tvg-url="http://127.0.0.1/diyp/epg.php"'];
				foreach ($groupconfig as $item => $titles) {
					foreach ($titles as $v) {
						$epg   = maxstr(implode('#', $v['epg']));
						$epg   = $epg === '' ? formatTitle($v['title']) : $epg;
						$title = formatTitle($v['title'], 2);
						$url   = formatUrls(implode('#', $v['url']));
						if ($optmerge === '2') {
							$out[] = sprintf('#EXTINF:-1 tvg-id="" tvg-name="%s" tvg-logo="" group-title="%s",%s', $epg, $item, $title);
							$out[] = $url;
						} else {
							foreach (explode("#", $url) as $u) {
								$out[] = sprintf('#EXTINF:-1 tvg-id="" tvg-name="%s" tvg-logo="" group-title="%s",%s', $epg, $item, $title);
								$out[] = $u;
							}
						}
					}
				}
				return $out;
			}

			function gensql($groupconfig, $optmerge) {
				$out = [];
				$isdelCounter = 120;
				foreach ($groupconfig as $item => $titles) {
					foreach ($titles as $v) {
						$epg   = maxstr(implode('#', $v['epg']));
						$epg   = $epg === '' ? formatTitle($v['title']) : $epg;
						$title = formatTitle($v['title'], 2);
						$url   = formatUrls(implode('#', $v['url']));
						if ($optmerge === '2') {
							$out[] = "INSERT or replace INTO `list` VALUES ('$item','$title','$epg','$url','$isdelCounter');";
						} else {
							foreach (explode("#", $url) as $u) {
								$out[] = "INSERT or replace INTO `list` VALUES ('$item','$title','$epg','$u','$isdelCounter');";
							}
						}
						$isdelCounter += 2;
					}
				}
				return $out;
			}

			if ($tosql == 'sql') {
				$output = gensql($groupconfig, $optmerge);
			} elseif ($tosql == 'txt') {
				$output = gentxt($groupconfig, $optmerge);
			} elseif ($tosql == 'm3u') {
				$output = genm3u($groupconfig, $optmerge);
			} else {
				$output = [];
			}

			if (count($output) > 0) {
				echo '<div style="width:1024px">';
				echo '<textarea id="outContent" rows="12" style="width:1024px">' . htmlspecialchars(implode(PHP_EOL, $output)) . '</textarea>';
				echo '<button type="button" style="float:right" onclick="saveFile()">保存文件</button>';
				echo '<input type="hidden" id="savetype" value="' . htmlspecialchars($tosql) . '">';
				echo '</div>';
			}
		}
	}
} catch (Exception $e) {
	echo "Error: " . $e->getMessage();
}

if ($debug) {
	echo ' * total cost ' . number_format(microtime(true) - $start, 4) . ' s.';
}
?>

<br><br>m3u输入样例<br>
<textarea rows="5" style="width:1024px" class="tex">
#EXTM3U x-tvg-url="http://127.0.0.1/diyp/epg.php"
#EXTINF:-1 tvg-id="" tvg-name="" tvg-logo="" group-title="广东频道",广州综合
http://nas.jdshipin.com:8801/gztv.php?id=zhonghe
#EXTINF:-1 tvg-id="" tvg-name="" tvg-logo="" group-title="广东频道",广州新闻
http://nas.jdshipin.com:8801/gztv.php?id=xinwen#http://113.100.193.10:9901/tsfile/live/1000_1.m3u8
#EXTINF:-1 tvg-id="" tvg-name="" tvg-logo="" group-title="直播频道",CCTV2
http://dbiptv.sn.chinamobile.com/3221226195/index.m3u8?0.smil#http://dbiptv2.sn.chinamobile.com/3221226195/index.m3u8?0.smil
</textarea>

<br><br>txt输入样例<br>
<textarea rows="5" style="width:1024px" class="tex">
广东频道,#genre#
广州综合,http://nas.jdshipin.com:8801/gztv.php?id=zhonghe
广州新闻,http://nas.jdshipin.com:8801/gztv.php?id=xinwen#http://113.100.193.10:9901/tsfile/live/1000_1.m3u8
直播频道,#genre#
CCTV2,http://dbiptv.sn.chinamobile.com/3221226195/index.m3u8?0.smil#http://dbiptv2.sn.chinamobile.com/3221226195/index.m3u8?0.smil
</textarea>

<br><br>URL输入样例<br>
<textarea rows="3" style="width:1024px" class="tex">
http://127.0.0.1/diyp/live.php?t=m3u
http://127.0.0.1/diyp/live.php?t=txt
</textarea>
</body>
</html>
