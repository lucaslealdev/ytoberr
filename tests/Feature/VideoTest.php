<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_formatted_duration_formats_seconds_as_hms_or_ms()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_duration_fmt_chan',
            'name' => 'Duration Format Channel',
            'url' => 'https://example.com/durationformat',
        ]);

        $cases = [
            [45, '0:45'],
            [125, '2:05'],
            [3661, '1:01:01'],
            [null, null],
        ];

        foreach ($cases as $i => [$seconds, $expected]) {
            $video = Video::create([
                'channel_id' => $channel->id,
                'youtube_id' => 'duration_fmt_vid_'.$i,
                'title' => 'Duration Format Test',
                'published_at' => now(),
                'duration' => $seconds,
            ]);

            $this->assertSame($expected, $video->formattedDuration(), "Failed for duration={$seconds}");
        }
    }
}
