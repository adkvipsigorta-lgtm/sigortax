<?php
/**
 * Migration: 1.4.0-lost-policy-events
 *
 * Kaçırılan Poliçeler modülü için olay tabanlı (event-based) mimari.
 *
 * NEDEN GEREKİYOR?
 * ─────────────────────────────────────────────────────────────────
 * Eski sistem, her sorguda dinamik UNION sorgusu çalıştırıyordu:
 *   - Mükerrer kayıt sorunu (aynı poliçe endorsement_no=0 ve =1 olarak 2 kez geliyordu)
 *   - 5 yıl önceki "geri kazanılan" olaylar görünmüyordu (tarih sınırı)
 *   - "Geri Kazanılan" sayısı yanlıştı: tüm normal yenilemeler dahil ediliyordu
 *   - Tarihsel "Bu poliçe kaçırılmış mıydı?" sorusuna cevap verilemiyor
 *
 * YENİ MİMARİ: Kalıcı olay kaydı.
 * ─────────────────────────────────────────────────────────────────
 * Her "kaçırma olayı" bir satır olarak saklanır, sonraki sorgular bu tablodan beslenir.
 *
 * TEMEL KURALLAR:
 *   Kaçırılma  : Poliçe bitti + 45 gün içinde yenileme YAPILMADI.
 *   Geri Kazanma: Kaçırılan poliçe için 45+ gün sonra yeni poliçe kesildi.
 *   Normal yenileme (≤ 45 gün): Bu tabloya GİRMEZ.
 *   Yeni müşteri / sıfırdan poliçe: Bu tabloya GİRMEZ.
 *
 * ZEYİL DESTEĞİ:
 *   Zeyil yapılan poliçelerin başlangıç tarihi değişebilir ama bitiş tarihi değişmez.
 *   Bu yüzden yenileme tespitinde hem starts_at (45 gün penceresi) hem de
 *   expires_at yakınlığı (8-16 ay) kontrol edilir.
 */

$pdo = Database::getInstance();
$pdo->exec("SET NAMES utf8mb4");

// ─────────────────────────────────────────────────────────────────
// ADIM 1 — Tablo oluştur
// ─────────────────────────────────────────────────────────────────
SchemaHelper::ensureTable('lost_policy_events', [
    'id'                  => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'policy_id'           => 'INT UNSIGNED NOT NULL',
    'customer_id'         => 'INT UNSIGNED NOT NULL',
    'insurance_type_id'   => 'INT UNSIGNED NOT NULL',
    // Araç branşları için plaka bazlı eşleşme; konut/sağlık vb. için NULL (müşteri+branş bazlı)
    'plate_no'            => 'VARCHAR(20) NULL',
    // Orijinal kaçırılan poliçenin bitiş tarihi
    'expires_at'          => 'DATE NOT NULL',
    // Resmi kaçırılma tarihi = expires_at + 45 gün (bu güne kadar yenileme gelmedi)
    'lost_at'             => 'DATE NOT NULL',
    'status'              => "ENUM('LOST','RECOVERED','ABANDONED','VEHICLE_SOLD') NOT NULL DEFAULT 'LOST'",
    // Geri kazanım: yeni poliçenin ID ve başlangıç tarihi
    'recovered_policy_id' => 'INT UNSIGNED NULL',
    'recovered_at'        => 'DATE NULL',
    // Yardımcı alanlar (eski lost_policy_actions'dan taşındı)
    'registration_no'     => 'VARCHAR(50) NULL',
    'expected_date'       => 'DATE NULL',
    'note'                => 'TEXT NULL',
    'updated_by'          => 'INT UNSIGNED NULL',
    'created_at'          => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'updated_at'          => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
]);

// Bir poliçe için en fazla bir "kaçırma olayı" — UNIQUE KEY duplikat girişi engeller
SchemaHelper::ensureIndex('lost_policy_events', 'uq_policy_event',         ['policy_id'], true);
// Aktif LOST kayıtlarını müşteri+branş bazında hızlı sorgulama (otomasyon için kritik)
SchemaHelper::ensureIndex('lost_policy_events', 'idx_customer_type_status', ['customer_id', 'insurance_type_id', 'status']);
// Araç branşları için plaka+branş bazında sorgulama
SchemaHelper::ensureIndex('lost_policy_events', 'idx_plate_type_status',    ['plate_no', 'insurance_type_id', 'status']);
// Tarih bazlı listeleme (yaklaşan vadeler, geçmiş vadeler)
SchemaHelper::ensureIndex('lost_policy_events', 'idx_status_lost_at',       ['status', 'lost_at']);
// "Geri Kazanılan" raporlama için
SchemaHelper::ensureIndex('lost_policy_events', 'idx_recovered',            ['status', 'recovered_at']);
// Hangi poliçenin "geri kazanım" poliçesi olduğunu ters sorgulama için
SchemaHelper::ensureIndex('lost_policy_events', 'idx_recovered_policy',     ['recovered_policy_id']);

// ─────────────────────────────────────────────────────────────────
// ADIM 2 — Geçmiş veriyi taşı: Plaka bazlı poliçeler
//           (Trafik, Kasko, Yeşil Kart vb.)
// ─────────────────────────────────────────────────────────────────
// INSERT IGNORE: UNIQUE KEY ihlalinde sessizce atlar → migration defalarca çalıştırılabilir
$pdo->exec("
INSERT IGNORE INTO lost_policy_events
    (policy_id, customer_id, insurance_type_id, plate_no,
     expires_at, lost_at,
     status, recovered_policy_id, recovered_at,
     registration_no, expected_date, note, updated_by)

SELECT
    p.id,
    p.customer_id,
    p.insurance_type_id,
    p.plate_no,
    p.expires_at,
    DATE_ADD(p.expires_at, INTERVAL 45 DAY)             AS lost_at,

    CASE
        WHEN p_rec.id IS NOT NULL        THEN 'RECOVERED'
        WHEN lpa.status = 'VEHICLE_SOLD' THEN 'VEHICLE_SOLD'
        WHEN lpa.status = 'ABANDONED'    THEN 'ABANDONED'
        ELSE                                  'LOST'
    END                                                  AS status,

    p_rec.id                                             AS recovered_policy_id,
    p_rec.starts_at                                      AS recovered_at,
    lpa.registration_no,
    lpa.expected_date,
    lpa.note,
    lpa.updated_by

FROM policies p
INNER JOIN insurance_types it
    ON it.id = p.insurance_type_id AND it.is_renewable = 1
LEFT JOIN lost_policy_actions lpa
    ON lpa.policy_id = p.id

-- İlk geri kazanım poliçesi: 45 günden sonra AYNI MÜŞTERİ tarafından yapılan en ERKEN yenileme
-- customer_id zorunlu: farklı kişi aynı plakayı alırsa bu geri kazanım DEĞİLDİR
LEFT JOIN policies p_rec
    ON p_rec.id = (
        SELECT id FROM policies
        WHERE deleted_at      IS NULL
          AND is_cancelled     = 0
          AND endorsement_no   <= 1
          AND plate_no          = p.plate_no
          AND insurance_type_id = p.insurance_type_id
          AND customer_id       = p.customer_id
          AND starts_at > DATE_ADD(p.expires_at, INTERVAL 45 DAY)
        ORDER BY starts_at ASC
        LIMIT 1
    )

WHERE p.deleted_at     IS NULL
  AND p.is_cancelled    = 0
  AND p.endorsement_no  <= 1
  AND p.production_type IN ('SELF', 'OUTGOING')
  AND p.plate_no IS NOT NULL AND p.plate_no != ''
  AND p.expires_at < CURDATE()
  AND DATEDIFF(p.expires_at, p.starts_at) >= 180   -- kısa süreli poliçeleri atla

  -- ── TEMEL KURAL: 45 gün içinde yenileme yapılmamış olmalı ──────
  -- Aynı gün başlayan yenilemeler (>=) ve zeyil durumu (expires_at yakınlığı) dahil
  AND NOT EXISTS (
      SELECT 1 FROM policies pq
      WHERE pq.deleted_at      IS NULL
        AND pq.is_cancelled     = 0
        AND pq.plate_no          = p.plate_no
        AND pq.insurance_type_id = p.insurance_type_id
        AND (
            -- Doğrudan yenileme: başlangıç tarihi eski poliçenin bitişinden itibaren 45 gün içinde
            (pq.endorsement_no <= 1 AND pq.starts_at >= p.expires_at AND pq.starts_at <= DATE_ADD(p.expires_at, INTERVAL 45 DAY))
            -- Zeyil durumu: bitiş tarihi eski poliçenin bitişinden 8-16 ay sonra (yıllık yenileme)
            OR pq.expires_at BETWEEN DATE_ADD(p.expires_at, INTERVAL 8 MONTH) AND DATE_ADD(p.expires_at, INTERVAL 16 MONTH)
        )
  )

  -- İptal kaydı olan poliçeleri atla
  AND NOT EXISTS (
      SELECT 1 FROM policies pc
      WHERE pc.policy_no   = p.policy_no
        AND pc.is_cancelled = 1
        AND pc.deleted_at  IS NULL
  )

  -- Duplikat önleme: aynı policy_no için daha yüksek endorsement_no varsa o alır
  AND NOT EXISTS (
      SELECT 1 FROM policies pdup
      WHERE pdup.policy_no      = p.policy_no
        AND pdup.id              != p.id
        AND pdup.endorsement_no  <= 1
        AND pdup.endorsement_no  > p.endorsement_no
        AND pdup.deleted_at     IS NULL
        AND pdup.is_cancelled    = 0
  )
");

// ─────────────────────────────────────────────────────────────────
// ADIM 3 — Geçmiş veriyi taşı: Müşteri+Branş bazlı poliçeler
//           (TSS, ÖSS, DASK, Konut, Seyahat vb.)
// ─────────────────────────────────────────────────────────────────
$pdo->exec("
INSERT IGNORE INTO lost_policy_events
    (policy_id, customer_id, insurance_type_id, plate_no,
     expires_at, lost_at,
     status, recovered_policy_id, recovered_at,
     registration_no, expected_date, note, updated_by)

SELECT
    p.id,
    p.customer_id,
    p.insurance_type_id,
    NULL                                                 AS plate_no,
    p.expires_at,
    DATE_ADD(p.expires_at, INTERVAL 45 DAY)             AS lost_at,

    CASE
        WHEN p_rec.id IS NOT NULL        THEN 'RECOVERED'
        WHEN lpa.status = 'VEHICLE_SOLD' THEN 'VEHICLE_SOLD'
        WHEN lpa.status = 'ABANDONED'    THEN 'ABANDONED'
        ELSE                                  'LOST'
    END                                                  AS status,

    p_rec.id                                             AS recovered_policy_id,
    p_rec.starts_at                                      AS recovered_at,
    lpa.registration_no,
    lpa.expected_date,
    lpa.note,
    lpa.updated_by

FROM policies p
INNER JOIN insurance_types it
    ON it.id = p.insurance_type_id AND it.is_renewable = 1
LEFT JOIN lost_policy_actions lpa
    ON lpa.policy_id = p.id

LEFT JOIN policies p_rec
    ON p_rec.id = (
        SELECT id FROM policies
        WHERE deleted_at      IS NULL
          AND is_cancelled     = 0
          AND endorsement_no   <= 1
          AND customer_id       = p.customer_id
          AND insurance_type_id = p.insurance_type_id
          AND policy_no         = p.policy_no
          AND starts_at > DATE_ADD(p.expires_at, INTERVAL 45 DAY)
        ORDER BY starts_at ASC
        LIMIT 1
    )

WHERE p.deleted_at     IS NULL
  AND p.is_cancelled    = 0
  AND p.endorsement_no  <= 1
  AND p.production_type IN ('SELF', 'OUTGOING')
  AND (p.plate_no IS NULL OR p.plate_no = '')
  AND p.expires_at < CURDATE()
  AND DATEDIFF(p.expires_at, p.starts_at) >= 180

  AND NOT EXISTS (
      SELECT 1 FROM policies pq
      WHERE pq.deleted_at      IS NULL
        AND pq.is_cancelled     = 0
        AND pq.customer_id       = p.customer_id
        AND pq.insurance_type_id = p.insurance_type_id
        AND (
            -- Aynı poliçe ailesi, 45 gün içinde yenileme (aynı gün dahil)
            (pq.policy_no = p.policy_no AND pq.endorsement_no <= 1 AND pq.starts_at >= p.expires_at AND pq.starts_at <= DATE_ADD(p.expires_at, INTERVAL 45 DAY))
            -- Farklı poliçe no olabilir (TSS/ÖSS vb.): bitiş tarihi 8-16 ay sonraysa yenileme
            OR pq.expires_at BETWEEN DATE_ADD(p.expires_at, INTERVAL 8 MONTH) AND DATE_ADD(p.expires_at, INTERVAL 16 MONTH)
        )
  )

  AND NOT EXISTS (
      SELECT 1 FROM policies pc
      WHERE pc.policy_no   = p.policy_no
        AND pc.is_cancelled = 1
        AND pc.deleted_at  IS NULL
  )

  AND NOT EXISTS (
      SELECT 1 FROM policies pdup
      WHERE pdup.policy_no      = p.policy_no
        AND pdup.id              != p.id
        AND pdup.endorsement_no  <= 1
        AND pdup.endorsement_no  > p.endorsement_no
        AND pdup.deleted_at     IS NULL
        AND pdup.is_cancelled    = 0
  )
");
