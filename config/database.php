<?php
// ============================================================
// ForexAnaliz — Veritabanı Konfigürasyonu
// ============================================================

define('DB_HOST',     'localhost');
define('DB_PORT',     3306);
define('DB_USER',     'root');
define('DB_PASSWORD', '');
define('DB_NAME',     'forexanaliz');
define('DB_CHARSET',  'utf8mb4');

// JWT
define('JWT_SECRET',  'forexanaliz-super-secret-key-2026-bartu');
define('JWT_EXPIRE',  86400);        // 1 gün (saniye)
define('REFRESH_EXPIRE', 2592000);   // 30 gün

// Gmail SMTP
define('SMTP_HOST',     'smtp.gmail.com');
define('SMTP_PORT',     587);
define('SMTP_USER',     'yousefalrmdan@gmail.com');
define('SMTP_PASSWORD', 'edjrhkofiurvichp');
define('SMTP_FROM',     'ForexAnaliz <yousefalrmdan@gmail.com>');

// ExchangeRate API
define('EXCHANGE_API_KEY', '5f2a420f3d7f7b00c810a2bd');
define('EXCHANGE_API_URL', 'https://v6.exchangerate-api.com/v6/' . EXCHANGE_API_KEY . '/latest/USD');

// Frankfurter API
define('FRANKFURTER_URL', 'https://api.frankfurter.app');

// Uygulama
define('APP_URL',  'http://localhost/ForexAnaliz_PHP');
define('APP_NAME', 'ForexAnaliz');
