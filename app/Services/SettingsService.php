<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SettingsService
{
    /** @var array<string, mixed> */
    private array $cache = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $value = $this->readFromDatabase($key);

        if ($value === null) {
            $value = config('tasktracker.'.$key, $default);
        }

        $this->cache[$key] = $value;

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::set($key, $value);
        unset($this->cache[$key]);
    }

    private function readFromDatabase(string $key): mixed
    {
        try {
            if (! Schema::hasTable('settings')) {
                return null;
            }

            return Setting::get($key);
        } catch (Throwable) {
            return null;
        }
    }
}
