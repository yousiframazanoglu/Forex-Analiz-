<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../dal/Database.php';
require_once __DIR__ . '/../bl/Auth.php';
require_once __DIR__ . '/../bl/Mail.php';
require_once __DIR__ . '/../config/database.php';

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$db     = Database::getInstance();

switch ($action) {

    // ── Kayıt ─────────────────────────────────────────────────
    case 'register':
        $email    = strtolower(trim($body['email']    ?? ''));
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        $fullname = trim($body['full_name'] ?? '');

        if (!$email || !$username || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Tum alanlari doldurun']);
            exit;
        }
        if (strlen($password) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'Sifre en az 6 karakter olmalidir']);
            exit;
        }

        $existing = $db->queryOne(
            'SELECT kullanici_id FROM kullanicilar WHERE email=? OR kullanici_adi=?',
            [$email, $username]
        );
        if ($existing) {
            http_response_code(409);
            echo json_encode(['error' => 'Bu email veya kullanici adi zaten kayitli']);
            exit;
        }

        $hash  = Auth::hashPassword($password);
        $token = Auth::createVerifyToken();
        $db->callSPVoid('sp_kullanici_ekle', [$email, $username, $hash, $fullname, $token]);

        Mail::send($email, 'ForexAnaliz — E-posta Dogrulama', Mail::tplVerify($username, $token));
        echo json_encode(['message' => 'Kayit basarili. E-postanizi kontrol edin.']);
        break;

    // ── Email Doğrulama ───────────────────────────────────────
    case 'verify_email':
        $token  = $_GET['token'] ?? '';
        $result = $db->callSPOne('sp_email_dogrula', [$token]);

        if (!$result || $result['sonuc'] !== 'basarili') {
            http_response_code(400);
            echo '<h2>Gecersiz dogrulama linki.</h2>';
            exit;
        }

        $user = $db->callSPOne('sp_kullanici_getir_id', [(string)$result['kullanici_id']]);
        if ($user) {
            Mail::send($user['email'], "ForexAnaliz'e Hosgeldin!", Mail::tplWelcome($user['kullanici_adi']));
        }

        header('Content-Type: text/html');
        echo "<html><head><meta http-equiv='refresh' content='3;url=" . APP_URL . "/frontend/index.html'></head>
              <body style='font-family:sans-serif;text-align:center;padding:60px;'>
              <h2 style='color:#E8731A;'>✓ E-posta dogrulandi!</h2>
              <p>3 saniye icinde yonlendiriliyorsunuz...</p></body></html>";
        break;

    // ── Giriş ─────────────────────────────────────────────────
    case 'login':
        $email    = strtolower(trim($body['email']    ?? ''));
        $password = $body['password'] ?? '';

        $user = $db->callSPOne('sp_kullanici_getir_email', [$email]);
        if (!$user || !Auth::verifyPassword($password, $user['sifre_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Email veya sifre yanlis']);
            exit;
        }
        if (!$user['aktif']) {
            http_response_code(403);
            echo json_encode(['error' => 'Lutfen once e-postanizi dogrulayin']);
            exit;
        }

        $refresh = Auth::createRefreshToken();
        $expires = date('Y-m-d H:i:s', time() + REFRESH_EXPIRE);
        $db->query(
            'INSERT INTO oturumlar (kullanici_id, token, bitis_tarihi) VALUES (?,?,?)',
            [(string)$user['kullanici_id'], $refresh, $expires]
        );
        $db->callSPVoid('sp_son_giris_guncelle', [(string)$user['kullanici_id']]);

        $access = Auth::createAccessToken(['sub' => $user['kullanici_id'], 'email' => $user['email']]);
        echo json_encode([
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'token_type'    => 'bearer',
            'user' => [
                'id'        => $user['kullanici_id'],
                'email'     => $user['email'],
                'username'  => $user['kullanici_adi'],
                'full_name' => $user['ad_soyad'],
                'is_admin'  => $user['admin'],
            ]
        ]);
        break;

    // ── Token Yenile ──────────────────────────────────────────
    case 'refresh':
        $token = $body['refresh_token'] ?? '';
        if (!$token) { http_response_code(400); echo json_encode(['error' => 'Token gerekli']); exit; }

        $row = $db->queryOne(
            'SELECT o.*, k.kullanici_id AS uid, k.email
             FROM oturumlar o JOIN kullanicilar k ON o.kullanici_id=k.kullanici_id
             WHERE o.token=?',
            [$token]
        );
        if (!$row) { http_response_code(401); echo json_encode(['error' => 'Gecersiz token']); exit; }
        if (strtotime($row['bitis_tarihi']) < time()) {
            $db->query('DELETE FROM oturumlar WHERE token=?', [$token]);
            http_response_code(401);
            echo json_encode(['error' => 'Token suresi dolmus']);
            exit;
        }

        $access = Auth::createAccessToken(['sub' => $row['uid'], 'email' => $row['email']]);
        echo json_encode(['access_token' => $access, 'token_type' => 'bearer']);
        break;

    // ── Çıkış ─────────────────────────────────────────────────
    case 'logout':
        $token = $body['refresh_token'] ?? '';
        if ($token) $db->query('DELETE FROM oturumlar WHERE token=?', [$token]);
        echo json_encode(['message' => 'Cikis yapildi']);
        break;

    // ── Profil getir ──────────────────────────────────────────
    case 'me':
        $user = Auth::requireAuth();
        echo json_encode([
            'id'         => $user['kullanici_id'],
            'email'      => $user['email'],
            'username'   => $user['kullanici_adi'],
            'full_name'  => $user['ad_soyad'],
            'is_admin'   => $user['admin'],
            'created_at' => $user['kayit_tarihi'],
            'last_login' => $user['son_giris'],
        ]);
        break;

    // ── Profil güncelle ───────────────────────────────────────
    case 'update_me':
        $user     = Auth::requireAuth();
        $fullname = $body['full_name'] ?? null;
        $username = $body['username']  ?? null;

        if ($username) {
            $exists = $db->queryOne(
                'SELECT kullanici_id FROM kullanicilar WHERE kullanici_adi=? AND kullanici_id!=?',
                [$username, (string)$user['kullanici_id']]
            );
            if ($exists) {
                http_response_code(409);
                echo json_encode(['error' => 'Bu kullanici adi zaten kullaniliyor']);
                exit;
            }
        }
        $db->callSPVoid('sp_kullanici_guncelle', [(string)$user['kullanici_id'], $fullname, $username]);
        echo json_encode(['message' => 'Profil guncellendi']);
        break;

    // ── Şifre Değiştir ────────────────────────────────────────
    case 'change_password':
        $user    = Auth::requireAuth();
        $oldPass = $body['old_password'] ?? '';
        $newPass = $body['new_password'] ?? '';

        $full = $db->callSPOne('sp_kullanici_getir_email', [$user['email']]);
        if (!Auth::verifyPassword($oldPass, $full['sifre_hash'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Mevcut sifre yanlis']);
            exit;
        }
        if (strlen($newPass) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'Yeni sifre en az 6 karakter olmalidir']);
            exit;
        }
        $db->callSPVoid('sp_sifre_guncelle', [(string)$user['kullanici_id'], Auth::hashPassword($newPass)]);
        echo json_encode(['message' => 'Sifre guncellendi']);
        break;

    // ── Şifremi Unuttum ───────────────────────────────────────
    case 'forgot_password':
        $email = strtolower(trim($body['email'] ?? ''));
        $user  = $db->callSPOne('sp_kullanici_getir_email', [$email]);

        if ($user) {
            $token = Auth::createResetToken();
            $exp   = date('Y-m-d H:i:s', time() + 3600);
            $db->query(
                'UPDATE kullanicilar SET reset_token=?, reset_token_exp=? WHERE kullanici_id=?',
                [$token, $exp, (string)$user['kullanici_id']]
            );
            Mail::send($email, 'ForexAnaliz — Sifre Sifirlama', Mail::tplReset($user['kullanici_adi'], $token));
        }
        echo json_encode(['message' => 'Eger bu email kayitliysa sifre sifirlama linki gonderildi']);
        break;

    // ── Şifre Sıfırla ─────────────────────────────────────────
    case 'reset_password':
        $token   = $body['token']        ?? '';
        $newPass = $body['new_password'] ?? '';

        if (strlen($newPass) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'Sifre en az 6 karakter olmalidir']);
            exit;
        }

        $user = $db->queryOne('SELECT * FROM kullanicilar WHERE reset_token=?', [$token]);
        if (!$user) { http_response_code(400); echo json_encode(['error' => 'Gecersiz link']); exit; }
        if (strtotime($user['reset_token_exp']) < time()) {
            http_response_code(400);
            echo json_encode(['error' => 'Linkin suresi dolmus']);
            exit;
        }

        $db->callSPVoid('sp_sifre_guncelle', [(string)$user['kullanici_id'], Auth::hashPassword($newPass)]);
        echo json_encode(['message' => 'Sifre basariyla guncellendi']);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Gecersiz action']);
}
