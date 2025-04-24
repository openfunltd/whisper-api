<?php

// timezone to Asia/Taipei
date_default_timezone_set('Asia/Taipei');
ini_set('post_max_size', '1024M');
ini_set('upload_max_filesize', '1024M');

if (file_exists(__DIR__ . '/config.php')) {
    include(__DIR__ . '/config.php');
}
include(__DIR__ . '/WebDispatcher.php');
include(__DIR__ . '/JobHelper.php');
