# Video transcription (local Whisper + OpenAI SEO)

Batch transcription for self-hosted videos, optional SEO summaries via OpenAI, and watch-page **About / Transcript** tabs.

## Database setup

Run once in phpMyAdmin (**select database `3595_connersclinic` first**):

1. `migrations/2026_transcription.sql` — queue + transcript tables  
2. `migrations/2027_transcript_seo.sql` — `seo_summary`, description modes, OpenAI config  

Fresh installs include both in `playtube.sql`.

## Server requirements

| Component | Requirement |
|-----------|-------------|
| Whisper (audio → text) | Python 3, `faster-whisper`, `shell_exec`, FFmpeg — typically needs SSH |
| OpenAI (SEO summary) | PHP `curl` + outbound HTTPS — often works on shared hosting |
| Cron | `transcribe-cron.php` on a schedule you choose (often every 2–5 minutes) |

```cron
# Typical when each batch finishes in a few minutes (3 jobs/run, sequential):
*/2 * * * * curl -s https://yoursite.com/transcribe-cron.php >/dev/null

# Lighter load:
*/5 * * * * curl -s https://yoursite.com/transcribe-cron.php >/dev/null
```

There is no minimum interval in code. Avoid `* * * * *` (every minute) unless the server can handle overlapping runs while Whisper is still busy.

## Admin workflow

**Admin Panel → Tools → Transcribe Videos**

1. Enable **Transcription system**; configure Whisper/Python if available on server.  
2. Set **OpenAI API key** and model (`gpt-4o-mini` recommended).  
3. Set **Clinic CTA** HTML and **description mode** (see below).  
4. **Test OpenAI** to confirm API access.  
5. Enqueue videos by **channel**; cron runs Whisper.  
6. After transcripts complete: **Generate SEO summaries** (OpenAI batch).  
7. Optionally **Apply descriptions** for rows that already have summaries.

## Description modes

| Mode | `videos.description` in database |
|------|----------------------------------|
| `replace_description` | Summary + clinic CTA only (replaces generic CTA text) |
| `append_summary` | Prepends summary + CTA above existing description |
| `display_only` | Unchanged; tabs on watch page only |

Full transcript is **never** written to `videos.description`.

## Watch page layout

- **About tab:** SEO summary → clinic CTA → (legacy description if no summary yet)  
- **Transcript tab:** Full plain-text transcript (when transcription completed)  
- **Captions:** WebVTT at `/vtt/{public_video_id}`  

Meta / Open Graph descriptions use the SEO summary when available (~220 chars).

## Server load monitoring

**Admin Panel → Tools → Transcribe Videos** includes a **Server load** panel (auto-refresh) and optional **email alerts** when 1-minute load per CPU exceeds your thresholds (default warning 0.85, critical 1.25).

- Samples are recorded at most every **2 minutes** (transcription cron or admin refresh).
- Alerts use the site SMTP settings; set **Alert email** or leave blank for the main site email.
- **Send test alert email** verifies delivery without waiting for high load.

Config keys are in `migrations/2028_transcript_load_monitor.sql` for existing installs.

## Troubleshooting

| Issue | Check |
|-------|--------|
| `#1046 No database selected` | Click database in phpMyAdmin sidebar before SQL |
| Jobs stay queued | Cron URL; `transcript_system` = on |
| No SEO summary | OpenAI key; **Test OpenAI**; `video_transcripts.error_message` |
| Tabs not showing | Transcript `status = completed`; deploy latest theme files |
| Description not updated | Mode not `display_only`; run **Apply descriptions** |
