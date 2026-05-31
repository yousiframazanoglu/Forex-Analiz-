<?php
require_once __DIR__ . '/../config/database.php';

// ============================================================
// DAL — Data Access Layer
// Tüm veritabanı işlemleri SADECE bu sınıftan geçer
// Tüm işlemler Stored Procedure üzerinden yapılır
// ============================================================
class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);
        if ($this->conn->connect_error) {
            die(json_encode(['error' => 'Veritabani baglantisi basarisiz: ' . $this->conn->connect_error]));
        }
        $this->conn->set_charset(DB_CHARSET);
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConn(): mysqli {
        return $this->conn;
    }

    // ── SP çağır, çok satır döndür ──────────────────────────
    public function callSP(string $proc, array $params = []): array {
        $conn = $this->conn;
        $placeholders = str_repeat('?,', count($params));
        $placeholders = rtrim($placeholders, ',');
        $sql = "CALL {$proc}(" . ($placeholders ?: '') . ")";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];

        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        // Çoklu result set varsa temizle
        while ($conn->more_results() && $conn->next_result()) {
            if ($res = $conn->store_result()) $res->free();
        }
        $stmt->close();
        return $rows;
    }

    // ── SP çağır, tek satır döndür ──────────────────────────
    public function callSPOne(string $proc, array $params = []): ?array {
        $rows = $this->callSP($proc, $params);
        return $rows[0] ?? null;
    }

    // ── SP çağır, sonuç yok ──────────────────────────────────
    public function callSPVoid(string $proc, array $params = []): bool {
        $conn = $this->conn;
        $placeholders = str_repeat('?,', count($params));
        $placeholders = rtrim($placeholders, ',');
        $sql = "CALL {$proc}(" . ($placeholders ?: '') . ")";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;

        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }

        $result = $stmt->execute();
        while ($conn->more_results() && $conn->next_result()) {
            if ($res = $conn->store_result()) $res->free();
        }
        $stmt->close();
        return $result;
    }

    // ── MySQL FUNCTION çağır ─────────────────────────────────
    public function callFn(string $fn, array $params = []) {
        $placeholders = str_repeat('?,', count($params));
        $placeholders = rtrim($placeholders, ',');
        $sql = "SELECT {$fn}({$placeholders}) AS sonuc";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return null;

        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row    = $result->fetch_assoc();
        $stmt->close();
        return $row['sonuc'] ?? null;
    }

    // ── Doğrudan sorgu (token işlemleri için) ───────────────
    public function query(string $sql, array $params = [], string $types = ''): array|bool {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return false;

        if (!empty($params)) {
            $t = $types ?: str_repeat('s', count($params));
            $stmt->bind_param($t, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        if ($result === false) {
            $stmt->close();
            return true; // INSERT/UPDATE/DELETE
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    public function queryOne(string $sql, array $params = []): ?array {
        $result = $this->query($sql, $params);
        if (is_array($result) && count($result) > 0) return $result[0];
        return null;
    }

    public function lastInsertId(): int {
        return $this->conn->insert_id;
    }
}
