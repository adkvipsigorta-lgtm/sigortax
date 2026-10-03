-- =============================================
-- Sigorta CRM - Demo Data Seed
-- Şifre tüm kullanıcılar için: demo1234
-- Oluşturulma: 2026-07-08
-- =============================================

SET NAMES utf8mb4;
SET time_zone = '+03:00';
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================
-- PERMISSIONS
-- =============================================
INSERT IGNORE INTO `permissions` (`id`, `key`, `group`, `group_label`, `label`, `sort_order`) VALUES
(1,  'customers.view',          'customers',    'Müşteriler',          'Görüntüle',           10),
(2,  'customers.manage',        'customers',    'Müşteriler',          'Ekle / Düzenle',       11),
(3,  'customers.delete',        'customers',    'Müşteriler',          'Sil',                 12),
(4,  'customers.export',        'customers',    'Müşteriler',          'Dışa Aktar',           13),
(5,  'policies.view',           'policies',     'Poliçeler',           'Görüntüle',           20),
(6,  'policies.manage',         'policies',     'Poliçeler',           'Ekle / Düzenle',       21),
(7,  'policies.delete',         'policies',     'Poliçeler',           'Sil',                 22),
(8,  'policies.export',         'policies',     'Poliçeler',           'Dışa Aktar',           23),
(9,  'tasks.view',              'tasks',        'Görev Takibi',        'Görüntüle',           30),
(10, 'tasks.manage',            'tasks',        'Görev Takibi',        'Yönet',               31),
(11, 'tasks.export',            'tasks',        'Görev Takibi',        'Dışa Aktar',           32),
(12, 'lost_policies.view',      'lost_policies','Kaçırılan Poliçeler', 'Görüntüle',           40),
(13, 'lost_policies.manage',    'lost_policies','Kaçırılan Poliçeler', 'Yönet',               41),
(14, 'offers.view',             'offers',       'Teklifler',           'Görüntüle',           50),
(15, 'offers.manage',           'offers',       'Teklifler',           'Yönet',               51),
(16, 'messages.view',           'messages',     'Mesajlar',            'Görüntüle',           60),
(17, 'messages.send',           'messages',     'Mesajlar',            'Gönder',               61),
(18, 'reports.view',            'reports',      'Raporlar',            'Görüntüle',           70),
(19, 'performance.view',        'performance',  'Satış Performansı',   'Görüntüle',           80),
(20, 'portfolio.view',          'portfolio',    'Portföy',             'Görüntüle',           90),
(21, 'personnel.view',          'personnel',    'Personel / İK',       'Görüntüle',          100),
(22, 'personnel.manage',        'personnel',    'Personel / İK',       'Yönet',              101),
(23, 'tools.excel_import',      'tools',        'Araçlar',             'Excel Import',        110),
(24, 'tools.allianz_import',    'tools',        'Araçlar',             'Allianz Import',      111),
(25, 'tools.reconciliation',    'tools',        'Araçlar',             'Mutabakat',           112),
(26, 'tools.cross_sell',        'tools',        'Araçlar',             'Çapraz Satış',        113),
(27, 'settings.users',          'settings',     'Ayarlar',             'Kullanıcı Yönetimi',  120),
(28, 'settings.companies',      'settings',     'Ayarlar',             'Şirketler',           121),
(29, 'settings.insurance_types','settings',     'Ayarlar',             'Sigorta Türleri',     122),
(30, 'settings.branches',       'settings',     'Ayarlar',             'Tali Acenteler',      123),
(31, 'settings.references',     'settings',     'Ayarlar',             'Referans Kaynakları', 124),
(32, 'settings.follow_up',      'settings',     'Ayarlar',             'Takip Aramaları',     125),
(33, 'policies.edit_past_months','policies',    'Poliçeler',           'Geçmiş Ay Düzenle',   24);

-- =============================================
-- SETTINGS (Acente Bilgileri + Alan Ayarları)
-- =============================================
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
('agency_name',         'Demo Sigorta Acentesi'),
('agency_phone',        '0212 555 00 00'),
('agency_email',        'info@demosigorta.com'),
('agency_address',      'Bağcılar Mah. Demo Cad. No:1 İstanbul'),
('agency_tax_no',       '1234567890'),
('agency_tax_office',   'Bağcılar Vergi Dairesi'),
('field_settings',      '{"commission_as_amount":false,"show_insured_name":true,"show_insured_no":true,"show_plate":true,"show_chassis":false,"show_engine":false,"show_registration":false,"show_vehicle_brand":true,"show_vehicle_model":true,"show_vehicle_year":true,"show_uavt":false,"show_dask_no":true,"show_network":true,"show_additional_insureds":false,"show_risk_address":true}'),
('sms_provider',        'netgsm'),
('renewal_reminder_days','15'),
('default_currency',    'TRY');

-- =============================================
-- USERS
-- Şifre: demo1234
-- =============================================
INSERT IGNORE INTO `users` (`id`, `name`, `email`, `password`, `is_active`, `role`, `phone`, `created_at`) VALUES
(1, 'Demo Admin', 'admin@demosigorta.com', '$2y$10$w23OuqSAvFN0FmqHgKjjBOoVGkTYysx7zkUPiDt.3Lxhlo6xME/ji', 1, 1, '0532 111 00 01', '2026-01-01 09:00:00');

-- =============================================
-- REFERENCE SOURCES
-- =============================================
INSERT IGNORE INTO `reference_sources` (`id`, `name`, `commission_rate`, `is_active`) VALUES
(1, 'Direkt Müşteri',   0.00, 1),
(2, 'Tavsiye',          5.00, 1),
(3, 'Web Sitesi',       0.00, 1),
(4, 'Sosyal Medya',     0.00, 1),
(5, 'Telefon Araması',  0.00, 1);

-- =============================================
-- BRANCHES (Tali Acenteler)
-- =============================================
INSERT IGNORE INTO `branches` (`id`, `name`, `phone`, `commission_rate`) VALUES
(1, 'Güven Sigorta Acentesi',   '0216 444 10 10', 15),
(2, 'Başarı Sigorta Şubesi',    '0212 333 20 20', 12),
(3, 'Anadolu Acentesi',         '0262 500 30 30', 10);

-- =============================================
-- CUSTOMERS (20 demo müşteri)
-- =============================================
INSERT IGNORE INTO `customers` (`id`, `customer_type`, `name`, `identity_no`, `birth_date`, `phone`, `email`, `job`, `city_id`, `created_at`) VALUES
(1,  'INDIVIDUAL', 'Ahmet Çelik',       '12345678901', '1978-03-15', '0532 100 01 01', 'ahmet.celik@email.com',    'Serbest Meslek', 34, '2026-01-10 10:00:00'),
(2,  'INDIVIDUAL', 'Fatma Demir',       '23456789012', '1985-07-22', '0533 100 02 02', 'fatma.demir@email.com',    'Öğretmen',       34, '2026-01-12 10:00:00'),
(3,  'INDIVIDUAL', 'Mustafa Şahin',     '34567890123', '1972-11-05', '0535 100 03 03', 'mustafa.sahin@email.com',  'Mühendis',       34, '2026-01-15 10:00:00'),
(4,  'INDIVIDUAL', 'Emine Yıldız',      '45678901234', '1990-02-28', '0536 100 04 04', 'emine.yildiz@email.com',   'Doktor',         6,  '2026-01-18 10:00:00'),
(5,  'INDIVIDUAL', 'Ali Öztürk',        '56789012345', '1968-09-10', '0537 100 05 05', 'ali.ozturk@email.com',     'Emekli',         34, '2026-01-20 10:00:00'),
(6,  'CORPORATE',  'Akın Tekstil Ltd.',  '1111111111',  NULL,         '0212 200 06 06', 'info@akintekstil.com',     NULL,             34, '2026-02-01 10:00:00'),
(7,  'INDIVIDUAL', 'Hasan Koç',         '67890123456', '1982-04-17', '0538 100 07 07', 'hasan.koc@email.com',      'Esnaf',          7,  '2026-02-05 10:00:00'),
(8,  'INDIVIDUAL', 'Ayşe Güneş',        '78901234567', '1994-12-03', '0539 100 08 08', 'ayse.gunes@email.com',     'Avukat',         34, '2026-02-10 10:00:00'),
(9,  'INDIVIDUAL', 'İbrahim Kara',      '89012345678', '1975-06-25', '0541 100 09 09', 'ibrahim.kara@email.com',   'Muhasebeci',     34, '2026-02-12 10:00:00'),
(10, 'INDIVIDUAL', 'Hatice Polat',      '90123456789', '1988-01-14', '0542 100 10 10', 'hatice.polat@email.com',   'Hemşire',        9,  '2026-02-15 10:00:00'),
(11, 'CORPORATE',  'Yıldız Gıda A.Ş.', '2222222222',  NULL,         '0312 200 11 11', 'info@yildizgida.com',      NULL,             6,  '2026-03-01 10:00:00'),
(12, 'INDIVIDUAL', 'Ömer Aydın',        '01234567890', '1965-08-30', '0543 100 12 12', 'omer.aydin@email.com',     'Eczacı',         34, '2026-03-05 10:00:00'),
(13, 'INDIVIDUAL', 'Zübeyde Çetin',     '11223344556', '1992-05-19', '0544 100 13 13', 'zubeyde.cetin@email.com',  'Mimarı',         34, '2026-03-08 10:00:00'),
(14, 'INDIVIDUAL', 'Recep Doğan',       '22334455667', '1970-10-07', '0545 100 14 14', 'recep.dogan@email.com',    'Serbest Meslek', 16, '2026-03-12 10:00:00'),
(15, 'INDIVIDUAL', 'Gülay Arslan',      '33445566778', '1986-03-23', '0546 100 15 15', 'gulay.arslan@email.com',   'Ev Hanımı',      34, '2026-03-15 10:00:00'),
(16, 'CORPORATE',  'Demirci İnşaat',    '3333333333',  NULL,         '0262 200 16 16', 'info@demirciinsaat.com',   NULL,             41, '2026-04-01 10:00:00'),
(17, 'INDIVIDUAL', 'Serkan Bulut',      '44556677889', '1980-07-11', '0547 100 17 17', 'serkan.bulut@email.com',   'Pilot',          34, '2026-04-05 10:00:00'),
(18, 'INDIVIDUAL', 'Nurcan Erdoğan',    '55667788990', '1997-02-06', '0548 100 18 18', 'nurcan.erdogan@email.com', 'Öğrenci',        34, '2026-04-10 10:00:00'),
(19, 'INDIVIDUAL', 'Cengiz Yılmaz',     '66778899001', '1958-11-29', '0549 100 19 19', 'cengiz.yilmaz@email.com',  'Emekli',         34, '2026-05-01 10:00:00'),
(20, 'INDIVIDUAL', 'Derya Çevik',       '77889900112', '1991-09-18', '0551 100 20 20', 'derya.cevik@email.com',    'Muhasebeci',     34, '2026-05-10 10:00:00');

-- =============================================
-- POLICIES (35 demo poliçe)
-- insurance_type_id: TSS=3, ÖSS=4, KONUT=7, DASK=9, TRAFİK=12, KASKO=14, İŞYERİ=19, SEYAHAT=20, FERDİ KAZA=35
-- companies: Anadolu=1, Türkiye=2, AKSigorta=4, AXA=6, Allianz=8, Ray=11, Mapfre=34, Groupama=30
-- =============================================
INSERT IGNORE INTO `policies` (`id`, `customer_id`, `insurance_type_id`, `company_id`, `production_type`, `policy_no`, `issued_at`, `starts_at`, `expires_at`, `gross_premium`, `net_premium`, `company_comm_rate`, `company_comm_amount`, `endorsement_no`, `plate_no`, `vehicle_brand`, `vehicle_model`, `vehicle_year`, `is_approved`, `sold_by`, `created_by`, `reference_source`, `created_at`) VALUES

-- Ahmet Çelik (id=1) - Trafik + Kasko + TSS
(1,  1, 12, 8,  'SELF', 'TRF-2025-001234', '2025-07-01', '2025-07-01', '2026-07-01', 2850.00, 2650.00, 7,  185.50, 1, '34 ABC 001', 'Toyota',    'Corolla',   '2020', 1, 1, 1, 1, '2025-07-01 11:00:00'),
(2,  1, 14, 8,  'SELF', 'KSK-2025-001234', '2025-07-01', '2025-07-01', '2026-07-01', 8500.00, 7900.00, 8,  632.00, 1, '34 ABC 001', 'Toyota',    'Corolla',   '2020', 1, 1, 1, 1, '2025-07-01 11:00:00'),
(3,  1, 3,  16, 'SELF', 'TSS-2025-001234', '2025-08-01', '2025-08-01', '2026-08-01', 4200.00, 3900.00, 0,  0.00,   1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 2, '2025-08-01 09:00:00'),

-- Fatma Demir (id=2) - Trafik + Konut + DASK
(4,  2, 12, 1,  'SELF', 'TRF-2025-002345', '2025-06-15', '2025-06-15', '2026-06-15', 1950.00, 1800.00, 7,  126.00, 1, '34 DEF 002', 'Renault',   'Clio',      '2019', 1, 1, 1, 1, '2025-06-15 10:00:00'),
(5,  2, 7,  4,  'SELF', 'KNT-2025-002345', '2025-09-01', '2025-09-01', '2026-09-01', 3100.00, 2850.00, 8,  228.00, 1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 2, '2025-09-01 09:00:00'),
(6,  2, 9,  4,  'SELF', 'DSK-2025-002345', '2025-09-01', '2025-09-01', '2026-09-01', 890.00,  820.00,  7,  57.40,  1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 2, '2025-09-01 09:00:00'),

-- Mustafa Şahin (id=3) - TSS + Kasko + Trafik
(7,  3, 3,  16, 'SELF', 'TSS-2025-003456', '2025-05-01', '2025-05-01', '2026-05-01', 5800.00, 5400.00, 0,  0.00,   1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 3, '2025-05-01 09:00:00'),
(8,  3, 14, 6,  'SELF', 'KSK-2025-003456', '2025-10-01', '2025-10-01', '2026-10-01', 12000.00,11200.00,8,  896.00, 1, '34 GHI 003', 'BMW',       '320i',      '2022', 1, 1, 1, 1, '2025-10-01 10:00:00'),
(9,  3, 12, 6,  'SELF', 'TRF-2025-003456', '2025-10-01', '2025-10-01', '2026-10-01', 3200.00, 2950.00, 7,  206.50, 1, '34 GHI 003', 'BMW',       '320i',      '2022', 1, 1, 1, 1, '2025-10-01 10:00:00'),

-- Emine Yıldız (id=4) - ÖSS + Trafik
(10, 4, 4,  16, 'SELF', 'OSS-2025-004567', '2025-04-01', '2025-04-01', '2026-04-01', 7200.00, 6700.00, 0,  0.00,   1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 2, '2025-04-01 09:00:00'),
(11, 4, 12, 2,  'SELF', 'TRF-2025-004567', '2025-11-01', '2025-11-01', '2026-11-01', 2100.00, 1950.00, 7,  136.50, 1, '06 JKL 004', 'Honda',     'Civic',     '2021', 1, 1, 1, 1, '2025-11-01 10:00:00'),

-- Ali Öztürk (id=5) - Trafik (süresi dolmuş) + Konut
(12, 5, 12, 1,  'SELF', 'TRF-2024-005678', '2024-07-01', '2024-07-01', '2025-07-01', 1750.00, 1600.00, 7,  112.00, 1, '34 MNO 005', 'Ford',      'Focus',     '2015', 1, 1, 1, 1, '2024-07-01 10:00:00'),
(13, 5, 7,  34, 'SELF', 'KNT-2025-005678', '2025-08-15', '2025-08-15', '2026-08-15', 2700.00, 2500.00, 8,  200.00, 1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 5, '2025-08-15 10:00:00'),

-- Akın Tekstil (id=6) - İşyeri + Trafik (tali)
(14, 6, 19, 30, 'SELF',     'ISY-2025-006789', '2025-06-01', '2025-06-01', '2026-06-01', 9500.00, 8800.00, 8,  704.00, 1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 1, '2025-06-01 09:00:00'),
(15, 6, 12, 1,  'INCOMING', 'TRF-2025-006789', '2025-06-01', '2025-06-01', '2026-06-01', 3800.00, 3500.00, 7,  245.00, 1, '34 PRS 006', 'Mercedes',  'Sprinter',  '2021', 1, 1, 1, 1, '2025-06-01 09:00:00'),

-- Hasan Koç (id=7) - Kasko + Trafik
(16, 7, 14, 11, 'SELF', 'KSK-2025-007890', '2025-07-15', '2025-07-15', '2026-07-15', 6800.00, 6300.00, 8,  504.00, 1, '07 TUV 007', 'Hyundai',   'Tucson',    '2023', 1, 1, 1, 2, '2025-07-15 10:00:00'),
(17, 7, 12, 11, 'SELF', 'TRF-2025-007890', '2025-07-15', '2025-07-15', '2026-07-15', 2450.00, 2270.00, 7,  158.90, 1, '07 TUV 007', 'Hyundai',   'Tucson',    '2023', 1, 1, 1, 2, '2025-07-15 10:00:00'),

-- Ayşe Güneş (id=8) - TSS + Konut
(18, 8, 3,  16, 'SELF', 'TSS-2025-008901', '2025-03-01', '2025-03-01', '2026-03-01', 3600.00, 3350.00, 0,  0.00,   1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 4, '2025-03-01 09:00:00'),
(19, 8, 7,  4,  'SELF', 'KNT-2025-008901', '2025-10-01', '2025-10-01', '2026-10-01', 2400.00, 2200.00, 8,  176.00, 1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 4, '2025-10-01 09:00:00'),

-- İbrahim Kara (id=9) - Trafik + ÖSS
(20, 9, 12, 2,  'SELF', 'TRF-2025-009012', '2025-09-01', '2025-09-01', '2026-09-01', 2200.00, 2040.00, 7,  142.80, 1, '34 WXY 009', 'Volkswagen','Golf',      '2018', 1, 1, 1, 1, '2025-09-01 10:00:00'),
(21, 9, 4,  16, 'SELF', 'OSS-2025-009012', '2025-09-01', '2025-09-01', '2026-09-01', 5500.00, 5100.00, 0,  0.00,   1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 1, '2025-09-01 09:00:00'),

-- Hatice Polat (id=10) - Trafik
(22, 10, 12, 8, 'SELF', 'TRF-2025-010123', '2025-11-15', '2025-11-15', '2026-11-15', 1850.00, 1720.00, 7,  120.40, 1, '09 ZAB 010', 'Fiat',      'Egea',      '2022', 1, 1, 1, 5, '2025-11-15 10:00:00'),

-- Yıldız Gıda (id=11) - İşyeri + Trafik (tali)
(23, 11, 19, 6, 'SELF',     'ISY-2025-011234', '2025-05-01', '2025-05-01', '2026-05-01', 15000.00,13900.00,8, 1112.00,1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 3, '2025-05-01 09:00:00'),
(24, 11, 12, 6, 'INCOMING', 'TRF-2025-011234', '2025-05-01', '2025-05-01', '2026-05-01', 4200.00, 3900.00, 7,  273.00, 1, '06 CDE 011', 'Isuzu',     'D-Max',     '2020', 1, 1, 1, 3, '2025-05-01 09:00:00'),

-- Ömer Aydın (id=12) - TSS + Kasko
(25, 12, 3,  16, 'SELF', 'TSS-2025-012345', '2025-02-01', '2025-02-01', '2026-02-01', 4800.00, 4450.00, 0,  0.00,   1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 2, '2025-02-01 09:00:00'),
(26, 12, 14, 34, 'SELF', 'KSK-2025-012345', '2025-12-01', '2025-12-01', '2026-12-01', 9800.00, 9100.00, 8,  728.00, 1, '34 FGH 012', 'Audi',      'A4',        '2021', 1, 1, 1, 1, '2025-12-01 10:00:00'),

-- Zübeyde Çetin (id=13) - Trafik + Konut + DASK
(27, 13, 12, 1,  'SELF', 'TRF-2026-013456', '2026-01-15', '2026-01-15', '2027-01-15', 2050.00, 1900.00, 7,  133.00, 1, '34 IJK 013', 'Peugeot',   '308',       '2023', 1, 1, 1, 4, '2026-01-15 10:00:00'),
(28, 13, 7,  4,  'SELF', 'KNT-2026-013456', '2026-01-15', '2026-01-15', '2027-01-15', 3400.00, 3150.00, 8,  252.00, 1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 4, '2026-01-15 09:00:00'),
(29, 13, 9,  4,  'SELF', 'DSK-2026-013456', '2026-01-15', '2026-01-15', '2027-01-15', 950.00,  880.00,  7,  61.60,  1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 4, '2026-01-15 09:00:00'),

-- Recep Doğan (id=14) - Kasko (iptal)
(30, 14, 14, 6,  'SELF', 'KSK-2025-014567', '2025-08-01', '2025-08-01', '2026-08-01', 7500.00, 6950.00, 8,  556.00, 1, '16 LMN 014', 'Nissan',    'Qashqai',   '2022', 0, 1, 1, 1, '2025-08-01 10:00:00'),

-- Serkan Bulut (id=17) - Trafik + Kasko + TSS
(31, 17, 12, 8,  'SELF', 'TRF-2026-017890', '2026-02-01', '2026-02-01', '2027-02-01', 3100.00, 2880.00, 7,  201.60, 1, '34 OPQ 017', 'Tesla',     'Model 3',   '2023', 1, 1, 1, 3, '2026-02-01 10:00:00'),
(32, 17, 14, 8,  'SELF', 'KSK-2026-017890', '2026-02-01', '2026-02-01', '2027-02-01', 14000.00,13000.00,8, 1040.00,1, '34 OPQ 017', 'Tesla',     'Model 3',   '2023', 1, 1, 1, 3, '2026-02-01 10:00:00'),
(33, 17, 3,  16, 'SELF', 'TSS-2026-017890', '2026-03-01', '2026-03-01', '2027-03-01', 5200.00, 4850.00, 0,  0.00,   1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 3, '2026-03-01 09:00:00'),

-- Nurcan Erdoğan (id=18) - TSS
(34, 18, 3,  16, 'SELF', 'TSS-2026-018901', '2026-04-01', '2026-04-01', '2027-04-01', 2900.00, 2700.00, 0,  0.00,   1, NULL,        NULL,        NULL,        NULL,   1, 1, 1, 4, '2026-04-01 09:00:00'),

-- Cengiz Yılmaz (id=19) - Trafik (süresi yakında dolacak)
(35, 19, 12, 2,  'SELF', 'TRF-2025-019012', '2025-07-20', '2025-07-20', '2026-07-20', 1680.00, 1560.00, 7,  109.20, 1, '34 RST 019', 'Renault',   'Symbol',    '2016', 1, 1, 1, 1, '2025-07-20 10:00:00');

-- İptal poliçe güncelle
UPDATE `policies` SET `is_cancelled` = 1 WHERE `id` = 30;

-- =============================================
-- TASKS (Görevler)
-- =============================================
INSERT IGNORE INTO `tasks` (`type`, `title`, `description`, `policy_id`, `customer_id`, `assigned_to`, `assigned_by`, `created_by`, `status`, `priority`, `deadline`, `created_at`) VALUES

-- Yenileme görevleri (süresi yaklaşan poliçeler)
('RENEWAL', 'Ahmet Çelik - Trafik Yenileme',     'Poliçe 01.07.2026 tarihinde sona eriyor.',  1,  1,  2, 1, 1, 'PENDING',     'HIGH',   '2026-07-10 17:00:00', '2026-06-16 09:00:00'),
('RENEWAL', 'Ahmet Çelik - Kasko Yenileme',      'Poliçe 01.07.2026 tarihinde sona eriyor.',  2,  1,  2, 1, 1, 'PENDING',     'HIGH',   '2026-07-10 17:00:00', '2026-06-16 09:00:00'),
('RENEWAL', 'Cengiz Yılmaz - Trafik Yenileme',   'Poliçe 20.07.2026 tarihinde sona eriyor.', 35, 19,  4, 1, 1, 'PENDING',     'MEDIUM', '2026-07-20 17:00:00', '2026-07-05 09:00:00'),
('RENEWAL', 'Hasan Koç - Kasko Yenileme',        'Poliçe 15.07.2026 tarihinde sona eriyor.', 16,  7,  2, 1, 1, 'IN_PROGRESS', 'HIGH',   '2026-07-12 17:00:00', '2026-07-01 09:00:00'),
('RENEWAL', 'Fatma Demir - Trafik Yenileme',     'Poliçe 15.06.2026 tarihinde sona erdi.',    4,  2,  3, 1, 1, 'COMPLETED',   'HIGH',   '2026-06-10 17:00:00', '2026-05-30 09:00:00'),

-- Teklif görevleri
('OFFER',   'Gülay Arslan - Kasko Teklifi',      'Kasko sigortası için teklif talep etti.',   NULL, 15, 1, 1, 1, 'PENDING',    'MEDIUM', '2026-07-15 17:00:00', '2026-07-06 11:00:00'),
('OFFER',   'Derya Çevik - TSS Teklifi',         'TSS için fiyat araştırıyor.',               NULL, 20, 1, 1, 1, 'PENDING',    'MEDIUM', '2026-07-12 17:00:00', '2026-07-05 14:00:00'),

-- Çapraz satış görevleri
('CROSS_SELL', 'Ali Öztürk - Kasko Önerisi',    'Trafiği var, kasko teklifi yapılacak.',      12,  5,  4, 1, 1, 'PENDING',    'LOW',    '2026-07-20 17:00:00', '2026-07-03 09:00:00'),
('CROSS_SELL', 'Hatice Polat - TSS Önerisi',    'Trafiği var, TSS görüşmesi yapılacak.',      22, 10,  2, 1, 1, 'PENDING',    'LOW',    '2026-07-25 17:00:00', '2026-07-04 09:00:00'),

-- Diğer görevler
('OTHER',   'Recep Doğan - İptal İşlemi Takibi','Kasko iptal nedeni araştırılacak.',          30, 14,  4, 1, 1, 'COMPLETED',  'MEDIUM', '2026-06-15 17:00:00', '2026-06-10 10:00:00');

-- =============================================
-- MIGRATIONS (kurulu migrationları işaretle)
-- =============================================
INSERT IGNORE INTO `migrations` (`filename`, `applied_at`) VALUES
('1.0.0-initial-schema.php',                   NOW()),
('1.3.5-cleanup-duplicate-renewal-tasks.php',   NOW()),
('1.4.1-zeyil-policy-support.php',              NOW());

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================
-- ÖZET
-- =============================================
-- Kullanıcılar (şifre: demo1234):
--   admin@demosigorta.com  → Admin
--   ayse@demosigorta.com   → Personel
--   mehmet@demosigorta.com → Personel
--   zeynep@demosigorta.com → Personel
--
-- 20 Müşteri (bireysel + kurumsal)
-- 35 Poliçe  (trafik, kasko, TSS, ÖSS, konut, DASK, işyeri)
-- 10 Görev   (yenileme, teklif, çapraz satış, diğer)
-- =============================================
