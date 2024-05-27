<?php

class WebDispatcher
{
    public static function dispatch()
    {
        list($uri) = explode('?', $_SERVER['REQUEST_URI'], 2);
        if ($uri == '/queue/add') {
            echo 'TODO';
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
