<?php

namespace App\Services\Meetings;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only access to Fireflies (GraphQL API) through one or more account keys.
 * A key sees only what its account recorded or was invited to, so meetings are
 * collected over all keys and merged by id.
 */
class FirefliesClient
{
    /** @var array<string, string> transcript id → key that listed it */
    private array $keyFor = [];

    /** @return list<string> */
    public function keys(): array
    {
        $keys = [(string) config('services.fireflies.api_key'), ...explode(',', (string) config('services.fireflies.extra_api_keys'))];

        return array_values(array_unique(array_filter(array_map('trim', $keys))));
    }

    public function configured(): bool
    {
        return $this->keys() !== [];
    }

    /**
     * The newest meetings over all keys. One failing key does not stop the others;
     * only when every key fails is it an error.
     *
     * @return list<array{id: string, title: string, date: mixed}> newest first
     */
    public function recent(int $limit = 10): array
    {
        $rows = [];
        $errors = [];
        foreach ($this->keys() as $key) {
            try {
                $data = $this->query(
                    'query Recent($limit: Int) { transcripts(limit: $limit) { id title date } }',
                    ['limit' => max(1, min(50, $limit))],
                    $key,
                );
            } catch (RuntimeException $e) {
                report($e);
                $errors[] = $e->getMessage();

                continue;
            }
            foreach ($data['transcripts'] ?? [] as $row) {
                $id = (string) $row['id'];
                if (! isset($rows[$id])) {
                    $rows[$id] = $row;
                    $this->keyFor[$id] = $key;
                }
            }
        }

        if ($rows === [] && $errors !== []) {
            throw new RuntimeException(implode('; ', array_unique($errors)));
        }

        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => (float) $b['date'] <=> (float) $a['date']);

        return $rows;
    }

    /** @return array<string, mixed> */
    public function transcript(string $id): array
    {
        // The key that listed this meeting first; otherwise try each until one sees it.
        $keys = array_values(array_unique(array_filter([$this->keyFor[$id] ?? null, ...$this->keys()])));
        $last = null;
        foreach ($keys as $key) {
            try {
                return $this->fetchTranscript($id, $key);
            } catch (RuntimeException $e) {
                $last = $e;
            }
        }

        throw $last ?? new RuntimeException('Fireflies API key is not set (FIREFLIES_API_KEY).');
    }

    /** @return array<string, mixed> */
    private function fetchTranscript(string $id, string $key): array
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
            GQL, ['id' => $id], $key);

        if (empty($data['transcript'])) {
            throw new RuntimeException("Fireflies: meeting {$id} not found.");
        }

        return $data['transcript'];
    }

    /** @return array<string, mixed> */
    private function query(string $query, array $variables, string $key): array
    {
        $response = Http::withToken($key)
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
