<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\KommoReadKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Called by the read-only Kommo MCP server to check a colleague's key.
 * The key authenticates only here, never against the tracker's own API.
 */
class KommoReadKeyController extends Controller
{
    public function whoami(Request $request): JsonResponse
    {
        $plain = (string) $request->bearerToken();
        $key = $plain !== '' ? KommoReadKey::findByPlain($plain) : null;
        $user = $key?->user;

        if (! $key || ! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->is_active || ! $user->hasPermission(Permission::KommoRead)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->save();
        }

        return response()->json([
            'data' => [
                'user' => $user->name,
                'key' => $key->name,
            ],
        ]);
    }
}
