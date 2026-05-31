<?php
require_once __DIR__ . '/../config/database.php';

// ============================================================
// BL — Business Logic: Auth
// JWT, şifre hash, token yönetimi
// ============================================================
class Auth {

    // ── Şifre hash ───────────────────────────────────────────
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    // ── JWT ──────────────────────────────────────────────────
    public static function createAccessToken(array $payload): string {
        $header  = self::base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['exp']  = time() + JWT_EXPIRE;
        $payload['type'] = 'access';
        $body    = self::base64url(json_encode($payload));
        $sig     = self::base64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
        return "$header.$body.$sig";
    }

    public static function decodeAccessToken(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $body, $sig] = $parts;
        $expected = self::base64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
        if (!hash_equals($expected, $sig)) return null;

        $payload = json_decode(self::base64urlDecode($body), true);
        if (!$payload || ($payload['exp'] ?? 0) < time()) return null;
        if (($payload['type'] ?? '') !== 'access') return null;

        return $payload;
    }

    public static function createRefreshToken(): string {
        return bin2hex(random_bytes(64));
    }

    public static function createVerifyToken(): string {
        return bin2hex(random_bytes(32));
    }

    public static function createResetToken(): string {
        return bin2hex(random_bytes(32));
    }

    // ── Request'ten token al ─────────────────────────────────
    public static function getBearerToken(): ?string {
        $headers = getallheaders();
        $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return $m[1];
        }
        return null;
    }

    // ── Mevcut kullanıcıyı doğrula ───────────────────────────
    public static function getCurrentUser(): ?array {
        $token = self::getBearerToken();
        if (!$token) return null;

        $payload = self::decodeAccessToken($token);
        if (!$payload) return null;

        $db   = \Database::getInstance();
        $user = $db->callSPOne('sp_kullanici_getir_id', [(string)$payload['sub']]);
        return $user;
    }

    public static function requireAuth(): array {
        $user = self::getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Yetkisiz erisim']);
            exit;
        }
        return $user;
    }

    // ── Yardımcı ─────────────────────────────────────────────
    private static function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
