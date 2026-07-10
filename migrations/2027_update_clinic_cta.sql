-- Update clinic CTA copy (select your database first in phpMyAdmin)

UPDATE `config` SET `value` = '<p><strong>Have you or a loved one been diagnosed with cancer?</strong></p><p>At Conners Clinic, we help people look beyond the diagnosis and explore potential underlying factors that may be contributing to their condition. Through personalized coaching, advanced testing, education, programmed rife machines, and custom wellness plans, we work with patients seeking a more comprehensive approach to their health journey.</p><p>Schedule a free 15-minute discovery call with Dr. Conners to learn about your options at <a href="https://www.connersclinic.com" target="_blank" rel="noopener">https://www.connersclinic.com</a></p>'
WHERE `name` = 'clinic_cta_html';
