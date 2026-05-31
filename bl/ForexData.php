<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../dal/Database.php';

// ============================================================
// BL — Business Logic: Forex Veri Servisi
// ExchangeRate API + Frankfurter API
// ============================================================
class ForexData {

    private static $ratesCache   = null;
    private static $ratesCacheTs = 0;
    private const  CACHE_TTL     = 300; // 5 dakika

    private static $pairs = [
        ['symbol' => 'EUR/USD', 'base' => 'EUR', 'quote' => 'USD'],
        ['symbol' => 'GBP/USD', 'base' => 'GBP', 'quote' => 'USD'],
        ['symbol' => 'USD/JPY', 'base' => 'USD', 'quote' => 'JPY'],
        ['symbol' => 'USD/CHF', 'base' => 'USD', 'quote' => 'CHF'],
        ['symbol' => 'AUD/USD', 'base' => 'AUD', 'quote' => 'USD'],
        ['symbol' => 'USD/CAD', 'base' => 'USD', 'quote' => 'CAD'],
        ['symbol' => 'NZD/USD', 'base' => 'NZD', 'quote' => 'USD'],
        ['symbol' => 'EUR/GBP', 'base' => 'EUR', 'quote' => 'GBP'],
        ['symbol' => 'EUR/JPY', 'base' => 'EUR', 'quote' => 'JPY'],
        ['symbol' => 'GBP/JPY', 'base' => 'GBP', 'quote' => 'JPY'],
        ['symbol' => 'USD/TRY', 'base' => 'USD', 'quote' => 'TRY'],
        ['symbol' => 'EUR/TRY', 'base' => 'EUR', 'quote' => 'TRY'],
        ['symbol' => 'XAU/USD', 'base' => 'XAU', 'quote' => 'USD'],
    ];

    // ── Kur verisini çek ─────────────────────────────────────
    public static function getRates(): ?array {
        $now = time();
        if (self::$ratesCache && ($now - self::$ratesCacheTs) < self::CACHE_TTL) {
            return self::$ratesCache;
        }

        $ctx  = stream_context_create(['http' => ['timeout' => 10]]);
        $json = @file_get_contents(EXCHANGE_API_URL, false, $ctx);
        if (!$json) return self::$ratesCache;

        $data = json_decode($json, true);
        if (($data['result'] ?? '') === 'success') {
            self::$ratesCache   = $data['conversion_rates'];
            self::$ratesCacheTs = $now;
            return self::$ratesCache;
        }
        return self::$ratesCache;
    }

    // ── Parite fiyatı hesapla ────────────────────────────────
    public static function calcPrice(array $rates, string $base, string $quote): ?float {
        if ($base === 'USD') return $rates[$quote] ?? null;
        if ($quote === 'USD') {
            $r = $rates[$base] ?? null;
            return $r ? 1 / $r : null;
        }
        // Capraz
        $rb = $rates[$base] ?? null;
        $rq = $rates[$quote] ?? null;
        if ($rb && $rq) return $rq / $rb;
        return null;
    }

    // ── Spread ───────────────────────────────────────────────
    public static function spread(float $price, string $symbol): array {
        $s = (str_contains($symbol, 'TRY') || str_contains($symbol, 'JPY')) ? 0.0002 : 0.00015;
        return ['bid' => $price * (1 - $s), 'ask' => $price * (1 + $s)];
    }

    // ── Decimal places ───────────────────────────────────────
    public static function dp(string $symbol): int {
        return (str_contains($symbol, 'JPY') || str_contains($symbol, 'TRY')) ? 2 : 4;
    }

    // ── Tüm pariteleri getir ─────────────────────────────────
    public static function getAllPrices(): array {
        $rates = self::getRates();
        if (!$rates) return [];

        $result = [];
        foreach (self::$pairs as $p) {
            $price = self::calcPrice($rates, $p['base'], $p['quote']);
            if (!$price) continue;

            $sp  = self::spread($price, $p['symbol']);
            $dp  = self::dp($p['symbol']);
            $off = self::mockChange($p['symbol']);

            $result[] = [
                'symbol'     => $p['symbol'],
                'base'       => $p['base'],
                'quote'      => $p['quote'],
                'bid'        => round($sp['bid'], $dp),
                'ask'        => round($sp['ask'], $dp),
                'mid'        => round($price, $dp),
                'change'     => round($off, $dp),
                'change_pct' => round(($off / $price) * 100, 4),
                'direction'  => $off >= 0 ? 'up' : 'down',
                'updated_at' => date('c'),
            ];
        }
        return $result;
    }

    // ── Mum verisi ───────────────────────────────────────────
    public static function getCandles(string $base, string $quote, string $tf = '1D'): array {
        // Önce DB'den bak
        $db     = Database::getInstance();
        $symbol = $base . '/' . $quote;
        $dbData = $db->callSP('sp_fiyat_getir', [$symbol, '60']);

        if (count($dbData) >= 10) {
            return array_map(fn($r) => [
                'time'   => $r['tarih'],
                'open'   => (float)$r['acilis'],
                'high'   => (float)$r['yuksek'],
                'low'    => (float)$r['dusuk'],
                'close'  => (float)$r['kapanis'],
                'volume' => (int)$r['hacim'],
            ], array_reverse($dbData));
        }

        // Frankfurter'dan çek
        $days  = ['1D' => 30, '1W' => 180, '1M' => 730][$tf] ?? 30;
        $end   = date('Y-m-d');
        $start = date('Y-m-d', strtotime("-{$days} days"));

        if ($base === 'XAU' || $quote === 'XAU') {
            return self::syntheticCandles($base, $quote, $days);
        }

        $apiBase = $quote === 'USD' ? $base : 'USD';
        $apiSym  = $quote === 'USD' ? $quote : ($base === 'USD' ? $quote : $quote);
        $url     = FRANKFURTER_URL . "/{$start}..{$end}?base={$apiBase}&symbols={$apiSym}";

        $ctx  = stream_context_create(['http' => ['timeout' => 15]]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return self::syntheticCandles($base, $quote, $days);

        $data = json_decode($json, true);
        if (!isset($data['rates'])) return self::syntheticCandles($base, $quote, $days);

        $candles = [];
        $dates   = array_keys($data['rates']);
        sort($dates);
        $symKey  = array_key_first($data['rates'][$dates[0]] ?? []);

        foreach ($dates as $d) {
            $raw = $data['rates'][$d][$symKey] ?? null;
            if (!$raw) continue;
            $close = $base === 'USD' ? $raw : 1 / $raw;
            $dp    = self::dp($symbol);

            srand(crc32($d));
            $vol  = $close * 0.005;
            $open = $close + (rand(-100, 100) / 100) * $vol;
            $high = max($open, $close) + abs((rand(0, 100) / 100) * $vol);
            $low  = min($open, $close) - abs((rand(0, 100) / 100) * $vol);

            $candle = [
                'time'   => $d,
                'open'   => round($open,  $dp),
                'high'   => round($high,  $dp),
                'low'    => round($low,   $dp),
                'close'  => round($close, $dp),
                'volume' => rand(50000, 200000),
            ];
            $candles[] = $candle;

            // DB'ye kaydet
            $db->callSPVoid('sp_fiyat_ekle', [
                $symbol,
                (string)$candle['open'],
                (string)$candle['high'],
                (string)$candle['low'],
                (string)$candle['close'],
                (string)$candle['volume'],
                $d,
            ]);
        }
        return $candles;
    }

    private static function syntheticCandles(string $base, string $quote, int $days): array {
        $rates = self::getRates() ?? [];
        $price = self::calcPrice($rates, $base, $quote);
        if (!$price) return [];

        $candles = [];
        $current = $price;
        $dp      = self::dp($base . '/' . $quote);

        for ($i = $days; $i > 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            srand(crc32($d . $base . $quote));
            $vol    = $current * 0.006;
            $change = (rand(-100, 100) / 100) * $vol;
            $open   = $current;
            $close  = $current + $change;
            $high   = max($open, $close) + abs((rand(0, 30) / 100) * $vol);
            $low    = min($open, $close) - abs((rand(0, 30) / 100) * $vol);

            $candles[] = [
                'time'   => $d,
                'open'   => round($open,  $dp),
                'high'   => round($high,  $dp),
                'low'    => round($low,   $dp),
                'close'  => round($close, $dp),
                'volume' => rand(40000, 180000),
            ];
            $current = $close;
        }
        return $candles;
    }

    private static function mockChange(string $symbol): float {
        $offsets = [
            'EUR/USD' => -0.0032, 'GBP/USD' => 0.0018, 'USD/JPY' => -0.72,
            'USD/CHF' => -0.0011, 'AUD/USD' => 0.0014, 'USD/CAD' => 0.0013,
            'NZD/USD' => 0.0013,  'EUR/GBP' => 0.0008, 'EUR/JPY' => 0.82,
            'GBP/JPY' => 0.44,    'USD/TRY' => 0.11,   'EUR/TRY' => 0.09,
            'XAU/USD' => 2.4,
        ];
        return $offsets[$symbol] ?? 0.0;
    }
}
