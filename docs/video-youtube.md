# Video board — YouTube on headless servers

The video board downloads on-demand videos with **yt-dlp** and plays from `./videos/` — no live embed for regular uploads. **YouTube live** streams can embed directly in the browser (see [Live streams](#live-streams)). Headless servers often hit YouTube bot checks on downloads; this guide covers the fix.

## Basics

- **Registry:** `youtube` URL or local `file` → `video.php?v=<key>`
- **Live streams:** check **Live stream** in admin (or paste `youtube.com/live/…`) — embeds without download
- **Refresh:** admin → **Video Board → Download / refresh**, or `php video.php fetch`
- **Sound:** muted by default; kiosks from `setup-kiosk.sh` allow unmuted autoplay
- **Cron (optional):** `0 4 * * 1 cd /var/www/boards && php video.php fetch >> /var/log/video-fetch.log 2>&1`
- **yt-dlp updates:** admin shows installed vs latest GitHub release

## Live streams

Use this for ongoing broadcasts (news, events, city cams on YouTube) where downloading makes no sense.

1. **Admin → Video Board** — add a playlist entry with a unique key.
2. Paste the YouTube URL, e.g. `https://www.youtube.com/live/awQzjn72bI0` or any watch/youtu.be link.
3. Check **Live stream (embed, no download)** — auto-checked when the URL contains `/live/`.
4. Save, then add `video.php?v=KEY` to rotation (or use the deploy checkbox).

**Rotation dwell** comes from **Live stream dwell** in board settings (default **300 s**), not file length. Tune it for how long each live slot should stay on the wall.

**Notes:**

- Live embeds need outbound HTTPS from the kiosk to YouTube (unlike downloaded files).
- Some streams block embedding — preview the entry before adding to rotation.
- Leave **Mute all videos** checked unless the kiosk is set up for unmuted autoplay.
- **Rotation sync:** the embed does not load until the slot is shown (avoids preload lag). Each time the slot returns, the iframe reloads to the live edge; while on screen it re-syncs about every **8 minutes** on long dwells.
- YouTube’s player still runs **10–30 seconds behind true live** — normal for embeds, not a signage bug.

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
