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

        try {
            $job_id = JobHelper::enqueue($data);
        } catch (Exception $e) {
            return self::error($e->getMessage());
        }
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
