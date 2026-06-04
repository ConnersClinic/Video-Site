-- AI key takeaways for processed transcripts (select database first in phpMyAdmin)

ALTER TABLE `video_transcripts`
  ADD COLUMN `key_takeaways` JSON DEFAULT NULL AFTER `seo_summary`;
