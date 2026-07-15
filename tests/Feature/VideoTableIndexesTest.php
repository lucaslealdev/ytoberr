<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VideoTableIndexesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SQLite doesn't automatically index foreign key columns the way MySQL does, so this
     * confirms the add_indexes_to_videos_table migration actually created the indexes it's
     * meant to: one on `channel_id` (the unindexed FK), and a compound one on
     * `['status', 'created_at']` covering both plain `status` filters and the
     * queue-processing "next pending video" query's filter+sort.
     */
    public function test_videos_table_has_expected_indexes(): void
    {
        $indexes = Schema::getIndexes('videos');
        $indexedColumnSets = array_map(fn (array $index) => $index['columns'], $indexes);

        $this->assertContains(['channel_id'], $indexedColumnSets);
        $this->assertContains(['status', 'created_at'], $indexedColumnSets);
    }
}
