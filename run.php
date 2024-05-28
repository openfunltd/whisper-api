<?php

include(__DIR__ . '/init.inc.php');
$job_id = $argv[1];
$logger = function($msg) use ($job_id) {
    JobHelper::log($job_id, $msg);
};
$job_file = JobHelper::getJobFile($job_id);
$job = json_decode(file_get_contents($job_file));

$job->data->tool = $job->data->tool ?? 'whisperx';

if ($job->data->tool == 'whisperx') {
    $file = JobHelper::getWavFromURL($job->data->url, $logger);
    echo $file;
}
