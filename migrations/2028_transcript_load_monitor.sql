-- Transcription server load monitor config (run once on existing installs)

INSERT IGNORE INTO `config` (`name`, `value`) VALUES
('transcript_load_monitor', 'on'),
('transcript_load_alert_email', ''),
('transcript_load_warn_per_cpu', '0.85'),
('transcript_load_crit_per_cpu', '1.25'),
('transcript_load_email_cooldown', '3600'),
('transcript_load_poll_seconds', '30'),
('transcript_load_history', '[]'),
('transcript_load_history_last', ''),
('transcript_load_last_alert', '');
