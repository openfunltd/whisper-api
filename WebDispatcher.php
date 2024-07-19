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

    public static function queueResult()
    {
        $job_id = $_GET['job_id'] ?? false;
        if (!$job_id) {
            return self::error('job_id is required');
        }
        $key = $_GET['key'] ?? '';
        $name = self::checkKey($key);

        $job_file = JobHelper::getJobFile($job_id);
        if (!file_exists($job_file)) {
            return self::error('job not found');
        }

        $job = json_decode(file_get_contents($job_file));
        if ($job->name != $name) {
            return self::error('key not match');
        }
        return self::json([
            'status' => 'ok',
            'job' => $job,
        ]);
    }

    public static function queueAdd()
    {
        $data = [];
        $required = [
            'file_upload' => ['file'],
            'whisper.cpp' => ['url'],
            'whisperx' => ['url'],
            'pyannote' => ['url'],
            'clean' => ['url'],
        ];

        $key = $_REQUEST['key'] ?? '';
        $name = self::checkKey($key);
        unset($_REQUEST['key']);
        $data['name'] = $name;

        $tool = $_GET['tool'] ?? false;
        $data['tool'] = $tool;
        if (!array_key_exists($tool, $required)) {
            return self::error("tool '{$tool}' not found");
        }
        if ($tool == 'file_upload') {
            if (!isset($_FILES['file'])) {
                return self::error('file is required');
            }
            $data['file'] = $_FILES['file'];
            $fileext = pathinfo($data['file']['name'], PATHINFO_EXTENSION);
            $tmpfile = self::uniqid(12) . '.' . $fileext;
            move_uploaded_file($data['file']['tmp_name'], getenv('data_dir') . '/tmp/' . $tmpfile);
            return self::json([
                'status' => 'ok',
                'file_url' => 'file://' . $tmpfile, 
            ]);
        }

        foreach ($required[$tool] as $k) {
            if (!isset($_REQUEST[$k])) {
                return self::error("{$k} is required");
            }
        }
        $data['data'] = $_REQUEST;

        try {
            $job_id = JobHelper::enqueue($data);
        } catch (Exception $e) {
            return self::error($e->getMessage());
        }
        return self::json([
            'status' => 'ok',
            'job_id' => $job_id,
            'api_url' => sprintf("https://%s/queue/result?job_id=%s&key=%s", $_SERVER['HTTP_HOST'], $job_id, $key),
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
        // XSRF
        header('Access-Control-Allow-Origin: *');
        // allow post
        header('Access-Control-Allow-Methods: POST');

        echo json_encode($ret, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
            return self::queueResult();
        } else {
            echo '404';
            exit;
        }
    }

}
