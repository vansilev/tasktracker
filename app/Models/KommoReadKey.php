<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Personal key for the read-only Kommo MCP server (kommo-read.tcsavant.com).
 * Only the SHA-256 hash is stored; the plain key is shown once.
 * These keys never authenticate against the tracker's own API.
 */
class KommoReadKey extends Model
{
    public const PREFIX = 'kr_';

    protected $fillable = [
        'user_id',
        'name',
        'token_hash',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{0: self, 1: string} */
    public static function issue(User $user, string $name): array
    {
        $plain = self::PREFIX.Str::random(48);

        $key = $user->kommoReadKeys()->create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
        ]);

        return [$key, $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX) || strlen($plain) > 200) {
            return null;
        }

        return self::query()->with('user')->where('token_hash', hash('sha256', $plain))->first();
    }
}
