<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

class SeedPlaceholderVideos extends Command
{
    use ConfirmableTrait;

    protected $signature = 'dev:seed-placeholder-videos
        {--count=60 : How many placeholder videos to create}
        {--clear : Remove previously-seeded placeholder channel/videos/files instead of creating more}
        {--force : Skip the production confirmation prompt}';

    protected $description = 'Seed (or --clear) a fake channel with placeholder videos — real thumbnail images, varied file sizes and publish dates — for previewing video-listing UI (e.g. the Cleaning page) without needing real downloaded content.';

    /**
     * Every channel/video this command creates uses this name/youtube_id prefix, so --clear
     * can find and remove exactly (and only) what it seeded, never a real channel or video
     * that happens to share a similar name.
     */
    private const PLACEHOLDER_CHANNEL_NAME = 'Placeholder Demo Channel';

    private const PLACEHOLDER_YOUTUBE_ID_PREFIX = 'placeholder_demo_vid_';

    /**
     * @var array<int, string>
     */
    private const TITLE_TEMPLATES = [
        '4K Nature Documentary - Full Episode',
        'Live Concert Recording - Full Show',
        'Building a Cabin From Scratch - Complete Timelapse',
        'Flagship Phone Review - 3 Months Later',
        'Mountain Biking Trail Compilation - Best Runs',
        'Building the Ultimate Gaming PC',
        'Deep Sea Exploration Highlights',
        'Smart Home Setup Walkthrough',
        'Fishing Trip Vlog',
        'Unboxing the New Wireless Earbuds',
        'Camping Gear Essentials for Winter',
        'Quick Tech Tips - Battery Life Hacks',
    ];

    public function handle(): int
    {
        if (! $this->confirmToProceed('This writes demo data to the database configured in .env.')) {
            return 1;
        }

        return $this->option('clear') ? $this->clear() : $this->seed();
    }

    private function seed(): int
    {
        $count = max(1, (int) $this->option('count'));
        $downloadsDir = Setting::getStoragePath();

        $channel = Channel::firstOrCreate(
            ['youtube_id' => 'UC_placeholder_demo'],
            ['name' => self::PLACEHOLDER_CHANNEL_NAME, 'url' => 'https://example.com/placeholderdemo']
        );

        $channelDir = $downloadsDir.'/'.$channel->name.'/Season 2026';
        if (! is_dir($channelDir)) {
            mkdir($channelDir, 0755, true);
        }

        for ($i = 1; $i <= $count; $i++) {
            $youtubeId = self::PLACEHOLDER_YOUTUBE_ID_PREFIX.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $title = self::TITLE_TEMPLATES[($i - 1) % count(self::TITLE_TEMPLATES)].' #'.$i;

            $relativeVideoPath = $channel->name.'/Season 2026/'.$youtubeId.'.mp4';
            $relativeThumbPath = $channel->name.'/Season 2026/'.$youtubeId.'.jpg';

            if (! file_exists($downloadsDir.'/'.$relativeVideoPath)) {
                file_put_contents($downloadsDir.'/'.$relativeVideoPath, 'placeholder video bytes');
            }

            if (! file_exists($downloadsDir.'/'.$relativeThumbPath)) {
                $this->writePlaceholderThumbnail($downloadsDir.'/'.$relativeThumbPath);
            }

            // Spreads publish dates from ~2 years ago up to recent, so both the "biggest" and
            // "oldest" sort orders (and their limits) have enough spread to preview against.
            $daysAgo = (int) (730 - ($i * (700 / $count)));

            Video::updateOrCreate(
                ['youtube_id' => $youtubeId],
                [
                    'channel_id' => $channel->id,
                    'title' => $title,
                    'published_at' => now()->subDays($daysAgo),
                    'status' => 'completed',
                    'file_path' => $relativeVideoPath,
                    'file_size' => random_int(50, 4000) * 1_000_000,
                    'thumbnail_path' => $relativeThumbPath,
                    'downloaded_at' => now()->subDays($daysAgo)->addHour(),
                ]
            );
        }

        $this->info("Seeded {$count} placeholder video(s) under the \"{$channel->name}\" channel.");
        $this->line('Run with --clear to remove them again.');

        return 0;
    }

    private function clear(): int
    {
        $downloadsDir = Setting::getStoragePath();

        $videoCount = Video::where('youtube_id', 'like', self::PLACEHOLDER_YOUTUBE_ID_PREFIX.'%')->count();
        Video::where('youtube_id', 'like', self::PLACEHOLDER_YOUTUBE_ID_PREFIX.'%')->delete();

        $channelCount = Channel::where('name', self::PLACEHOLDER_CHANNEL_NAME)->count();
        Channel::where('name', self::PLACEHOLDER_CHANNEL_NAME)->delete();

        $dir = $downloadsDir.'/'.self::PLACEHOLDER_CHANNEL_NAME;
        if (is_dir($dir)) {
            exec('rm -rf '.escapeshellarg($dir));
        }

        $this->info("Removed {$videoCount} placeholder video(s) and {$channelCount} placeholder channel(s).");

        return 0;
    }

    private function writePlaceholderThumbnail(string $path): void
    {
        $image = imagecreatetruecolor(320, 180);
        $backgroundColor = imagecolorallocate($image, random_int(30, 90), random_int(30, 90), random_int(30, 90));
        imagefill($image, 0, 0, $backgroundColor);
        $textColor = imagecolorallocate($image, 230, 230, 230);
        imagestring($image, 5, 90, 82, 'PLACEHOLDER', $textColor);
        imagejpeg($image, $path, 80);
        imagedestroy($image);
    }
}
