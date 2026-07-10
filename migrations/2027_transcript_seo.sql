-- SEO summary + description modes for video transcripts (select database first in phpMyAdmin)

ALTER TABLE `video_transcripts`
  ADD COLUMN `seo_summary` mediumtext DEFAULT NULL AFTER `plain_text`,
  ADD COLUMN `description_applied` tinyint(1) NOT NULL DEFAULT '0' AFTER `seo_summary`;

INSERT IGNORE INTO `config` (`name`, `value`) VALUES
('openai_api_key', ''),
('openai_model', 'gpt-4o-mini'),
('clinic_cta_html', '<p><strong>Have you or a loved one been diagnosed with cancer?</strong></p><p>At Conners Clinic, we help people look beyond the diagnosis and explore potential underlying factors that may be contributing to their condition. Through personalized coaching, advanced testing, education, programmed rife machines, and custom wellness plans, we work with patients seeking a more comprehensive approach to their health journey.</p><p>Schedule a free 15-minute discovery call with Dr. Conners to learn about your options at <a href=\"https://www.connersclinic.com\" target=\"_blank\" rel=\"noopener\">https://www.connersclinic.com</a></p>'),
('transcript_description_mode', 'replace_description'),
('openai_summary_prompt', '');
