# ForexAnaliz PHP — Kurulum Rehberi

## Klasör Yapısı
```
ForexAnaliz_PHP/
├── config/
│   └── database.php       ← MySQL + SMTP ayarları
├── dal/
│   └── Database.php       ← DAL katmanı (SP çağrıları)
├── bl/
│   ├── Auth.php           ← JWT + şifre işlemleri
│   ├── Mail.php           ← Gmail SMTP
│   └── ForexData.php      ← Canlı kur + mum verisi
├── api/
│   ├── auth.php           ← Auth endpoint'leri
│   └── forex.php          ← Forex endpoint'leri
└── frontend/              ← HTML/CSS/JS
    ├── index.html
    ├── piyasalar.html
    ├── chart.html
    ├── giris.html / kayit.html / profil.html
    ├── api.js             ← PHP backend'e bağlı
    ├── chart.js
    └── style.css
```

## N-Katmanlı Mimari
```
[UI - HTML/JS]
     ↓ fetch()
[API - api/auth.php, api/forex.php]
     ↓
[BL  - bl/Auth.php, bl/Mail.php, bl/ForexData.php]
     ↓
[DAL - dal/Database.php → MySQL Stored Procedures]
     ↓
[DB  - MySQL (forexanaliz)]
```

---

## Adım 1 — XAMPP Kur ve Başlat
1. XAMPP Control Panel
2. Apache → Start
3. MySQL → Start

---

## Adım 2 — Projeyi XAMPP'a Koy
`ForexAnaliz_PHP` klasörünü şuraya kopyala:
```
C:\xampp\htdocs\ForexAnaliz_PHP\
```

---

## Adım 3 — Veritabanı Kur
1. `http://localhost/phpmyadmin` aç
2. `forexanaliz` veritabanı seçili
3. SQL sekmesi → `forexanaliz_mysql.sql` içeriğini yapıştır → Git

(SQL dosyası ForexAnaliz_FINAL/backend klasöründe)

---

## Adım 4 — Siteyi Aç
```
http://localhost/ForexAnaliz_PHP/frontend/index.html
```

---

## API Endpoint'leri
- Auth:  http://localhost/ForexAnaliz_PHP/api/auth.php?action=login
- Forex: http://localhost/ForexAnaliz_PHP/api/forex.php?action=prices
