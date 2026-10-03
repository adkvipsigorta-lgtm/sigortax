<?php
/**
 * Migration: 1.1.8-permissions-tables
 * permissions ve user_permissions tablolarini olusturur.
 * Tablo zaten varsa atlar, eksik kolonlari ekler.
 */

// 1. permissions tablosu
SchemaHelper::ensureTable('permissions', [
    'id'          => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'key'         => 'VARCHAR(50) NOT NULL',
    'group'       => 'VARCHAR(50) NOT NULL',
    'group_label' => 'VARCHAR(100) NOT NULL',
    'label'       => 'VARCHAR(100) NOT NULL',
    'sort_order'  => 'INT NOT NULL DEFAULT 0',
]);
SchemaHelper::ensureIndex('permissions', 'uq_key', ['key'], true);
 

// 2. user_permissions tablosu
SchemaHelper::ensureTable('user_permissions', [
    'id'             => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'user_id'        => 'INT UNSIGNED NOT NULL',
    'permission_key' => 'VARCHAR(50) NOT NULL',
    'allowed'        => 'TINYINT(1) NOT NULL DEFAULT 1',
]);
SchemaHelper::ensureIndex('user_permissions', 'uq_user_perm', ['user_id', 'permission_key'], true);
SchemaHelper::ensureIndex('user_permissions', 'idx_user_id', ['user_id']);
 

// 3. Varsayilan izin tanimlari (varsa atla)
$pdo = Database::getInstance();
$stmt = $pdo->prepare("
    INSERT IGNORE INTO `permissions` (`id`, `key`, `group`, `group_label`, `label`, `sort_order`)
    VALUES (?, ?, ?, ?, ?, ?)
");

$rows = [
    [1,  'customers.view',          'customers',    'Müşteriler',          'Görüntüle',           10],
    [2,  'customers.manage',        'customers',    'Müşteriler',          'Ekle / Düzenle',      11],
    [3,  'customers.delete',        'customers',    'Müşteriler',          'Sil',                 12],
    [4,  'customers.export',        'customers',    'Müşteriler',          'Dışa Aktar',          13],
    [5,  'policies.view',           'policies',     'Poliçeler',           'Görüntüle',           20],
    [6,  'policies.manage',         'policies',     'Poliçeler',           'Ekle / Düzenle',      21],
    [7,  'policies.delete',         'policies',     'Poliçeler',           'Sil',                 22],
    [8,  'policies.export',         'policies',     'Poliçeler',           'Dışa Aktar',          23],
    [9,  'tasks.view',              'tasks',        'Görev Takibi',        'Görüntüle',           30],
    [10, 'tasks.manage',            'tasks',        'Görev Takibi',        'Yönet',               31],
    [11, 'tasks.export',            'tasks',        'Görev Takibi',        'Dışa Aktar',          32],
    [12, 'lost_policies.view',      'lost_policies','Kaçırılan Poliçeler', 'Görüntüle',           40],
    [13, 'lost_policies.manage',    'lost_policies','Kaçırılan Poliçeler', 'Yönet',               41],
    [14, 'offers.view',             'offers',       'Teklifler',           'Görüntüle',           50],
    [15, 'offers.manage',           'offers',       'Teklifler',           'Yönet',               51],
    [16, 'messages.view',           'messages',     'Mesajlar',            'Görüntüle',           60],
    [17, 'messages.send',           'messages',     'Mesajlar',            'Gönder',              61],
    [18, 'reports.view',            'reports',      'Raporlar',            'Görüntüle',           70],
    [19, 'performance.view',        'performance',  'Satış Performansı',   'Görüntüle',           80],
    [20, 'portfolio.view',          'portfolio',    'Portföy',             'Görüntüle',           90],
    [21, 'personnel.view',          'personnel',    'Personel / İK',       'Görüntüle',          100],
    [22, 'personnel.manage',        'personnel',    'Personel / İK',       'Yönet',              101],
    [23, 'tools.excel_import',      'tools',        'Araçlar',             'Excel Import',       110],
    [24, 'tools.allianz_import',    'tools',        'Araçlar',             'Allianz Import',     111],
    [25, 'tools.reconciliation',    'tools',        'Araçlar',             'Mutabakat',          112],
    [26, 'tools.cross_sell',        'tools',        'Araçlar',             'Çapraz Satış',       113],
    [27, 'settings.users',          'settings',     'Ayarlar',             'Kullanıcı Yönetimi', 120],
    [28, 'settings.companies',      'settings',     'Ayarlar',             'Şirketler',          121],
    [29, 'settings.insurance_types','settings',     'Ayarlar',             'Sigorta Türleri',    122],
    [30, 'settings.branches',       'settings',     'Ayarlar',             'Tali Acenteler',     123],
    [31, 'settings.references',     'settings',     'Ayarlar',             'Referans Kaynakları',124],
    [32, 'settings.follow_up',      'settings',     'Ayarlar',             'Takip Aramaları',    125],
];

foreach ($rows as $row) {
    $stmt->execute($row);
}
 
