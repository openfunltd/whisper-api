<?php

class WebDispatcher
{
    public static function uniqid($length)
    {
        $ret = '';
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for ($i = 0; $i < $length; $i++) {
            $ret .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $ret;
    }

    public static function enqueue($data)
    {
        if (!getenv('data_dir')) {
            return self::error('data_dir not set');
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

        $job_id = self::uniqid(16);

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

    public static function queueAdd()
    {
        $data = [];
        $data['url'] = $_GET['url'] ?? false;
        if (!$data['url']) {
            return self::error('url is required');
        }
        $data['init_prompt'] = $_GET['init_prompt'] ?? '';
        $data['tool'] = $_GET['tool'] ?? 'whisperx';
        $data['diarize'] = intval($_GET['diarize'] ?? 0);
        $data['type'] = $_GET['type'] ?? 'auto';

        $key = $_GET['key'] ?? '';
        $name = self::checkKey($key);
        $data['name'] = $name;
        $data['callback'] = $_GET['callback'] ?? '';

        $job_id = self::enqueue($data);
        return self::json([
            'status' => 'ok',
            'job_id' => $job_id,
        ]);
    }

    public static function checkKey($check_key)
    {
        if (!getenv('keys')) {
            return self::error('keys not set');
        }
        $keys = explode(';', getenv('keys'));
        foreach ($keys as $k) {
            list($name, $key) = explode('=', $k, 2);
            if ($key == $check_key) {
                return $name;
            }
        }

        return self::error('key not found');
    }

    public static function error($message)
    {
        self::json([
            'status' => 'error',
            'error' => $message,
        ]);
    }

    public static function json($ret)
    {
        header('Content-Type: application/json');
        echo json_encode($ret);
        exit;
    }

    public static function dispatch()
    {
        list($uri) = explode('?', $_SERVER['REQUEST_URI'], 2);
        if ($uri == '/queue/add') {
            return self::queueAdd();
        } elseif ($uri == '/queue') {
            echo 'TODO';
        } elseif ($uri == '/queue/result') {
            echo 'TODO';
        } else {
            echo '404';
            exit;
        }
    }
}
