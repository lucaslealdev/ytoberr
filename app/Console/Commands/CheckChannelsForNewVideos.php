<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Video;
use App\Services\YtDlpWrapper;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:check-channels {--channel= : Only check the given channel ID instead of every channel}')]
#[Description('Check for new videos in configured channels and queue downloads')]
class CheckChannelsForNewVideos extends Command
{
    public function handle()
    {
        $this->info('Checking for new videos...');
        $ytDlp = config('services.ytdlp_path', base_path('bin/yt-dlp'));
        $wrapper = app(YtDlpWrapper::class);

        $channels = $this->option('channel')
            ? Channel::where('id', $this->option('channel'))->get()
            : Channel::all();

        foreach ($channels as $channel) {
            $this->info("Checking channel: {$channel->name}");

            // 1. Pré-check de live_status (baseado no Pinchflat) usando o Wrapper
            $metadata = $wrapper->getMetadata($channel->url, ['live_status'], ['--playlist-items 1']);

            if ($metadata) {
                $liveStatus = $metadata['live_status'] ?? null;

                if (in_array($liveStatus, ['is_live', 'is_upcoming', 'post_live'])) {
                    $this->warn("Skipping channel {$channel->name} due to live_status: {$liveStatus}");

                    continue;
                }
            }

            // 2. Fetch new videos (IDs)
            $output = [];
            $resultCode = 0;
            // Use --ignore-errors and -j to get metadata of the last 10 videos including 'was_live'
            $command = escapeshellarg($ytDlp).' --ignore-errors -j --playlist-items :10 '.escapeshellarg($channel->url).' 2>&1';
            exec($command, $output, $resultCode);

            $processedCount = 0;
            foreach ($output as $jsonLine) {
                $metadata = json_decode($jsonLine, true);
                if (! $metadata) {
                    continue; // Pula avisos, erros ou linhas de log em formato de texto comum
                }

                $videoId = $metadata['id'] ?? null;
                $wasLive = $metadata['was_live'] ?? false;

                if ($wasLive) {
                    $this->info("Skipping video {$videoId}: Originated from a live stream.");

                    continue;
                }

                // Verifica se o vídeo foi publicado antes da data de corte (cutoff_date) do canal
                $publishedAt = isset($metadata['upload_date'])
                    ? Carbon::parse($metadata['upload_date'])
                    : now();

                if ($channel->cutoff_date && $publishedAt->lt(Carbon::parse($channel->cutoff_date))) {
                    $this->info("Skipping video {$videoId}: published before channel cut-off date ({$channel->cutoff_date}).");

                    continue;
                }

                if (! empty($videoId) && ! Video::where('youtube_id', $videoId)->exists()) {
                    $this->info("New video found: {$videoId}. Adding to database download queue.");
                    Video::create([
                        'channel_id' => $channel->id,
                        'youtube_id' => $videoId,
                        'title' => $metadata['title'] ?? 'Unknown Title',
                        'description' => $metadata['description'] ?? null,
                        'published_at' => $publishedAt->toDateTimeString(),
                        'status' => 'pending',
                    ]);
                }
                $processedCount++;
            }

            if ($processedCount === 0 && $resultCode !== 0) {
                $this->error("Failed to check channel: {$channel->name}");
            }
        }
        $this->info('Done.');
    }
}
