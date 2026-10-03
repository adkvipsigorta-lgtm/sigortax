<?php

require_once __DIR__ . '/PolicyController.php';

class ImportMappingController
{
    // GET /api/import-mappings
    public function index(array $user): void
    {
        $rows = Database::fetchAll(
            "SELECT id, name, mapping_json, updated_at FROM import_mappings WHERE user_id = ? ORDER BY updated_at DESC",
            [$user['userId']]
        );
        $data = array_map(function ($r) {
            return [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'mapping' => json_decode($r['mapping_json'], true) ?: [],
                'updatedAt' => $r['updated_at'],
            ];
        }, $rows);
        Response::success($data);
    }

    // POST /api/import-mappings   { name, mapping }
    public function store(array $user, array $input): void
    {
        $validator = new Validator();
        if (!$validator->validate($input, [
            'name' => 'required|min:1',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }
        $name = trim($input['name']);
        $mapping = isset($input['mapping']) ? $input['mapping'] : [];
        if (!is_array($mapping)) $mapping = [];

        $existing = Database::fetch(
            "SELECT id FROM import_mappings WHERE user_id = ? AND name = ?",
            [$user['userId'], $name]
        );
        $now = date('Y-m-d H:i:s');
        $json = json_encode($mapping, JSON_UNESCAPED_UNICODE);

        if ($existing) {
            Database::update('import_mappings', [
                'mapping_json' => $json,
                'updated_at' => $now,
            ], 'id = ?', [$existing['id']]);
            Response::success(['id' => (int) $existing['id']], 'Sablon guncellendi');
            return;
        }

        $id = Database::insert('import_mappings', [
            'user_id' => $user['userId'],
            'name' => $name,
            'mapping_json' => $json,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Response::success(['id' => $id], 'Sablon olusturuldu', 201);
    }

    // DELETE /api/import-mappings/{id}
    public function destroy(array $user, int $id): void
    {
        $row = Database::fetch("SELECT id FROM import_mappings WHERE id = ? AND user_id = ?", [$id, $user['userId']]);
        if (!$row) Response::error('Sablon bulunamadi', 404);
        Database::query("DELETE FROM import_mappings WHERE id = ?", [$id]);
        Response::success(null, 'Sablon silindi');
    }

    /**
     * POST /api/import-mappings/run
     * Excel'den parse edilmis kayitlari toplu olarak DB'ye yazar.
     * Allianz save mantiginin generic versiyonu.
     *
     * Beklenen body:
     *   { policies: [{ customerName, identityNumber, customerType, phone, birthDate,
     *                  insuranceId|insuranceName, companyId|companyName, policyNo,
     *                  endorsementNo, issuedAt, startsAt, expiresAt,
     *                  grossPremium, netPremium, companyCommRate, branchCommRate,
     *                  isCancelled, productionType, branchId, plateNo, uavt,
     *                  chassisNo, engineNo, modelYear, registrationNo,
     *                  insuredName, additionalInsureds, network, daskNo, referenceSource, soldBy }] }
     */
    public function run(array $user, array $input): void
    {
        if (empty($input['policies']) || !is_array($input['policies'])) {
            Response::error('Kaydedilecek police bulunamadi', 400);
        }

        // Büyük Excel dosyalarında timeout'u önle
        set_time_limit(180);

        $saved = 0;
        $errors = [];
        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
        foreach ($input['policies'] as $idx => $p) {
            try {
                // Find or create customer
                $customerId = null;
                $identityNumber = trim((string) ($p['identityNumber'] ?? ''));
                if ($identityNumber !== '') {
                    $existing = Database::fetch(
                        "SELECT id FROM customers WHERE identity_no = ? AND deleted_at IS NULL",
                        [$identityNumber]
                    );
                    if ($existing) {
                        $customerId = (int) $existing['id'];
                        $upd = ['updated_at' => date('Y-m-d H:i:s')];
                        $cust = Database::fetch("SELECT phone, birth_date, address, tax_office FROM customers WHERE id = ?", [$customerId]);
                        if ($cust) {
                            if (empty($cust['phone']) && !empty($p['phone'])) $upd['phone'] = $p['phone'];
                            if (empty($cust['birth_date']) && !empty($p['birthDate'])) $upd['birth_date'] = $p['birthDate'];
                            if (empty($cust['address']) && !empty($p['customerAddress'])) $upd['address'] = $p['customerAddress'];
                            if (empty($cust['tax_office']) && !empty($p['taxOffice'])) $upd['tax_office'] = $p['taxOffice'];
                            if (count($upd) > 1) Database::update('customers', $upd, 'id = ?', [$customerId]);
                        }
                    }
                }
                if (!$customerId && !empty($p['customerName'])) {
                    $now = date('Y-m-d H:i:s');
                    $customerId = Database::insert('customers', [
                        'customer_type' => $p['customerType'] ?? (strlen($identityNumber) === 11 ? 'INDIVIDUAL' : 'CORPORATE'),
                        'name' => $p['customerName'],
                        'identity_no' => $identityNumber,
                        'phone' => $p['phone'] ?? '',
                        'email' => $p['email'] ?? '',
                        'birth_date' => !empty($p['birthDate']) ? $p['birthDate'] : null,
                        'address' => $p['customerAddress'] ?? '',
                        'tax_office' => $p['taxOffice'] ?? '',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                // Resolve insurance
                $insuranceId = null;
                if (!empty($p['insuranceId'])) {
                    $insuranceId = (int) $p['insuranceId'];
                } elseif (!empty($p['insuranceName'])) {
                    $ins = Database::fetch(
                        "SELECT id FROM insurance_types WHERE name LIKE ? AND level = 'subcategory' AND deleted_at IS NULL LIMIT 1",
                        ['%' . $p['insuranceName'] . '%']
                    );
                    if ($ins) $insuranceId = (int) $ins['id'];
                }

                // Resolve company
                $companyId = null;
                if (!empty($p['companyId'])) {
                    $companyId = (int) $p['companyId'];
                } elseif (!empty($p['companyName'])) {
                    $co = Database::fetch(
                        "SELECT id FROM companies WHERE name LIKE ? AND deleted_at IS NULL LIMIT 1",
                        ['%' . $p['companyName'] . '%']
                    );
                    if ($co) $companyId = (int) $co['id'];
                }

                $endorsementNo = (int) ($p['endorsementNo'] ?? 1);
                $policyNo = (string) ($p['policyNo'] ?? '');

                // Duplicate check
                if ($policyNo !== '') {
                    $dup = Database::fetch(
                        "SELECT id FROM policies WHERE policy_no = ? AND endorsement_no = ? AND deleted_at IS NULL",
                        [$policyNo, $endorsementNo]
                    );
                    if ($dup) {
                        $errors[] = "Satir " . ($idx + 1) . ": $policyNo/$endorsementNo zaten mevcut";
                        continue;
                    }
                }

                $parentId = $policyNo !== '' ? PolicyController::resolveParentId($policyNo) : null;
                $productionType = $p['productionType'] ?? 'SELF';
                $branchId = !empty($p['branchId']) ? (int) $p['branchId'] : null;

                Database::insert('policies', [
                    'production_type' => $productionType,
                    'customer_id' => $customerId,
                    'insurance_type_id' => $insuranceId,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'policy_no' => $policyNo,
                    'insured_name' => !empty($p['insuredName']) ? $p['insuredName'] : null,
                    'parent_id' => $parentId,
                    'endorsement_no' => $endorsementNo,
                    'issued_at' => $p['issuedAt'] ?? $p['startsAt'] ?? date('Y-m-d'),
                    'starts_at' => $p['startsAt'] ?? date('Y-m-d'),
                    'expires_at' => $p['expiresAt'] ?? date('Y-m-d'),
                    'gross_premium' => $p['grossPremium'] ?? 0,
                    'net_premium' => $p['netPremium'] ?? 0,
                    'company_comm_rate' => $p['companyCommRate'] ?? 0,
                    'branch_comm_rate' => $p['branchCommRate'] ?? 0,
                    'is_approved' => 1,
                    'is_cancelled' => !empty($p['isCancelled']) ? 1 : 0,
                    'plate_no' => $p['plateNo'] ?? null,
                    'registration_no' => $p['registrationNo'] ?? null,
                    'chassis_no' => $p['chassisNo'] ?? null,
                    'engine_no' => $p['engineNo'] ?? null,
                    'vehicle_brand' => $p['vehicleBrand'] ?? null,
                    'vehicle_model' => $p['vehicleModel'] ?? null,
                    'vehicle_year' => $p['vehicleYear'] ?? null,
                    'uavt_code' => $p['uavt'] ?? null,
                    'dask_no' => $p['daskNo'] ?? null,
                    'network' => $p['network'] ?? null,
                    'additional_insureds' => $p['additionalInsureds'] ?? null,
                    'reference_source' => !empty($p['referenceSource']) ? (int) $p['referenceSource'] : null,
                    'sold_by' => !empty($p['soldBy']) ? (int) $p['soldBy'] : null,
                    'created_by' => $user['userId'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $saved++;
            } catch (\Throwable $e) {
                $errors[] = "Satir " . ($idx + 1) . ": " . $e->getMessage();
            }
        }

        $pdo->commit();

        } catch (\Throwable $fatal) {
            // Beklenmedik sistem hatası: tüm import geri alınır
            $pdo->rollBack();
            Response::error('Import sistem hatası, hiçbir kayıt yazılmadı: ' . $fatal->getMessage(), 500);
        }

        Response::success(['saved' => $saved, 'errors' => $errors], "$saved police kaydedildi");
    }
}
