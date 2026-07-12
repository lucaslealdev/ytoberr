# =============================================================
# Makefile — Shortcuts for local development
# =============================================================

.PHONY: serve queue queue-bg queue-stop migrate cache-clear setup-bins

## Setup binary dependencies (yt-dlp is architecture-independent; ffmpeg/ffprobe are fetched for the current CPU architecture)
setup-bins:
	mkdir -p bin
	curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o bin/yt-dlp
	chmod +x bin/yt-dlp
	arch="$$(uname -m)"; \
	case "$$arch" in \
		x86_64) farch=amd64 ;; \
		aarch64|arm64) farch=arm64 ;; \
		armv7l|armv6l) farch=armhf ;; \
		*) echo "Unsupported architecture for ffmpeg static build: $$arch" >&2; exit 1 ;; \
	esac; \
	curl -L "https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-$${farch}-static.tar.xz" -o ffmpeg.tar.xz
	mkdir -p bin/ffmpeg_temp
	tar -xf ffmpeg.tar.xz -C bin/ffmpeg_temp --strip-components=1
	mv bin/ffmpeg_temp/ffmpeg bin/ffmpeg
	mv bin/ffmpeg_temp/ffprobe bin/ffprobe
	chmod +x bin/ffmpeg bin/ffprobe
	rm -rf bin/ffmpeg_temp ffmpeg.tar.xz

## Run the queue worker
queue:
	php artisan queue:work

## Run the queue worker in the background
queue-bg:
	nohup php artisan queue:work > storage/logs/queue.log 2>&1 & echo $$! > storage/queue.pid

## Stop the background queue worker
queue-stop:
	@if [ -f storage/queue.pid ]; then \
		kill $$(cat storage/queue.pid) && rm storage/queue.pid && echo "Queue worker stopped."; \
	else \
		echo "Queue worker PID file not found."; \
	fi

## Run database migrations
migrate:
	php artisan migrate

## Clear all application caches
cache-clear:
	php artisan optimize:clear
