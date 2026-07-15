# Ytoberr

Self-hosted web dashboard for local archiving and automated monitoring of YouTube channels.

## 🚀 Features

- **Automated Monitoring:** Periodically checks for new uploads.
- **Organized Archiving:** Downloaded videos are organized into directories: `{channel}/{year}/{month}/{video}.{ext}` (file saved exactly as downloaded by yt-dlp, without reprocessing).
- **Plex Compatibility:** Plex-friendly naming with thumbnails saved as companion files (`{video}-thumb.jpg`).
- **Quality Management:** Per-channel download quality setting.

## 🛠️ Tech Stack

- **Backend:** Laravel 13.x (PHP 8.4)
- **Database:** SQLite
- **Download:** `yt-dlp` (`ffmpeg` is only used internally by yt-dlp to merge separate audio/video formats; the application never invokes ffmpeg directly). yt-dlp also needs a JavaScript runtime to reliably resolve YouTube's signature challenges — without one, some videos fail to download or only degraded formats are available. The Docker image bundles Node.js >= 22 for this; `make setup-bins` additionally fetches `deno` for non-Docker installs on glibc-based Linux (deno's official build doesn't support musl systems like Alpine, so it's skipped there automatically — install Node.js >= 22 yourself in that case).

## ⚙️ Installation

1. Clone the repository.
2. Install dependencies: `composer install`.
3. Configure the `.env` file (based on `.env.example`).
4. Run migrations: `php artisan migrate`.
5. Process pending one-time data migrations: `php artisan operations:process`.
6. Download and set up the binary dependencies (`yt-dlp`, `ffmpeg`, `ffprobe`, and `deno` on compatible systems): `make setup-bins`.

## 🖥️ Development Commands (Makefile)

The project includes a `Makefile` with useful shortcuts:

- `make setup-bins`: Downloads and sets up the binary dependencies (`yt-dlp`, `ffmpeg`, `ffprobe`, and `deno` on glibc-based systems) into the `bin/` folder.
- `make serve`: Starts the development server.
- `make queue-bg`: Starts the queue worker in the background.
- `make queue-stop`: Stops the queue worker.
- `make migrate`: Runs migrations.
- `make cache-clear`: Clears Laravel caches.

## 🐳 Docker (Recommended)

The simplest way to install and run Ytoberr is via Docker. The image already includes the Laravel scheduler's cron, the queue worker, a bundled Node.js runtime (yt-dlp's JavaScript engine — see Tech Stack above), and automatically downloads `yt-dlp`/`ffmpeg`/`ffprobe` on first run, in addition to running migrations automatically.

A ready-to-use image (multi-architecture, `amd64`/`arm64`) is automatically published to the GitHub Container Registry on every push to `main` (tag `latest`) and on every `vX.Y.Z` release (version tags), via [`.github/workflows/docker-publish.yml`](.github/workflows/docker-publish.yml). To use it without cloning the repository, simply:

```bash
docker run -d --name ytoberr -p 8080:8080 \
  -v ytoberr-storage:/var/www/html/storage/app \
  -v ytoberr-bin:/var/www/html/bin \
  ghcr.io/lucaslealdev/ytoberr:latest
```

If you'd rather clone the repository and use Docker Compose (builds locally by default; use `YTOBERR_IMAGE=ghcr.io/lucaslealdev/ytoberr:latest docker compose up -d` to pull the published image instead of building):

```bash
docker compose up -d --build
```

The application comes up at `http://localhost:8080` (adjustable via `APP_PORT`/`APP_URL`).

Persistent data (defined in `docker-compose.yml`):

- `ytoberr-storage` (named volume): SQLite database (`storage/app/database.sqlite`), videos, thumbnails, and other generated files (`storage/app`). It's all in a single purpose-built volume because SQLite lives inside `storage/app` — there's no separate volume for the database.
- `./bin` (bind mount to the project's `bin/` folder): if `yt-dlp`/`ffmpeg`/`ffprobe` already exist there (e.g., from a previous local `make setup-bins`), the container reuses them directly without downloading anything. If any of them is missing, the container downloads all three automatically and keeps them there for future startups. Node.js (yt-dlp's JS runtime) is bundled in the image itself rather than fetched here, since deno — what `make setup-bins` fetches on non-Docker installs — doesn't run on this image's musl-based Alpine base.

Useful variables (can be set in a `.env` next to `docker-compose.yml` or exported in the shell):

- `APP_URL` / `APP_PORT`: the application's public URL and port (defaults to `http://localhost:8080` / `8080`).
- `APP_KEY`: Laravel's encryption key. If omitted, it's generated automatically on first boot (recommended to pin it so it persists across container recreations).
- `TZ`: the container's timezone (default `UTC`).

Without Docker Compose, the same result can be achieved with:

```bash
docker build -t ytoberr .
docker run -d --name ytoberr -p 8080:8080 \
  -v ytoberr-storage:/var/www/html/storage/app \
  -v "$(pwd)/bin:/var/www/html/bin" \
  ytoberr
```

## 🏷️ Versioning

The project follows [Semantic Versioning](https://semver.org/) (`MAJOR.MINOR.PATCH`). The current version lives in the [`VERSION`](VERSION) file at the project root and is displayed in the dashboard's footer.

To release a new version:

1. Update the `VERSION` file (e.g., `1.1.0`).
2. Commit and create a matching git tag with the `v` prefix:
   ```bash
   git commit -am "chore: bump version to 1.1.0"
   git tag v1.1.0
   git push origin main --tags
   ```
3. Pushing the `vX.Y.Z` tag triggers the [Docker workflow](.github/workflows/docker-publish.yml), which publishes `ghcr.io/lucaslealdev/ytoberr:1.1.0` (and updates `:latest`, since the tag lands on `main`).

## 📅 Scheduling & Queues (Production without Docker)

If you'd rather run outside Docker, manually configure the services below to ensure Ytoberr continuously monitors for new videos and processes downloads in the background (when using Docker, both are already configured inside the container):

### 1. Task Scheduler (Cron Job)
Laravel uses a single Cron Job to manage all internal scheduling (such as checking for new videos every 3 hours).
Open the Linux crontab (`crontab -e`) and add the following line:

```bash
* * * * * cd /home/lucas/ytoberr && php artisan schedule:run >> /dev/null 2>&1
```

*(Replace `/home/lucas/ytoberr` with the correct absolute path of your project installation).*

### 2. Queue Processing (Queue Worker)
Heavy video and thumbnail downloads are dispatched to background queues so they don't block the web interface.

*   **Development:** Use `make queue-bg` to start the worker in the background and `make queue-stop` to stop it.
*   **Production (Supervisor):** It's highly recommended to run the **Supervisor** process manager to keep the queue worker constantly active and automatically restart it if it fails.

Example Supervisor configuration (`/etc/supervisor/conf.d/ytoberr-worker.conf`):

```ini
[program:ytoberr-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /home/lucas/ytoberr/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=lucas
numprocs=1
redirect_stderr=true
stdout_logfile=/home/lucas/ytoberr/storage/logs/worker.log
stopwaitsecs=3600
```

*(Adjust the `user`, absolute paths, and logs according to your server's environment permissions).*

## ⚡ Performance: Concurrent Access & Video Streaming

The web server (both in Docker and in a manual `php artisan serve` setup) runs on a fixed pool of workers, sized by `PHP_CLI_SERVER_WORKERS` (default `12`, set in the Dockerfile/`.env.example`). Every request — including large video files streamed by the player/download routes — occupies one worker for the whole duration, and a single video being watched or downloaded can open several concurrent Range requests while scrubbing.

For a self-hosted/small-household install this is usually plenty, but if you expect several people to browse the dashboard while others are watching or downloading videos at the same time, consider:

- Raising `PHP_CLI_SERVER_WORKERS` further (each worker adds some memory overhead).
- Fronting the container with a real reverse proxy (nginx, Caddy, Traefik, etc.), which can serve large files far more efficiently than tying up a PHP worker for the whole transfer.

## 🙏 Credits

This project only exists by standing on the shoulders of giants:

- **[yt-dlp](https://github.com/yt-dlp/yt-dlp)** — the download and metadata extraction engine behind all archiving.
- **[Pinchflat](https://github.com/kieraneglin/pinchflat)** — direct reference for architecture and conventions (Plex-compatible file naming, NFO generation, episode numbering by date/index, among others).

## 📄 License

This project is open-sourced under the [MIT license](https://opensource.org/licenses/MIT).
