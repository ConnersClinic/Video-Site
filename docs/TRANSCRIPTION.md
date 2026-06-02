# Video transcription (local Whisper)

Batch transcription for self-hosted videos using [faster-whisper](https://github.com/SYSTRAN/faster-whisper), FFmpeg, and PlayTube’s cron + admin tools.

## Database setup

On an existing site, run once:

```bash
mysql -u USER -p DATABASE < migrations/2026_transcription.sql
```

Fresh installs include the tables in `playtube.sql`.

## Server requirements

- PHP `shell_exec` enabled (same as FFmpeg)
- FFmpeg binary configured in Admin → Import & Upload
- Python 3.10+
- `pip install faster-whisper`

Optional: use a virtualenv and set **Python command** in admin to e.g. `/var/www/venv/bin/python3`.

Test the script manually:

```bash
cd /path/to/site
python3 scripts/transcribe_whisper.py --input /path/to/sample.wav --output_dir /tmp/out --model base --language en
```

## Cron

Add alongside `cronjob.php`:

```cron
*/10 * * * * curl -s https://yoursite.com/transcribe-cron.php >/dev/null
```

## Admin workflow

1. **Admin Panel → Tools → Transcribe Videos**
2. Enable **Transcription system**, set Python path / model / language.
3. Choose a **channel** (user with uploaded videos).
4. Set batch size and optional date filter → **Enqueue batch**.
5. Monitor status on the same page; cron processes the queue.

## What gets transcribed

- Self-hosted files only (`video_location` on disk or remote storage).
- Skips YouTube, Vimeo, Dailymotion, Facebook, Twitch, Instagram, OK embeds.
- Default: `converted = 1`, `active = 1`, `approved = 1`.

## Output

- **WebVTT** at `upload/transcripts/{video_id}.vtt` (uploaded to S3 if configured).
- **Plain text** in `video_transcripts.plain_text` for search/admin.
- Captions on the watch page via `/vtt/{public_video_id}`.

## Troubleshooting

| Issue | Check |
|-------|--------|
| Jobs stay queued | Cron URL reachable; `transcript_system` = on |
| FFmpeg errors | `ffmpeg_binary_file` path; video file exists or S3 URL downloadable |
| Whisper errors | `pip install faster-whisper`; run script manually; inspect `video_transcripts.error_message` |
| No CC on player | Transcript `status = completed`; video is not an embed |
