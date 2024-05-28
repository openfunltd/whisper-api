<?php

// timezone to Asia/Taipei
date_default_timezone_set('Asia/Taipei');

if (file_exists(__DIR__ . '/config.php')) {
    include(__DIR__ . '/config.php');
}
include(__DIR__ . '/WebDispatcher.php');
include(__DIR__ . '/JobHelper.php');
