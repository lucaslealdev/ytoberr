<?php

namespace App\Console\Commands;

use App\Services\BannedVideoCheckService;
use Illuminate\Console\Command;

class CheckBannedVideos extends Command
{
    protected $signature = 'videos:check-banned-videos';

    protected $description = 'Process the next batch of an in-progress banned-videos check (armed from Settings), a no-op if none is running. See BannedVideoCheckService.';

    public function handle(BannedVideoCheckService $bannedVideoCheck)
    {
        if (! $bannedVideoCheck->isRunning()) {
            return 0;
        }

        $this->info('Checking next batch of videos for bans...');

        $bannedVideoCheck->processNextBatch();

        return 0;
    }
}
