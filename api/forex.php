<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../dal/Database.php';
require_once __DIR__ . '/../bl/Auth.php';
require_once __DIR__ . '/../bl/ForexData.php';
require_once __DIR__ . '/../bl/Mail.php';
require_once __DIR__ . '/../config/database.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$db     = Database::getInstance();

switch ($action) {

    // ── Tüm fiyatlar ──────────────────────────────────────────
    case 'prices':
        echo json_encode(['data' => ForexData::getAllPrices()]);
        break;

    // ── Tek fiyat ─────────────────────────────────────────────
    case 'price':
        $symbol = strtoupper($_GET['symbol'] ?? '');
        $prices = ForexData::getAllPrices();
        $found  = array_filter($prices, fn($p) => $p['symbol'] === $symbol);
        $found  = array_values($found);
        if (empty($found)) { http_response_code(404); echo json_encode(['error' => 'Bulunamadi']); exit; }
        echo json_encode($found[0]);
        break;

    // ── Mum verisi ────────────────────────────────────────────
    case 'candles':
        $symbol = strtoupper(str_replace('-', '/', $_GET['symbol'] ?? 'EUR/USD'));
        $tf     = $_GET['tf'] ?? '1D';
        $pairs  = [
            'EUR/USD'=>['EUR','USD'], 'GBP/USD'=>['GBP','USD'],
            'USD/JPY'=>['USD','JPY'], 'USD/CHF'=>['USD','CHF'],
            'AUD/USD'=>['AUD','USD'], 'USD/CAD'=>['USD','CAD'],
            'NZD/USD'=>['NZD','USD'], 'EUR/GBP'=>['EUR','GBP'],
            'EUR/JPY'=>['EUR','JPY'], 'GBP/JPY'=>['GBP','JPY'],
            'USD/TRY'=>['USD','TRY'], 'EUR/TRY'=>['EUR','TRY'],
            'XAU/USD'=>['XAU','USD'],
        ];
        if (!isset($pairs[$symbol])) { http_response_code(404); echo json_encode(['error' => 'Desteklenmiyor']); exit; }
        [$base, $quote] = $pairs[$symbol];
        $candles = ForexData::getCandles($base, $quote, $tf);
        echo json_encode(['symbol' => $symbol, 'timeframe' => $tf, 'data' => $candles]);
        break;

    // ── Risk/Kazanç (MySQL FUNCTION) ──────────────────────────
    case 'risk_kazanc':
        $giris = (float)($_GET['giris'] ?? 0);
        $sl    = (float)($_GET['sl']    ?? 0);
        $tp    = (float)($_GET['tp']    ?? 0);
        $oran  = $db->callFn('fn_risk_kazanc_orani', [(string)$giris, (string)$sl, (string)$tp]);
        echo json_encode(['giris' => $giris, 'sl' => $sl, 'tp' => $tp, 'oran' => (float)$oran]);
        break;

    // ── Pip değeri (MySQL FUNCTION) ───────────────────────────
    case 'pip_degeri':
        $sembol = $_GET['sembol'] ?? 'EUR/USD';
        $lot    = (float)($_GET['lot'] ?? 1);
        $kur    = (float)($_GET['kur'] ?? 1);
        $deger  = $db->callFn('fn_pip_degeri', [$sembol, (string)$lot, (string)$kur]);
        echo json_encode(['sembol' => $sembol, 'lot' => $lot, 'kur' => $kur, 'pip_degeri' => (float)$deger]);
        break;

    // ── Ekonomik takvim ───────────────────────────────────────
    case 'takvim':
        $rows = $db->callSP('sp_takvim_getir', []);
        echo json_encode(['data' => $rows]);
        break;

    // ── İletişim formu ────────────────────────────────────────
    case 'contact':
        $adSoyad = trim($body['ad_soyad'] ?? '');
        $email   = trim($body['email']    ?? '');
        $konu    = trim($body['konu']     ?? '');
        $mesaj   = trim($body['mesaj']    ?? '');

        if (!$adSoyad || !$email || !$konu || !$mesaj) {
            http_response_code(400);
            echo json_encode(['error' => 'Tum alanlari doldurun']);
            exit;
        }
        $db->callSPVoid('sp_mesaj_ekle', [$adSoyad, $email, $konu, $mesaj]);
        Mail::send($email, 'Mesajiniz Alindi', Mail::tplContactConfirm($adSoyad));
        echo json_encode(['message' => 'Mesajiniz gonderildi']);
        break;

    // ── Favoriler ─────────────────────────────────────────────
    case 'favorites':
        $user = Auth::requireAuth();
        if ($method === 'GET') {
            $rows = $db->callSP('sp_favorileri_getir', [(string)$user['kullanici_id']]);
            echo json_encode(['data' => array_column($rows, 'sembol')]);
        } elseif ($method === 'POST') {
            $symbol   = strtoupper($body['symbol'] ?? '');
            $pariteId = $db->callFn('fn_parite_id_bul', [$symbol]);
            if ($pariteId) $db->callSPVoid('sp_favori_ekle', [(string)$user['kullanici_id'], (string)$pariteId]);
            echo json_encode(['message' => "{$symbol} favorilere eklendi"]);
        } elseif ($method === 'DELETE') {
            $symbol   = strtoupper($body['symbol'] ?? $_GET['symbol'] ?? '');
            $pariteId = $db->callFn('fn_parite_id_bul', [$symbol]);
            if ($pariteId) $db->callSPVoid('sp_favori_sil', [(string)$user['kullanici_id'], (string)$pariteId]);
            echo json_encode(['message' => "{$symbol} favorilerden cikarildi"]);
        }
        break;

    // ── Alarmlar ──────────────────────────────────────────────
    case 'alerts':
        $user = Auth::requireAuth();
        if ($method === 'GET') {
            $rows = $db->callSP('sp_alarmlari_getir', [(string)$user['kullanici_id']]);
            echo json_encode(['data' => $rows]);
        } elseif ($method === 'POST') {
            $symbol   = strtoupper($body['symbol']    ?? '');
            $yon      = $body['direction'] ?? 'yukari';
            $hedef    = (float)($body['target'] ?? 0);
            $pariteId = $db->callFn('fn_parite_id_bul', [$symbol]);
            if ($pariteId) {
                $db->callSPVoid('sp_alarm_ekle', [
                    (string)$user['kullanici_id'], (string)$pariteId, $yon, (string)$hedef
                ]);
            }
            echo json_encode(['message' => 'Alarm olusturuldu']);
        } elseif ($method === 'DELETE') {
            $alarmId = (int)($body['alarm_id'] ?? $_GET['id'] ?? 0);
            $db->callSPVoid('sp_alarm_sil', [(string)$alarmId, (string)$user['kullanici_id']]);
            echo json_encode(['message' => 'Alarm silindi']);
        }
        break;

    // ── İşlem notları ─────────────────────────────────────────
    case 'notes':
        $user = Auth::requireAuth();
        if ($method === 'GET') {
            $rows = $db->callSP('sp_notlari_getir', [(string)$user['kullanici_id']]);
            echo json_encode(['data' => $rows]);
        } elseif ($method === 'POST') {
            $symbol   = strtoupper($body['symbol'] ?? '');
            $pariteId = $db->callFn('fn_parite_id_bul', [$symbol]);
            if ($pariteId) {
                $db->callSPVoid('sp_not_ekle', [
                    (string)$user['kullanici_id'],
                    (string)$pariteId,
                    $body['direction']  ?? 'al',
                    (string)($body['entry'] ?? 0),
                    (string)($body['sl']    ?? 0),
                    (string)($body['tp']    ?? 0),
                    (string)($body['lot']   ?? 0),
                    $body['note'] ?? '',
                ]);
            }
            echo json_encode(['message' => 'Not kaydedildi']);
        } elseif ($method === 'DELETE') {
            $notId = (int)($body['note_id'] ?? $_GET['id'] ?? 0);
            $db->callSPVoid('sp_not_sil', [(string)$notId, (string)$user['kullanici_id']]);
            echo json_encode(['message' => 'Not silindi']);
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Gecersiz action']);
}
