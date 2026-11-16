<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\MeetingPersonAlias;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Maps the names and emails Fireflies shows to tracker users.
 *
 * Order: an alias someone set by hand → a user with that email → what the model
 * suggested for this meeting's speaker → a name match across Cyrillic and Latin
 * spellings ("Natalia Svitenko" = "Свитенко Наталья"). A guess is used only when
 * exactly one active user fits.
 */
class MeetingPeople
{
    /** @var Collection<int, User>|null */
    private ?Collection $users = null;

    /** @var array<string, MeetingPersonAlias>|null */
    private ?array $aliases = null;

    /**
     * @return array{user: ?User, external: bool, source: string}
     *                                                            source: alias | email | model | name | none
     */
    public function resolve(?string $email, ?string $name, ?Meeting $meeting = null): array
    {
        $email = self::normalizeEmail($email);
        $nameKey = self::normalizeName($name);

        foreach (array_filter([$email, $nameKey]) as $key) {
            $alias = $this->aliases()[$key] ?? null;
            if ($alias) {
                return ['user' => $alias->user_id ? $this->users()->firstWhere('id', $alias->user_id) : null, 'external' => $alias->is_external, 'source' => 'alias'];
            }
        }

        if ($email !== '') {
            $user = $this->users()->first(fn (User $u) => self::normalizeEmail($u->email) === $email);
            if ($user) {
                return ['user' => $user, 'external' => false, 'source' => 'email'];
            }
        }

        if ($name !== null && $meeting && is_array($meeting->speaker_map) && array_key_exists($name, $meeting->speaker_map)) {
            $mapped = $meeting->speaker_map[$name];
            if ($mapped === 'external') {
                return ['user' => null, 'external' => true, 'source' => 'model'];
            }
            $user = is_numeric($mapped) ? $this->users()->firstWhere('id', (int) $mapped) : null;
            if ($user) {
                return ['user' => $user, 'external' => false, 'source' => 'model'];
            }
        }

        if ($name !== null && trim($name) !== '') {
            $user = $this->matchByName($name);
            if ($user) {
                return ['user' => $user, 'external' => false, 'source' => 'name'];
            }
        }

        return ['user' => null, 'external' => false, 'source' => 'none'];
    }

    /**
     * Everyone who took part: invited people and people who spoke, merged when
     * they are the same tracker user.
     *
     * @return list<array{key: string, label: string, email: ?string, speaker: ?string, user: ?User, external: bool, source: string, spoke: bool, invited: bool}>
     */
    public function participants(Meeting $meeting): array
    {
        $rows = [];

        foreach ($meeting->attendees ?? [] as $attendee) {
            $email = $attendee['email'] ?? null;
            $name = $attendee['name'] ?? null;
            if (! $email && ! $name) {
                continue;
            }
            $r = $this->resolve($email, $name, $meeting);
            $rows[] = [
                'key' => self::normalizeEmail($email) ?: self::normalizeName($name),
                'label' => $r['user']?->name ?? ($name ?: $email),
                'email' => $email,
                'speaker' => null,
                'user' => $r['user'],
                'external' => $r['external'],
                'source' => $r['source'],
                'spoke' => false,
                'invited' => true,
            ];
        }

        foreach ($meeting->speakerNames() as $speaker) {
            $r = $this->resolve(null, $speaker, $meeting);
            $existing = null;
            foreach ($rows as $i => $row) {
                if ($r['user'] && $row['user'] && $row['user']->id === $r['user']->id) {
                    $existing = $i;
                    break;
                }
            }
            if ($existing !== null) {
                $rows[$existing]['spoke'] = true;
                $rows[$existing]['speaker'] = $speaker;

                continue;
            }
            $rows[] = [
                'key' => self::normalizeName($speaker),
                'label' => $r['user']?->name ?? $speaker,
                'email' => null,
                'speaker' => $speaker,
                'user' => $r['user'],
                'external' => $r['external'],
                'source' => $r['source'],
                'spoke' => true,
                'invited' => false,
            ];
        }

        return $rows;
    }

    /** @return list<int> tracker users who were in the meeting (invited or spoke) */
    public function presentUserIds(Meeting $meeting): array
    {
        return collect($this->participants($meeting))
            ->pluck('user')
            ->filter()
            ->pluck('id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Meetings are private. An admin always sees them; for everyone else the admin
     * picks per meeting: participants (+ people let in by hand), only the people
     * let in by hand, or admins only.
     */
    public function canView(User $user, Meeting $meeting): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $mode = $meeting->access_mode ?: Meeting::ACCESS_PARTICIPANTS;
        if ($mode === Meeting::ACCESS_ADMINS) {
            return false;
        }

        if ($meeting->viewers()->whereKey($user->id)->exists()) {
            return true;
        }

        if ($mode === Meeting::ACCESS_SELECTED) {
            return false;
        }

        return $this->organizer($meeting)?->id === $user->id
            || in_array($user->id, $this->presentUserIds($meeting), true);
    }

    public function organizer(Meeting $meeting): ?User
    {
        return $this->resolve($meeting->organizer_email, null, $meeting)['user'];
    }

    /** Set by a person on the meeting page; wins over every guess, for every meeting. */
    public function remember(string $key, ?int $userId, bool $external, User $by): void
    {
        $key = str_contains($key, '@') ? self::normalizeEmail($key) : self::normalizeName($key);
        if ($key === '') {
            return;
        }

        if (! $userId && ! $external) {
            MeetingPersonAlias::query()->where('alias', $key)->delete();
        } else {
            MeetingPersonAlias::query()->updateOrCreate(
                ['alias' => $key],
                ['user_id' => $external ? null : $userId, 'is_external' => $external, 'set_by' => $by->id],
            );
        }

        $this->aliases = null;
    }

    /** @return Collection<int, User> */
    public function users(): Collection
    {
        return $this->users ??= User::query()
            ->where('is_active', true)
            ->with('department:id,name')
            ->orderBy('name')
            ->get();
    }

    private function matchByName(string $name): ?User
    {
        $wanted = self::nameTokens($name);
        if ($wanted === []) {
            return null;
        }

        $hits = $this->users()->filter(function (User $user) use ($wanted) {
            $have = self::nameTokens($user->name);
            if ($have === []) {
                return false;
            }
            // A one-word name ("Алина") is too weak unless the user's name is one word too.
            if (count($wanted) === 1 && count($have) > 1) {
                return false;
            }
            foreach ($wanted as $token) {
                $found = false;
                foreach ($have as $candidate) {
                    if (self::sameWord($token, $candidate)) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    return false;
                }
            }

            return true;
        });

        return $hits->count() === 1 ? $hits->first() : null;
    }

    /** Ukrainian/Russian/English spellings of one name differ in vowels and soft signs. */
    private static function sameWord(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $skeleton = fn (string $w) => preg_replace('/[aeiouy]+/', '', $w);
        $sa = $skeleton($a);
        $sb = $skeleton($b);

        return strlen($sa) >= 3 && $sa === $sb
            || (strlen($a) >= 4 && strlen($b) >= 4 && substr($a, 0, 4) === substr($b, 0, 4) && abs(strlen($a) - strlen($b)) <= 2);
    }

    /** @return list<string> */
    private static function nameTokens(string $name): array
    {
        $latin = self::toLatin(mb_strtolower($name));
        $tokens = preg_split('/[^a-z]+/', $latin, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Patronymics and initials do not help to match a short Latin name.
        return array_values(array_filter($tokens, fn (string $t) => strlen($t) >= 3
            && ! preg_match('/(ovna|evna|ivna|ovich|evich|ych|ich|ch)$/', $t)));
    }

    public static function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    public static function normalizeName(?string $name): string
    {
        $tokens = preg_split('/\s+/u', mb_strtolower(trim((string) $name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($tokens);

        return implode(' ', $tokens);
    }

    private static function toLatin(string $text): string
    {
        static $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ye',
            'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k',
            'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya', '’' => '', "'" => '',
        ];

        // Russian "г" is "g", Ukrainian is "h": treat both as one sound.
        $latin = strtr($text, $map);

        return str_replace(['g', 'h'], 'h', preg_replace('/(?<=[a-z])kh/', 'h', $latin));
    }

    /** @return array<string, MeetingPersonAlias> */
    private function aliases(): array
    {
        return $this->aliases ??= MeetingPersonAlias::query()->get()->keyBy('alias')->all();
    }
}
