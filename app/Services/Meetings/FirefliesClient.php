<?php

namespace App\Services\Meetings;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Read-only access to the company Fireflies account (GraphQL API). */
class FirefliesClient
{
    public function configured(): bool
    {
        return filled(config('services.fireflies.api_key'));
    }

    /** @return list<array{id: string, title: string, date: mixed}> newest first */
    public function recent(int $limit = 10): array
    {
        $data = $this->query(
            'query Recent($limit: Int) { transcripts(limit: $limit) { id title date } }',
            ['limit' => max(1, min(50, $limit))],
        );

        return $data['transcripts'] ?? [];
    }

    /** @return array<string, mixed> */
    public function transcript(string $id): array
    {
        $data = $this->query(<<<'GQL'
            query One($id: String!) {
              transcript(id: $id) {
                id title date duration organizer_email meeting_link
                meeting_attendees { displayName email name }
                sentences { speaker_name text start_time end_time }
                summary { keywords action_items overview short_summary }
              }
            }
            GQL, ['id' => $id]);

        if (empty($data['transcript'])) {
            throw new RuntimeException("Fireflies: meeting {$id} not found.");
        }

        return $data['transcript'];
    }

    /** @return array<string, mixed> */
    private function query(string $query, array $variables): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Fireflies API key is not set (FIREFLIES_API_KEY).');
        }

        $response = Http::withToken((string) config('services.fireflies.api_key'))
            ->acceptJson()
            ->timeout(60)
            ->retry(2, 1000, throw: false)
            ->post((string) config('services.fireflies.url'), ['query' => $query, 'variables' => $variables]);

        $body = $response->json();
        if (! $response->successful() || ! empty($body['errors'])) {
            $message = $body['errors'][0]['message'] ?? ('HTTP '.$response->status());
            throw new RuntimeException('Fireflies: '.$message);
        }

        return $body['data'] ?? [];
    }
}
