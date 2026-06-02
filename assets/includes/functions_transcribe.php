<?php

function PT_TranscriptRootDir() {
    $root = realpath(__DIR__ . '/../..');
    return ($root ? $root : dirname(__DIR__, 2)) . '/';
}

function PT_IsTranscribableVideo($video) {
    if (empty($video) || empty($video->id)) {
        return false;
    }
    $embed_fields = array('youtube', 'vimeo', 'daily', 'facebook', 'twitch', 'instagram', 'ok');
    foreach ($embed_fields as $field) {
        if (!empty($video->{$field})) {
            return false;
        }
    }
    if (empty($video->video_location)) {
        return false;
    }
    if (strpos($video->video_location, 'http') === 0) {
        return false;
    }
    if (!empty($video->active) && (int) $video->active !== 1) {
        return false;
    }
    if (isset($video->approved) && (int) $video->approved !== 1) {
        return false;
    }
    return true;
}

function PT_GetVideoTranscript($video_id) {
    global $db;
    $video_id = (int) $video_id;
    if ($video_id < 1) {
        return null;
    }
    return $db->where('video_id', $video_id)->getOne(T_VIDEO_TRANSCRIPTS);
}

function PT_GetTranscriptVttUrl($video) {
    if (empty($video->video_id)) {
        return '';
    }
    $row = PT_GetVideoTranscript($video->id);
    if (empty($row) || $row->status !== 'completed' || empty($row->vtt_path)) {
        return '';
    }
    return PT_Link('vtt/' . $video->video_id);
}

function PT_AttachTranscriptToVideo($video) {
    $video->transcript_vtt_url = '';
    $video->transcript_language = 'en';
    $row = PT_GetVideoTranscript($video->id);
    if (!empty($row) && $row->status === 'completed' && !empty($row->vtt_path)) {
        $video->transcript_vtt_url = PT_GetTranscriptVttUrl($video);
        $video->transcript_language = !empty($row->language) ? $row->language : 'en';
    }
    return $video;
}

function PT_VttToPlainText($vtt_content) {
    $lines = explode("\n", $vtt_content);
    $parts = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line === 'WEBVTT') {
            continue;
        }
        if (strpos($line, '-->') !== false) {
            continue;
        }
        if (preg_match('/^\d+$/', $line)) {
            continue;
        }
        if (stripos($line, 'NOTE') === 0) {
            continue;
        }
        $parts[] = strip_tags($line);
    }
    return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
}

function PT_UpsertVideoTranscript($video_id, $fields) {
    global $db;
    $video_id = (int) $video_id;
    $now = time();
    $existing = PT_GetVideoTranscript($video_id);
    $fields['updated_at'] = $now;
    if (empty($existing)) {
        $fields['video_id'] = $video_id;
        $fields['created_at'] = $now;
        return $db->insert(T_VIDEO_TRANSCRIPTS, $fields);
    }
    return $db->where('video_id', $video_id)->update(T_VIDEO_TRANSCRIPTS, $fields);
}

function PT_IsVideoInTranscriptQueue($video_id) {
    global $db;
    return $db->where('video_id', (int) $video_id)->getValue(T_TRANSCRIPT_QUEUE, 'COUNT(*)') > 0;
}

function PT_EnqueueTranscript($video_id) {
    global $db;
    $video_id = (int) $video_id;
    if ($video_id < 1) {
        return false;
    }
    $video = $db->where('id', $video_id)->getOne(T_VIDEOS);
    if (!PT_IsTranscribableVideo($video)) {
        return false;
    }
    if (PT_IsVideoInTranscriptQueue($video_id)) {
        return true;
    }
    $row = PT_GetVideoTranscript($video_id);
    if (!empty($row) && $row->status === 'completed') {
        return false;
    }
    if (!empty($row) && $row->status === 'processing') {
        return false;
    }
    PT_UpsertVideoTranscript($video_id, array(
        'status' => 'pending',
        'error_message' => '',
    ));
    return $db->insert(T_TRANSCRIPT_QUEUE, array(
        'video_id' => $video_id,
        'processing' => 0,
        'created_at' => time(),
    ));
}

function PT_TranscriptTempDir() {
    $dir = PT_TranscriptRootDir() . 'upload/temp/transcribe';
    if (!file_exists($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function PT_TranscriptStorageDir() {
    $dir = PT_TranscriptRootDir() . 'upload/transcripts';
    if (!file_exists($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function PT_ResolveVideoFileForTranscript($video) {
    global $pt;
    $root = PT_TranscriptRootDir();
    $relative = $video->video_location;
    $local = $root . $relative;
    if (file_exists($local)) {
        return array('path' => $local, 'temp' => false);
    }
    $url = PT_GetMedia($relative);
    if (empty($url)) {
        return array('path' => '', 'temp' => false, 'error' => 'Could not resolve video URL');
    }
    $temp = PT_TranscriptTempDir() . '/video_' . (int) $video->id . '_' . time() . '.mp4';
    $data = @file_get_contents($url);
    if ($data === false || $data === '') {
        return array('path' => '', 'temp' => false, 'error' => 'Could not download video from storage');
    }
    if (@file_put_contents($temp, $data) === false) {
        return array('path' => '', 'temp' => false, 'error' => 'Could not write temp video file');
    }
    return array('path' => $temp, 'temp' => true);
}

function PT_VideoDurationSeconds($video) {
    if (!empty($video->duration) && function_exists('durationToSeconds')) {
        $sec = durationToSeconds($video->duration);
        if ($sec > 0) {
            return $sec;
        }
    }
    return 0;
}

function PT_ExtractAudioForTranscript($video_path, $temp_wav) {
    global $pt;
    if (empty($pt->config->ffmpeg_binary_file) || !is_executable($pt->config->ffmpeg_binary_file)) {
        return array('ok' => false, 'error' => 'FFmpeg binary is not configured or not executable');
    }
    $ffmpeg = escapeshellarg($pt->config->ffmpeg_binary_file);
    $in = escapeshellarg($video_path);
    $out = escapeshellarg($temp_wav);
    $cmd = "{$ffmpeg} -y -i {$in} -vn -ac 1 -ar 16000 -c:a pcm_s16le {$out} 2>&1";
    $output = shell_exec($cmd);
    if (!file_exists($temp_wav) || filesize($temp_wav) < 1) {
        return array('ok' => false, 'error' => 'FFmpeg audio extraction failed: ' . substr((string) $output, 0, 500));
    }
    return array('ok' => true);
}

function PT_RunWhisper($wav_path, $output_dir) {
    global $pt;
    $root = PT_TranscriptRootDir();
    $python = !empty($pt->config->whisper_command) ? $pt->config->whisper_command : 'python3';
    $script = !empty($pt->config->whisper_script) ? $pt->config->whisper_script : 'scripts/transcribe_whisper.py';
    if ($script[0] !== '/') {
        $script = $root . $script;
    }
    if (!file_exists($script)) {
        return array('ok' => false, 'error' => 'Whisper script not found: ' . $script);
    }
    $model = !empty($pt->config->whisper_model) ? $pt->config->whisper_model : 'base';
    $language = !empty($pt->config->transcript_language) ? $pt->config->transcript_language : 'en';
    if (!is_dir($output_dir)) {
        @mkdir($output_dir, 0777, true);
    }
    $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script)
        . ' --input ' . escapeshellarg($wav_path)
        . ' --output_dir ' . escapeshellarg($output_dir)
        . ' --model ' . escapeshellarg($model)
        . ' --language ' . escapeshellarg($language)
        . ' 2>&1';
    $output = shell_exec($cmd);
    $base = pathinfo($wav_path, PATHINFO_FILENAME);
    $vtt = rtrim($output_dir, '/') . '/' . $base . '.vtt';
    if (file_exists($vtt)) {
        return array('ok' => true, 'vtt_path' => $vtt, 'output' => $output);
    }
    if (!empty($output)) {
        $lines = array_filter(array_map('trim', explode("\n", trim($output))));
        $last = end($lines);
        if (!empty($last) && file_exists($last) && substr($last, -4) === '.vtt') {
            return array('ok' => true, 'vtt_path' => $last, 'output' => $output);
        }
    }
    return array('ok' => false, 'error' => 'Whisper did not produce VTT: ' . substr((string) $output, 0, 800));
}

function PT_GetTranscribableVideosQuery($user_id, $options = array()) {
    global $db;
    $user_id = (int) $user_id;

    if (!empty($options['failed_only'])) {
        $failed_rows = $db->rawQuery(
            "SELECT video_id FROM " . T_VIDEO_TRANSCRIPTS . " WHERE status = 'failed'"
        );
        $failed_ids = array();
        if (!empty($failed_rows)) {
            foreach ($failed_rows as $r) {
                $failed_ids[] = (int) $r->video_id;
            }
        }
        if (empty($failed_ids)) {
            return array();
        }
        $db->where('id', $failed_ids, 'IN');
    } elseif (!empty($options['skip_completed'])) {
        $completed_rows = $db->rawQuery(
            "SELECT video_id FROM " . T_VIDEO_TRANSCRIPTS . " WHERE status = 'completed'"
        );
        $completed_ids = array();
        if (!empty($completed_rows)) {
            foreach ($completed_rows as $r) {
                $completed_ids[] = (int) $r->video_id;
            }
        }
        if (!empty($completed_ids)) {
            $db->where('id', $completed_ids, 'NOT IN');
        }
    }

    $db->where('user_id', $user_id);
    $db->where('youtube', '');
    $db->where('vimeo', '');
    $db->where('daily', '');
    $db->where('facebook', '');
    $db->where('twitch', '');
    $db->where('instagram', '');
    $db->where('ok', '');
    $db->where('active', 1);
    $db->where('approved', 1);
    $db->where('video_location', '', '!=');
    if (!empty($options['require_converted'])) {
        $db->where('converted', 1);
    }
    if (!empty($options['time_start']) && !empty($options['time_end'])) {
        $db->where('time', $options['time_start'], '>=');
        $db->where('time', $options['time_end'], '<=');
    }
    $limit = !empty($options['limit']) ? (int) $options['limit'] : 0;
    if ($limit > 0) {
        return $db->orderBy('time', 'DESC')->get(T_VIDEOS, $limit);
    }
    return $db->orderBy('time', 'DESC')->get(T_VIDEOS);
}

function PT_GetTranscriptChannels() {
    global $db;
    $sql = "SELECT u.id, u.username, COUNT(v.id) AS video_count
        FROM " . T_USERS . " u
        INNER JOIN " . T_VIDEOS . " v ON v.user_id = u.id
        WHERE v.youtube = '' AND v.vimeo = '' AND v.daily = '' AND v.facebook = ''
          AND v.twitch = '' AND v.instagram = '' AND v.ok = ''
          AND v.active = 1 AND v.approved = 1 AND v.video_location <> ''
        GROUP BY u.id
        ORDER BY u.username ASC";
    return $db->rawQuery($sql);
}

function PT_TranscriptStatusForChannel($user_id) {
    global $db;
    $user_id = (int) $user_id;
    $counts = array(
        'pending' => 0,
        'processing' => 0,
        'completed' => 0,
        'failed' => 0,
        'skipped' => 0,
        'queued' => 0,
    );
    $rows = $db->rawQuery(
        "SELECT t.status, COUNT(*) AS cnt FROM " . T_VIDEO_TRANSCRIPTS . " t
         INNER JOIN " . T_VIDEOS . " v ON v.id = t.video_id
         WHERE v.user_id = " . $user_id . " GROUP BY t.status"
    );
    if (!empty($rows)) {
        foreach ($rows as $r) {
            if (isset($counts[$r->status])) {
                $counts[$r->status] = (int) $r->cnt;
            }
        }
    }
    $counts['queued'] = (int) $db->rawQueryValue(
        "SELECT COUNT(*) FROM " . T_TRANSCRIPT_QUEUE . " q
         INNER JOIN " . T_VIDEOS . " v ON v.id = q.video_id
         WHERE v.user_id = " . $user_id . " AND q.processing = 0"
    );
    $recent = $db->rawQuery(
        "SELECT t.video_id, t.status, t.error_message, v.title FROM " . T_VIDEO_TRANSCRIPTS . " t
         INNER JOIN " . T_VIDEOS . " v ON v.id = t.video_id
         WHERE v.user_id = " . $user_id . " AND t.status IN ('failed','processing')
         ORDER BY t.updated_at DESC LIMIT 10"
    );
    return array('counts' => $counts, 'recent' => $recent ? $recent : array());
}

function PT_CleanupTranscriptTemp($paths) {
    foreach ($paths as $path) {
        if (!empty($path) && file_exists($path)) {
            @unlink($path);
        }
    }
}

function PT_ProcessTranscriptJob($queue_row) {
    global $db, $pt;
    $video_id = (int) $queue_row->video_id;
    $video = $db->where('id', $video_id)->getOne(T_VIDEOS);
    $temp_files = array();
    $max_attempts = 3;

    if (empty($video) || !PT_IsTranscribableVideo($video)) {
        PT_UpsertVideoTranscript($video_id, array(
            'status' => 'skipped',
            'error_message' => 'Video is not eligible for transcription',
        ));
        $db->where('id', (int) $queue_row->id)->delete(T_TRANSCRIPT_QUEUE);
        return;
    }

    $max_duration = !empty($pt->config->transcript_max_duration) ? (int) $pt->config->transcript_max_duration : 7200;
    $duration_sec = PT_VideoDurationSeconds($video);
    if ($max_duration > 0 && $duration_sec > $max_duration) {
        PT_UpsertVideoTranscript($video_id, array(
            'status' => 'skipped',
            'error_message' => 'Video exceeds max duration (' . $max_duration . 's)',
        ));
        $db->where('id', (int) $queue_row->id)->delete(T_TRANSCRIPT_QUEUE);
        return;
    }

    PT_UpsertVideoTranscript($video_id, array('status' => 'processing', 'error_message' => ''));

    $resolved = PT_ResolveVideoFileForTranscript($video);
    if (empty($resolved['path'])) {
        PT_TranscriptJobFailed($queue_row, $video_id, $resolved['error'] ?? 'Video file not found', $max_attempts);
        return;
    }
    if (!empty($resolved['temp'])) {
        $temp_files[] = $resolved['path'];
    }

    $wav = PT_TranscriptTempDir() . '/audio_' . $video_id . '_' . time() . '.wav';
    $temp_files[] = $wav;
    $extract = PT_ExtractAudioForTranscript($resolved['path'], $wav);
    if (empty($extract['ok'])) {
        PT_TranscriptJobFailed($queue_row, $video_id, $extract['error'], $max_attempts);
        PT_CleanupTranscriptTemp($temp_files);
        return;
    }

    $whisper_out = PT_TranscriptTempDir() . '/out_' . $video_id . '_' . time();
    @mkdir($whisper_out, 0777, true);
    $whisper = PT_RunWhisper($wav, $whisper_out);
    if (empty($whisper['ok'])) {
        PT_TranscriptJobFailed($queue_row, $video_id, $whisper['error'], $max_attempts);
        PT_CleanupTranscriptTemp($temp_files);
        if (is_dir($whisper_out)) {
            @rmdir($whisper_out);
        }
        return;
    }

    $vtt_content = file_get_contents($whisper['vtt_path']);
    $plain = PT_VttToPlainText($vtt_content);
    $dest_rel = 'upload/transcripts/' . $video->video_id . '.vtt';
    $dest_abs = PT_TranscriptRootDir() . $dest_rel;
    PT_TranscriptStorageDir();
    if (!@copy($whisper['vtt_path'], $dest_abs)) {
        PT_TranscriptJobFailed($queue_row, $video_id, 'Could not save VTT file', $max_attempts);
        PT_CleanupTranscriptTemp($temp_files);
        return;
    }
    $temp_files[] = $whisper['vtt_path'];

    if ($pt->remoteStorage) {
        PT_UploadToS3($dest_rel, array('delete' => true));
    }

    $language = !empty($pt->config->transcript_language) ? $pt->config->transcript_language : 'en';
    PT_UpsertVideoTranscript($video_id, array(
        'status' => 'completed',
        'plain_text' => $plain,
        'vtt_path' => $dest_rel,
        'language' => $language,
        'error_message' => '',
    ));
    $db->where('id', (int) $queue_row->id)->delete(T_TRANSCRIPT_QUEUE);
    PT_CleanupTranscriptTemp($temp_files);
    if (is_dir($whisper_out)) {
        $files = glob($whisper_out . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($whisper_out);
    }
}

function PT_TranscriptJobFailed($queue_row, $video_id, $error, $max_attempts) {
    global $db;
    $row = PT_GetVideoTranscript($video_id);
    $attempts = !empty($row->attempts) ? (int) $row->attempts + 1 : 1;
    PT_UpsertVideoTranscript($video_id, array(
        'status' => 'failed',
        'error_message' => substr($error, 0, 2000),
        'attempts' => $attempts,
    ));
    $db->where('id', (int) $queue_row->id)->delete(T_TRANSCRIPT_QUEUE);
    if ($attempts < $max_attempts) {
        $db->insert(T_TRANSCRIPT_QUEUE, array(
            'video_id' => (int) $video_id,
            'processing' => 0,
            'created_at' => time(),
        ));
        PT_UpsertVideoTranscript($video_id, array('status' => 'pending'));
    }
}

function PT_TranscriptTimeRange($selected_time) {
    $time_start = 0;
    $time_end = 0;
    if ($selected_time == 'today') {
        $time_start = strtotime(date('M') . ' ' . date('d') . ', ' . date('Y') . ' 12:00am');
        $time_end = strtotime(date('M') . ' ' . date('d') . ', ' . date('Y') . ' 11:59pm');
    } elseif ($selected_time == 'this_week') {
        $time = strtotime(date('l') . ', ' . date('M') . ' ' . date('d') . ', ' . date('Y'));
        if (date('l') == 'Saturday') {
            $time_start = strtotime(date('M') . ' ' . date('d') . ', ' . date('Y') . ' 12:00am');
        } else {
            $time_start = strtotime('last saturday, 12:00am', $time);
        }
        if (date('l') == 'Friday') {
            $time_end = strtotime(date('M') . ' ' . date('d') . ', ' . date('Y') . ' 11:59pm');
        } else {
            $time_end = strtotime('next Friday, 11:59pm', $time);
        }
    } elseif ($selected_time == 'this_month') {
        $time_start = strtotime('1 ' . date('M') . ' ' . date('Y') . ' 12:00am');
        $time_end = strtotime(cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y')) . ' ' . date('M') . ' ' . date('Y') . ' 11:59pm');
    } elseif ($selected_time == 'this_year') {
        $time_start = strtotime('1 January ' . date('Y') . ' 12:00am');
        $time_end = strtotime('31 December ' . date('Y') . ' 11:59pm');
    }
    return array('time_start' => $time_start, 'time_end' => $time_end);
}
