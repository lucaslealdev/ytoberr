<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class PlexAssetService
{
    /**
     * Ensure tvshow.nfo and channel art (poster/fanart/banner) exist in the Plex show folder,
     * copied from the internally stored channel images.
     */
    public function syncChannelAssets(Channel $channel, string $channelDir): void
    {
        if (! file_exists($channelDir)) {
            mkdir($channelDir, 0755, true);
        }

        // poster.jpg/banner.jpg/fanart.jpg sitting directly in the show folder are what Plex's
        // "Local Media Assets" agent actually reads; tvshow.nfo intentionally carries no
        // <thumb>/<fanart> references to them (Plex's own NFO schema doesn't include any either).
        foreach (['poster', 'fanart', 'banner'] as $art) {
            $sourcePath = 'channels/'.$channel->id.'/'.$art.'.jpg';
            if (Storage::disk('public')->exists($sourcePath)) {
                copy(Storage::disk('public')->path($sourcePath), $channelDir.'/'.$art.'.jpg');
            }
        }

        $this->writeTvShowNfo($channel, $channelDir.'/tvshow.nfo');
    }

    /**
     * Write a per-video .nfo file next to the downloaded video, using the
     * Season {year} / Episode {monthDay} convention.
     */
    public function writeVideoNfo(Video $video, string $path, int $year, string $monthDay): void
    {
        $xml = new \SimpleXMLElement('<episodedetails></episodedetails>');
        $xml->addChild('title', $video->title);
        $xml->addChild('plot', $video->description ?? '');
        $xml->addChild('aired', Carbon::parse($video->published_at)->toDateString());
        $xml->addChild('season', (string) $year);
        $xml->addChild('episode', $monthDay);
        $uniqueId = $xml->addChild('uniqueid', $video->youtube_id);
        $uniqueId->addAttribute('type', 'youtube');

        file_put_contents($path, $xml->asXML());
    }

    private function writeTvShowNfo(Channel $channel, string $path): void
    {
        $xml = new \SimpleXMLElement('<tvshow></tvshow>');
        $xml->addChild('title', $channel->name);
        $xml->addChild('plot', $channel->description ?? '');

        file_put_contents($path, $xml->asXML());
    }
}
