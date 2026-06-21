# Video board — YouTube on headless servers

The video board downloads with **yt-dlp** and plays from `./videos/` — no live embed. Headless servers often hit YouTube bot checks; this guide covers the fix.

## Basics

- **Registry:** `youtube` URL or local `file` → `video.php?v=<key>`
- **Refresh:** admin → **Video Board → Download / refresh**, or `php video.php fetch`
- **Sound:** muted by default; kiosks from `setup-kiosk.sh` allow unmuted autoplay
- **Cron (optional):** `0 4 * * 1 cd /var/www/boards && php video.php fetch >> /var/log/video-fetch.log 2>&1`
- **yt-dlp updates:** admin shows installed vs latest GitHub release

## When YouTube blocks the server

Symptom: “Sign in to confirm you're not a bot” or fetch failures.

### 1. Install deno

`setup-server.sh` installs **deno** — yt-dlp needs a JS runtime for YouTube.

### 2. Export Netscape cookies

While logged into YouTube in a desktop browser:

- **Chrome:** `yt-dlp --cookies-from-browser chrome` often fails (cookie encryption). Use extension **Get cookies.txt LOCALLY** → export for youtube.com → save as `cookies.txt`
- **Firefox:** cookie export extensions work well

### 3. Test locally before uploading

Requires **yt-dlp 2025.10+** (Homebrew apt builds are often older):

```bash
brew install deno
brew upgrade yt-dlp   # or: pip3 install -U "yt-dlp[default]"
yt-dlp --js-runtimes deno --remote-components ejs:github \
  --cookies cookies.txt -F 'https://www.youtube.com/watch?v=VIDEO_ID'
```

You must see **mp4/webm** format rows (720p, 1080p, …) — not only `sb0` storyboard lines.

If `no such option: --js-runtimes`, upgrade yt-dlp via pip or the [GitHub release binary](https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp).

### 4. Install cookies on the server

```bash
scp cookies.txt server:/var/www/html/boards/config/cookies/youtube.txt
sudo chown www-data:www-data /var/www/html/boards/config/cookies/youtube.txt
sudo chmod 640 /var/www/html/boards/config/cookies/youtube.txt
```

Re-export when fetches fail again.

### 5. Fallback — local file

Download on desktop:

```bash
yt-dlp -o lantern.mp4 'https://www.youtube.com/watch?v=VIDEO_ID'
scp lantern.mp4 server:/var/www/html/boards/videos/
```

Set the video registry entry to **local file** `lantern.mp4` — no cookies needed on the server.

## Requirements summary

| Component | Purpose |
|-----------|---------|
| `yt-dlp` | Download (PATH or `bin/yt-dlp`) |
| **deno** (or node) | YouTube JS challenge |
| `config/cookies/youtube.txt` | Optional; bot bypass |
| **ffmpeg** / **ffprobe** | Merged downloads, duration readouts |

Videos live in `./videos/` inside the webroot for range-request streaming.
