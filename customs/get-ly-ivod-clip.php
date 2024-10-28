<?php

$video_id = $_SERVER['argv'][1];

$get_m3u8_by_id = function($video_id) {
    $url = sprintf("https://ivod.ly.gov.tw/Play/Clip/1M/%d", $video_id);
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    // ipv4
    curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    $content = curl_exec($curl);
    if (!preg_match('#readyPlayer\("([^"]*)#', $content, $matches)) {
        throw new Exception("readyPlayer not found");
    }
    curl_close($curl);
    $url = $matches[1];
    if (strpos($url, 'playlist.m3u8') === false) {
        throw new Exception("Invalid URL");
    }
    $url = str_replace('playlist.m3u8', 'chunklist.m3u8', $url);
    return $url;
};
if (!$video_id) {
    throw new Exception("Invalid video_id");
}
$url = $get_m3u8_by_id($video_id);

$output = $_SERVER['argv'][2];
if (!$output) {
    $output = "output.mp4";
}
$cache_dir = $_SERVER['argv'][3];

if (!$cache_dir) {
    $cache_dir = __DIR__;
} else {
    $cache_dir = rtrim($cache_dir, '/');
}
if (!file_exists($cache_dir)) {
    mkdir($cache_dir, 0777, true);
}

// get all ivod-lyvod.cdn.hinet.net IP
$ips = gethostbynamel('ivod-lyvod.cdn.hinet.net');
$content = file_get_contents($url);
if (!$content) {
    throw new Exception("Failed to get {$url}");
}
$lines = explode("\n", trim($content));
$total = count($lines);
$seq = 0;
file_put_contents($cache_dir . '/mylist.txt', '');
$files = [];
foreach ($lines as $key => $line) {
    if (strpos($line, '#') === 0) {
        continue;
    }
    for ($retry = 0; $retry < 3; $retry ++) {
        $ip = $ips[$seq % count($ips)];
        $seq++;

        $filename = trim($line);
        $file_url = str_replace('chunklist.m3u8', $filename, $url);

        $args = '--max-time 3 --connect-timeout 3 --retry 0';
        $cmd = sprintf('curl %s --resolve ivod-lyvod.cdn.hinet.net:443:%s -o %s %s', $args, $ip, escapeshellarg($cache_dir . '/' . $filename), escapeshellarg($file_url));
        error_log("{$key}/{$total}: {$cmd}");
        system($cmd, $ret);
        if ($ret == 0) {
            file_put_contents($cache_dir . '/mylist.txt', "file '$filename'\n", FILE_APPEND);
            $files[] = $filename;
            //sleep(1);
            break;
        }
        sleep(3);

        $url = $get_m3u8_by_id($video_id);
    }
    if ($retry >= 3) {
        throw new Exception("Failed to download {$file_url}");
    }
}
$cmd = sprintf("ffmpeg -f concat -safe 0 -i %s -c copy -acodec pcm_s16le -ar 16000 %s",
    escapeshellarg($cache_dir . '/mylist.txt'),
    escapeshellarg($output)
);
system($cmd, $ret);
if ($ret != 0) {
    throw new Exception("Failed to concat files");
}

foreach ($files as $file) {
    unlink($cache_dir . "/{$file}");
}
unlink($cache_dir . "/mylist.txt");
