<?php

namespace App\Modules\System\Services;

use App\Modules\System\Support\SemanticVersion;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Performs the two bounded, read-only GitHub calls needed for update status.
 */
final class GitHubVersionClient
{
    public function latestRelease(): ?array
    {
        $response = $this->request()->get('repos/'.$this->repository().'/releases', [
            'per_page' => 20,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('GitHub releases request failed with HTTP '.$response->status().'.');
        }

        $releases = $response->json();

        if (! is_array($releases)) {
            throw new RuntimeException('GitHub releases response was not an array.');
        }

        $includePrereleases = (bool) config('app.update.include_prereleases', true);
        $latest = null;

        foreach ($releases as $release) {
            if (! is_array($release) || (bool) ($release['draft'] ?? false)) {
                continue;
            }

            $version = SemanticVersion::normalize($release['tag_name'] ?? null)
                ?? SemanticVersion::normalize($release['name'] ?? null);

            if ($version === null) {
                continue;
            }

            if (! $includePrereleases
                && ((bool) ($release['prerelease'] ?? false) || SemanticVersion::isPrerelease($version))) {
                continue;
            }

            if ($latest !== null && ! version_compare($version, $latest['version'], '>')) {
                continue;
            }

            $latest = [
                'version' => $version,
                'tag' => is_string($release['tag_name'] ?? null) ? $release['tag_name'] : null,
                'name' => is_string($release['name'] ?? null) ? $release['name'] : null,
                'url' => is_string($release['html_url'] ?? null) ? $release['html_url'] : null,
                'published_at' => is_string($release['published_at'] ?? null) ? $release['published_at'] : null,
                'prerelease' => (bool) ($release['prerelease'] ?? false),
            ];
        }

        return $latest;
    }

    public function compare(string $installedCommit, string $updateBranch): array
    {
        if (preg_match('/^[0-9a-f]{40}$/i', $installedCommit) !== 1) {
            throw new RuntimeException('Installed commit must be a full hexadecimal SHA.');
        }

        if (preg_match('/^(?!.*\.\.)(?!\/)[A-Za-z0-9._\/-]{1,255}$/', $updateBranch) !== 1) {
            throw new RuntimeException('Update branch is not a valid Git reference.');
        }

        $comparison = $installedCommit.'...'.rawurlencode($updateBranch);
        $response = $this->request()->get('repos/'.$this->repository().'/compare/'.$comparison);

        if (! $response->successful()) {
            throw new RuntimeException('GitHub comparison request failed with HTTP '.$response->status().'.');
        }

        $remoteStatus = $response->json('status');
        $commitsBehind = is_numeric($response->json('ahead_by')) ? (int) $response->json('ahead_by') : null;
        $commitsAhead = is_numeric($response->json('behind_by')) ? (int) $response->json('behind_by') : null;

        return [
            'status' => match ($remoteStatus) {
                'identical' => 'current',
                'ahead' => 'behind',
                'behind' => 'ahead',
                'diverged' => 'diverged',
                default => 'unknown',
            },
            // GitHub describes the configured branch as the comparison head.
            'commits_behind' => $commitsBehind,
            'commits_ahead' => $commitsAhead,
        ];
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl('https://api.github.com')
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'Nexum-PSA',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->connectTimeout(2)
            ->timeout(5);

        $token = config('app.update.github_token');

        if (is_string($token) && trim($token) !== '') {
            $request = $request->withToken(trim($token));
        }

        return $request;
    }

    private function repository(): string
    {
        $repository = trim((string) config('app.update.github_repository', ''));

        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository) !== 1) {
            throw new RuntimeException('GitHub repository must use owner/name format.');
        }

        return $repository;
    }
}
