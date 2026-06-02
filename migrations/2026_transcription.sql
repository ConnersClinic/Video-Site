-- Video transcription tables and config (run once on existing installs)

CREATE TABLE IF NOT EXISTS `transcript_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `video_id` int(11) NOT NULL DEFAULT '0',
  `processing` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `video_id` (`video_id`),
  KEY `processing` (`processing`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `video_transcripts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `video_id` int(11) NOT NULL DEFAULT '0',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `plain_text` mediumtext,
  `vtt_path` varchar(500) NOT NULL DEFAULT '',
  `language` varchar(10) NOT NULL DEFAULT 'en',
  `error_message` text,
  `attempts` tinyint(3) NOT NULL DEFAULT '0',
  `created_at` int(11) NOT NULL DEFAULT '0',
  `updated_at` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `video_id` (`video_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `config` (`name`, `value`) VALUES
('transcript_system', 'off'),
('whisper_command', 'python3'),
('whisper_script', 'scripts/transcribe_whisper.py'),
('whisper_model', 'base'),
('transcript_queue_count', '1'),
('transcript_max_duration', '7200'),
('transcript_language', 'en'),
('transcript_cron_last_run', '');
