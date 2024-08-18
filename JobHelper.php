<?php

class JobHelper
{
    public static function worker()
    {
        while (true) {
            $job = self::dequeue();
            if (is_null($job)) {
                usleep(1000);
                continue;
            }

            $job_id = $job->job_id;
            error_log("job_id: $job_id");
            system(sprintf("php %s/run.php %s", __DIR__, $job_id));
        }
    }

    public static function getJobFile($job_id)
    {
        if (!getenv('data_dir')) {
            throw new Exception("data_dir not set");
        }
        $data_dir = getenv('data_dir');
        return $data_dir . "/job/{$job_id}.json";
    }

    public static function videoToWav($mp4_file, $logger, $output_file)
    {
        $data_dir = getenv('data_dir');
        $tmp_wav_file = tempnam("{$data_dir}/tmp", 'download_');
        unlink($tmp_wav_file);
        $tmp_wav_file .= '.wav';
        $logger("converting mp4 to wav", $tmp_wav_file);
        system(sprintf("ffmpeg -i %s -acodec pcm_s16le -ar 16000 %s", escapeshellarg($mp4_file), escapeshellarg($tmp_wav_file)), $ret);
        if ($ret != 0) {
            unlink($mp4_file);
            unlink($tmp_wav_file);
            throw new Exception("ffmpeg failed");
        }
        unlink($mp4_file);
        rename($tmp_wav_file, $output_file);
        return $output_file;
    }

    public static function getCacheNameFromURL($url)
    {
        if (strpos($url, 'file://') === 0) {
            return substr($url, 7);
        }
        if (strpos($url, 'https://storage.googleapis.com/') === 0) {
            $url = preg_replace('#\?(.*)$#', $url, $matches);
        }
        $hostname = parse_url($url, PHP_URL_HOST);
        $crc32 = hash('crc32b', $url);
        return "{$hostname}_{$crc32}";
    }

    public static function cleanCacheFromURL($url)
    {
        if (!getenv('data_dir')) {
            throw new Exception("data_dir not set");
        }
        $data_dir = getenv('data_dir');
        if (!file_exists($data_dir)) {
            mkdir($data_dir, 0777, true);
        } 
        if (!file_exists("{$data_dir}/tmp")) {
            mkdir("{$data_dir}/tmp", 0777, true);
        }
        $filename = self::getCacheNameFromURL($url);
        $output_file = "{$data_dir}/tmp/{$filename}.wav";
        if (file_exists($output_file)) {
            unlink($output_file);
            return true;
        }
        if (strpos($url, 'file://') === 0) {
            $input_file = "{$data_dir}/tmp/" . substr($url, 7);
            if (file_exists($input_file)) {
                unlink($input_file);
                return true;
            }
        }
        return false;
    }

    public static function getWavFromURL($url, $logger)
    {
        if (!getenv('data_dir')) {
            throw new Exception("data_dir not set");
        }
        $data_dir = getenv('data_dir');
        if (!file_exists($data_dir)) {
            mkdir($data_dir, 0777, true);
        } 
        if (!file_exists("{$data_dir}/tmp")) {
            mkdir("{$data_dir}/tmp", 0777, true);
        }
        $filename = self::getCacheNameFromURL($url);
        $output_file = "{$data_dir}/tmp/{$filename}.wav";
        if (file_exists($output_file)) {
            $logger("$url already downloaded", $output_file);
            return $output_file;
        }

        if (strpos($url, 'file://') === 0) { 
            $input_file = "{$data_dir}/tmp/" . substr($url, 7);
            return self::videoToWav($input_file, $logger, $output_file);
        } else if (strpos($url, '.m3u8') !== false) {
            $tmp_mp4_file = tempnam("{$data_dir}/tmp", 'download_');
            unlink($tmp_mp4_file);
            $tmp_mp4_file .= '.mp4';
            $logger("downloading $url", $tmp_mp4_file);
            $url = trim($url);
            system(sprintf("yt-dlp -o %s %s", escapeshellarg($tmp_mp4_file), escapeshellarg($url)), $ret);
            if ($ret != 0) {
                unlink($tmp_mp4_file);

                system(sprintf("yt-dlp --legacy-server-connect -o %s %s", escapeshellarg($tmp_mp4_file), escapeshellarg($url)), $ret);
                if ($ret != 0) {
                    unlink($tmp_mp4_file);
                    throw new Exception("yt-dlp failed");
                }
            }
            return self::videoToWav($tmp_mp4_file, $logger, $output_file);
        } else if (strpos($url, 'https://ivod.ly.gov.tw/') === 0) {
            $logger("downloading $url");
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            // ipv4
            curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            $content = curl_exec($curl);
            if (!preg_match('#readyPlayer\("([^"]*)#', $content, $matches)) {
                throw new Exception("readyPlayer not found");
            }
            $tmp_mp4_file = tempnam("{$data_dir}/tmp", 'download_');
            unlink($tmp_mp4_file);
            $tmp_mp4_file .= '.mp4';
            $logger("downloading $matches[1]", $tmp_mp4_file);
            system(sprintf("yt-dlp -o %s %s", escapeshellarg($tmp_mp4_file), escapeshellarg($matches[1])), $ret);
            if ($ret != 0) {
                unlink($tmp_mp4_file);

                system(sprintf("yt-dlp --legacy-server-connect -o %s %s", escapeshellarg($tmp_mp4_file), escapeshellarg($matches[1])), $ret);
                if ($ret != 0) {
                    unlink($tmp_mp4_file);
                    throw new Exception("yt-dlp failed");
                }
            }
            return self::videoToWav($tmp_mp4_file, $logger, $output_file);
        } elseif (strpos($url, 'https://storage.googleapis.com/') === 0) {
            $tmp_mp4_file = tempnam("{$data_dir}/tmp", 'download_');
            unlink($tmp_mp4_file);
            $tmp_mp4_file .= '.mp4';
            $logger("downloading $url", $tmp_mp4_file);

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            // output to $tmp_mp4_file
            curl_setopt($curl, CURLOPT_FILE, fopen($tmp_mp4_file, 'w'));
            curl_exec($curl);
            $info = curl_getinfo($curl);
            curl_close($curl);
            if ($info['http_code'] != 200) {
                unlink($tmp_mp4_file);
                throw new Exception("download failed");
            }
            return self::videoToWav($tmp_mp4_file, $logger, $output_file);
        } else {
        }
    }

    public static function dequeue()
    {
        if (!getenv('data_dir')) {
            throw new Exception("data_dir not set");
        }
        clearstatcache();
        $data_dir = getenv('data_dir');

        // 沒有相關資料夾就表示沒有 job
        if (!file_exists($data_dir)) {
            return null;
        }
        if (!file_exists($data_dir . '/queue')) {
            return null;
        }
        if (!file_exists($data_dir . '/job')) {
            return null;
        }
        if (!file_exists($data_dir . '/queue_counter')) {
            return null;
        }

        // queue_counter 的 queue_pos 與檔案不一致，表示有新的 job
        $queue_counter = file_get_contents($data_dir . '/queue_counter');
        list($queue_ymd, $queue_pos) = explode(':', $queue_counter, 2);
        $daily_queue = $data_dir . "/queue/{$queue_ymd}.jsonl";
        if (file_exists($daily_queue) and $queue_pos != filesize($daily_queue)) {
            $fp = fopen("{$data_dir}/queue/{$queue_ymd}.jsonl", 'r');
            fseek($fp, $queue_pos);
            $line = fgets($fp);
            file_put_contents($data_dir . '/queue_counter', "{$queue_ymd}:" . ftell($fp));
            fclose($fp);

            return json_decode($line);
        }

        // 如果 queue_ymd 等於今天日期，表示沒有新的 job
        $today_ymd = date('Ymd');
        if ($queue_ymd == $today_ymd) {
            return null;
        }

        // 到這邊表示一天的日期的資料已經處理完，要換日期
        $new_queue_ymd = date('Ymd', strtotime('+1 day', strtotime($queue_ymd)));
        file_put_contents($data_dir . '/queue_counter', "{$new_queue_ymd}:0");
        return null;
    }

    public static function enqueue($data)
    {
        if (!getenv('data_dir')) {
            throw new Exception('data_dir not set');
        }
        $data_dir = getenv('data_dir');
        if (!file_exists($data_dir)) {
            mkdir($data_dir, 0777, true);
        }
        if (!file_exists($data_dir . '/queue')) {
            mkdir($data_dir . '/queue', 0777, true);
        }
        if (!file_exists($data_dir . '/job')) {
            mkdir($data_dir . '/job', 0777, true);
        }

        $ymd = date('Ymd');

        $job_id = WebDispatcher::uniqid(16);
        $data['job_id'] = $job_id;
        $data['queued_at'] = date('Y-m-d H:i:s');

        file_put_contents($data_dir . "/queue/{$ymd}.jsonl", json_encode($data) . "\n", FILE_APPEND);
        $data['status'] = 'queued';
        file_put_contents($data_dir . "/job/{$job_id}.json", json_encode($data) . "\n", FILE_APPEND);
        if (!file_exists($data_dir . '/queue_counter')) {
            file_put_contents($data_dir . '/queue_counter', "{$ymd}:0");
        }

        return $job_id;
    }

    public static function updateData($job_id, $key, $value)
    {
        $job_file = self::getJobFile($job_id);
        $job = json_decode(file_get_contents($job_file));
        $job->$key = $value;
        file_put_contents($job_file, json_encode($job));
    }

    public static function updateStatus($job_id, $status)
    {
        $job_file = self::getJobFile($job_id);
        $job = json_decode(file_get_contents($job_file));
        $job->status = $status;
        file_put_contents($job_file, json_encode($job));

        self::log($job_id, "status: $status");
    }

    public static function log($job_id, $msg, $target = null)
    {
        $job_file = self::getJobFile($job_id);
        $job = json_decode(file_get_contents($job_file));
        $job->log = $job->log ?? [];
        $job->log[] = [
            'time' => date('Y-m-d H:i:s'),
            'msg' => $msg,
            'target' => $target,
        ];
        file_put_contents($job_file, json_encode($job));
    }


}
