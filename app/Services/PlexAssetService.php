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
        if (!file_exists($channelDir)) {
            mkdir($channelDir, 0755, true);
        }

        $copiedArt = [];
        foreach (['poster', 'fanart', 'banner'] as $art) {
            $sourcePath = 'channels/' . $channel->id . '/' . $art . '.jpg';
            if (Storage::disk('public')->exists($sourcePath)) {
                copy(Storage::disk('public')->path($sourcePath), $channelDir . '/' . $art . '.jpg');
                $copiedArt[] = $art;
            }
        }

        $this->writeTvShowNfo($channel, $channelDir . '/tvshow.nfo', $copiedArt);
    }

    /**
     * Write a per-video .nfo file next to the downloaded video, using the
     * Season {year} / Episode {monthDay} convention.
     */
    public function writeVideoNfo(Video $video, string $path, int $year, string $monthDay, ?string $thumbFilename = null): void
    {
        $xml = new \SimpleXMLElement('<episodedetails></episodedetails>');
        $xml->addChild('title', $video->title);
        $xml->addChild('plot', $video->description ?? '');
        $xml->addChild('aired', Carbon::parse($video->published_at)->toDateString());
        $xml->addChild('season', (string) $year);
        $xml->addChild('episode', $monthDay);
        if ($thumbFilename) {
            $xml->addChild('thumb', $thumbFilename);
        }
        $uniqueId = $xml->addChild('uniqueid', $video->youtube_id);
        $uniqueId->addAttribute('type', 'youtube');

        file_put_contents($path, $xml->asXML());
    }

    /**
     * @param string[] $art Which of poster/fanart/banner were actually copied alongside this nfo.
     */
    private function writeTvShowNfo(Channel $channel, string $path, array $art = []): void
    {
        $xml = new \SimpleXMLElement('<tvshow></tvshow>');
        $xml->addChild('title', $channel->name);
        $xml->addChild('plot', $channel->description ?? '');

        if (in_array('poster', $art)) {
            $xml->addChild('thumb', 'poster.jpg')->addAttribute('aspect', 'poster');
        }
        if (in_array('banner', $art)) {
            $xml->addChild('thumb', 'banner.jpg')->addAttribute('aspect', 'banner');
        }
        if (in_array('fanart', $art)) {
            $xml->addChild('fanart')->addChild('thumb', 'fanart.jpg');
        }

        file_put_contents($path, $xml->asXML());
    }
}
