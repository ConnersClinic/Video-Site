-- Secondary watch-page CTA + updated top strip defaults (optional; PHP has fallbacks)

INSERT IGNORE INTO `config` (`name`, `value`) VALUES
('watch_cta2_headline', 'Want help looking deeper?'),
('watch_cta2_body', 'Schedule a free 15-minute discovery call to learn how Conners Clinic helps patients explore underlying factors through personalized coaching, testing, and education.'),
('watch_cta2_button_text', 'Schedule Free Discovery Call'),
('watch_cta2_button_url', 'https://www.connersclinic.com/schedule-now/'),
('watch_page_cta2_html', '');

UPDATE `config` SET `value` = 'Need personalized guidance?' WHERE `name` = 'watch_cta_headline' AND (`value` IS NULL OR `value` = '' OR `value` = 'Need help understanding what''s driving your health concerns?');
UPDATE `config` SET `value` = 'Explore what may be driving your health concerns with Conners Clinic''s root-cause-focused coaching and testing support.' WHERE `name` = 'watch_cta_body' AND (`value` IS NULL OR `value` = '' OR `value` LIKE 'Conners Clinic helps patients look deeper%');
UPDATE `config` SET `value` = 'Free Discovery Call' WHERE `name` = 'watch_cta_button_text' AND (`value` IS NULL OR `value` = '' OR `value` LIKE 'Schedule a Free%');
