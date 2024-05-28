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

    public static function getWavFromURL($url)
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
        $hostname = parse_url($url, PHP_URL_HOST);
        $crc32 = hash('crc32b', $url);
        $output_file = "{$data_dir}/tmp/{$hostname}_{$crc32}.wav";
        if (file_exists($output_file)) {
            return $output_file;
        }

        if (strpos($url, 'https://ivod.ly.gov.tw/') === 0) {
            error_log("download from $url");
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
            system(sprintf("yt-dlp -o %s %s", escapeshellarg($tmp_mp4_file), escapeshellarg($matches[1])), $ret);
            if ($ret != 0) {
                unlink($tmp_mp4_file);
                throw new Exception("yt-dlp failed");
            }
            $tmp_wav_file = tempnam("{$data_dir}/tmp", 'download_');
            unlink($tmp_wav_file);
            $tmp_wav_file .= '.wav';
            system(sprintf("ffmpeg -i %s -acodec pcm_s16le -ar 16000 %s", escapeshellarg($tmp_mp4_file), escapeshellarg($tmp_wav_file)), $ret);
            if ($ret != 0) {
                unlink($tmp_mp4_file);
                unlink($tmp_wav_file);
                throw new Exception("ffmpeg failed");
            }
            unlink($tmp_mp4_file);
            rename($tmp_wav_file, $output_file);
            return $output_file;
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

        file_put_contents($data_dir . "/queue/{$ymd}.jsonl", json_encode([
            'job_id' => $job_id,
            'data' => $data,
            'queued_at' => time(),
        ]) . "\n", FILE_APPEND);

        file_put_contents($data_dir . "/job/{$job_id}.json", json_encode([
            'job_id' => $job_id,
            'data' => $data,
            'status' => 'queued',
            'queued_at' => time(),
        ]));

        if (!file_exists($data_dir . '/queue_counter')) {
            file_put_contents($data_dir . '/queue_counter', "{$ymd}:0");
        }

        return $job_id;
    }


}
