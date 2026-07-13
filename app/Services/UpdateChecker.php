<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateChecker
{
    /**
     * Latest vX.Y.Z tag published on GitHub for this project, or null if it couldn't be
     * determined (offline, rate-limited, no tags yet, etc). Cached to avoid hitting GitHub's
     * API on every Settings page load.
     */
    public function latestVersion(): ?string
    {
        return Cache::remember('update_checker:latest_version', now()->addHours(6), function () {
            $repo = config('services.github_repo');

            try {
                $response = Http::timeout(5)
                    ->connectTimeout(3)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->get("https://api.github.com/repos/{$repo}/tags");

                if (! $response->successful()) {
                    return null;
                }

                $latest = null;
                $latestParsed = null;

                foreach ($response->json() ?? [] as $tag) {
                    $parsed = $this->parseVersion($tag['name'] ?? '');

                    if ($parsed === null) {
                        continue;
                    }

                    if ($latestParsed === null || $parsed > $latestParsed) {
                        $latest = ltrim($tag['name'], 'v');
                        $latestParsed = $parsed;
                    }
                }

                return $latest;
            } catch (\Throwable $e) {
                Log::warning('Failed to check for Ytoberr updates: '.$e->getMessage());

                return null;
            }
        });
    }

    /**
     * True if $latest is a strictly newer semantic version than $current.
     */
    public function isNewer(?string $current, ?string $latest): bool
    {
        $currentParsed = $current !== null ? $this->parseVersion($current) : null;
        $latestParsed = $latest !== null ? $this->parseVersion($latest) : null;

        if ($currentParsed === null || $latestParsed === null) {
            return false;
        }

        return $latestParsed > $currentParsed;
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function parseVersion(string $version): ?array
    {
        if (! preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', trim($version), $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }
}
