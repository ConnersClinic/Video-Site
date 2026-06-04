<?php
require_once './assets/init.php';

if (empty($pt->config->transcript_system) || $pt->config->transcript_system != 'on') {
    header('Content-Type: application/json');
    echo json_encode(array('status' => 200, 'message' => 'Transcript system disabled'));
    exit();
}

$db->where('name', 'transcript_cron_last_run')->update(T_CONFIG, array('value' => time()));

$queue_count = !empty($pt->config->transcript_queue_count) ? (int) $pt->config->transcript_queue_count : 1;
if ($queue_count < 1) {
    $queue_count = 1;
}
if ($queue_count > 5) {
    $queue_count = 5;
}

$process_queue = $db->arraybuilder()->where('processing', 0)->orderBy('created_at', 'ASC')->get(T_TRANSCRIPT_QUEUE, $queue_count);

if (empty($process_queue)) {
    header('Content-Type: application/json');
    echo json_encode(array('status' => 200, 'message' => 'No jobs in queue'));
    exit();
}

ob_end_clean();
header('Content-Encoding: none');
header('Connection: close');
ignore_user_abort();
ob_start();
header('Content-Type: application/json');
$response = array('status' => 200, 'message' => 'Processing ' . count($process_queue) . ' job(s)');
echo json_encode($response);
$size = ob_get_length();
header('Content-Length: ' . $size);
ob_end_flush();
flush();
session_write_close();
if (is_callable('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
if (is_callable('litespeed_finish_request')) {
    litespeed_finish_request();
}

if (function_exists('PT_RecordTranscriptLoadSnapshot')) {
    $load_record = PT_RecordTranscriptLoadSnapshot(true);
    if (!empty($load_record['snapshot']) && !empty($load_record['health'])) {
        PT_MaybeSendTranscriptLoadAlert($load_record['snapshot'], $load_record['health']);
    }
}

foreach ($process_queue as $queue_row) {
    $db->where('id', (int) $queue_row['id'])->update(T_TRANSCRIPT_QUEUE, array('processing' => 1));
    try {
        PT_ProcessTranscriptJob($queue_row);
    } catch (Exception $e) {
        PT_TranscriptJobFailed($queue_row, (int) $queue_row['video_id'], $e->getMessage(), 3);
    }
    $stuck = $db->arraybuilder()->where('id', (int) $queue_row['id'])->getOne(T_TRANSCRIPT_QUEUE);
    if (!empty($stuck) && !empty($stuck['processing'])) {
        $db->where('id', (int) $queue_row['id'])->delete(T_TRANSCRIPT_QUEUE);
    }
}
