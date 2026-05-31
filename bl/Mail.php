<?php
require_once __DIR__ . '/../config/database.php';

// ============================================================
// BL — Business Logic: Mail
// Gmail SMTP ile mail gönderimi (PHPMailer olmadan, socket)
// ============================================================
class Mail {

    public static function send(string $to, string $subject, string $html): bool {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . SMTP_FROM . "\r\n";
        $headers .= "Reply-To: " . SMTP_USER . "\r\n";

        // PHP mail() fonksiyonu XAMPP'ta localhost'ta çalışmayabilir
        // smtp_mailer kullanıyoruz
        return self::smtpSend($to, $subject, $html);
    }

    private static function smtpSend(string $to, string $subject, string $html): bool {
        try {
            $socket = fsockopen('ssl://' . SMTP_HOST, 465, $errno, $errstr, 10);
            if (!$socket) {
                // TLS dene
                $socket = fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 10);
                if (!$socket) return false;
            }

            $response = fgets($socket, 515);

            // EHLO
            fputs($socket, "EHLO localhost\r\n");
            while ($line = fgets($socket, 515)) {
                if (substr($line, 3, 1) === ' ') break;
            }

            // STARTTLS (port 587)
            if (SMTP_PORT == 587) {
                fputs($socket, "STARTTLS\r\n");
                fgets($socket, 515);
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                fputs($socket, "EHLO localhost\r\n");
                while ($line = fgets($socket, 515)) {
                    if (substr($line, 3, 1) === ' ') break;
                }
            }

            // AUTH LOGIN
            fputs($socket, "AUTH LOGIN\r\n");
            fgets($socket, 515);
            fputs($socket, base64_encode(SMTP_USER) . "\r\n");
            fgets($socket, 515);
            fputs($socket, base64_encode(SMTP_PASSWORD) . "\r\n");
            $auth = fgets($socket, 515);
            if (substr($auth, 0, 3) !== '235') {
                fclose($socket);
                return false;
            }

            // MAIL FROM
            fputs($socket, "MAIL FROM:<" . SMTP_USER . ">\r\n");
            fgets($socket, 515);

            // RCPT TO
            fputs($socket, "RCPT TO:<{$to}>\r\n");
            fgets($socket, 515);

            // DATA
            fputs($socket, "DATA\r\n");
            fgets($socket, 515);

            $msg  = "To: {$to}\r\n";
            $msg .= "From: " . SMTP_FROM . "\r\n";
            $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $msg .= "MIME-Version: 1.0\r\n";
            $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
            $msg .= "\r\n";
            $msg .= $html . "\r\n.\r\n";

            fputs($socket, $msg);
            fgets($socket, 515);

            fputs($socket, "QUIT\r\n");
            fclose($socket);
            return true;

        } catch (Exception $e) {
            error_log("Mail hatasi: " . $e->getMessage());
            return false;
        }
    }

    // ── Mail şablonları ──────────────────────────────────────
    public static function tplVerify(string $username, string $token): string {
        $url = APP_URL . "/api/auth.php?action=verify_email&token={$token}";
        return "
        <div style='font-family:sans-serif;max-width:520px;margin:auto;padding:32px;background:#fff;border:1px solid #eee;border-radius:12px;'>
          <h2 style='color:#E8731A;'>ForexAnaliz — E-posta Dogrulama</h2>
          <p>Merhaba <strong>{$username}</strong>,</p>
          <p>Hesabini aktiflesirmek icin asagidaki butona tikla:</p>
          <a href='{$url}' style='display:inline-block;background:#E8731A;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;margin:16px 0;'>
            E-postami Dogrula
          </a>
          <p style='color:#999;font-size:.82rem;'>Bu link 24 saat gecerlidir.</p>
        </div>";
    }

    public static function tplReset(string $username, string $token): string {
        $url = APP_URL . "/frontend/sifre-sifirla.html?token={$token}";
        return "
        <div style='font-family:sans-serif;max-width:520px;margin:auto;padding:32px;background:#fff;border:1px solid #eee;border-radius:12px;'>
          <h2 style='color:#E8731A;'>ForexAnaliz — Sifre Sifirlama</h2>
          <p>Merhaba <strong>{$username}</strong>,</p>
          <a href='{$url}' style='display:inline-block;background:#E8731A;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;margin:16px 0;'>
            Sifremi Sifirla
          </a>
          <p style='color:#999;font-size:.82rem;'>Bu link 1 saat gecerlidir.</p>
        </div>";
    }

    public static function tplWelcome(string $username): string {
        return "
        <div style='font-family:sans-serif;max-width:520px;margin:auto;padding:32px;background:#fff;border:1px solid #eee;border-radius:12px;'>
          <h2 style='color:#E8731A;'>ForexAnaliz'e Hosgeldin!</h2>
          <p>Merhaba <strong>{$username}</strong>, hesabin aktif edildi.</p>
          <a href='" . APP_URL . "/frontend/index.html' style='display:inline-block;background:#E8731A;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;'>
            Platforma Git
          </a>
        </div>";
    }

    public static function tplContactConfirm(string $adSoyad): string {
        return "
        <div style='font-family:sans-serif;max-width:520px;margin:auto;padding:32px;background:#fff;border:1px solid #eee;border-radius:12px;'>
          <h2 style='color:#E8731A;'>Mesajiniz Alindi</h2>
          <p>Merhaba <strong>{$adSoyad}</strong>, mesajiniz tarafimiza ulasti.</p>
          <p style='color:#999;font-size:.82rem;'>ForexAnaliz Ekibi</p>
        </div>";
    }
}
