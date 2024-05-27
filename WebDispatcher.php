<?php

class WebDispatcher
{
    public static function queueAdd()
    {
        $url = $_GET['url'] ?? false;
        if (!$url) {
            return self::error('url is required');
        }

        $init_prompt = $_GET['init_prompt'] ?? '';
        $tool = $_GET['tool'] ?? 'whisperx';
        $diarize = intval($_GET['diarize'] ?? 0);
        $type = $_GET['type'] ?? 'auto';
        $key = $_GET['key'] ?? '';

        $name = self::checkKey($key);
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
