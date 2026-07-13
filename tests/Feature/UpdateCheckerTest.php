<?php

namespace Tests\Feature;

use App\Services\UpdateChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_latest_version_returns_the_highest_semver_tag_from_github()
    {
        Http::fake([
            'api.github.com/repos/*' => Http::response([
                ['name' => 'v1.2.0'],
                ['name' => 'v1.10.0'],
                ['name' => 'v1.9.5'],
                ['name' => 'not-a-version'],
            ]),
        ]);

        $version = (new UpdateChecker)->latestVersion();

        // Numeric (not lexicographic) comparison: 1.10.0 must beat 1.9.5.
        $this->assertEquals('1.10.0', $version);
    }

    public function test_latest_version_returns_null_when_github_is_unreachable()
    {
        Http::fake([
            'api.github.com/*' => Http::failedConnection(),
        ]);

        $this->assertNull((new UpdateChecker)->latestVersion());
    }

    public function test_latest_version_returns_null_when_there_are_no_valid_tags()
    {
        Http::fake([
            'api.github.com/*' => Http::response([]),
        ]);

        $this->assertNull((new UpdateChecker)->latestVersion());
    }

    public function test_latest_version_result_is_cached()
    {
        Http::fake([
            'api.github.com/*' => Http::response([['name' => 'v2.0.0']]),
        ]);

        $checker = new UpdateChecker;
        $this->assertEquals('2.0.0', $checker->latestVersion());
        $this->assertEquals('2.0.0', $checker->latestVersion());

        Http::assertSentCount(1);
    }

    public function test_is_newer()
    {
        $checker = new UpdateChecker;

        $cases = [
            'newer patch' => ['1.0.0', '1.0.1', true],
            'newer minor' => ['1.0.0', '1.1.0', true],
            'newer major' => ['1.0.0', '2.0.0', true],
            'numeric not lexicographic' => ['1.9.5', '1.10.0', true],
            'same version' => ['1.0.0', '1.0.0', false],
            'older' => ['1.1.0', '1.0.0', false],
            'null latest' => ['1.0.0', null, false],
            'null current' => [null, '1.0.0', false],
            'unparseable current' => ['dev', '1.0.0', false],
            'unparseable latest' => ['1.0.0', 'not-a-version', false],
        ];

        foreach ($cases as $description => [$current, $latest, $expected]) {
            $this->assertSame($expected, $checker->isNewer($current, $latest), $description);
        }
    }
}
