<?php

/**
 * SEO content pipeline — runs after transcription; consumes raw transcript, produces blog articles.
 * Prompts and standards live under content-pipeline/ (editable without code changes).
 */

function PT_SeoPipelineRootDir() {
    return PT_TranscriptRootDir() . 'content-pipeline/';
}

function PT_SeoPipelineStatuses() {
    return array(
        'transcript_ready',
        'pass_0_running',
        'pass_0_complete',
        'entities_extracting',
        'entities_extracted',
        'entities_comparing',
        'entities_compared',
        'entity_library_updated',
        'entity_review_queue_created',
        'pass_1_running',
        'pass_1_complete',
        'pass_2_running',
        'pass_2_complete',
        'pass_3_running',
        'pass_3_complete',
        'pass_4_running',
        'complete',
        'ready_for_review',
        'failed',
    );
}

function PT_SeoPipelineJsonFile($relative) {
    $path = PT_SeoPipelineRootDir() . $relative;
    if (!file_exists($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function PT_SeoPipelineWriteJsonFile($relative, $data) {
    $path = PT_SeoPipelineRootDir() . $relative;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents($path, $json) !== false;
}

function PT_LoadSeoPipelineModels() {
    $models = PT_SeoPipelineJsonFile('config/pipeline-models.json');
    if (empty($models)) {
        return array(
            'pass_0' => 'gpt-4o-mini',
            'entity_extraction' => 'gpt-4o-mini',
            'entity_comparison' => 'gpt-4o-mini',
            'entity_auto_merge' => 'deterministic',
            'entity_review_queue' => 'gpt-4o-mini',
            'pass_1' => 'gpt-4o-mini',
            'pass_2' => 'gpt-4o',
            'pass_3' => 'gpt-4o-mini',
            'pass_4' => 'gpt-4o',
            'json_repair' => 'gpt-4o-mini',
        );
    }
    return $models;
}

function PT_GetSeoPipelineModel($pass_key) {
    global $pt;
    $config_key = 'seo_pipeline_model_' . $pass_key;
    if (!empty($pt->config->{$config_key})) {
        return trim($pt->config->{$config_key});
    }
    $models = PT_LoadSeoPipelineModels();
    return !empty($models[$pass_key]) ? $models[$pass_key] : 'gpt-4o-mini';
}

function PT_LoadSeoVersions() {
    $v = PT_SeoPipelineJsonFile('config/versions.json');
    if (empty($v)) {
        $v = array('prompts' => array(), 'standards' => array());
    }
    return $v;
}

function PT_BumpSeoFileVersion($type, $key) {
    $v = PT_LoadSeoVersions();
    if (!isset($v[$type][$key])) {
        $v[$type][$key] = 1;
    } else {
        $v[$type][$key] = (int) $v[$type][$key] + 1;
    }
    PT_SeoPipelineWriteJsonFile('config/versions.json', $v);
    return $v[$type][$key];
}

function PT_GetPromptVersion($prompt_slug) {
    $v = PT_LoadSeoVersions();
    return !empty($v['prompts'][$prompt_slug]) ? (int) $v['prompts'][$prompt_slug] : 1;
}

function PT_GetStandardsVersion($standards_key) {
    $v = PT_LoadSeoVersions();
    return !empty($v['standards'][$standards_key]) ? (int) $v['standards'][$standards_key] : 1;
}

function PT_GetEditorialStandardsVersion() {
    return max(
        PT_GetStandardsVersion('brand-standards'),
        PT_GetStandardsVersion('medical-terminology-standards'),
        PT_GetStandardsVersion('seo-standards')
    );
}

function PT_LoadBrandStandards() {
    return PT_SeoPipelineJsonFile('standards/brand-standards.json') ?: array();
}

function PT_LoadMedicalTerminologyStandards() {
    return PT_SeoPipelineJsonFile('standards/medical-terminology-standards.json') ?: array();
}

function PT_LoadSeoStandards() {
    return PT_SeoPipelineJsonFile('standards/seo-standards.json') ?: array();
}

function PT_LoadEditorialStandards() {
    return array(
        'brand' => PT_LoadBrandStandards(),
        'medical_terminology' => PT_LoadMedicalTerminologyStandards(),
        'seo' => PT_LoadSeoStandards(),
    );
}

function PT_LoadEntityLibrary() {
    $lib = PT_SeoPipelineJsonFile('standards/entity-library.json');
    if (empty($lib)) {
        return array('entities' => array());
    }
    if (!empty($lib['entities']) && is_array($lib['entities'])) {
        return $lib;
    }
    return PT_NormalizeEntityLibraryToSlugKeyed($lib);
}

function PT_NormalizeEntityLibraryToSlugKeyed($lib) {
    $entities = array();
    if (!empty($lib['entities']) && is_array($lib['entities'])) {
        return array('entities' => $lib['entities']);
    }
    foreach ($lib as $category => $items) {
        if ($category === 'file_name' || !is_array($items)) {
            continue;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = !empty($item['name']) ? $item['name'] : '';
            $slug = !empty($item['slug']) ? $item['slug'] : PT_SeoSlugify($name);
            if ($slug === '') {
                continue;
            }
            $item['category'] = !empty($item['category']) ? $item['category'] : $category;
            $item['slug'] = $slug;
            $item['aliases'] = !empty($item['aliases']) ? $item['aliases'] : array();
            $item['status'] = !empty($item['status']) ? $item['status'] : 'active';
            $entities[$slug] = $item;
        }
    }
    return array('entities' => $entities);
}

function PT_SaveEntityLibrary($library) {
    if (empty($library['entities'])) {
        $library = PT_NormalizeEntityLibraryToSlugKeyed($library);
    }
    PT_BumpSeoFileVersion('standards', 'entity-library');
    return PT_SeoPipelineWriteJsonFile('standards/entity-library.json', $library);
}

function PT_SeoSlugify($text) {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function PT_ListSeoPromptFiles() {
    return array(
        'pass-0-clean-transcript' => 'Pass 0: Clean transcript',
        'entity-extraction' => 'Entity extraction',
        'entity-comparison' => 'Entity comparison',
        'entity-auto-merge' => 'Entity auto-merge (reference)',
        'entity-review-queue' => 'Entity review queue',
        'pass-1-seo-blueprint' => 'Pass 1: SEO blueprint',
        'pass-2-blog-article' => 'Pass 2: Blog article',
        'pass-3-seo-audit' => 'Pass 3: SEO audit',
        'pass-4-final-revision' => 'Pass 4: Final revision',
    );
}

function PT_LoadPromptTemplate($slug) {
    $path = PT_SeoPipelineRootDir() . 'prompts/' . $slug . '.md';
    if (!file_exists($path)) {
        return '';
    }
    return file_get_contents($path);
}

function PT_SavePromptTemplate($slug, $content) {
    $allowed = array_keys(PT_ListSeoPromptFiles());
    if (!in_array($slug, $allowed, true)) {
        return false;
    }
    $path = PT_SeoPipelineRootDir() . 'prompts/' . $slug . '.md';
    PT_BumpSeoFileVersion('prompts', $slug);
    return file_put_contents($path, $content) !== false;
}

function PT_ReplacePromptPlaceholders($template, $vars) {
    $map = array(
        '[EDITORIAL_STANDARDS_JSON]' => 'EDITORIAL_STANDARDS_JSON',
        '[ENTITY_LIBRARY_JSON]' => 'ENTITY_LIBRARY_JSON',
        '[PASTE TRANSCRIPT]' => 'PASTE_TRANSCRIPT',
        '[PASTE CLEANED TRANSCRIPT]' => 'PASTE_CLEANED_TRANSCRIPT',
        '[PASTE ENTITY EXTRACTION OUTPUT]' => 'ENTITY_EXTRACTION_OUTPUT',
        '[ENTITY_COMPARISON_RESULTS_JSON]' => 'ENTITY_COMPARISON_RESULTS_JSON',
        '[PASS_0_OUTPUT]' => 'PASS_0_OUTPUT',
        '[PASS_1_OUTPUT]' => 'PASS_1_OUTPUT',
        '[PASS_2_OUTPUT]' => 'PASS_2_OUTPUT',
        '[PASS_3_OUTPUT]' => 'PASS_3_OUTPUT',
        '[PASS_2_ARTICLE]' => 'PASS_2_OUTPUT',
    );
    foreach ($map as $placeholder => $key) {
        if (strpos($template, $placeholder) === false) {
            continue;
        }
        $value = '';
        if (!empty($vars[$key])) {
            $value = is_string($vars[$key]) ? $vars[$key] : json_encode($vars[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $template = str_replace($placeholder, $value, $template);
    }
    return $template;
}

function PT_GetVideoSeoArticle($video_id) {
    global $db;
    $video_id = (int) $video_id;
    if ($video_id < 1) {
        return null;
    }
    return $db->where('video_id', $video_id)->getOne(T_VIDEO_SEO_ARTICLES);
}

function PT_UpsertVideoSeoArticle($video_id, $fields) {
    global $db;
    $video_id = (int) $video_id;
    $now = time();
    $json_fields = array(
        'transcript_quality',
        'entity_extraction_output',
        'entity_comparison_output',
        'entity_review_queue_output',
        'seo_audit',
        'prompt_versions',
        'standards_versions',
    );
    foreach ($json_fields as $jf) {
        if (isset($fields[$jf]) && is_array($fields[$jf])) {
            $fields[$jf] = json_encode($fields[$jf], JSON_UNESCAPED_UNICODE);
        }
    }
    $fields['updated_at'] = $now;
    $existing = PT_GetVideoSeoArticle($video_id);
    if (empty($existing)) {
        $fields['video_id'] = $video_id;
        $fields['created_at'] = $now;
        return $db->insert(T_VIDEO_SEO_ARTICLES, $fields);
    }
    return $db->where('video_id', $video_id)->update(T_VIDEO_SEO_ARTICLES, $fields);
}

function PT_SeoArticleToArray($row) {
    if (empty($row)) {
        return null;
    }
    $json_fields = array(
        'transcript_quality',
        'entity_extraction_output',
        'entity_comparison_output',
        'entity_review_queue_output',
        'seo_audit',
        'prompt_versions',
        'standards_versions',
    );
    $out = (array) $row;
    foreach ($json_fields as $jf) {
        if (!empty($out[$jf]) && is_string($out[$jf])) {
            $decoded = json_decode($out[$jf], true);
            if (is_array($decoded)) {
                $out[$jf] = $decoded;
            }
        }
    }
    return $out;
}

function PT_InitializeSeoArticleFromTranscript($video_id) {
    $video_id = (int) $video_id;
    $transcript = PT_GetVideoTranscript($video_id);
    if (empty($transcript) || $transcript->status !== 'completed' || empty($transcript->plain_text)) {
        return array('ok' => false, 'error' => 'Video does not have a completed transcript');
    }
    $existing = PT_GetVideoSeoArticle($video_id);
    $raw = $transcript->plain_text;
    $versions = PT_LoadSeoVersions();
    $fields = array(
        'raw_transcript' => $raw,
        'status' => 'transcript_ready',
        'error_message' => '',
        'failed_pass' => null,
        'failed_raw_response' => null,
        'pass_0_prompt_version' => PT_GetPromptVersion('pass-0-clean-transcript'),
        'editorial_standards_version' => PT_GetEditorialStandardsVersion(),
        'prompt_versions' => !empty($versions['prompts']) ? $versions['prompts'] : array(),
        'standards_versions' => !empty($versions['standards']) ? $versions['standards'] : array(),
    );
    if (empty($existing)) {
        $fields['cleaned_transcript'] = null;
    } elseif (empty($existing->raw_transcript)) {
        $fields['raw_transcript'] = $raw;
    }
    PT_UpsertVideoSeoArticle($video_id, $fields);
    return array('ok' => true, 'article' => PT_GetVideoSeoArticle($video_id));
}

function PT_SeoPipelineClearFromPass($video_id, $rerun_from) {
    $clears = array(
        'pass_0' => array(
            'cleaned_transcript', 'transcript_quality',
            'entity_extraction_output', 'entity_comparison_output', 'entity_review_queue_output',
            'seo_blueprint', 'draft_article_markdown', 'seo_audit', 'final_article_markdown',
            'seo_title', 'meta_description', 'primary_keyword', 'url_slug',
        ),
        'pass_1' => array(
            'seo_blueprint', 'draft_article_markdown', 'seo_audit', 'final_article_markdown',
            'seo_title', 'meta_description', 'primary_keyword', 'url_slug',
        ),
        'pass_2' => array(
            'draft_article_markdown', 'seo_audit', 'final_article_markdown',
            'seo_title', 'meta_description', 'primary_keyword', 'url_slug',
        ),
        'pass_4' => array(
            'final_article_markdown', 'seo_title', 'meta_description', 'primary_keyword', 'url_slug',
        ),
    );
    if (empty($clears[$rerun_from])) {
        return;
    }
    $update = array();
    foreach ($clears[$rerun_from] as $field) {
        $update[$field] = null;
    }
    PT_UpsertVideoSeoArticle($video_id, $update);
}

function PT_SeoStatusForRerun($rerun_from) {
    $map = array(
        'pass_0' => 'transcript_ready',
        'pass_1' => 'entity_review_queue_created',
        'pass_2' => 'pass_1_complete',
        'pass_4' => 'pass_3_complete',
    );
    return !empty($map[$rerun_from]) ? $map[$rerun_from] : 'transcript_ready';
}

function PT_IsVideoInSeoPipelineQueue($video_id) {
    global $db;
    return $db->where('video_id', (int) $video_id)->where('processing', 0)->getValue(T_SEO_PIPELINE_QUEUE, 'COUNT(*)') > 0;
}

function PT_EnqueueSeoPipeline($video_id, $rerun_from = null) {
    global $db;
    $video_id = (int) $video_id;
    if ($video_id < 1) {
        return false;
    }
    if (PT_IsVideoInSeoPipelineQueue($video_id)) {
        return true;
    }
    $processing = $db->where('video_id', $video_id)->where('processing', 1)->getValue(T_SEO_PIPELINE_QUEUE, 'COUNT(*)');
    if ($processing > 0) {
        return true;
    }
    if ($rerun_from) {
        PT_SeoPipelineClearFromPass($video_id, $rerun_from);
        PT_UpsertVideoSeoArticle($video_id, array(
            'status' => PT_SeoStatusForRerun($rerun_from),
            'error_message' => '',
            'failed_pass' => null,
        ));
    } else {
        $init = PT_InitializeSeoArticleFromTranscript($video_id);
        if (empty($init['ok'])) {
            return false;
        }
    }
    return $db->insert(T_SEO_PIPELINE_QUEUE, array(
        'video_id' => $video_id,
        'processing' => 0,
        'rerun_from' => $rerun_from,
        'priority' => 0,
        'created_at' => time(),
    ));
}

function PT_LogSeoPipelineCall($data) {
    global $db;
    $row = array(
        'video_id' => (int) ($data['video_id'] ?? 0),
        'pass_name' => !empty($data['pass_name']) ? substr($data['pass_name'], 0, 64) : '',
        'model_used' => !empty($data['model_used']) ? substr($data['model_used'], 0, 64) : '',
        'input_tokens' => isset($data['input_tokens']) ? (int) $data['input_tokens'] : null,
        'output_tokens' => isset($data['output_tokens']) ? (int) $data['output_tokens'] : null,
        'started_at' => (int) ($data['started_at'] ?? time()),
        'completed_at' => !empty($data['completed_at']) ? (int) $data['completed_at'] : time(),
        'status' => !empty($data['status']) ? $data['status'] : 'unknown',
        'error_message' => !empty($data['error_message']) ? substr($data['error_message'], 0, 5000) : null,
        'raw_response' => !empty($data['raw_response']) ? $data['raw_response'] : null,
    );
    return $db->insert(T_SEO_PIPELINE_LOGS, $row);
}

function PT_SeoOpenAiChat($messages, $model, $options = array()) {
    global $pt;
    $api_key = !empty($pt->config->openai_api_key) ? trim($pt->config->openai_api_key) : '';
    if ($api_key === '') {
        return array('ok' => false, 'content' => '', 'error' => 'OpenAI API key is not configured');
    }
    $temperature = isset($options['temperature']) ? (float) $options['temperature'] : 0.4;
    $max_tokens = isset($options['max_tokens']) ? (int) $options['max_tokens'] : 16000;
    $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 180;
    $payload = array(
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'max_tokens' => $max_tokens,
    );
    if (!empty($options['response_format_json'])) {
        $payload['response_format'] = array('type' => 'json_object');
    }
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
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
        return array('ok' => false, 'content' => '', 'error' => 'OpenAI request failed: ' . $curl_error, 'raw' => '');
    }
    $data = json_decode($response, true);
    if ($http_code < 200 || $http_code >= 300) {
        $msg = !empty($data['error']['message']) ? $data['error']['message'] : substr($response, 0, 500);
        return array('ok' => false, 'content' => '', 'error' => 'OpenAI HTTP ' . $http_code . ': ' . $msg, 'raw' => $response);
    }
    $content = '';
    if (!empty($data['choices'][0]['message']['content'])) {
        $content = trim($data['choices'][0]['message']['content']);
    }
    return array(
        'ok' => $content !== '',
        'content' => $content,
        'error' => $content === '' ? 'OpenAI returned empty content' : '',
        'raw' => $response,
        'usage' => !empty($data['usage']) ? $data['usage'] : array(),
    );
}

function PT_StripMarkdownJsonFence($text) {
    $text = trim($text);
    if (preg_match('/^```(?:json)?\s*([\s\S]*?)```\s*$/i', $text, $m)) {
        return trim($m[1]);
    }
    return $text;
}

function PT_ParseJsonFromAiResponse($text) {
    $text = PT_StripMarkdownJsonFence($text);
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

function PT_SeoRepairJson($invalid_text, $pass_name) {
    $model = PT_GetSeoPipelineModel('json_repair');
    $prompt = 'The following output was supposed to be valid JSON for pass "' . $pass_name . '" but failed to parse. Return ONLY valid JSON with the same structure and data, no markdown fences, no commentary.';
    $result = PT_SeoOpenAiChat(array(
        array('role' => 'system', 'content' => 'You fix malformed JSON. Output JSON only.'),
        array('role' => 'user', 'content' => $prompt . "\n\n" . substr($invalid_text, 0, 120000)),
    ), $model, array('temperature' => 0, 'max_tokens' => 16000, 'response_format_json' => true));
    if (empty($result['ok'])) {
        return null;
    }
    return PT_ParseJsonFromAiResponse($result['content']);
}

function PT_SeoValidateJsonPass($raw_content, $pass_name, $video_id, $model) {
    $parsed = PT_ParseJsonFromAiResponse($raw_content);
    if (is_array($parsed)) {
        return array('ok' => true, 'data' => $parsed, 'raw' => $raw_content);
    }
    $repaired = PT_SeoRepairJson($raw_content, $pass_name);
    if (is_array($repaired)) {
        PT_LogSeoPipelineCall(array(
            'video_id' => $video_id,
            'pass_name' => $pass_name . '_json_repair',
            'model_used' => PT_GetSeoPipelineModel('json_repair'),
            'status' => 'success',
            'started_at' => time(),
            'completed_at' => time(),
        ));
        return array('ok' => true, 'data' => $repaired, 'raw' => $raw_content, 'repaired' => true);
    }
    return array('ok' => false, 'data' => null, 'raw' => $raw_content, 'error' => 'Invalid JSON after repair attempt');
}

function PT_ComputeTranscriptQuality($raw, $cleaned) {
    $raw = trim($raw);
    $cleaned = trim($cleaned);
    $raw_words = str_word_count($raw);
    $clean_words = str_word_count($cleaned);
    $diff = abs($raw_words - $clean_words);
    $similar = 0;
    similar_text(mb_substr($raw, 0, 5000), mb_substr($cleaned, 0, 5000), $similar);
    $brand_corrections = 0;
    $brand = PT_LoadBrandStandards();
    if (!empty($brand['brand_names'])) {
        foreach ($brand['brand_names'] as $wrong => $right) {
            if (stripos($raw, $wrong) !== false && stripos($cleaned, $right) !== false) {
                $brand_corrections++;
            }
        }
    }
    $score = (int) round(min(100, max(50, $similar)));
    return array(
        'score' => $score,
        'issues_found' => $diff,
        'brand_corrections' => $brand_corrections,
        'entity_corrections' => 0,
        'speaker_corrections' => 0,
    );
}

function PT_ExtractArticleMetadataFromMarkdown($markdown) {
    $meta = array(
        'seo_title' => '',
        'meta_description' => '',
        'primary_keyword' => '',
        'url_slug' => '',
        'body' => $markdown,
    );
    if (preg_match('/^SEO Title:\s*(.+)$/mi', $markdown, $m)) {
        $meta['seo_title'] = trim($m[1]);
    }
    if (preg_match('/^Meta Description:\s*(.+)$/mi', $markdown, $m)) {
        $meta['meta_description'] = trim($m[1]);
    }
    if (preg_match('/^Primary Keyword:\s*(.+)$/mi', $markdown, $m)) {
        $meta['primary_keyword'] = trim($m[1]);
    }
    if (preg_match('/^URL Slug:\s*(.+)$/mi', $markdown, $m)) {
        $meta['url_slug'] = trim($m[1]);
    }
    if (preg_match('/^---\s*\n([\s\S]*)$/', $markdown, $m)) {
        $meta['body'] = trim($m[1]);
    }
    return $meta;
}

function PT_EntityLibraryFindByNameOrAlias($library, $name) {
    $name_lower = strtolower(trim($name));
    if (empty($library['entities'])) {
        return null;
    }
    foreach ($library['entities'] as $slug => $entity) {
        if (strtolower($entity['name']) === $name_lower) {
            return $slug;
        }
        if (!empty($entity['aliases'])) {
            foreach ($entity['aliases'] as $alias) {
                if (strtolower(trim($alias)) === $name_lower) {
                    return $slug;
                }
            }
        }
    }
    return null;
}

function PT_SeoDeterministicAutoMerge($library, $comparison) {
    $summary = array(
        'entities_before' => !empty($library['entities']) ? count($library['entities']) : 0,
        'entities_after' => 0,
        'aliases_added' => 0,
        'new_entities_added' => 0,
        'merge_conflicts' => 0,
    );
    $merge_conflicts = array();
    if (empty($library['entities'])) {
        $library['entities'] = array();
    }
    if (!empty($comparison['auto_merge'])) {
        foreach ($comparison['auto_merge'] as $item) {
            $matched = !empty($item['matched_existing_name']) ? $item['matched_existing_name'] : '';
            $slug = PT_EntityLibraryFindByNameOrAlias($library, $matched);
            if ($slug && !empty($item['suggested_alias'])) {
                $alias = trim($item['suggested_alias']);
                if ($alias !== '' && !in_array($alias, $library['entities'][$slug]['aliases'], true)) {
                    $library['entities'][$slug]['aliases'][] = $alias;
                    $summary['aliases_added']++;
                }
            }
        }
    }
    if (!empty($comparison['suggested_aliases'])) {
        foreach ($comparison['suggested_aliases'] as $item) {
            $existing = !empty($item['existing_entity']) ? $item['existing_entity'] : '';
            $slug = PT_EntityLibraryFindByNameOrAlias($library, $existing);
            if ($slug && !empty($item['alias_to_add'])) {
                $alias = trim($item['alias_to_add']);
                if ($alias !== '' && !in_array($alias, $library['entities'][$slug]['aliases'], true)) {
                    $library['entities'][$slug]['aliases'][] = $alias;
                    $summary['aliases_added']++;
                }
            }
        }
    }
    if (!empty($comparison['add_new'])) {
        foreach ($comparison['add_new'] as $item) {
            $name = !empty($item['name']) ? trim($item['name']) : '';
            if ($name === '') {
                continue;
            }
            $existing_slug = PT_EntityLibraryFindByNameOrAlias($library, $name);
            if ($existing_slug) {
                $summary['merge_conflicts']++;
                $merge_conflicts[] = array(
                    'name' => $name,
                    'reason' => 'Entity already exists',
                    'existing_match' => $library['entities'][$existing_slug]['name'],
                );
                continue;
            }
            $slug = !empty($item['suggested_slug']) ? $item['suggested_slug'] : PT_SeoSlugify($name);
            $base_slug = $slug;
            $n = 2;
            while (isset($library['entities'][$slug])) {
                $slug = $base_slug . '-' . $n;
                $n++;
            }
            $library['entities'][$slug] = array(
                'name' => $name,
                'category' => !empty($item['category']) ? $item['category'] : 'concepts',
                'slug' => $slug,
                'aliases' => array(),
                'status' => 'active',
            );
            $summary['new_entities_added']++;
        }
    }
    $summary['entities_after'] = count($library['entities']);
    return array(
        'summary' => $summary,
        'updated_entity_library' => $library,
        'merge_conflicts' => $merge_conflicts,
    );
}

function PT_PersistEntityReviewQueueItems($video_id, $queue_output) {
    global $db;
    if (empty($queue_output['review_queue']) || !is_array($queue_output['review_queue'])) {
        return 0;
    }
    $count = 0;
    foreach ($queue_output['review_queue'] as $item) {
        $name = !empty($item['entity_name']) ? $item['entity_name'] : '';
        if ($name === '') {
            continue;
        }
        $review_id = 'rev_' . $video_id . '_' . PT_SeoSlugify($name) . '_' . substr(md5(json_encode($item)), 0, 8);
        $exists = $db->where('review_id', $review_id)->getValue(T_ENTITY_REVIEW_QUEUE, 'COUNT(*)');
        if ($exists > 0) {
            continue;
        }
        $db->insert(T_ENTITY_REVIEW_QUEUE, array(
            'review_id' => $review_id,
            'video_id' => (int) $video_id,
            'entity_name' => substr($name, 0, 255),
            'category' => !empty($item['category']) ? substr($item['category'], 0, 64) : '',
            'priority' => !empty($item['priority']) ? substr($item['priority'], 0, 32) : 'medium',
            'recommended_action' => !empty($item['recommended_action']) ? substr($item['recommended_action'], 0, 64) : '',
            'confidence' => isset($item['confidence']) ? (float) $item['confidence'] : null,
            'reason' => !empty($item['reason']) ? $item['reason'] : '',
            'possible_existing_match' => !empty($item['possible_existing_match']) ? substr($item['possible_existing_match'], 0, 255) : '',
            'suggested_slug' => !empty($item['suggested_slug']) ? substr($item['suggested_slug'], 0, 255) : '',
            'trigger_phrases' => !empty($item['trigger_phrases']) ? json_encode($item['trigger_phrases']) : '[]',
            'notes' => !empty($item['notes']) ? $item['notes'] : '',
            'status' => 'pending',
            'created_at' => time(),
        ));
        $count++;
    }
    return $count;
}

function PT_SeoPipelineBuildVars($article) {
    $standards = PT_LoadEditorialStandards();
    $library = PT_LoadEntityLibrary();
    $extraction_json = '';
    if (!empty($article->entity_extraction_output)) {
        $extraction_json = is_string($article->entity_extraction_output)
            ? $article->entity_extraction_output
            : json_encode($article->entity_extraction_output);
    }
    $comparison_json = '';
    if (!empty($article->entity_comparison_output)) {
        $comparison_json = is_string($article->entity_comparison_output)
            ? $article->entity_comparison_output
            : json_encode($article->entity_comparison_output);
    }
    return array(
        'EDITORIAL_STANDARDS_JSON' => json_encode($standards, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        'ENTITY_LIBRARY_JSON' => json_encode($library, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        'PASTE_TRANSCRIPT' => !empty($article->raw_transcript) ? $article->raw_transcript : '',
        'PASTE_CLEANED_TRANSCRIPT' => !empty($article->cleaned_transcript) ? $article->cleaned_transcript : '',
        'ENTITY_EXTRACTION_OUTPUT' => $extraction_json,
        'ENTITY_COMPARISON_RESULTS_JSON' => $comparison_json,
        'PASS_0_OUTPUT' => !empty($article->cleaned_transcript) ? $article->cleaned_transcript : '',
        'PASS_1_OUTPUT' => !empty($article->seo_blueprint) ? $article->seo_blueprint : '',
        'PASS_2_OUTPUT' => !empty($article->draft_article_markdown) ? $article->draft_article_markdown : '',
        'PASS_3_OUTPUT' => !empty($article->seo_audit) ? (is_string($article->seo_audit) ? $article->seo_audit : json_encode($article->seo_audit)) : '',
    );
}

function PT_SeoPipelineRunAiPass($video_id, $pass_name, $prompt_slug, $model_key, $article, $json_output = false, $options = array()) {
    $template = PT_LoadPromptTemplate($prompt_slug);
    if ($template === '') {
        return array('ok' => false, 'error' => 'Prompt template not found: ' . $prompt_slug);
    }
    $vars = PT_SeoPipelineBuildVars($article);
    $prompt = PT_ReplacePromptPlaceholders($template, $vars);
    $model = PT_GetSeoPipelineModel($model_key);
    $started = time();
    $messages = array(array('role' => 'user', 'content' => $prompt));
    $ai_opts = array(
        'temperature' => !empty($options['temperature']) ? $options['temperature'] : 0.4,
        'max_tokens' => !empty($options['max_tokens']) ? $options['max_tokens'] : 16000,
        'timeout' => !empty($options['timeout']) ? $options['timeout'] : 300,
        'response_format_json' => $json_output,
    );
    $result = PT_SeoOpenAiChat($messages, $model, $ai_opts);
    $usage = !empty($result['usage']) ? $result['usage'] : array();
    PT_LogSeoPipelineCall(array(
        'video_id' => $video_id,
        'pass_name' => $pass_name,
        'model_used' => $model,
        'input_tokens' => !empty($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
        'output_tokens' => !empty($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        'started_at' => $started,
        'completed_at' => time(),
        'status' => !empty($result['ok']) ? 'success' : 'failed',
        'error_message' => !empty($result['error']) ? $result['error'] : null,
        'raw_response' => empty($result['ok']) ? (!empty($result['raw']) ? $result['raw'] : $result['content']) : null,
    ));
    if (empty($result['ok'])) {
        return $result;
    }
    if ($json_output) {
        $validated = PT_SeoValidateJsonPass($result['content'], $pass_name, $video_id, $model);
        if (empty($validated['ok'])) {
            return array('ok' => false, 'error' => $validated['error'], 'content' => $result['content'], 'raw' => $result['raw']);
        }
        return array('ok' => true, 'data' => $validated['data'], 'content' => $result['content']);
    }
    return array('ok' => true, 'content' => $result['content']);
}

function PT_SeoPipelineMarkFailed($video_id, $pass_name, $error, $raw = null) {
    PT_UpsertVideoSeoArticle($video_id, array(
        'status' => 'failed',
        'failed_pass' => $pass_name,
        'error_message' => substr($error, 0, 2000),
        'failed_raw_response' => $raw,
    ));
}

function PT_SeoPipelineNextStatus($current) {
    $flow = array(
        'transcript_ready' => 'pass_0_running',
        'pass_0_complete' => 'entities_extracting',
        'entities_extracted' => 'entities_comparing',
        'entities_compared' => 'entity_library_updated',
        'entity_library_updated' => 'entity_review_queue_created',
        'entity_review_queue_created' => 'pass_1_running',
        'pass_1_complete' => 'pass_2_running',
        'pass_2_complete' => 'pass_3_running',
        'pass_3_complete' => 'pass_4_running',
    );
    return !empty($flow[$current]) ? $flow[$current] : null;
}

function PT_SeoPipelineRunSingleStep($video_id) {
    $video_id = (int) $video_id;
    $article = PT_GetVideoSeoArticle($video_id);
    if (empty($article)) {
        $init = PT_InitializeSeoArticleFromTranscript($video_id);
        if (empty($init['ok'])) {
            return $init;
        }
        $article = PT_GetVideoSeoArticle($video_id);
    }
    $status = $article->status;
    if ($status === 'complete') {
        return array('ok' => true, 'done' => true, 'status' => $status);
    }
    if ($status === 'failed') {
        return array('ok' => false, 'error' => $article->error_message, 'status' => 'failed');
    }

    $standards_ver = PT_GetEditorialStandardsVersion();

    switch ($status) {
        case 'transcript_ready':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'pass_0_running'));
            $result = PT_SeoPipelineRunAiPass($video_id, 'pass_0', 'pass-0-clean-transcript', 'pass_0', $article, false, array('max_tokens' => 16000, 'timeout' => 300));
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'pass_0', $result['error'], !empty($result['raw']) ? $result['raw'] : (!empty($result['content']) ? $result['content'] : null));
                return $result;
            }
            $cleaned = $result['content'];
            $quality = PT_ComputeTranscriptQuality($article->raw_transcript, $cleaned);
            PT_UpsertVideoSeoArticle($video_id, array(
                'cleaned_transcript' => $cleaned,
                'transcript_quality' => $quality,
                'pass_0_prompt_version' => PT_GetPromptVersion('pass-0-clean-transcript'),
                'editorial_standards_version' => $standards_ver,
                'status' => 'pass_0_complete',
            ));
            return array('ok' => true, 'status' => 'pass_0_complete');

        case 'pass_0_complete':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'entities_extracting'));
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'entity_extraction', 'entity-extraction', 'entity_extraction', $article, true);
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'entity_extraction', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            PT_UpsertVideoSeoArticle($video_id, array(
                'entity_extraction_output' => $result['data'],
                'status' => 'entities_extracted',
            ));
            return array('ok' => true, 'status' => 'entities_extracted');

        case 'entities_extracted':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'entities_comparing'));
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'entity_comparison', 'entity-comparison', 'entity_comparison', $article, true);
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'entity_comparison', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            PT_UpsertVideoSeoArticle($video_id, array(
                'entity_comparison_output' => $result['data'],
                'status' => 'entities_compared',
            ));
            return array('ok' => true, 'status' => 'entities_compared');

        case 'entities_compared':
            $article = PT_GetVideoSeoArticle($video_id);
            $comparison = is_string($article->entity_comparison_output)
                ? json_decode($article->entity_comparison_output, true)
                : (array) $article->entity_comparison_output;
            $library = PT_LoadEntityLibrary();
            $merge = PT_SeoDeterministicAutoMerge($library, $comparison);
            PT_SaveEntityLibrary($merge['updated_entity_library']);
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'entity_library_updated'));
            return array('ok' => true, 'status' => 'entity_library_updated');

        case 'entity_library_updated':
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'entity_review_queue', 'entity-review-queue', 'entity_review_queue', $article, true);
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'entity_review_queue', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            PT_UpsertVideoSeoArticle($video_id, array(
                'entity_review_queue_output' => $result['data'],
                'status' => 'entity_review_queue_created',
            ));
            PT_PersistEntityReviewQueueItems($video_id, $result['data']);
            return array('ok' => true, 'status' => 'entity_review_queue_created');

        case 'entity_review_queue_created':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'pass_1_running'));
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'pass_1', 'pass-1-seo-blueprint', 'pass_1', $article, false, array('max_tokens' => 12000));
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'pass_1', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            PT_UpsertVideoSeoArticle($video_id, array(
                'seo_blueprint' => $result['content'],
                'status' => 'pass_1_complete',
            ));
            return array('ok' => true, 'status' => 'pass_1_complete');

        case 'pass_1_complete':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'pass_2_running'));
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'pass_2', 'pass-2-blog-article', 'pass_2', $article, false, array('max_tokens' => 16000, 'timeout' => 600, 'temperature' => 0.6));
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'pass_2', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            $meta = PT_ExtractArticleMetadataFromMarkdown($result['content']);
            PT_UpsertVideoSeoArticle($video_id, array(
                'draft_article_markdown' => $result['content'],
                'seo_title' => $meta['seo_title'],
                'meta_description' => $meta['meta_description'],
                'primary_keyword' => $meta['primary_keyword'],
                'url_slug' => $meta['url_slug'],
                'status' => 'pass_2_complete',
            ));
            return array('ok' => true, 'status' => 'pass_2_complete');

        case 'pass_2_complete':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'pass_3_running'));
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'pass_3', 'pass-3-seo-audit', 'pass_3', $article, true, array('max_tokens' => 12000));
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'pass_3', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            PT_UpsertVideoSeoArticle($video_id, array(
                'seo_audit' => $result['data'],
                'status' => 'pass_3_complete',
            ));
            return array('ok' => true, 'status' => 'pass_3_complete');

        case 'pass_3_complete':
            PT_UpsertVideoSeoArticle($video_id, array('status' => 'pass_4_running'));
            $article = PT_GetVideoSeoArticle($video_id);
            $result = PT_SeoPipelineRunAiPass($video_id, 'pass_4', 'pass-4-final-revision', 'pass_4', $article, false, array('max_tokens' => 16000, 'timeout' => 600, 'temperature' => 0.5));
            if (empty($result['ok'])) {
                PT_SeoPipelineMarkFailed($video_id, 'pass_4', $result['error'], !empty($result['content']) ? $result['content'] : null);
                return $result;
            }
            $meta = PT_ExtractArticleMetadataFromMarkdown($result['content']);
            PT_UpsertVideoSeoArticle($video_id, array(
                'final_article_markdown' => $result['content'],
                'seo_title' => $meta['seo_title'] ?: $article->seo_title,
                'meta_description' => $meta['meta_description'] ?: $article->meta_description,
                'primary_keyword' => $meta['primary_keyword'] ?: $article->primary_keyword,
                'url_slug' => $meta['url_slug'] ?: $article->url_slug,
                'status' => 'complete',
                'error_message' => '',
                'failed_pass' => null,
            ));
            return array('ok' => true, 'status' => 'complete', 'done' => true);

        default:
            if (strpos($status, '_running') !== false) {
                return array('ok' => false, 'error' => 'Pipeline step appears stuck in ' . $status);
            }
            $next = PT_SeoPipelineNextStatus($status);
            if ($next) {
                PT_UpsertVideoSeoArticle($video_id, array('status' => str_replace('_running', '', $next)));
                return PT_SeoPipelineRunSingleStep($video_id);
            }
            return array('ok' => false, 'error' => 'Unknown pipeline status: ' . $status);
    }
}

function PT_ProcessSeoPipelineQueueJob($queue_row) {
    global $db, $pt;
    if (!empty($pt->config->seo_pipeline_system) && $pt->config->seo_pipeline_system !== 'on') {
        $db->where('id', (int) $queue_row->id)->delete(T_SEO_PIPELINE_QUEUE);
        return;
    }
    $video_id = (int) $queue_row->video_id;
    $db->where('id', (int) $queue_row->id)->update(T_SEO_PIPELINE_QUEUE, array('processing' => 1));
    $max_steps = 20;
    $steps = 0;
    $done = false;
    while ($steps < $max_steps && !$done) {
        $result = PT_SeoPipelineRunSingleStep($video_id);
        $steps++;
        if (empty($result['ok'])) {
            break;
        }
        if (!empty($result['done'])) {
            $done = true;
        }
        $article = PT_GetVideoSeoArticle($video_id);
        if (!empty($article) && in_array($article->status, array('complete', 'failed'), true)) {
            $done = true;
        }
    }
    $db->where('id', (int) $queue_row->id)->delete(T_SEO_PIPELINE_QUEUE);
}

function PT_GetSeoPipelineQueueCount() {
    global $db, $pt;
    $limit = !empty($pt->config->seo_pipeline_queue_count) ? (int) $pt->config->seo_pipeline_queue_count : 1;
    return max(1, min(3, $limit));
}

function PT_ListVideosForSeoPipeline($options = array()) {
    global $db;
    $limit = !empty($options['limit']) ? (int) $options['limit'] : 50;
    $offset = !empty($options['offset']) ? (int) $options['offset'] : 0;
    $channel_id = !empty($options['channel_id']) ? (int) $options['channel_id'] : 0;
    $where = "t.status = 'completed' AND t.plain_text IS NOT NULL AND t.plain_text <> ''";
    if ($channel_id > 0) {
        $where .= ' AND v.user_id = ' . $channel_id;
    }
    $sql = "SELECT v.id AS video_id, v.title, v.video_id AS public_id, t.status AS transcript_status,
            a.status AS seo_status, a.seo_title, a.updated_at AS seo_updated
            FROM " . T_VIDEO_TRANSCRIPTS . " t
            INNER JOIN " . T_VIDEOS . " v ON v.id = t.video_id
            LEFT JOIN " . T_VIDEO_SEO_ARTICLES . " a ON a.video_id = t.video_id
            WHERE {$where}
            ORDER BY v.time DESC
            LIMIT {$offset}, {$limit}";
    return $db->rawQuery($sql);
}

function PT_ApproveEntityReviewItem($review_id, $action, $admin_user_id, $extra = array()) {
    global $db;
    $row = $db->where('review_id', PT_Secure($review_id))->getOne(T_ENTITY_REVIEW_QUEUE);
    if (empty($row) || $row->status !== 'pending') {
        return array('ok' => false, 'error' => 'Review item not found or already processed');
    }
    $library = PT_LoadEntityLibrary();
    $name = $row->entity_name;
    $status_map = array(
        'approve_new' => 'approved_new',
        'merge_existing' => 'merged_existing',
        'create_alias' => 'alias_created',
        'ignore' => 'ignored',
        'needs_research' => 'needs_research',
    );
    if (empty($status_map[$action])) {
        return array('ok' => false, 'error' => 'Invalid action');
    }
    if ($action === 'approve_new') {
        $slug = !empty($extra['slug']) ? $extra['slug'] : (!empty($row->suggested_slug) ? $row->suggested_slug : PT_SeoSlugify($name));
        if (isset($library['entities'][$slug])) {
            $slug = $slug . '-' . time();
        }
        $library['entities'][$slug] = array(
            'name' => $name,
            'category' => $row->category ?: 'concepts',
            'slug' => $slug,
            'aliases' => array(),
            'status' => 'active',
        );
        PT_SaveEntityLibrary($library);
    } elseif ($action === 'merge_existing' || $action === 'create_alias') {
        $match = !empty($extra['existing_entity']) ? $extra['existing_entity'] : $row->possible_existing_match;
        $slug = PT_EntityLibraryFindByNameOrAlias($library, $match);
        if ($slug) {
            if ($action === 'create_alias' || $action === 'merge_existing') {
                if (!in_array($name, $library['entities'][$slug]['aliases'], true)) {
                    $library['entities'][$slug]['aliases'][] = $name;
                    PT_SaveEntityLibrary($library);
                }
            }
        }
    }
    $db->where('review_id', $row->review_id)->update(T_ENTITY_REVIEW_QUEUE, array(
        'status' => $status_map[$action],
        'reviewed_at' => time(),
        'reviewed_by' => (int) $admin_user_id,
    ));
    return array('ok' => true);
}

/**
 * Extension point: publish final Markdown to CMS (not implemented).
 */
function PT_SeoPublishArticleToCms($video_id) {
    $article = PT_GetVideoSeoArticle((int) $video_id);
    if (empty($article) || empty($article->final_article_markdown)) {
        return array('ok' => false, 'error' => 'No final article to publish');
    }
    return array('ok' => false, 'error' => 'CMS publishing not configured');
}

function PT_GetDisplayTranscriptForVideo($video_id) {
    $seo = PT_GetVideoSeoArticle($video_id);
    if (!empty($seo) && !empty($seo->cleaned_transcript)) {
        return $seo->cleaned_transcript;
    }
    $row = PT_GetVideoTranscript($video_id);
    return (!empty($row) && !empty($row->plain_text)) ? $row->plain_text : '';
}
