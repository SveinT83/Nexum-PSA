<?php

namespace App\Modules\System\Queries;

use App\Modules\System\Services\GitHubVersionClient;
use App\Modules\System\Support\SemanticVersion;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Combines local build metadata with cached, failure-tolerant GitHub status.
 */
final class ApplicationVersionStatusQuery
{
    public function __construct(private readonly GitHubVersionClient $github) {}

    public function local(): array
    {
        $configuredCommit = config('app.commit');
        $installedCommit = is_string($configuredCommit)
            && preg_match('/^[0-9a-f]{40}$/i', $configuredCommit) === 1
                ? strtolower($configuredCommit)
                : null;

        return [
            'installed_version' => (string) config('app.version', 'unknown'),
            'installed_commit' => $installedCommit,
            'installed_commit_short' => $installedCommit !== null ? substr($installedCommit, 0, 7) : null,
            'update_branch' => (string) config('app.update.branch', 'main'),
            'latest_release' => null,
            'release_status' => 'unknown',
            'comparison_status' => $installedCommit === null ? 'commit_unknown' : 'unknown',
            'commits_behind' => null,
            'commits_ahead' => null,
            'github_available' => false,
            'stale' => false,
            'checked_at' => null,
        ];
    }

    public function get(): array
    {
        $keys = $this->cacheKeys();
        $fresh = Cache::get($keys['fresh']);

        if (is_array($fresh)) {
            return $fresh;
        }

        $lastSuccessful = Cache::get($keys['last_successful']);

        if (Cache::has($keys['failure_gate'])) {
            return $this->staleOrUnavailable($lastSuccessful);
        }

        $status = $this->local();
        $remoteSucceeded = false;

        try {
            $latestRelease = $this->github->latestRelease();
            $remoteSucceeded = true;
            $status['latest_release'] = $latestRelease;

            if ($latestRelease !== null) {
                $isNewer = SemanticVersion::isNewer(
                    $latestRelease['version'] ?? null,
                    $status['installed_version']
                );
                $status['release_status'] = $isNewer === null
                    ? 'unknown'
                    : ($isNewer ? 'update_available' : 'current');
            }
        } catch (Throwable) {
            // A release failure is represented in the normalized status below.
        }

        if ($status['installed_commit'] !== null) {
            try {
                $comparison = $this->github->compare(
                    $status['installed_commit'],
                    $status['update_branch']
                );
                $remoteSucceeded = true;
                $status['comparison_status'] = $comparison['status'];
                $status['commits_behind'] = $comparison['commits_behind'];
                $status['commits_ahead'] = $comparison['commits_ahead'];
            } catch (Throwable) {
                $status['comparison_status'] = 'unknown';
            }
        }

        if (! $remoteSucceeded) {
            Cache::put($keys['failure_gate'], true, now()->addMinutes(5));

            return $this->staleOrUnavailable($lastSuccessful);
        }

        $status['github_available'] = true;
        $status['checked_at'] = now()->toIso8601String();
        Cache::put($keys['fresh'], $status, now()->addMinutes(30));
        Cache::put($keys['last_successful'], $status, now()->addHours(24));

        return $status;
    }

    private function staleOrUnavailable(mixed $lastSuccessful): array
    {
        if (! is_array($lastSuccessful)) {
            return $this->local();
        }

        $lastSuccessful['github_available'] = false;
        $lastSuccessful['stale'] = true;

        return $lastSuccessful;
    }

    private function cacheKeys(): array
    {
        $signature = hash('sha256', implode('|', [
            (string) config('app.version'),
            (string) config('app.commit'),
            (string) config('app.update.branch'),
            (string) config('app.update.github_repository'),
            config('app.update.include_prereleases') ? 'prerelease' : 'stable',
        ]));
        $prefix = 'nexum:application-version-status:'.$signature;

        return [
            'fresh' => $prefix.':fresh',
            'last_successful' => $prefix.':last-successful',
            'failure_gate' => $prefix.':failure-gate',
        ];
    }
}
