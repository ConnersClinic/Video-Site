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
    $video->transcript_row = null;
    $video->has_completed_transcript = false;
    $row = PT_GetVideoTranscript($video->id);
    if (!empty($row) && $row->status === 'completed') {
        $video->transcript_row = $row;
        $video->has_completed_transcript = true;
        if (!empty($row->vtt_path)) {
            $video->transcript_vtt_url = PT_GetTranscriptVttUrl($video);
            $video->transcript_language = !empty($row->language) ? $row->language : 'en';
        }
    }
    return $video;
}

function PT_GetClinicCtaHtmlDefault() {
    return '<p><strong>Have you or a loved one been diagnosed with cancer?</strong></p>'
        . '<p>At Conners Clinic, we help people look beyond the diagnosis and explore potential underlying factors that may be contributing to their condition. Through personalized coaching, advanced testing, education, programmed rife machines, and custom wellness plans, we work with patients seeking a more comprehensive approach to their health journey.</p>'
        . '<p>Schedule a free 15-minute discovery call with Dr. Conners to learn about your options at <a href="https://www.connersclinic.com" target="_blank" rel="noopener">https://www.connersclinic.com</a></p>';
}

function PT_GetDefaultClinicCtaHtml() {
    global $pt;
    if (!empty($pt->config->clinic_cta_html)) {
        return $pt->config->clinic_cta_html;
    }
    return PT_GetClinicCtaHtmlDefault();
}

function PT_GetTranscriptDescriptionMode() {
    global $pt;
    $mode = !empty($pt->config->transcript_description_mode) ? $pt->config->transcript_description_mode : 'replace_description';
    if (!in_array($mode, array('display_only', 'replace_description', 'append_summary'), true)) {
        return 'replace_description';
    }
    return $mode;
}

function PT_TruncateForMeta($text, $max = 160) {
    $text = trim(strip_tags($text));
    $text = preg_replace('/\s+/', ' ', $text);
    if (mb_strlen($text, 'UTF-8') <= $max) {
        return $text;
    }
    return mb_substr($text, 0, $max - 3, 'UTF-8') . '...';
}

function PT_FormatTranscriptForDisplay($plain_text) {
    $plain_text = trim(strip_tags($plain_text));
    if ($plain_text === '') {
        return '';
    }
    $chunks = preg_split('/(?<=[.!?])\s+/', $plain_text, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($chunks)) {
        return '<p>' . htmlspecialchars($plain_text) . '</p>';
    }
    $paragraphs = array();
    $buffer = '';
    foreach ($chunks as $sentence) {
        $buffer .= ($buffer === '' ? '' : ' ') . $sentence;
        if (strlen($buffer) > 400) {
            $paragraphs[] = '<p>' . htmlspecialchars($buffer) . '</p>';
            $buffer = '';
        }
    }
    if ($buffer !== '') {
        $paragraphs[] = '<p>' . htmlspecialchars($buffer) . '</p>';
    }
    return implode("\n", $paragraphs);
}

function PT_BuildSeoSummaryHtml($seo_summary) {
    $seo_summary = trim(strip_tags($seo_summary));
    if ($seo_summary === '') {
        return '';
    }
    $parts = preg_split('/\n\s*\n/', $seo_summary);
    $html = '';
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $html .= '<p>' . nl2br(htmlspecialchars($part)) . '</p>';
        }
    }
    if ($html === '') {
        $html = '<p>' . nl2br(htmlspecialchars($seo_summary)) . '</p>';
    }
    return $html;
}

function PT_BuildWatchDescriptionTabsHtml($video) {
    $row = !empty($video->transcript_row) ? $video->transcript_row : PT_GetVideoTranscript($video->id);
    $has_transcript = !empty($row) && $row->status === 'completed' && !empty($row->plain_text);
    $seo_summary = (!empty($row->seo_summary)) ? $row->seo_summary : '';

    $about_parts = array();
    if ($seo_summary !== '') {
        $about_parts[] = '<div class="watch-seo-summary" itemprop="description">' . PT_BuildSeoSummaryHtml($seo_summary) . '</div>';
    } elseif (!$has_transcript) {
        $about_parts[] = '<div class="watch-video-description"><p dir="auto" itemprop="description">' . $video->markup_description . '</p></div>';
    }
    $about_parts[] = '<div class="watch-clinic-cta">' . PT_GetDefaultClinicCtaHtml() . '</div>';
    if ($seo_summary === '' && $has_transcript && !empty($video->markup_description)) {
        $about_parts[] = '<div class="watch-legacy-description watch-video-description"><p dir="auto">' . $video->markup_description . '</p></div>';
    }
    $about_html = implode("\n", $about_parts);

    $transcript_html = '';
    if ($has_transcript) {
        $display_text = $row->plain_text;
        if (function_exists('PT_GetDisplayTranscriptForVideo')) {
            $cleaned = PT_GetDisplayTranscriptForVideo($video->id);
            if ($cleaned !== '') {
                $display_text = $cleaned;
            }
        }
        $body = PT_FormatTranscriptForDisplay($display_text);
        $transcript_html = '<h4 class="watch-transcript-heading">Transcript</h4>'
            . '<div class="watch-transcript-body watch-video-description" style="max-height:100px;overflow:hidden;">' . $body . '</div>';
    }

    if (!$has_transcript) {
        return '<div class="watch-video-desc-single">' . $about_html
            . '<div class="watch-video-show-more desc pt_mn_wtch_rdmre">Show more</div></div>';
    }

    return '<div class="watch-video-desc-tabs">'
        . '<ul class="nav nav-tabs watch-desc-nav" role="tablist">'
        . '<li class="active"><a href="#watch-tab-about" data-toggle="tab" role="tab">About</a></li>'
        . '<li><a href="#watch-tab-transcript" data-toggle="tab" role="tab">Transcript</a></li>'
        . '</ul>'
        . '<div class="tab-content watch-desc-tab-content">'
        . '<div class="tab-pane active" id="watch-tab-about" role="tabpanel">' . $about_html
        . '<div class="watch-video-show-more desc pt_mn_wtch_rdmre">Show more</div></div>'
        . '<div class="tab-pane" id="watch-tab-transcript" role="tabpanel">' . $transcript_html
        . '<div class="watch-video-show-more transcript-show-more desc pt_mn_wtch_rdmre">Show more</div></div>'
        . '</div></div>';
}

function PT_GenerateTranscriptSeoSummary($video, $plain_text) {
    global $pt;
    $api_key = !empty($pt->config->openai_api_key) ? trim($pt->config->openai_api_key) : '';
    if ($api_key === '') {
        return array('ok' => false, 'summary' => '', 'error' => 'OpenAI API key is not configured');
    }
    $model = !empty($pt->config->openai_model) ? $pt->config->openai_model : 'gpt-4o-mini';
    $title = !empty($video->title) ? strip_tags($video->title) : '';
    $tags = !empty($video->tags) ? strip_tags($video->tags) : '';
    $excerpt = mb_substr(trim($plain_text), 0, 6000, 'UTF-8');
    $system = !empty($pt->config->openai_summary_prompt) ? $pt->config->openai_summary_prompt : (
        'You write concise, SEO-friendly video descriptions for a health clinic website. '
        . 'Return 2 to 4 plain sentences. No markdown, no bullet points, no hashtags. '
        . 'Focus on what the video covers and who it helps.'
    );
    $user = "Video title: {$title}\nTags: {$tags}\n\nTranscript excerpt:\n{$excerpt}";
    $payload = array(
        'model' => $model,
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.5,
        'max_tokens' => 300,
    );
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload),
    ));
    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        return array('ok' => false, 'summary' => '', 'error' => 'OpenAI request failed: ' . $curl_error);
    }
    $data = json_decode($response, true);
    if ($http_code < 200 || $http_code >= 300) {
        $msg = !empty($data['error']['message']) ? $data['error']['message'] : substr($response, 0, 500);
        return array('ok' => false, 'summary' => '', 'error' => 'OpenAI HTTP ' . $http_code . ': ' . $msg);
    }
    $summary = '';
    if (!empty($data['choices'][0]['message']['content'])) {
        $summary = trim($data['choices'][0]['message']['content']);
        $summary = preg_replace('/^["\']+|["\']+$/', '', $summary);
    }
    if ($summary === '') {
        return array('ok' => false, 'summary' => '', 'error' => 'OpenAI returned an empty summary');
    }
    return array('ok' => true, 'summary' => $summary, 'error' => '');
}

function PT_ComposeVideoDescriptionFromTranscript($seo_summary) {
    $parts = array();
    $summary_html = PT_BuildSeoSummaryHtml($seo_summary);
    if ($summary_html !== '') {
        $parts[] = $summary_html;
    }
    $parts[] = PT_GetDefaultClinicCtaHtml();
    return trim(implode("\n\n", $parts));
}

function PT_ApplyTranscriptToVideoDescription($video_id, $seo_summary) {
    global $db, $pt;
    $video_id = (int) $video_id;
    $mode = PT_GetTranscriptDescriptionMode();
    if ($mode === 'display_only' || trim($seo_summary) === '') {
        return false;
    }
    $video = $db->where('id', $video_id)->getOne(T_VIDEOS);
    if (empty($video)) {
        return false;
    }
    $composed = PT_ComposeVideoDescriptionFromTranscript($seo_summary);
    if ($mode === 'append_summary') {
        $existing = trim($video->description);
        $composed = $composed . ($existing !== '' ? "\n\n" . $existing : '');
    }
    $db->where('id', $video_id)->update(T_VIDEOS, array('description' => $composed));
    PT_UpsertVideoTranscript($video_id, array('description_applied' => 1));
    return true;
}

function PT_FinalizeTranscriptWithSeo($video, $plain_text, $dest_rel, $language) {
    global $db;
    $video_id = (int) $video->id;
    $summary_result = PT_GenerateTranscriptSeoSummary($video, $plain_text);
    $seo_summary = '';
    $extra_error = '';
    if (!empty($summary_result['ok'])) {
        $seo_summary = $summary_result['summary'];
    } else {
        $extra_error = !empty($summary_result['error']) ? $summary_result['error'] : 'SEO summary failed';
    }
    PT_UpsertVideoTranscript($video_id, array(
        'status' => 'completed',
        'plain_text' => $plain_text,
        'vtt_path' => $dest_rel,
        'language' => $language,
        'seo_summary' => $seo_summary,
        'error_message' => $extra_error,
    ));
    if ($seo_summary !== '') {
        PT_ApplyTranscriptToVideoDescription($video_id, $seo_summary);
    }
}

function PT_RegenerateSeoSummaryForVideo($video_id) {
    global $db;
    $video_id = (int) $video_id;
    $row = PT_GetVideoTranscript($video_id);
    $video = $db->where('id', $video_id)->getOne(T_VIDEOS);
    if (empty($row) || empty($video) || $row->status !== 'completed' || empty($row->plain_text)) {
        return array('ok' => false, 'error' => 'No completed transcript for this video');
    }
    $summary_result = PT_GenerateTranscriptSeoSummary($video, $row->plain_text);
    if (empty($summary_result['ok'])) {
        return $summary_result;
    }
    PT_UpsertVideoTranscript($video_id, array(
        'seo_summary' => $summary_result['summary'],
        'error_message' => '',
    ));
    PT_ApplyTranscriptToVideoDescription($video_id, $summary_result['summary']);
    return array('ok' => true, 'summary' => $summary_result['summary']);
}

function PT_TestOpenAiConnection() {
    global $pt;
    $api_key = !empty($pt->config->openai_api_key) ? trim($pt->config->openai_api_key) : '';
    if ($api_key === '') {
        return array('ok' => false, 'error' => 'OpenAI API key is empty');
    }
    $model = !empty($pt->config->openai_model) ? $pt->config->openai_model : 'gpt-4o-mini';
    $payload = array(
        'model' => $model,
        'messages' => array(
            array('role' => 'user', 'content' => 'Reply with exactly: OK'),
        ),
        'max_tokens' => 5,
    );
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload),
    ));
    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code >= 200 && $http_code < 300) {
        return array('ok' => true, 'message' => 'OpenAI connection successful');
    }
    $data = json_decode($response, true);
    $msg = !empty($data['error']['message']) ? $data['error']['message'] : 'HTTP ' . $http_code;
    return array('ok' => false, 'error' => $msg);
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

function PT_GetDefaultTranscriptChannelUsernames() {
    global $pt;
    $raw = !empty($pt->config->transcript_channel_usernames)
        ? $pt->config->transcript_channel_usernames
        : 'DrKevinConners,ConnersClinic';
    $names = array_filter(array_map('trim', explode(',', $raw)));
    return !empty($names) ? $names : array('DrKevinConners', 'ConnersClinic');
}

function PT_GetTranscriptChannels($options = array()) {
    global $db;
    $purpose = !empty($options['purpose']) ? $options['purpose'] : 'batch';
    $channels_by_id = array();

    foreach (PT_GetDefaultTranscriptChannelUsernames() as $username) {
        $user = $db->where('username', $username)->getOne(T_USERS);
        if (empty($user)) {
            $found = $db->rawQuery(
                'SELECT * FROM ' . T_USERS . ' WHERE LOWER(username) = ? LIMIT 1',
                array(strtolower($username))
            );
            $user = !empty($found[0]) ? $found[0] : null;
        }
        if (!empty($user)) {
            $channels_by_id[(int) $user->id] = (object) array(
                'id' => (int) $user->id,
                'username' => $user->username,
                'video_count' => 0,
                'transcribable_count' => 0,
            );
        }
    }

    if ($purpose === 'seo') {
        $rows = $db->rawQuery(
            "SELECT u.id, u.username, COUNT(DISTINCT t.video_id) AS video_count,
                SUM(CASE WHEN t.plain_text IS NOT NULL AND t.plain_text <> '' THEN 1 ELSE 0 END) AS transcribable_count
             FROM " . T_USERS . " u
             INNER JOIN " . T_VIDEOS . " v ON v.user_id = u.id
             INNER JOIN " . T_VIDEO_TRANSCRIPTS . " t ON t.video_id = v.id AND t.status = 'completed'
             WHERE v.active = 1 AND v.approved = 1
             GROUP BY u.id
             ORDER BY u.username ASC"
        );
    } else {
        $rows = $db->rawQuery(
            "SELECT u.id, u.username, COUNT(v.id) AS video_count,
                SUM(CASE WHEN v.youtube = '' AND v.vimeo = '' AND v.daily = '' AND v.facebook = ''
                    AND v.twitch = '' AND v.instagram = '' AND v.ok = ''
                    AND v.video_location IS NOT NULL AND v.video_location <> ''
                    AND v.video_location NOT LIKE 'http%'
                    THEN 1 ELSE 0 END) AS transcribable_count
             FROM " . T_USERS . " u
             INNER JOIN " . T_VIDEOS . " v ON v.user_id = u.id
             WHERE v.active = 1 AND v.approved = 1
             GROUP BY u.id
             HAVING video_count > 0
             ORDER BY u.username ASC"
        );
    }

    if (!empty($rows)) {
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if (!empty($channels_by_id[$id])) {
                $channels_by_id[$id]->video_count = (int) $row->video_count;
                $channels_by_id[$id]->transcribable_count = (int) ($row->transcribable_count ?? 0);
            } else {
                $channels_by_id[$id] = $row;
            }
        }
    }

    $channels = array_values($channels_by_id);
    usort($channels, function ($a, $b) {
        return strcasecmp($a->username, $b->username);
    });
    return $channels;
}

function PT_FormatTranscriptChannelLabel($channel, $purpose = 'batch') {
    $total = (int) ($channel->video_count ?? 0);
    $transcribable = (int) ($channel->transcribable_count ?? 0);
    $label = '@' . $channel->username;
    if ($purpose === 'seo') {
        return $label . ' (' . $total . ' with transcript' . ($total === 1 ? '' : 's') . ')';
    }
    if ($transcribable > 0 && $transcribable < $total) {
        return $label . ' (' . $total . ' videos, ' . $transcribable . ' self-hosted for Whisper)';
    }
    if ($transcribable > 0) {
        return $label . ' (' . $transcribable . ' video' . ($transcribable === 1 ? '' : 's') . ')';
    }
    return $label . ' (' . $total . ' video' . ($total === 1 ? '' : 's') . ', 0 self-hosted)';
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
    PT_FinalizeTranscriptWithSeo($video, $plain, $dest_rel, $language);
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
