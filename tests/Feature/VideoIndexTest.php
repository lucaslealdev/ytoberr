<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_videos_index_shows_title_channel_and_links_to_show_page()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_index_chan',
            'name' => 'Index Channel',
            'url' => 'https://example.com/index',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'index_vid',
            'title' => 'Index Test Video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/videos');

        $response->assertStatus(200);
        $response->assertSee('Index Test Video');
        $response->assertSee('Index Channel');
        $response->assertSee('/videos/'.$video->id, false);
    }

    public function test_videos_index_shows_empty_state()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/videos');

        $response->assertStatus(200);
        $response->assertSee('No videos archived yet.');
    }

    public function test_videos_index_paginates_at_12_per_page()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_paginate_chan',
            'name' => 'Paginate Channel',
            'url' => 'https://example.com/paginate',
        ]);

        for ($i = 1; $i <= 15; $i++) {
            Video::create([
                'channel_id' => $channel->id,
                'youtube_id' => 'paginate_vid_'.$i,
                'title' => 'Paginate Video '.$i,
                'published_at' => now()->subMinutes($i),
                'status' => 'completed',
            ]);
        }

        $page1 = $this->actingAs($user)->get('/videos');
        $page1->assertStatus(200);
        $page1->assertSee('Paginate Video 1');
        $page1->assertSee('Paginate Video 12');
        $page1->assertDontSee('Paginate Video 13');
        $page1->assertSee('Page 1 of 2');

        $page2 = $this->actingAs($user)->get('/videos?page=2');
        $page2->assertStatus(200);
        $page2->assertSee('Paginate Video 13');
        $page2->assertSee('Paginate Video 15');
        $page2->assertSee('Page 2 of 2');
    }

    public function test_search_finds_videos_by_title_and_description()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_search_chan',
            'name' => 'Search Channel',
            'url' => 'https://example.com/search',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'search_vid_title',
            'title' => 'A video about Zebras',
            'description' => 'nothing relevant here',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'search_vid_desc',
            'title' => 'Unrelated title',
            'description' => 'this one talks about zebras too',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'search_vid_nomatch',
            'title' => 'Completely different topic',
            'description' => 'giraffes and elephants',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/videos?search=zebras');

        $response->assertStatus(200);
        $response->assertSee('A video about Zebras');
        $response->assertSee('Unrelated title');
        $response->assertDontSee('Completely different topic');
    }

    public function test_search_orders_results_by_relevance()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_relevance_chan',
            'name' => 'Relevance Channel',
            'url' => 'https://example.com/relevance',
        ]);

        // Mentions "rocket" once, in the description only.
        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'relevance_weak',
            'title' => 'Some unrelated launch video',
            'description' => 'briefly mentions a rocket',
            'published_at' => now()->subDay(),
            'status' => 'completed',
        ]);

        // Mentions "rocket" in both title and description: should rank higher.
        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'relevance_strong',
            'title' => 'Rocket launch highlights',
            'description' => 'all about the rocket and its rocket engines',
            'published_at' => now()->subWeek(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/videos?search=rocket');
        $response->assertStatus(200);

        $content = $response->getContent();
        $strongPos = strpos($content, 'Rocket launch highlights');
        $weakPos = strpos($content, 'Some unrelated launch video');

        $this->assertNotFalse($strongPos);
        $this->assertNotFalse($weakPos);
        $this->assertTrue($strongPos < $weakPos, 'Stronger keyword match should rank first under relevance sort.');
    }

    public function test_search_query_with_special_characters_does_not_error()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/videos?search='.urlencode('"weird" OR *query* -- test'));

        $response->assertStatus(200);
    }

    public function test_sort_by_title_orders_alphabetically()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_sort_chan',
            'name' => 'Sort Channel',
            'url' => 'https://example.com/sort',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'sort_vid_b',
            'title' => 'Banana video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'sort_vid_a',
            'title' => 'Apple video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/videos?sort=title');
        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertTrue(strpos($content, 'Apple video') < strpos($content, 'Banana video'));
    }

    public function test_banned_filter_shows_only_banned_videos()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_banned_filter_chan',
            'name' => 'Banned Filter Channel',
            'url' => 'https://example.com/banned-filter',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'not_banned_vid',
            'title' => 'Still Available Video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'is_banned_vid',
            'title' => 'Removed Video',
            'published_at' => now(),
            'status' => 'completed',
            'is_banned' => true,
        ]);

        $response = $this->actingAs($user)->get('/videos?banned=1');

        $response->assertStatus(200);
        $response->assertSee('Removed Video');
        $response->assertDontSee('Still Available Video');
    }

    public function test_banned_filter_is_off_by_default()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_banned_default_chan',
            'name' => 'Banned Default Channel',
            'url' => 'https://example.com/banned-default',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'default_banned_vid',
            'title' => 'Removed Video',
            'published_at' => now(),
            'status' => 'completed',
            'is_banned' => true,
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'default_ok_vid',
            'title' => 'Still Available Video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/videos');

        $response->assertStatus(200);
        $response->assertSee('Removed Video');
        $response->assertSee('Still Available Video');
    }
}
