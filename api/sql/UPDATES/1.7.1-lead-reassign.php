<?php
/**
 * Migration: 1.7.1-lead-reassign
 * Lead otomatik yeniden atama: assigned_at, skipped_users kolonlari
 * + lead_holidays tablosu (resmi tatiller)
 * + lead_reassign_timeout ayari
 */

// leads tablosuna yeni kolonlar
SchemaHelper::ensureColumn('leads', 'assigned_at', "DATETIME NULL COMMENT 'Son atanma zamani'", 'assigned_to');
SchemaHelper::ensureColumn('leads', 'skipped_users', "JSON NULL COMMENT 'Denenmis personel id listesi'", 'assigned_at');

// Resmi tatiller tablosu
SchemaHelper::ensureTable('lead_holidays', [
    'id'         => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'name'       => "VARCHAR(100) NOT NULL COMMENT 'Tatil adi'",
    'date'       => "DATE NOT NULL COMMENT 'Tatil tarihi'",
    'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
]);

SchemaHelper::ensureIndex('lead_holidays', 'idx_lead_holidays_date', ['date'], true);

// Varsayilan resmi tatiller (2026-2027)
$holidays = [
    ['1 Ocak Yılbaşı', '2026-01-01'],
    ['1 Ocak Yılbaşı', '2027-01-01'],
    ['23 Nisan Ulusal Egemenlik', '2026-04-23'],
    ['23 Nisan Ulusal Egemenlik', '2027-04-23'],
    ['1 Mayıs İşçi Bayramı', '2026-05-01'],
    ['1 Mayıs İşçi Bayramı', '2027-05-01'],
    ['19 Mayıs Gençlik Bayramı', '2026-05-19'],
    ['19 Mayıs Gençlik Bayramı', '2027-05-19'],
    ['15 Temmuz Demokrasi Bayramı', '2026-07-15'],
    ['15 Temmuz Demokrasi Bayramı', '2027-07-15'],
    ['30 Ağustos Zafer Bayramı', '2026-08-30'],
    ['30 Ağustos Zafer Bayramı', '2027-08-30'],
    ['29 Ekim Cumhuriyet Bayramı', '2026-10-29'],
    ['29 Ekim Cumhuriyet Bayramı', '2027-10-29'],
];

foreach ($holidays as [$name, $date]) {
    $exists = Database::fetch("SELECT id FROM lead_holidays WHERE date = ?", [$date]);
    if (!$exists) {
        Database::insert('lead_holidays', [
            'name' => $name,
            'date' => $date,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

// Yeniden atama süresi ayarı (dakika)
$setting = Database::fetch("SELECT `key` FROM settings WHERE `key` = 'lead_reassign_timeout'");
if (!$setting) {
    Database::insert('settings', [
        'key' => 'lead_reassign_timeout',
        'value' => '30',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
