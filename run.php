<?php

include(__DIR__ . '/init.inc.php');
$job_id = $argv[1];
$logger = function($msg) use ($job_id) {
    JobHelper::log($job_id, $msg);
};
$job_file = JobHelper::getJobFile($job_id);
$job = json_decode(file_get_contents($job_file));
if ($job->data->status == 'done') {
    exit;
}

$job->data->tool = $job->data->tool ?? 'whisperx';

if ($job->data->tool == 'whisperx') {
    $file = JobHelper::getWavFromURL($job->data->url, $logger);
    $params = '';
    if ($job->data->diarize) {
        $params .= ' --diarize';
    }
    if ($job->data->language ?? false) {
        $params .= ' --language ' . escapeshellarg($job->data->language);
    }
    if ($job->data->model ?? false) {
        $params .= ' --model ' . escapeshellarg($job->data->model);
    } else {
        $params .= ' --model large-v3';
    }
    if ($job->data->init_prompt ?? false) {
        $params .= ' --init_prompt ' . escapeshellarg($job->data->init_prompt);
    }

    $output_dir = getenv('data_dir') . '/tmp/' . WebDispatcher::uniqid(12);
    mkdir($output_dir, 0777, true);
    $logger("running whisperx with $params");
    $cmd = sprintf("whisperx --output_dir %s %s %s", escapeshellarg($output_dir), $params, escapeshellarg($file));

    system("conda run -n whisperx $cmd", $ret);

    $logger("merging result");
    $result = new StdClass;
    foreach (glob("$output_dir/*.*") as $output_file) {
        $ext = pathinfo($output_file, PATHINFO_EXTENSION);
        $result->$ext = file_get_contents($output_file);
    }
    JobHelper::updateData($job_id, 'result', $result);
    JobHelper::updateStatus($job_id, 'done');
}
