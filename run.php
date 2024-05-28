<?php

include(__DIR__ . '/init.inc.php');
$job_id = $argv[1];
$job_file = JobHelper::getJobFile($job_id);
$job = json_decode(file_get_contents($job_file));

$job->data->tool = $job->data->tool ?? 'whisperx';

if ($job->data->tool == 'whisperx') {
    $file = JobHelper::getWavFromURL($job->data->url);
    echo $file;
}
