<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class IssueApiToken extends Command
{
    protected $signature = 'tasktracker:issue-api-token
                            {email : User email}
                            {--name=mcp : Token name stored in the database}';

    protected $description = 'Create a Sanctum personal access token for API / MCP access';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("User not found: {$email}");

            return self::FAILURE;
        }

        if (! $user->is_active) {
            $this->error("User is deactivated: {$email}");

            return self::FAILURE;
        }

        $token = $user->createToken((string) $this->option('name'))->plainTextToken;

        $this->info('Token created. Copy it now — it will not be shown again.');
        $this->line($token);

        return self::SUCCESS;
    }
}
