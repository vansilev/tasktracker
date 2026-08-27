<?php

namespace App\Services;

use App\Enums\BillingCategory;
use App\Enums\BillingDueDayRule;
use App\Enums\BillingKind;
use App\Enums\BillingPaymentMethod;
use App\Enums\BillingState;
use App\Models\BillingItem;
use App\Models\Department;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BillingSheetImportService
{
    public const SPREADSHEET_ID = '1U5QWM38J_DZ3cnTYsJayGGqzKnm-Vy4Z';

    public const SHEET_GIDS = [
        'internet' => '670237787',
        'plugins' => '1947480136',
        'services' => '1767096480',
        'budget2025' => '1042294623',
        'cardpay' => '445144464',
    ];

    public function __construct(private BillingCycleService $cycle) {}

    public function download(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }

        foreach (self::SHEET_GIDS as $gid) {
            $url = 'https://docs.google.com/spreadsheets/d/'.self::SPREADSHEET_ID.'/export?format=csv&gid='.$gid;
            $response = Http::timeout(30)->get($url);
            if (! $response->successful()) {
                throw new \RuntimeException("Sheet download failed for gid {$gid}: HTTP ".$response->status());
            }
            file_put_contents($dir.'/sheet_'.$gid.'.csv', $response->body());
        }
    }

    /** @return array{imported: int, skipped_existing: int, skipped: list<array<string, string>>, preview: list<array<string, mixed>>} */
    public function import(string $dir, bool $dryRun = false): array
    {
        $rows = $this->buildRows($dir);
        $skipped = $rows['skipped'];
        $preview = [];
        $imported = 0;
        $skippedExisting = 0;

        $maxim = User::query()->where('email', 'crm.manager@tcsavant.com')->first();
        $irina = User::query()->where('email', 'elearning@tcsavant.com')->first();
        $itHead = Department::query()->where('name', 'IT')->first()?->head;
        $eduHead = Department::query()->where('name', 'Учебный отдел')->first()?->head;

        $write = function () use ($rows, $dryRun, $maxim, $irina, $itHead, $eduHead, &$preview, &$imported, &$skippedExisting) {
            foreach ($rows['items'] as $item) {
                $people = $this->resolvePeople($item, $maxim, $irina, $itHead, $eduHead);
                $payload = $this->payload($item, $people);
                $preview[] = [
                    ...$item,
                    'payer_email' => $people['payer']?->email,
                    'owner_email' => $people['owner']?->email,
                    'next_due_on' => $payload['next_due_on'],
                    'amount' => $payload['amount'],
                    'currency' => $payload['currency'],
                    'kind' => $payload['kind']->value,
                    'payment_method' => $payload['payment_method']->value,
                    'state' => $payload['state']->value,
                ];

                if ($dryRun) {
                    $imported++;

                    continue;
                }

                $existing = BillingItem::query()
                    ->where('vendor', $payload['vendor'])
                    ->where('product', $payload['product'])
                    ->first();

                if ($existing) {
                    $skippedExisting++;

                    continue;
                }

                BillingItem::query()->create($payload);
                $imported++;
            }
        };

        if ($dryRun) {
            $write();
        } else {
            DB::transaction($write);
        }

        return [
            'imported' => $imported,
            'skipped_existing' => $skippedExisting,
            'skipped' => $skipped,
            'preview' => $preview,
        ];
    }

    /** @return array{items: list<array<string, mixed>>, skipped: list<array<string, string>>} */
    public function buildRows(string $dir): array
    {
        $byFp = [];
        $skipped = [];

        foreach ($this->parseInternet($this->csv($dir, 'internet'), $skipped) as $row) {
            $this->put($byFp, $row);
        }
        foreach ($this->parsePlugins($this->csv($dir, 'plugins')) as $row) {
            $this->put($byFp, $row);
        }
        foreach ($this->parseServices($this->csv($dir, 'services')) as $row) {
            $this->put($byFp, $row);
        }
        foreach ($this->parseCardPay($this->csv($dir, 'cardpay')) as $row) {
            $this->put($byFp, $row);
        }

        $items = [];
        foreach ($byFp as $fp => $row) {
            if ($this->shouldSkip($row, $reason)) {
                $skipped[] = ['reason' => $reason, 'vendor' => $row['vendor'], 'product' => $row['product'], 'fp' => $fp];

                continue;
            }
            $items[] = $this->applyOverlay($row);
        }

        usort($items, fn (array $a, array $b) => [$a['vendor'], $a['product']] <=> [$b['vendor'], $b['product']]);

        return ['items' => $items, 'skipped' => $skipped];
    }

    /** @param  array<string, array<string, mixed>>  $byFp */
    private function put(array &$byFp, array $row): void
    {
        $fp = $this->fingerprint($row['vendor'], $row['product']);
        $row['fingerprint'] = $fp;
        if (! isset($byFp[$fp])) {
            $byFp[$fp] = $row;

            return;
        }

        $byFp[$fp] = $this->combine($byFp[$fp], $row);
    }

    /** @param  array<string, mixed>  $base */
    private function combine(array $base, array $incoming): array
    {
        foreach (['amount', 'currency', 'source_date', 'period_raw', 'url', 'info', 'initiator', 'day_of_month', 'due_day_rule'] as $field) {
            if (($base[$field] ?? null) === null && ($incoming[$field] ?? null) !== null) {
                $base[$field] = $incoming[$field];
            }
        }
        if (($incoming['amount'] ?? null) !== null && ($base['amount'] ?? null) === null) {
            $base['vendor'] = $incoming['vendor'];
            $base['product'] = $incoming['product'];
            $base['amount'] = $incoming['amount'];
            $base['currency'] = $incoming['currency'] ?? $base['currency'];
        }
        if (($incoming['method'] ?? null) === BillingPaymentMethod::Card) {
            $base['method'] = BillingPaymentMethod::Card;
        }
        if (($incoming['initiator'] ?? '') !== '') {
            $base['initiator'] = $incoming['initiator'];
        }
        $base['notes_extra'] = trim(($base['notes_extra'] ?? '')."\n".($incoming['notes_extra'] ?? ''));

        return $base;
    }

    /** @param  array<string, mixed>  $row */
    private function shouldSkip(array $row, ?string &$reason = null): bool
    {
        $blob = mb_strtolower($row['vendor'].' '.$row['product']);
        if (str_contains($blob, 'айті софтвер') || str_contains($blob, 'айти софтвер')) {
            $reason = 'Ringostat duplicate empty license row';

            return true;
        }
        if (preg_match('/google\s*ads|facebook|\bfb\b|instagram/', $blob)) {
            $reason = 'Ads 2025 — not in first import';

            return true;
        }
        if (str_contains($blob, 'hostinger.com') && (float) ($row['amount'] ?? 0) === 990.0) {
            $reason = 'Hostinger 990 USD — suspicious copied ads row';

            return true;
        }
        $reason = null;

        return false;
    }

    /** @param  array<string, mixed>  $row */
    private function applyOverlay(array $row): array
    {
        $fp = $row['fingerprint'];

        $row['kind'] = $row['kind'] ?? $this->kindFromPeriod((string) ($row['period_raw'] ?? ''));
        $row['period_months'] = $row['period_months'] ?? $this->periodMonths((string) ($row['period_raw'] ?? ''), $row['kind']);
        $row['method'] = $row['method'] ?? BillingPaymentMethod::Card;
        $row['state'] = BillingState::Active;
        $row['overlay_note'] = $row['overlay_note'] ?? null;

        if ($fp === 'learndash') {
            $row['vendor'] = 'LearnDash';
            $row['product'] = 'LMS';
            $row['amount'] = 200.0;
            $row['currency'] = 'USD';
            $row['kind'] = BillingKind::Subscription;
            $row['period_months'] = 12;
            $row['method'] = BillingPaymentMethod::Card;
            $row['overlay_note'] = 'LearnDash: каноническая сумма 200 USD (не 199 с листа плагинов).';
        }

        if ($fp === 'vimeo-old') {
            $row['vendor'] = 'Vimeo General (Old)';
            $row['product'] = 'Advanced Plan (500 video)';
            $row['amount'] = 780.0;
            $row['currency'] = 'USD';
            $row['overlay_note'] = 'Взяли 780 USD из бюджета марта; на листе Сервисы было 1080 USD.';
        }

        if ($fp === 'gws-avant') {
            $row['amount'] = 980.0;
            $row['currency'] = 'UAH';
            $row['method'] = BillingPaymentMethod::Bank;
            $row['overlay_note'] = 'В таблице 980 EUR — считаем 980 грн, проверить.';
        }

        if ($fp === 'gws-daks') {
            $row['currency'] = 'UAH';
            $row['method'] = BillingPaymentMethod::Bank;
            $row['overlay_note'] = 'Учёт в грн (9637,27). В таблице также пометка 192 usd.';
        }

        if ($fp === 'hostro-tcsavant') {
            $row['state'] = BillingState::Archived;
            $row['archive_reason'] = 'Дубль домена tcsavant.com: активен GoDaddy.';
            $row['method'] = BillingPaymentMethod::Bank;
        }

        if ($fp === 'tov-domain-avant') {
            $row['state'] = BillingState::Archived;
            $row['archive_reason'] = 'Старый договор на avant.od.ua: активна imena.';
            $row['method'] = BillingPaymentMethod::Bank;
        }

        if ($fp === 'adobe-firefly') {
            $row['vendor'] = 'Adobe';
            $row['product'] = 'Firefly';
            $row['amount'] = 30.0;
            $row['currency'] = 'USD';
            $row['kind'] = BillingKind::Subscription;
            $row['period_months'] = 1;
            $row['source_date'] = '30.08.2026';
            $row['day_of_month'] = 30;
            $row['overlay_note'] = 'Дата в таблице 30.08.0226 — исправлено на 2026.';
            $row['initiator'] = $row['initiator'] ?: 'Учебный отдел';
        }

        if ($fp === 'cursor') {
            $row['vendor'] = 'Cursor';
            $row['product'] = 'Pro';
            $row['amount'] = 60.0;
            $row['currency'] = 'USD';
            $row['kind'] = BillingKind::Subscription;
            $row['period_months'] = 1;
            $row['day_of_month'] = 26;
            $row['overlay_note'] = 'Одна месячная подписка 26-го, не 12 копий месяца.';
            $row['initiator'] = $row['initiator'] ?: 'Максим GOLDT';
        }

        if ($fp === 'chatgpt') {
            $row['vendor'] = 'ChatGPT';
            $row['product'] = 'Plus';
            $row['kind'] = BillingKind::Subscription;
            $row['period_months'] = 1;
            $row['day_of_month'] = 29;
            $row['auto_renew'] = true;
            $row['overlay_note'] = 'Есть на Сервисах, в Card pay 2026 нет — всё равно включили.';
        }

        if ($fp === 'imena-daks-club') {
            $row['amount'] = null;
            $row['currency'] = null;
            $row['overlay_note'] = 'Цена пустая — по счету.';
        }

        if ($fp === 'ringostat') {
            $row['vendor'] = 'Ringostat';
            $row['product'] = 'Техническое обеспечение сервиса';
            $row['amount'] = 25090.42;
            $row['currency'] = 'UAH';
            $row['method'] = BillingPaymentMethod::Bank;
            $row['kind'] = BillingKind::Subscription;
            $row['period_months'] = 12;
        }

        if (in_array($fp, ['edenai', 'openai'], true)) {
            $row['kind'] = BillingKind::OnDemand;
            $row['period_months'] = null;
            $row['next_due_override'] = false;
            $row['source_date'] = null;
        }

        if ($fp === 'heygen-extra') {
            $row['kind'] = BillingKind::OnDemand;
            $row['period_months'] = null;
            $row['source_date'] = null;
            $row['amount'] = 15.0;
            $row['currency'] = 'USD';
        }

        if (($row['sheet'] ?? '') === 'internet') {
            $row['method'] = BillingPaymentMethod::Bank;
        }

        return $row;
    }

    /** @param  list<list<string>>  $csv */
    /** @param  list<list<string>>  $csv */
    private function parseInternet(array $csv, array &$skipped): array
    {
        $out = [];
        foreach ($csv as $i => $cols) {
            $vendor = trim($cols[0] ?? '');
            if ($i === 0 || $vendor === '' || $vendor === 'Бухгалтерия' || str_contains($vendor, 'Название организации')) {
                continue;
            }
            $info = trim($cols[1] ?? '');
            if (str_contains(mb_strtolower($vendor), 'софтвер') && str_contains(mb_strtolower($info), 'ringostat')) {
                $skipped[] = ['reason' => 'Ringostat duplicate empty license row', 'vendor' => $vendor, 'product' => $info];

                continue;
            }
            $money = $this->parseMoney(trim($cols[2] ?? ''), 'UAH');
            $period = trim($cols[3] ?? '');
            $date = trim($cols[4] ?? '');
            $out[] = $this->row(
                vendor: $vendor,
                product: $info !== '' ? preg_replace('/\s+/', ' ', $info) : $vendor,
                amount: $money['amount'],
                currency: $money['currency'],
                periodRaw: $period,
                sourceDate: $date,
                info: $info,
                method: BillingPaymentMethod::Bank,
                sheet: 'internet',
                extra: trim($cols[5] ?? '') !== '' ? 'Пометка: '.trim($cols[5]) : null,
            );
        }

        return $out;
    }

    /** @param  list<list<string>>  $csv */
    private function parsePlugins(array $csv): array
    {
        $out = [];
        foreach ($csv as $i => $cols) {
            $vendor = trim($cols[0] ?? '');
            if ($i === 0 || $vendor === '' || str_contains($vendor, 'Название организации')) {
                continue;
            }
            $url = trim($cols[1] ?? '');
            $money = $this->parseMoney(trim($cols[2] ?? ''), 'USD');
            $out[] = $this->row(
                vendor: $vendor,
                product: $this->pluginProduct($vendor),
                amount: $money['amount'],
                currency: $money['currency'],
                periodRaw: trim($cols[3] ?? ''),
                sourceDate: trim($cols[4] ?? ''),
                url: $url,
                method: BillingPaymentMethod::Card,
                sheet: 'plugins',
                initiator: 'IT',
            );
        }

        return $out;
    }

    /** @param  list<list<string>>  $csv */
    private function parseServices(array $csv): array
    {
        $out = [];
        $carryVendor = '';
        foreach ($csv as $i => $cols) {
            if ($i === 0) {
                continue;
            }
            $vendor = trim($cols[0] ?? '');
            $product = trim($cols[1] ?? '');
            $date = trim($cols[2] ?? '');
            $price = trim($cols[3] ?? '');
            $period = trim($cols[4] ?? '');
            $note = trim($cols[5] ?? '');

            if ($vendor !== '' && $product === '' && $date === '' && $price === '') {
                $carryVendor = $vendor;

                continue;
            }
            if ($vendor === '' && $product === '') {
                continue;
            }
            if ($vendor !== '') {
                $carryVendor = $vendor;
            } elseif ($carryVendor !== '') {
                $vendor = $carryVendor;
            }
            if ($product === '') {
                $product = $vendor;
            }

            $money = $this->parseMoney($price, 'USD');
            $due = $this->parseDue($date);
            $out[] = $this->row(
                vendor: $this->prettyVendor($vendor),
                product: $product,
                amount: $money['amount'],
                currency: $money['currency'] ?? ($price === '' ? null : 'USD'),
                periodRaw: $period,
                sourceDate: $due['date'] ?? $date,
                url: str_starts_with(strtolower($vendor), 'http') ? $vendor : null,
                method: BillingPaymentMethod::Card,
                sheet: 'services',
                extra: $note !== '' ? $note : $money['note'],
                dayOfMonth: $due['day'],
                dueDayRule: $due['rule'],
                autoRenew: $due['auto_renew'],
            );
        }

        return $out;
    }

    /** @param  list<list<string>>  $csv */
    private function parseCardPay(array $csv): array
    {
        $out = [];
        $seen = [];
        foreach ($csv as $i => $cols) {
            if ($i === 0) {
                continue;
            }
            $initiator = trim($cols[1] ?? '');
            $vendor = trim($cols[2] ?? '');
            $product = trim($cols[3] ?? '');
            if ($vendor === '' && $product === '') {
                continue;
            }
            $blob = mb_strtolower($vendor.' '.$product.' '.$initiator);
            if (str_contains($blob, 'бюджет') || preg_match('/^(січень|лютий|березень|квітень|травень|червень|липень|серпень|вересень|жовтень|лістопад|грудень)/iu', trim($cols[0] ?? ''))) {
                continue;
            }
            $fp = $this->fingerprint($vendor, $product);
            if (isset($seen[$fp])) {
                continue;
            }
            $seen[$fp] = true;
            $money = $this->parseMoney(trim($cols[6] ?? ''), 'USD');
            $due = $this->parseDue(trim($cols[5] ?? ''));
            $comment = trim($cols[10] ?? '');
            $out[] = $this->row(
                vendor: $this->prettyVendor($vendor),
                product: $product !== '' ? $product : $vendor,
                amount: $money['amount'],
                currency: $money['currency'],
                periodRaw: trim($cols[7] ?? ''),
                sourceDate: $due['date'] ?? trim($cols[5] ?? ''),
                method: BillingPaymentMethod::Card,
                sheet: 'cardpay',
                initiator: $initiator,
                extra: $comment !== '' ? $comment : $money['note'],
                dayOfMonth: $due['day'],
                dueDayRule: $due['rule'],
            );
        }

        return $out;
    }

    private function row(
        string $vendor,
        string $product,
        ?float $amount,
        ?string $currency,
        string $periodRaw,
        string $sourceDate,
        ?string $info = null,
        ?string $url = null,
        BillingPaymentMethod $method = BillingPaymentMethod::Card,
        string $sheet = '',
        ?string $extra = null,
        ?string $initiator = null,
        ?int $dayOfMonth = null,
        ?BillingDueDayRule $dueDayRule = null,
        bool $autoRenew = false,
    ): array {
        $kind = $this->kindFromPeriod($periodRaw);
        $due = $sourceDate !== '' ? $this->parseDue($sourceDate) : ['date' => null, 'day' => $dayOfMonth, 'rule' => $dueDayRule, 'auto_renew' => $autoRenew];

        return [
            'vendor' => $vendor,
            'product' => $product,
            'amount' => $amount,
            'currency' => $amount === null ? null : $currency,
            'period_raw' => $periodRaw,
            'kind' => $kind,
            'period_months' => $this->periodMonths($periodRaw, $kind),
            'source_date' => $due['date'] ?? ($sourceDate !== '' ? $sourceDate : null),
            'info' => $info,
            'url' => $url,
            'method' => $method,
            'sheet' => $sheet,
            'notes_extra' => $extra,
            'initiator' => $initiator,
            'day_of_month' => $dayOfMonth ?? $due['day'],
            'due_day_rule' => $dueDayRule ?? $due['rule'],
            'auto_renew' => $autoRenew || ($due['auto_renew'] ?? false),
            'state' => BillingState::Active,
        ];
    }

    /** @return array{amount: ?float, currency: ?string, note: ?string} */
    public function parseMoney(string $raw, string $defaultCurrency): array
    {
        $raw = trim(str_replace("\u{00A0}", ' ', $raw));
        if ($raw === '' || preg_match('/по\s*счет/iu', $raw)) {
            return ['amount' => null, 'currency' => null, 'note' => null];
        }

        $note = null;
        if (str_contains($raw, '/')) {
            $note = $raw;
            $raw = trim(explode('/', $raw, 2)[0]);
        }

        $currency = $defaultCurrency;
        if (preg_match('/(грн|uah)/iu', $raw)) {
            $currency = 'UAH';
        } elseif (preg_match('/(uds|usd|\$)/iu', $raw)) {
            $currency = 'USD';
        } elseif (preg_match('/(euro|eur|eu)\b/iu', $raw)) {
            $currency = 'EUR';
        }

        $number = trim(preg_replace('/[^\d.,\s]/u', '', $raw) ?? '');
        $number = str_replace(' ', '', $number);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $number)) {
            $number = str_replace('.', '', $number);
            $number = str_replace(',', '.', $number);
        } elseif (str_contains($number, ',') && ! str_contains($number, '.')) {
            $number = str_replace(',', '.', $number);
        } elseif (preg_match('/^\d+,\d{3}\.\d+$/', $number)) {
            $number = str_replace(',', '', $number);
        }

        if ($number === '' || ! is_numeric($number)) {
            return ['amount' => null, 'currency' => null, 'note' => $raw];
        }

        return ['amount' => round((float) $number, 2), 'currency' => $currency, 'note' => $note];
    }

    /** @return array{date: ?string, day: ?int, rule: ?BillingDueDayRule, auto_renew: bool} */
    public function parseDue(string $raw): array
    {
        $raw = trim(str_replace("\u{00A0}", ' ', $raw));
        $auto = str_contains(mb_strtolower($raw), 'автомат');
        $until = (bool) preg_match('/до\s+\d/u', mb_strtolower($raw));

        if (preg_match('/по\s*необхід|по\s*необходим/iu', $raw)) {
            return ['date' => null, 'day' => null, 'rule' => null, 'auto_renew' => false];
        }

        if (preg_match('/(\d{1,2})\s*(числа|числ)/u', $raw, $m)) {
            return ['date' => null, 'day' => (int) $m[1], 'rule' => $until ? BillingDueDayRule::Until : BillingDueDayRule::On, 'auto_renew' => $auto];
        }

        if (preg_match('/(\d{1,2})[.\s\/-](\d{1,2})[.\s\/-](\d{2,4})/', $raw, $m)) {
            $year = (int) $m[3];
            if ($year < 100) {
                $year += 2000;
            } elseif ($year < 1000) {
                $year = 2000 + ($year % 100);
            }

            return [
                'date' => sprintf('%02d.%02d.%04d', (int) $m[1], (int) $m[2], $year),
                'day' => (int) $m[1],
                'rule' => $until ? BillingDueDayRule::Until : BillingDueDayRule::On,
                'auto_renew' => $auto,
            ];
        }

        if (preg_match('/(\d{1,2})\s+авг/iu', $raw, $m)) {
            return ['date' => sprintf('%02d.08.2026', (int) $m[1]), 'day' => (int) $m[1], 'rule' => BillingDueDayRule::On, 'auto_renew' => $auto];
        }

        return ['date' => $raw !== '' ? $raw : null, 'day' => null, 'rule' => $until ? BillingDueDayRule::Until : null, 'auto_renew' => $auto];
    }

    public function fingerprint(string $vendor, string $product): string
    {
        $blob = mb_strtolower(trim($vendor.' '.$product));
        $blob = str_replace(['https://', 'http://', 'www.'], '', $blob);

        return match (true) {
            str_contains($blob, 'learndash') && ! str_contains($blob, 'woon') => 'learndash',
            str_contains($blob, 'quiz save') || (str_contains($blob, 'woon') && str_contains($blob, 'save')) => 'wooninjas-save',
            str_contains($blob, 'advanced quizzes') || (str_contains($blob, 'woon') && str_contains($blob, 'advanced')) => 'wooninjas-advanced',
            str_contains($blob, 'ringostat') => 'ringostat',
            str_contains($blob, 'хостро') => 'hostro-tcsavant',
            str_contains($blob, 'godaddy') && str_contains($blob, 'tcsavant') => 'godaddy-tcsavant',
            str_contains($blob, 'godaddy') && str_contains($blob, 'avant.ws') => 'godaddy-avant.ws',
            str_contains($blob, 'godaddy') && str_contains($blob, 'for-code') => 'godaddy-for-code',
            str_contains($blob, 'imena') && str_contains($blob, 'avant.od.ua') => 'imena-avant.od.ua',
            str_contains($blob, 'технолог') && str_contains($blob, 'avant.od.ua') && str_contains($blob, 'домен') => 'tov-domain-avant',
            str_contains($blob, 'технолог') && str_contains($blob, 'avant.od.ua') => 'tov-hosting-avant',
            str_contains($blob, 'vimeo') && (str_contains($blob, 'general') || str_contains($blob, 'advanced')) => 'vimeo-old',
            str_contains($blob, 'vimeo') => 'vimeo-media',
            str_contains($blob, 'workspace') && str_contains($blob, 'avant') => 'gws-avant',
            str_contains($blob, 'workspace') && str_contains($blob, 'daks') => 'gws-daks',
            str_contains($blob, 'cursor') => 'cursor',
            str_contains($blob, 'firefly') || (str_contains($blob, 'adobe') && str_contains($blob, 'fire')) => 'adobe-firefly',
            str_contains($blob, 'chatgpt') => 'chatgpt',
            str_contains($blob, 'heygen') && (str_contains($blob, 'доп') || str_contains($blob, 'кредит')) => 'heygen-extra',
            str_contains($blob, 'hey gen') || str_contains($blob, 'heygen') => 'heygen',
            str_contains($blob, 'hostinger') && str_contains($blob, 'kvm') => 'hostinger-kvm2',
            str_contains($blob, 'hostinger') && str_contains($blob, 'cloud') => 'hostinger-cloud',
            str_contains($blob, 'hostinger.com') => 'hostinger-990',
            str_contains($blob, 'edenai') => 'edenai',
            str_contains($blob, 'openai') => 'openai',
            str_contains($blob, 'make') => 'make',
            str_contains($blob, '1password') || str_contains($blob, '1 password') => '1password',
            str_contains($blob, 'kommo') => 'kommo',
            str_contains($blob, 'box.com') || str_contains($blob, 'business cloud') => 'box',
            str_contains($blob, 'hostpro') || str_contains($blob, 'ws.tcsavant') => 'hostpro',
            str_contains($blob, 'adm.tools') || (str_contains($blob, 'daks.club') && str_contains($blob, 'хостинг')) => 'adm-tools',
            str_contains($blob, 'control.imena') || (str_contains($blob, 'imena') && str_contains($blob, 'daks.club')) => 'imena-daks-club',
            str_contains($blob, 'imena') && str_contains($blob, 'daks.ua') => 'imena-daks.ua',
            str_contains($blob, 'viber') => 'viber',
            str_contains($blob, 'telegram') => 'telegram',
            str_contains($blob, '4d9y8n2') || str_contains($blob, 'фізичний сервер') => 'ua-hosting-physical',
            str_contains($blob, 'vps') && str_contains($blob, 'kvm') => 'ua-hosting-vps',
            str_contains($blob, 'crocoblock') => 'crocoblock',
            str_contains($blob, 'elementor') => 'elementor',
            str_contains($blob, 'easy wp smtp') || str_contains($blob, 'easywpsmtp') => 'easy-wp-smtp',
            str_contains($blob, 'filebird') => 'filebird',
            str_contains($blob, 'user role') => 'user-role-pro',
            str_contains($blob, 'google ads') => 'ads-google',
            str_contains($blob, 'fb') && str_contains($blob, 'inst') => 'ads-fb',
            default => preg_replace('/\s+/', ' ', $blob) ?: $blob,
        };
    }

    private function kindFromPeriod(string $raw): BillingKind
    {
        $raw = mb_strtolower($raw);
        if (str_contains($raw, 'навсегда')) {
            return BillingKind::Lifetime;
        }
        if (preg_match('/поповн|разов|потреб|необхід|необходим/', $raw)) {
            return BillingKind::OnDemand;
        }

        return BillingKind::Subscription;
    }

    private function periodMonths(string $raw, BillingKind $kind): ?int
    {
        if (! $kind->requiresPeriodMonths()) {
            return null;
        }
        $raw = mb_strtolower($raw);
        if (preg_match('/год|рік|year|ежегод|12/', $raw)) {
            return 12;
        }

        return 1;
    }

    private function prettyVendor(string $vendor): string
    {
        $vendor = trim($vendor);
        $vendor = preg_replace('#^https?://#i', '', $vendor) ?? $vendor;
        $vendor = rtrim($vendor, '/');

        return match (mb_strtolower($vendor)) {
            'godaddy' => 'GoDaddy',
            'hostinger' => 'Hostinger',
            '1password' => '1Password',
            'make.com' => 'Make.com',
            'imena.ua' => 'imena.ua',
            default => $vendor,
        };
    }

    private function pluginProduct(string $vendor): string
    {
        if (str_contains($vendor, ' — ')) {
            return trim(Str::after($vendor, ' — '));
        }
        if (str_contains($vendor, ' - ')) {
            return trim(Str::after($vendor, ' - '));
        }

        return 'License';
    }

    private function categoryFor(array $row): BillingCategory
    {
        $blob = mb_strtolower($row['vendor'].' '.$row['product'].' '.($row['info'] ?? ''));

        return match (true) {
            str_contains($blob, 'домен') || str_contains($blob, 'godaddy') || str_contains($blob, 'imena') => BillingCategory::Domain,
            str_contains($blob, 'hosting') || str_contains($blob, 'хостинг') || str_contains($blob, 'kvm') || str_contains($blob, 'vps') || str_contains($blob, 'сервер') => BillingCategory::Hosting,
            str_contains($blob, 'internet') || str_contains($blob, 'інтернет') || str_contains($blob, 'интернет') || str_contains($blob, 'kyivstar') || str_contains($blob, 'київстар') || str_contains($blob, 'viber') || str_contains($blob, 'telegram') || str_contains($blob, 'телеком') || str_contains($blob, 'связь') => BillingCategory::InternetTelecom,
            ($row['fingerprint'] ?? '') === 'crocoblock' || ($row['fingerprint'] ?? '') === 'elementor' || str_contains($blob, 'plugin') || str_contains($blob, 'learndash') || str_contains($blob, 'smtp') || str_contains($blob, 'filebird') || str_contains($blob, 'woon') => BillingCategory::Plugin,
            str_contains($blob, 'ads') || str_contains($blob, 'реклам') => BillingCategory::Ads,
            str_contains($blob, 'heygen') || str_contains($blob, 'openai') || str_contains($blob, 'chatgpt') || str_contains($blob, 'cursor') || str_contains($blob, 'firefly') || str_contains($blob, 'edenai') || str_contains($blob, 'ии') || str_contains($blob, 'ai') => BillingCategory::Ai,
            default => BillingCategory::Saas,
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{payer: ?User, owner: ?User, owner_label: ?string}
     */
    private function resolvePeople(array $item, ?User $maxim, ?User $irina, ?User $itHead, ?User $eduHead): array
    {
        $label = trim((string) ($item['initiator'] ?? ''));
        $method = $item['method'] instanceof BillingPaymentMethod ? $item['method'] : BillingPaymentMethod::Card;
        $fp = $item['fingerprint'] ?? '';

        $payer = null;
        $owner = null;

        if (preg_match('/максим/iu', $label) || $fp === 'cursor') {
            $payer = $maxim;
            $owner = $itHead ?? $maxim;
        } elseif (preg_match('/ірина|ирина/iu', $label) && ! preg_match('/виктор/iu', $label)) {
            $payer = $irina;
            $owner = $irina;
        } elseif (preg_match('/учебн/iu', $label) || $fp === 'adobe-firefly') {
            $owner = $eduHead;
            $payer = null;
        } elseif (preg_match('/^it$|^іт$/iu', $label) || ($item['sheet'] ?? '') === 'plugins') {
            $owner = $itHead ?? $maxim;
            $payer = $method === BillingPaymentMethod::Card ? $maxim : null;
        } elseif ($method === BillingPaymentMethod::Card && ! in_array($fp, ['edenai', 'openai', 'chatgpt'], true) && ($item['sheet'] ?? '') === 'services') {
            $owner = $itHead ?? $maxim;
            $payer = $maxim;
        }

        if (in_array($fp, ['edenai', 'openai', 'chatgpt'], true)) {
            $payer = null;
            $owner = null;
        }

        return [
            'payer' => $payer,
            'owner' => $owner,
            'owner_label' => $label !== '' ? $label : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{payer: ?User, owner: ?User, owner_label: ?string}  $people
     * @return array<string, mixed>
     */
    private function payload(array $item, array $people): array
    {
        $kind = $item['kind'] instanceof BillingKind ? $item['kind'] : BillingKind::Subscription;
        $method = $item['method'] instanceof BillingPaymentMethod ? $item['method'] : BillingPaymentMethod::Card;
        $state = $item['state'] instanceof BillingState ? $item['state'] : BillingState::Active;
        $period = $kind->requiresPeriodMonths() ? (int) ($item['period_months'] ?? 1) : null;
        $day = $item['day_of_month'] ?? null;
        $nextDue = $this->nextDue($item, $kind, $period, $day);

        $notes = array_filter([
            'Источник: '.($item['sheet'] ?? 'sheet'),
            ! empty($item['source_date']) ? 'Дата в таблице: '.$item['source_date'] : null,
            $item['overlay_note'] ?? null,
            $item['notes_extra'] ?? null,
        ]);

        return [
            'vendor' => $item['vendor'],
            'product' => Str::limit((string) $item['product'], 160, ''),
            'description' => $item['info'] ?? null,
            'category' => $this->categoryFor($item),
            'kind' => $kind,
            'period_months' => $period,
            'amount' => $item['amount'],
            'currency' => $item['amount'] === null ? null : ($item['currency'] ?? 'UAH'),
            'next_due_on' => $nextDue,
            'due_day_of_month' => $day,
            'due_day_rule' => $item['due_day_rule'] ?? null,
            'payment_method' => $method,
            'payer_user_id' => $people['payer']?->id,
            'owner_user_id' => $people['owner']?->id,
            'owner_label' => $people['owner_label'],
            'portal_url' => $item['url'] ?? null,
            'account_ref' => $item['info'] ?? null,
            'auto_renew' => (bool) ($item['auto_renew'] ?? false),
            'state' => $state,
            'archived_at' => $state === BillingState::Archived ? now() : null,
            'archive_reason' => $item['archive_reason'] ?? null,
            'notes' => implode("\n", $notes),
        ];
    }

    private function nextDue(array $item, BillingKind $kind, ?int $period, mixed $day): ?string
    {
        if (! $kind->requiresDueDate()) {
            return null;
        }

        $today = $this->cycle->today();
        $day = $day !== null ? (int) $day : null;
        $parsed = null;
        if (! empty($item['source_date']) && preg_match('/^\d{2}\.\d{2}\.\d{4}$/', (string) $item['source_date'])) {
            $parsed = Carbon::createFromFormat('d.m.Y', $item['source_date'], config('app.timezone'))?->startOfDay();
        }

        if ($parsed === null && $day !== null) {
            $parsed = $this->cycle->clampDay($today->copy()->startOfMonth(), $day);
            if ($parsed->lte($today) && $period) {
                return $this->cycle->advanceToFuture($parsed, $period, $day)->toDateString();
            }

            return $parsed->toDateString();
        }

        if ($parsed === null) {
            return null;
        }

        if ($parsed->gt($today)) {
            return $parsed->toDateString();
        }

        if (! $period) {
            return null;
        }

        return $this->cycle->advanceToFuture($parsed, $period, $day)->toDateString();
    }

    /** @return list<list<string>> */
    private function csv(string $dir, string $name): array
    {
        $gid = self::SHEET_GIDS[$name];
        $path = $dir.'/sheet_'.$gid.'.csv';
        if (! is_readable($path)) {
            throw new \InvalidArgumentException("Missing {$path}");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot read {$path}");
        }

        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = array_map(fn ($v) => is_string($v) ? $v : (string) $v, $row);
        }
        fclose($handle);

        return $rows;
    }
}
