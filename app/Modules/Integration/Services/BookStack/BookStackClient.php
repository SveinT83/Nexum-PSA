<?php

namespace App\Modules\Integration\Services\BookStack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class BookStackClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $tokenId,
        private readonly string $tokenSecret,
    ) {
    }

    public function testConnection(): array
    {
        try {
            $response = $this->request()->get($this->endpoint('/api/books'), [
                'count' => 1,
            ]);
        } catch (ConnectionException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }

        if ($response->successful()) {
            return [
                'success' => true,
                'message' => null,
            ];
        }

        return [
            'success' => false,
            'message' => $response->json('message') ?: 'BookStack API returned HTTP ' . $response->status() . '.',
        ];
    }

    private function request()
    {
        return Http::acceptJson()
            ->withHeaders([
                'Authorization' => 'Token ' . $this->tokenId . ':' . $this->tokenSecret,
            ])
            ->timeout(15);
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
