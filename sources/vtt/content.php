<?php
header('Content-Type: text/vtt; charset=utf-8');

$public_id = !empty($_GET['id']) ? PT_Secure($_GET['id']) : '';
if (empty($public_id)) {
    http_response_code(404);
    echo "WEBVTT\n\n";
    exit();
}

$video = $db->where('video_id', $public_id)->getOne(T_VIDEOS);
if (empty($video)) {
    http_response_code(404);
    echo "WEBVTT\n\n";
    exit();
}

$transcript = PT_GetVideoTranscript($video->id);
if (empty($transcript) || $transcript->status !== 'completed' || empty($transcript->vtt_path)) {
    http_response_code(404);
    echo "WEBVTT\n\n";
    exit();
}

$root = PT_TranscriptRootDir();
$local = $root . $transcript->vtt_path;

if (file_exists($local)) {
    readfile($local);
    exit();
}

global $pt;
if ($pt->remoteStorage) {
    $url = PT_GetMedia($transcript->vtt_path);
    if (!empty($url)) {
        header('Location: ' . $url);
        exit();
    }
}

http_response_code(404);
echo "WEBVTT\n\n";
exit();
