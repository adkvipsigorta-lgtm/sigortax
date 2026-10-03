<?php

require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../helpers/Response.php';

class LostPolicyController
{
    /**
     * Son 10 günde 45 günlük eşiği yeni geçmiş poliçeleri otomatik ekle.
     * Dar tarih penceresi (10 gün) sayesinde her sayfa açılışında çok hızlı çalışır.
     * INSERT IGNORE tekrar eklemeyi engeller.
     */
    /**
     * Otomatik sync: sayfa her açıldığında çalışır.
     * - Tablo boşsa (ilk kurulum): 13 aylık tam tarihsel sync
     * - Tablo doluysa: sadece son 10 günde eşiği geçenleri ekle (hızlı)
     */
    private static function autoSyncRecent(): void
    {
        try {
            require_once __DIR__ . '/../helpers/SchemaHelper.php';

            SchemaHelper::ensureTable('lost_policy_events', [
                'id'                  => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
                'policy_id'           => 'INT UNSIGNED NOT NULL',
                'customer_id'         => 'INT UNSIGNED NOT NULL',
                'insurance_type_id'   => 'INT UNSIGNED NOT NULL',
                'plate_no'            => 'VARCHAR(20) NULL',
                'expires_at'          => 'DATE NOT NULL',
                'lost_at'             => 'DATE NOT NULL',
                'status'              => "ENUM('LOST','RECOVERED','ABANDONED','VEHICLE_SOLD') NOT NULL DEFAULT 'LOST'",
                'recovered_policy_id' => 'INT UNSIGNED NULL',
                'recovered_at'        => 'DATE NULL',
                'registration_no'     => 'VARCHAR(50) NULL',
                'expected_date'       => 'DATE NULL',
                'note'                => 'TEXT NULL',
                'updated_by'          => 'INT UNSIGNED NULL',
                'created_at'          => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at'          => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ]);
            SchemaHelper::ensureIndex('lost_policy_events', 'uq_policy_event',         ['policy_id'], true);
            SchemaHelper::ensureIndex('lost_policy_events', 'idx_customer_type_status', ['customer_id', 'insurance_type_id', 'status']);
            SchemaHelper::ensureIndex('lost_policy_events', 'idx_plate_type_status',    ['plate_no', 'insurance_type_id', 'status']);
            SchemaHelper::ensureIndex('lost_policy_events', 'idx_status_lost_at',       ['status', 'lost_at']);
            SchemaHelper::ensureIndex('lost_policy_events', 'idx_recovered',            ['status', 'recovered_at']);
            SchemaHelper::ensureIndex('lost_policy_events', 'idx_recovered_policy',     ['recovered_policy_id']);

            $pdo = Database::getInstance();

            // Tablo boşsa ilk kurulum: 13 aylık tam sync
            $count = (int)($pdo->query("SELECT COUNT(*) FROM lost_policy_events")->fetchColumn());
            if ($count === 0) {
                set_time_limit(120);
                $dateFilter = "AND p.expires_at >= DATE_SUB(CURDATE(), INTERVAL 400 DAY)";
            } else {
                // Normal çalışma: sadece son 10 günde 45 günlük eşiği geçenler
                $dateFilter = "AND p.expires_at >= DATE_SUB(CURDATE(), INTERVAL 55 DAY)
                               AND p.expires_at <  DATE_SUB(CURDATE(), INTERVAL 44 DAY)";
            }

            $commonWhere = "
                AND p.deleted_at IS NULL AND p.is_cancelled = 0 AND p.endorsement_no <= 1
                AND p.production_type IN ('SELF','OUTGOING')
                AND p.expires_at < DATE_SUB(CURDATE(), INTERVAL 44 DAY)
                AND DATEDIFF(p.expires_at, p.starts_at) >= 180
                $dateFilter
            ";

            // Plaka bazlı
            $pdo->exec("
                INSERT IGNORE INTO lost_policy_events
                    (policy_id, customer_id, insurance_type_id, plate_no,
                     expires_at, lost_at, status, recovered_policy_id, recovered_at)
                SELECT p.id, p.customer_id, p.insurance_type_id, p.plate_no,
                       p.expires_at, DATE_ADD(p.expires_at, INTERVAL 45 DAY), 'LOST', NULL, NULL
                FROM policies p
                INNER JOIN insurance_types it ON it.id = p.insurance_type_id AND it.is_renewable = 1
                WHERE p.plate_no IS NOT NULL AND p.plate_no != ''
                  $commonWhere
                  AND NOT EXISTS (
                      SELECT 1 FROM policies pq
                      WHERE pq.deleted_at IS NULL AND pq.is_cancelled = 0
                        AND pq.plate_no = p.plate_no AND pq.insurance_type_id = p.insurance_type_id
                        AND (
                            -- Doğrudan yenileme: başlangıç tarihi 45 gün içinde (aynı gün dahil)
                            (pq.endorsement_no <= 1 AND pq.starts_at >= p.expires_at AND pq.starts_at <= DATE_ADD(p.expires_at, INTERVAL 45 DAY))
                            -- Zeyil durumu: bitiş tarihi eski poliçenin bitişinden 8-16 ay sonra
                            OR pq.expires_at BETWEEN DATE_ADD(p.expires_at, INTERVAL 8 MONTH) AND DATE_ADD(p.expires_at, INTERVAL 16 MONTH)
                        )
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM policies pc
                      WHERE pc.policy_no = p.policy_no AND pc.is_cancelled = 1 AND pc.deleted_at IS NULL
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM policies pdup
                      WHERE pdup.policy_no = p.policy_no AND pdup.id != p.id
                        AND pdup.endorsement_no <= 1 AND pdup.endorsement_no > p.endorsement_no
                        AND pdup.deleted_at IS NULL AND pdup.is_cancelled = 0
                  )
            ");

            // Hatalı geri kazanım düzeltme: plaka bazlı poliçelerde yeni poliçenin
            // sahibi farklı müşteriyse (araç satışı) RECOVERED → LOST'a döndür
            $pdo->exec("
                UPDATE lost_policy_events lpe
                INNER JOIN policies p_rec ON p_rec.id = lpe.recovered_policy_id
                SET lpe.status              = 'LOST',
                    lpe.recovered_policy_id = NULL,
                    lpe.recovered_at        = NULL
                WHERE lpe.plate_no IS NOT NULL
                  AND lpe.status = 'RECOVERED'
                  AND p_rec.customer_id != lpe.customer_id
            ");

            // Müşteri+branş bazlı
            $pdo->exec("
                INSERT IGNORE INTO lost_policy_events
                    (policy_id, customer_id, insurance_type_id, plate_no,
                     expires_at, lost_at, status, recovered_policy_id, recovered_at)
                SELECT p.id, p.customer_id, p.insurance_type_id, NULL,
                       p.expires_at, DATE_ADD(p.expires_at, INTERVAL 45 DAY), 'LOST', NULL, NULL
                FROM policies p
                INNER JOIN insurance_types it ON it.id = p.insurance_type_id AND it.is_renewable = 1
                WHERE (p.plate_no IS NULL OR p.plate_no = '')
                  $commonWhere
                  AND NOT EXISTS (
                      SELECT 1 FROM policies pq
                      WHERE pq.deleted_at IS NULL AND pq.is_cancelled = 0
                        AND pq.customer_id = p.customer_id AND pq.insurance_type_id = p.insurance_type_id
                        AND (
                            -- Aynı poliçe ailesi, 45 gün içinde yenileme
                            (pq.policy_no = p.policy_no AND pq.endorsement_no <= 1 AND pq.starts_at >= p.expires_at AND pq.starts_at <= DATE_ADD(p.expires_at, INTERVAL 45 DAY))
                            -- Farklı poliçe no olabilir: bitiş tarihi 8-16 ay sonraysa yenileme (TSS/ÖSS vb.)
                            OR pq.expires_at BETWEEN DATE_ADD(p.expires_at, INTERVAL 8 MONTH) AND DATE_ADD(p.expires_at, INTERVAL 16 MONTH)
                        )
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM policies pc
                      WHERE pc.policy_no = p.policy_no AND pc.is_cancelled = 1 AND pc.deleted_at IS NULL
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM policies pdup
                      WHERE pdup.policy_no = p.policy_no AND pdup.id != p.id
                        AND pdup.endorsement_no <= 1 AND pdup.endorsement_no > p.endorsement_no
                        AND pdup.deleted_at IS NULL AND pdup.is_cancelled = 0
                  )
            ");

            // Hatalı geri kazanım düzeltme: plakasız poliçelerde farklı policy_no ile yapılan
            // yenilemeler geri kazanım sayılmaz (farklı sözleşme = farklı sigorta)
            $pdo->exec("
                UPDATE lost_policy_events lpe
                INNER JOIN policies p_orig ON p_orig.id = lpe.policy_id
                INNER JOIN policies p_rec  ON p_rec.id  = lpe.recovered_policy_id
                SET lpe.status              = 'LOST',
                    lpe.recovered_policy_id = NULL,
                    lpe.recovered_at        = NULL
                WHERE (lpe.plate_no IS NULL OR lpe.plate_no = '')
                  AND lpe.status = 'RECOVERED'
                  AND p_rec.policy_no != p_orig.policy_no
            ");

            // ── Plaka bazlı: yanlış LOST tespiti ──────────────────────────────────
            // 45 gün içinde yenileme YAPILMIŞ ama LOST event oluşmuşsa sil.
            // Zeyil desteği için expires_at 8-16 ay kontrolü de dahil.
            $pdo->exec("
                DELETE lpe FROM lost_policy_events lpe
                WHERE lpe.plate_no IS NOT NULL AND lpe.plate_no != ''
                  AND lpe.status = 'LOST'
                  AND EXISTS (
                      SELECT 1 FROM policies pq
                      WHERE pq.deleted_at IS NULL AND pq.is_cancelled = 0
                        AND pq.plate_no = lpe.plate_no
                        AND pq.insurance_type_id = lpe.insurance_type_id
                        AND (
                            (pq.endorsement_no <= 1 AND pq.starts_at >= lpe.expires_at AND pq.starts_at <= DATE_ADD(lpe.expires_at, INTERVAL 45 DAY))
                            OR pq.expires_at BETWEEN DATE_ADD(lpe.expires_at, INTERVAL 8 MONTH) AND DATE_ADD(lpe.expires_at, INTERVAL 16 MONTH)
                        )
                  )
            ");
            // ── Plaka bazlı: yanlış RECOVERED tespiti ─────────────────────────────
            // recovered_policy aslında 45 gün içinde başlamış = hiç kaçırılmamış.
            // expires_at kontrolü kullanma: 46-90 gün gecikmeli dönen gerçek geri kazanımları silme.
            $pdo->exec("
                DELETE lpe FROM lost_policy_events lpe
                INNER JOIN policies p_rec ON p_rec.id = lpe.recovered_policy_id AND p_rec.deleted_at IS NULL
                WHERE lpe.plate_no IS NOT NULL AND lpe.plate_no != ''
                  AND lpe.status = 'RECOVERED'
                  AND p_rec.starts_at >= lpe.expires_at
                  AND p_rec.starts_at <= DATE_ADD(lpe.expires_at, INTERVAL 45 DAY)
            ");
            // ── Plakasız: yanlış LOST tespiti ─────────────────────────────────────
            $pdo->exec("
                DELETE lpe FROM lost_policy_events lpe
                INNER JOIN policies p_orig ON p_orig.id = lpe.policy_id AND p_orig.deleted_at IS NULL
                WHERE (lpe.plate_no IS NULL OR lpe.plate_no = '')
                  AND lpe.status = 'LOST'
                  AND EXISTS (
                      SELECT 1 FROM policies pq
                      WHERE pq.deleted_at IS NULL AND pq.is_cancelled = 0
                        AND pq.customer_id = lpe.customer_id
                        AND pq.insurance_type_id = lpe.insurance_type_id
                        AND (
                            (pq.policy_no = p_orig.policy_no AND pq.endorsement_no <= 1 AND pq.starts_at >= lpe.expires_at AND pq.starts_at <= DATE_ADD(lpe.expires_at, INTERVAL 45 DAY))
                            OR pq.expires_at BETWEEN DATE_ADD(lpe.expires_at, INTERVAL 8 MONTH) AND DATE_ADD(lpe.expires_at, INTERVAL 16 MONTH)
                        )
                  )
            ");
            // ── Plakasız: yanlış RECOVERED tespiti ────────────────────────────────
            $pdo->exec("
                DELETE lpe FROM lost_policy_events lpe
                INNER JOIN policies p_rec ON p_rec.id = lpe.recovered_policy_id AND p_rec.deleted_at IS NULL
                WHERE (lpe.plate_no IS NULL OR lpe.plate_no = '')
                  AND lpe.status = 'RECOVERED'
                  AND p_rec.starts_at >= lpe.expires_at
                  AND p_rec.starts_at <= DATE_ADD(lpe.expires_at, INTERVAL 45 DAY)
            ");
        } catch (\Throwable $e) {
            // Hata varsa sessizce geç — liste çalışmaya devam etsin
        }
    }


    /**
     * Bir sonraki yıldönümü tarihini hesaplar (SQL ifadesi).
     * $expiresCol: bitiş tarihi kolonu (varsayılan: lpe.expires_at)
     * $refDate   : referans tarih SQL ifadesi (varsayılan: CURDATE())
     */
    private function nextAnniversaryExpr(
        string $expiresCol = 'lpe.expires_at',
        string $refDate    = 'CURDATE()'
    ): string {
        return "DATE_ADD($expiresCol, INTERVAL
            CASE
                WHEN DATE_ADD($expiresCol, INTERVAL TIMESTAMPDIFF(YEAR, $expiresCol, $refDate) YEAR) < $refDate
                THEN TIMESTAMPDIFF(YEAR, $expiresCol, $refDate) + 1
                ELSE TIMESTAMPDIFF(YEAR, $expiresCol, $refDate)
            END
        YEAR)";
    }

    /**
     * Sekme (tab) + aralık (range) bazlı tarih filtresi.
     * $nextExpr: tahmini vadeyi veren SQL ifadesi
     */
    private function tabDateFilter(string $tab, string $range, string $nextExpr): string
    {
        if ($tab === 'upcoming') {
            if ($range === 'week') {
                return " AND ($nextExpr) >= CURDATE() AND ($nextExpr) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
            } elseif ($range === 'month') {
                return " AND ($nextExpr) >= CURDATE() AND ($nextExpr) <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
            } else {
                return " AND ($nextExpr) >= CURDATE() AND ($nextExpr) <= DATE_ADD(CURDATE(), INTERVAL 45 DAY)";
            }
        } else {
            return " AND ($nextExpr) < CURDATE() AND ($nextExpr) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        }
    }

    /**
     * Kaçırılan poliçeleri listele
     * GET /api/lost-policies?tab=upcoming|overdue&range=week|month&branch=&search=&status=&page=1&limit=50
     */
    public function index($user, $query)
    {
        Permission::require($user, 'lost_policies.view');
        self::autoSyncRecent();

        $tab    = $query['tab']    ?? 'upcoming';
        $range  = $query['range']  ?? '';
        $branch = $query['branch'] ?? '';
        $search = $query['search'] ?? '';
        $status = $query['status'] ?? '';
        $page   = max(1, (int)($query['page']  ?? 1));
        $limit  = min(100, max(10, (int)($query['limit'] ?? 50)));

        // Tahmini vade: agent'in manuel girdiği tarih varsa onu, yoksa otomatik yıldönümünü kullan
        $next           = "COALESCE(lpe.expected_date, {$this->nextAnniversaryExpr()})";
        $nextAtRecovery = $this->nextAnniversaryExpr('lpe.expires_at', 'DATE(lpe.recovered_at)');

        $where = '';

        if ($status === 'WON') {
            // Geri kazanılan: son 1 yıl içinde RECOVERED olan olaylar
            $where .= " AND lpe.status = 'RECOVERED'
                AND lpe.recovered_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        } elseif ($status === 'VEHICLE_SOLD') {
            $where .= " AND lpe.status IN ('VEHICLE_SOLD', 'ABANDONED')";
        } elseif ($status === 'PENDING') {
            $where .= " AND lpe.status = 'LOST'";
            $where .= $this->tabDateFilter($tab, $range, $next);
        } elseif ($status === 'ABANDONED') {
            $where .= " AND lpe.status = 'ABANDONED'";
        } else {
            // Varsayılan aktif liste: LOST + 1 yılı geçmiş ABANDONED (otomatik sıfırlama için)
            $where .= " AND (lpe.status = 'LOST'
                OR (lpe.status = 'ABANDONED' AND lpe.updated_at < DATE_SUB(CURDATE(), INTERVAL 1 YEAR)))";
            $where .= $this->tabDateFilter($tab, $range, $next);
        }

        if ($branch) {
            $where .= " AND lpe.insurance_type_id = " . (int)$branch;
        }
        if ($search) {
            $s = addslashes($search);
            $where .= " AND (c.name LIKE '%$s%' OR c.phone LIKE '%$s%' OR c.identity_no LIKE '%$s%'
                OR p.policy_no LIKE '%$s%' OR p.plate_no LIKE '%$s%')";
        }

        $select = "
            lpe.policy_id                                               AS id,
            p.policy_no,
            p.plate_no,
            COALESCE(lpe.registration_no, p.registration_no)           AS current_registration_no,
            lpe.expires_at,
            ($next)                                                     AS estimated_renewal,
            DATEDIFF(($next), CURDATE())                                AS remaining_days,
            lpe.customer_id,
            lpe.insurance_type_id,
            p.gross_premium,
            p.company_id,
            c.name                                                      AS customer_name,
            c.identity_no                                               AS customer_identity,
            c.phone                                                     AS customer_phone,
            c.email                                                     AS customer_email,
            it.name                                                     AS branch_name,
            it.color                                                    AS branch_color,
            co.name                                                     AS company_name,
            CASE lpe.status
                WHEN 'LOST'      THEN 'PENDING'
                WHEN 'RECOVERED' THEN 'WON'
                ELSE lpe.status
            END                                                         AS action_status,
            lpe.note                                                    AS action_note,
            lpe.expected_date,
            CASE WHEN lpe.status = 'RECOVERED'
                THEN DATEDIFF(($nextAtRecovery), DATE(lpe.recovered_at))
                ELSE NULL
            END                                                         AS won_days_before,
            p_rec.gross_premium                                         AS new_premium
        ";

        $sql = "SELECT $select
            FROM lost_policy_events lpe
            INNER JOIN policies p        ON p.id  = lpe.policy_id    AND p.deleted_at IS NULL
            INNER JOIN customers c       ON c.id  = lpe.customer_id  AND c.deleted_at IS NULL
            INNER JOIN insurance_types it ON it.id = lpe.insurance_type_id
            LEFT  JOIN companies co      ON co.id = p.company_id
            LEFT  JOIN policies p_rec    ON p_rec.id = lpe.recovered_policy_id
            WHERE 1=1 $where
            ORDER BY " . ($status === 'WON' ? "lpe.recovered_at DESC" : "estimated_renewal ASC") . "";

        $result = Database::paginate($sql, [], $page, $limit);

        // ABANDONED kayıtlar 1 yıl geçince otomatik LOST'a döner
        $resetIds = [];
        foreach ($result['data'] as &$item) {
            if ($item['action_status'] === 'ABANDONED') {
                $resetIds[] = (int)$item['id'];
                $item['action_status'] = 'PENDING';
            }
        }
        unset($item);
        if (!empty($resetIds)) {
            $placeholders = implode(',', array_fill(0, count($resetIds), '?'));
            Database::query(
                "UPDATE lost_policy_events SET status = 'LOST', note = '' WHERE policy_id IN ($placeholders)",
                $resetIds
            );
        }

        Response::success([
            'items'      => $result['data'],
            'pagination' => $result['pagination']
        ]);
    }

    /**
     * Özet istatistikler
     * GET /api/lost-policies/stats
     */
    public function stats($user)
    {
        self::autoSyncRecent();

        $next     = "COALESCE(lpe.expected_date, {$this->nextAnniversaryExpr()})";
        $lostBase = "FROM lost_policy_events lpe WHERE lpe.status = 'LOST'";

        $thisWeek = (int)(Database::fetch(
            "SELECT COUNT(*) AS cnt $lostBase
             AND ($next) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)", []
        )['cnt'] ?? 0);

        $thisMonth = (int)(Database::fetch(
            "SELECT COUNT(*) AS cnt $lostBase
             AND ($next) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)", []
        )['cnt'] ?? 0);

        $overdue = (int)(Database::fetch(
            "SELECT COUNT(*) AS cnt $lostBase
             AND ($next) < CURDATE() AND ($next) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)", []
        )['cnt'] ?? 0);

        $won = (int)(Database::fetch(
            "SELECT COUNT(*) AS cnt FROM lost_policy_events lpe
             WHERE lpe.status = 'RECOVERED'
               AND lpe.recovered_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)", []
        )['cnt'] ?? 0);

        $notNeeded = (int)(Database::fetch(
            "SELECT COUNT(*) AS cnt FROM lost_policy_events lpe
             WHERE lpe.status IN ('VEHICLE_SOLD', 'ABANDONED')", []
        )['cnt'] ?? 0);

        // Aktif penceredeki kayıtların branş dağılımı (filtre dropdown'u için)
        $branches = Database::fetchAll(
            "SELECT it.id, it.name, COUNT(*) AS cnt
             FROM lost_policy_events lpe
             INNER JOIN insurance_types it ON it.id = lpe.insurance_type_id
             WHERE lpe.status = 'LOST'
               AND (($next) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 45 DAY)
                 OR (($next) < CURDATE() AND ($next) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)))
             GROUP BY it.id, it.name
             ORDER BY cnt DESC", []
        );

        Response::success([
            'thisWeek'  => $thisWeek,
            'thisMonth' => $thisMonth,
            'overdue'   => $overdue,
            'won'       => $won,
            'notNeeded' => $notNeeded,
            'branches'  => $branches
        ]);
    }

    /**
     * Durum / not / vade güncelle
     * PUT /api/lost-policies/{id}  (id = policy_id)
     */
    public function update($user, $id, $input)
    {
        Permission::require($user, 'lost_policies.manage');

        $status         = $input['status']         ?? null;
        $note           = $input['note']           ?? null;
        $expectedDate   = $input['expectedDate']   ?? null;
        $registrationNo = $input['registrationNo'] ?? null;

        // WON = RECOVERED otomatik atanır, agent elle seçemez
        $validStatuses = ['PENDING', 'ABANDONED', 'VEHICLE_SOLD'];
        if ($status && !in_array($status, $validStatuses)) {
            Response::error('Geçersiz durum', 400);
        }

        // PENDING → 'LOST' (yeni şemada PENDING karşılığı LOST)
        $dbStatus = ($status === 'PENDING') ? 'LOST' : $status;

        $event = Database::fetch(
            "SELECT id FROM lost_policy_events WHERE policy_id = ?", [$id]
        );
        if (!$event) {
            Response::error('Kaçırılan poliçe kaydı bulunamadı', 404);
        }

        $updateData = ['updated_by' => $user['id']];
        if ($dbStatus !== null)                        $updateData['status']          = $dbStatus;
        if ($note !== null)                            $updateData['note']            = $note;
        if (array_key_exists('expectedDate', $input))  $updateData['expected_date']   = $expectedDate   ?: null;
        if (array_key_exists('registrationNo', $input)) $updateData['registration_no'] = $registrationNo ?: null;

        Database::update('lost_policy_events', $updateData, 'policy_id = ?', [$id]);

        Response::success(['message' => 'Güncellendi']);
    }

    /**
     * Yeni kaçırılma olaylarını tespit et ve lost_policy_events'e ekle.
     * Son 13 ayda süresi dolmuş + 45 günü geçmiş poliçeleri tarar.
     * Nightly cron: GET /api/lost-policies/sync
     * Manuel tetikleme: Kaçırılan Poliçeler sayfasındaki "Senkronize Et" butonu
     */
    public function syncNewLostEvents($user)
    {
        Permission::require($user, 'lost_policies.manage');

        set_time_limit(120);

        require_once __DIR__ . '/../helpers/SchemaHelper.php';

        // Tablo yoksa oluştur (migration sistemi çalışmamışsa da güvence)
        SchemaHelper::ensureTable('lost_policy_events', [
            'id'                  => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'policy_id'           => 'INT UNSIGNED NOT NULL',
            'customer_id'         => 'INT UNSIGNED NOT NULL',
            'insurance_type_id'   => 'INT UNSIGNED NOT NULL',
            'plate_no'            => 'VARCHAR(20) NULL',
            'expires_at'          => 'DATE NOT NULL',
            'lost_at'             => 'DATE NOT NULL',
            'status'              => "ENUM('LOST','RECOVERED','ABANDONED','VEHICLE_SOLD') NOT NULL DEFAULT 'LOST'",
            'recovered_policy_id' => 'INT UNSIGNED NULL',
            'recovered_at'        => 'DATE NULL',
            'registration_no'     => 'VARCHAR(50) NULL',
            'expected_date'       => 'DATE NULL',
            'note'                => 'TEXT NULL',
            'updated_by'          => 'INT UNSIGNED NULL',
            'created_at'          => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at'          => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ]);
        SchemaHelper::ensureIndex('lost_policy_events', 'uq_policy_event',         ['policy_id'], true);
        SchemaHelper::ensureIndex('lost_policy_events', 'idx_customer_type_status', ['customer_id', 'insurance_type_id', 'status']);
        SchemaHelper::ensureIndex('lost_policy_events', 'idx_plate_type_status',    ['plate_no', 'insurance_type_id', 'status']);
        SchemaHelper::ensureIndex('lost_policy_events', 'idx_status_lost_at',       ['status', 'lost_at']);
        SchemaHelper::ensureIndex('lost_policy_events', 'idx_recovered',            ['status', 'recovered_at']);
        SchemaHelper::ensureIndex('lost_policy_events', 'idx_recovered_policy',     ['recovered_policy_id']);

        $pdo = Database::getInstance();
        $pdo->exec("SET NAMES utf8mb4");

        try {

        // Plaka bazlı (Trafik, Kasko, Yeşil Kart vb.)
        $pdo->exec("
            INSERT IGNORE INTO lost_policy_events
                (policy_id, customer_id, insurance_type_id, plate_no,
                 expires_at, lost_at, status,
                 recovered_policy_id, recovered_at,
                 registration_no, expected_date, note, updated_by)
            SELECT
                p.id, p.customer_id, p.insurance_type_id, p.plate_no,
                p.expires_at,
                DATE_ADD(p.expires_at, INTERVAL 45 DAY),
                CASE
                    WHEN p_rec.id IS NOT NULL        THEN 'RECOVERED'
                    WHEN lpa.status = 'VEHICLE_SOLD' THEN 'VEHICLE_SOLD'
                    WHEN lpa.status = 'ABANDONED'    THEN 'ABANDONED'
                    ELSE                                  'LOST'
                END,
                p_rec.id,
                p_rec.starts_at,
                lpa.registration_no, lpa.expected_date, lpa.note, lpa.updated_by
            FROM policies p
            INNER JOIN insurance_types it ON it.id = p.insurance_type_id AND it.is_renewable = 1
            LEFT  JOIN lost_policy_actions lpa ON lpa.policy_id = p.id
            LEFT  JOIN policies p_rec ON p_rec.id = (
                SELECT id FROM policies
                WHERE deleted_at IS NULL AND is_cancelled = 0 AND endorsement_no <= 1
                  AND plate_no = p.plate_no AND insurance_type_id = p.insurance_type_id
                  AND customer_id = p.customer_id
                  AND starts_at > DATE_ADD(p.expires_at, INTERVAL 45 DAY)
                ORDER BY starts_at ASC LIMIT 1
            )
            WHERE p.deleted_at IS NULL
              AND p.is_cancelled = 0
              AND p.endorsement_no <= 1
              AND p.production_type IN ('SELF', 'OUTGOING')
              AND p.plate_no IS NOT NULL AND p.plate_no != ''
              AND p.expires_at < DATE_SUB(CURDATE(), INTERVAL 44 DAY)
              AND p.expires_at >= DATE_SUB(CURDATE(), INTERVAL 400 DAY)
              AND DATEDIFF(p.expires_at, p.starts_at) >= 180
              AND NOT EXISTS (
                  SELECT 1 FROM policies pq
                  WHERE pq.deleted_at IS NULL AND pq.is_cancelled = 0
                    AND pq.plate_no = p.plate_no AND pq.insurance_type_id = p.insurance_type_id
                    AND (
                        (pq.endorsement_no <= 1 AND pq.starts_at >= p.expires_at AND pq.starts_at <= DATE_ADD(p.expires_at, INTERVAL 45 DAY))
                        OR pq.expires_at BETWEEN DATE_ADD(p.expires_at, INTERVAL 8 MONTH) AND DATE_ADD(p.expires_at, INTERVAL 16 MONTH)
                    )
              )
              AND NOT EXISTS (
                  SELECT 1 FROM policies pc
                  WHERE pc.policy_no = p.policy_no AND pc.is_cancelled = 1 AND pc.deleted_at IS NULL
              )
              AND NOT EXISTS (
                  SELECT 1 FROM policies pdup
                  WHERE pdup.policy_no = p.policy_no AND pdup.id != p.id
                    AND pdup.endorsement_no <= 1 AND pdup.endorsement_no > p.endorsement_no
                    AND pdup.deleted_at IS NULL AND pdup.is_cancelled = 0
              )
        ");

        // Müşteri+branş bazlı (DASK, Konut, Sağlık vb.)
        $pdo->exec("
            INSERT IGNORE INTO lost_policy_events
                (policy_id, customer_id, insurance_type_id, plate_no,
                 expires_at, lost_at, status,
                 recovered_policy_id, recovered_at,
                 registration_no, expected_date, note, updated_by)
            SELECT
                p.id, p.customer_id, p.insurance_type_id, NULL,
                p.expires_at,
                DATE_ADD(p.expires_at, INTERVAL 45 DAY),
                CASE
                    WHEN p_rec.id IS NOT NULL        THEN 'RECOVERED'
                    WHEN lpa.status = 'VEHICLE_SOLD' THEN 'VEHICLE_SOLD'
                    WHEN lpa.status = 'ABANDONED'    THEN 'ABANDONED'
                    ELSE                                  'LOST'
                END,
                p_rec.id,
                p_rec.starts_at,
                lpa.registration_no, lpa.expected_date, lpa.note, lpa.updated_by
            FROM policies p
            INNER JOIN insurance_types it ON it.id = p.insurance_type_id AND it.is_renewable = 1
            LEFT  JOIN lost_policy_actions lpa ON lpa.policy_id = p.id
            LEFT  JOIN policies p_rec ON p_rec.id = (
                SELECT id FROM policies
                WHERE deleted_at IS NULL AND is_cancelled = 0 AND endorsement_no <= 1
                  AND customer_id = p.customer_id AND insurance_type_id = p.insurance_type_id
                  AND policy_no = p.policy_no
                  AND starts_at > DATE_ADD(p.expires_at, INTERVAL 45 DAY)
                ORDER BY starts_at ASC LIMIT 1
            )
            WHERE p.deleted_at IS NULL
              AND p.is_cancelled = 0
              AND p.endorsement_no <= 1
              AND p.production_type IN ('SELF', 'OUTGOING')
              AND (p.plate_no IS NULL OR p.plate_no = '')
              AND p.expires_at < DATE_SUB(CURDATE(), INTERVAL 44 DAY)
              AND p.expires_at >= DATE_SUB(CURDATE(), INTERVAL 400 DAY)
              AND DATEDIFF(p.expires_at, p.starts_at) >= 180
              AND NOT EXISTS (
                  SELECT 1 FROM policies pq
                  WHERE pq.deleted_at IS NULL AND pq.is_cancelled = 0
                    AND pq.customer_id = p.customer_id AND pq.insurance_type_id = p.insurance_type_id
                    AND (
                        (pq.policy_no = p.policy_no AND pq.endorsement_no <= 1 AND pq.starts_at >= p.expires_at AND pq.starts_at <= DATE_ADD(p.expires_at, INTERVAL 45 DAY))
                        OR pq.expires_at BETWEEN DATE_ADD(p.expires_at, INTERVAL 8 MONTH) AND DATE_ADD(p.expires_at, INTERVAL 16 MONTH)
                    )
              )
              AND NOT EXISTS (
                  SELECT 1 FROM policies pc
                  WHERE pc.policy_no = p.policy_no AND pc.is_cancelled = 1 AND pc.deleted_at IS NULL
              )
              AND NOT EXISTS (
                  SELECT 1 FROM policies pdup
                  WHERE pdup.policy_no = p.policy_no AND pdup.id != p.id
                    AND pdup.endorsement_no <= 1 AND pdup.endorsement_no > p.endorsement_no
                    AND pdup.deleted_at IS NULL AND pdup.is_cancelled = 0
              )
        ");

        } catch (\Throwable $e) {
            Response::error('Veritabanı hatası: ' . $e->getMessage(), 500);
        }

        Response::success(['message' => 'Senkronizasyon tamamlandı']);
    }

    /**
     * Excel export
     * GET /api/lost-policies/export?tab=upcoming|overdue&branch=&search=&status=
     */
    public function export($user, $query)
    {
        Permission::require($user, 'lost_policies.view');

        $tab    = $query['tab']    ?? 'upcoming';
        $branch = $query['branch'] ?? '';
        $search = $query['search'] ?? '';
        $status = $query['status'] ?? '';

        $next  = "COALESCE(lpe.expected_date, {$this->nextAnniversaryExpr()})";
        $where = '';

        if ($status === 'WON') {
            $where .= " AND lpe.status = 'RECOVERED'
                AND lpe.recovered_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        } elseif ($status === 'VEHICLE_SOLD') {
            $where .= " AND lpe.status IN ('VEHICLE_SOLD', 'ABANDONED')";
        } elseif ($status === 'ABANDONED') {
            $where .= " AND lpe.status = 'ABANDONED'";
        } else {
            // PENDING ve varsayılan için tab filtresi uygula
            $where .= " AND lpe.status = 'LOST'";
            $where .= $this->tabDateFilter($tab, '', $next);
        }

        if ($branch) {
            $where .= " AND lpe.insurance_type_id = " . (int)$branch;
        }
        if ($search) {
            $s = addslashes($search);
            $where .= " AND (c.name LIKE '%$s%' OR c.phone LIKE '%$s%' OR c.identity_no LIKE '%$s%'
                OR p.policy_no LIKE '%$s%' OR p.plate_no LIKE '%$s%')";
        }

        $select = "
            c.name                                             AS musteri,
            c.identity_no                                      AS tc_kimlik,
            c.phone                                            AS telefon,
            it.name                                            AS brans,
            p.plate_no                                         AS plaka,
            COALESCE(lpe.registration_no, p.registration_no)  AS ruhsat_no,
            p.policy_no                                        AS police_no,
            lpe.expires_at                                     AS bitis_tarihi,
            ($next)                                            AS tahmini_vade,
            DATEDIFF(($next), CURDATE())                       AS kalan_gun,
            co.name                                            AS sirket,
            p.gross_premium                                    AS prim,
            CASE lpe.status
                WHEN 'LOST'      THEN 'PENDING'
                WHEN 'RECOVERED' THEN 'WON'
                ELSE lpe.status
            END                                                AS durum,
            lpe.note                                           AS not_text
        ";

        $sql = "SELECT $select
            FROM lost_policy_events lpe
            INNER JOIN policies p         ON p.id  = lpe.policy_id    AND p.deleted_at IS NULL
            INNER JOIN customers c        ON c.id  = lpe.customer_id  AND c.deleted_at IS NULL
            INNER JOIN insurance_types it ON it.id = lpe.insurance_type_id
            LEFT  JOIN companies co       ON co.id = p.company_id
            WHERE 1=1 $where
            ORDER BY tahmini_vade ASC";

        $rows = Database::fetchAll($sql, []);

        $statusLabels = [
            'PENDING'      => 'Beklemede',
            'WON'          => 'Kazanıldı',
            'ABANDONED'    => 'Kaybedildi',
            'VEHICLE_SOLD' => 'Araç/Konut Satıldı'
        ];

        // Durum etiketlerini ve plaka boş değerlerini dönüştür
        foreach ($rows as &$r) {
            $r['plaka']   = $r['plaka'] ?: '-';
            $r['ruhsat_no'] = $r['ruhsat_no'] ?? '-';
            $r['prim']    = (float) $r['prim'];
            $r['durum']   = $statusLabels[$r['durum']] ?? $r['durum'];
        }
        unset($r);

        $columns = [
            ['key' => 'musteri',       'label' => 'Müşteri'],
            ['key' => 'tc_kimlik',     'label' => 'TC Kimlik',      'type' => Response::COL_IDENTIFIER],
            ['key' => 'telefon',       'label' => 'Telefon',        'type' => Response::COL_IDENTIFIER],
            ['key' => 'brans',         'label' => 'Branş'],
            ['key' => 'plaka',         'label' => 'Plaka'],
            ['key' => 'ruhsat_no',     'label' => 'Ruhsat No'],
            ['key' => 'police_no',     'label' => 'Poliçe No',      'type' => Response::COL_IDENTIFIER],
            ['key' => 'bitis_tarihi',  'label' => 'Bitiş Tarihi',   'type' => Response::COL_DATE],
            ['key' => 'tahmini_vade',  'label' => 'Tahmini Vade',   'type' => Response::COL_DATE],
            ['key' => 'kalan_gun',     'label' => 'Kalan Gün',      'type' => Response::COL_NUMBER],
            ['key' => 'sirket',        'label' => 'Şirket'],
            ['key' => 'prim',          'label' => 'Prim',           'type' => Response::COL_CURRENCY],
            ['key' => 'durum',         'label' => 'Durum'],
            ['key' => 'not_text',      'label' => 'Not',            'type' => Response::COL_TEXT],
        ];

        Response::xlsx($rows, $columns, 'kacirilan_policeler_' . date('Y-m-d') . '.xlsx');
    }
}
