-- SEO content pipeline (post-transcription article generation)
-- Run after migrations/2027_transcript_seo.sql

CREATE TABLE IF NOT EXISTS `video_seo_articles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `video_id` int(11) NOT NULL,
  `raw_transcript` mediumtext DEFAULT NULL,
  `cleaned_transcript` mediumtext DEFAULT NULL,
  `transcript_quality` text DEFAULT NULL,
  `transcript_version` int(11) NOT NULL DEFAULT 1,
  `pass_0_prompt_version` int(11) NOT NULL DEFAULT 1,
  `editorial_standards_version` int(11) NOT NULL DEFAULT 1,
  `entity_extraction_output` mediumtext DEFAULT NULL,
  `entity_comparison_output` mediumtext DEFAULT NULL,
  `entity_review_queue_output` mediumtext DEFAULT NULL,
  `seo_blueprint` mediumtext DEFAULT NULL,
  `draft_article_markdown` mediumtext DEFAULT NULL,
  `seo_audit` mediumtext DEFAULT NULL,
  `final_article_markdown` mediumtext DEFAULT NULL,
  `seo_title` varchar(500) DEFAULT NULL,
  `meta_description` varchar(500) DEFAULT NULL,
  `primary_keyword` varchar(255) DEFAULT NULL,
  `url_slug` varchar(255) DEFAULT NULL,
  `status` varchar(64) NOT NULL DEFAULT 'transcript_ready',
  `failed_pass` varchar(64) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `failed_raw_response` mediumtext DEFAULT NULL,
  `prompt_versions` text DEFAULT NULL,
  `standards_versions` text DEFAULT NULL,
  `created_at` int(11) NOT NULL DEFAULT 0,
  `updated_at` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `video_id` (`video_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `seo_pipeline_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `video_id` int(11) NOT NULL,
  `processing` tinyint(1) NOT NULL DEFAULT 0,
  `rerun_from` varchar(32) DEFAULT NULL,
  `priority` int(11) NOT NULL DEFAULT 0,
  `created_at` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `processing` (`processing`),
  KEY `video_id` (`video_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `seo_pipeline_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `video_id` int(11) NOT NULL DEFAULT 0,
  `pass_name` varchar(64) NOT NULL DEFAULT '',
  `model_used` varchar(64) DEFAULT NULL,
  `input_tokens` int(11) DEFAULT NULL,
  `output_tokens` int(11) DEFAULT NULL,
  `started_at` int(11) NOT NULL DEFAULT 0,
  `completed_at` int(11) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT '',
  `error_message` text DEFAULT NULL,
  `raw_response` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `video_id` (`video_id`),
  KEY `pass_name` (`pass_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `entity_review_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `review_id` varchar(64) NOT NULL,
  `video_id` int(11) NOT NULL DEFAULT 0,
  `entity_name` varchar(255) NOT NULL DEFAULT '',
  `category` varchar(64) DEFAULT NULL,
  `priority` varchar(32) DEFAULT NULL,
  `recommended_action` varchar(64) DEFAULT NULL,
  `confidence` decimal(5,4) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `possible_existing_match` varchar(255) DEFAULT NULL,
  `suggested_slug` varchar(255) DEFAULT NULL,
  `trigger_phrases` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `created_at` int(11) NOT NULL DEFAULT 0,
  `reviewed_at` int(11) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `review_id` (`review_id`),
  KEY `status` (`status`),
  KEY `video_id` (`video_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `config` (`name`, `value`) VALUES
('seo_pipeline_system', 'on'),
('seo_pipeline_queue_count', '1'),
('seo_pipeline_max_concurrent', '1'),
('seo_pipeline_cron_last_run', '0');
