<?php

require_once __DIR__ . '/PolicyController.php';

class AllianzController
{
    /**
     * Allianz XML adres formatını okunabilir iki satıra dönüştürür.
     * Giriş: "TÜRKİYE, İSTANBUL 34255, GAZİOSMANPAŞA, FEVZİ ÇAKMAK 795. Sokak SOK. NO:2 A D:22 PAFTA:... PARSEL:..."
     * Çıkış: "Fevzi Çakmak, 795. Sokak No:2 A D:22\nGaziosmanpaşa - İstanbul"
     */
    private function formatAllianzAddress(string $raw): string
    {
        if (empty($raw)) return '';

        $parts = array_map('trim', explode(',', $raw));

        // En az 3 parça ve ilki TÜRKİYE olmalı
        if (count($parts) < 3 || mb_strtoupper($parts[0], 'UTF-8') !== 'TÜRKİYE') {
            return $raw;
        }

        // Şehir — posta kodunu kaldır: "İSTANBUL 34255" → "İSTANBUL"
        $city = trim(preg_replace('/\s+\d{4,6}\b/', '', $parts[1]));

        // İlçe
        $district = trim($parts[2]);

        // Sokak detayı: 3. indeksten itibaren birleştir
        $street = trim(implode(' ', array_slice($parts, 3)));

        // "Sokak SOK." → "Sokak" (tekrarlanan SOK. kaldır)
        $street = preg_replace('/\bSOK\.\s*/i', '', $street);
        // PAFTA, PARSEL, ADA, SAYFANO bilgilerini kaldır
        $street = preg_replace('/\s+PAFTA:\S+/i', '', $street);
        $street = preg_replace('/\s+PARSEL:\S+/i', '', $street);
        $street = preg_replace('/\s+ADA:\S+/i', '', $street);
        $street = preg_replace('/\s+SAYFANO:\S+/i', '', $street);
        // Fazla boşlukları temizle
        $street = preg_replace('/\s+/', ' ', trim($street));

        // Türkçe title case — Allianz ALL CAPS girdisinde I ve İ ikisi de 'i' (noktalı) olarak ele alınır
        $titleTR = function (string $s): string {
            $s = str_replace(['İ', 'I'], 'i', $s);  // her ikisi de noktalı küçük i'ye dön
            $s = mb_strtolower($s, 'UTF-8');
            // Kelime kelime: i→İ, ı→I, diğerleri mb_strtoupper
            $words = preg_split('/(\s+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
            $out = [];
            foreach ($words as $w) {
                if (trim($w) === '') { $out[] = $w; continue; }
                $first = mb_substr($w, 0, 1, 'UTF-8');
                $rest  = mb_substr($w, 1, null, 'UTF-8');
                if ($first === 'i') $first = 'İ';
                elseif ($first === 'ı') $first = 'I';
                else $first = mb_strtoupper($first, 'UTF-8');
                $out[] = $first . $rest;
            }
            return implode('', $out);
        };

        $streetFormatted   = $titleTR($street);
        $districtFormatted = $titleTR($district);
        $cityFormatted     = $titleTR($city);

        // Satır 1: sokak detayı   Satır 2: ilçe - şehir
        return $streetFormatted . "\n" . $districtFormatted . ' - ' . $cityFormatted;
    }

    public function upload(array $user): void
    {
        AuthMiddleware::requireAdmin($user);

        if (empty($_FILES['file'])) {
            Response::error('Dosya yuklenemedi', 400);
        }

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error('Dosya yukleme hatasi', 400);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xml') {
            Response::error('Sadece XML dosyalari kabul edilir', 400);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($file['tmp_name']);
        if (!$xml) {
            Response::error('XML dosyasi okunamadi', 400);
        }

        // Tüm ZEYIL elementlerini XPath ile al (derinlik ne olursa olsun)
        $zeyils = $xml->xpath('//ZEYIL');
        if (empty($zeyils)) {
            Response::error('XML dosyasinda ZEYIL blogu bulunamadi', 400);
        }

        $fmtDate = function (string $d): string {
            if (!$d) return '';
            if (strlen($d) === 8 && ctype_digit($d)) {
                return substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
            }
            if (strpos($d, '/') !== false) {
                $p = explode('/', $d);
                if (count($p) === 3) {
                    return $p[2] . '-' . str_pad($p[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($p[0], 2, '0', STR_PAD_LEFT);
                }
            }
            return $d;
        };

        $policies = [];

        foreach ($zeyils as $z) {
            $get = fn($tag) => trim((string) ($z->$tag ?? ''));

            // SIGORTALI_LIST
            $sigortaliList = [];
            if (isset($z->SIGORTALI_LIST->SIGORTALI)) {
                foreach ($z->SIGORTALI_LIST->SIGORTALI as $s) {
                    $sigortaliList[] = [
                        'name'         => trim((string) ($s->SIGORTALI_ADI_SOYADI ?? '')),
                        'tc'           => trim((string) ($s->TC_KIMLIK_NO ?? '')),
                        'vergi'        => trim((string) ($s->VERGI_NO ?? '')),
                        'adres'        => $this->formatAllianzAddress(trim((string) ($s->ADRES ?? ''))),
                        'vergiDairesi' => trim((string) ($s->VERGI_DAIRESI ?? '')),
                    ];
                }
            }

            // DETAIL_TABLE
            $det = $z->DETAIL_TABLE_LIST->DETAIL_TABLE ?? null;
            $getDet = fn($tag) => $det ? trim((string) ($det->$tag ?? '')) : '';

            $netAmount   = (float) $get('NET_PRIM');
            $grossAmount = (float) $get('BRUT_PRIM');
            $commission  = (float) ($get('KOMISYON') ?: $get('KOMISYON_DOVIZ'));
            $commRate    = $netAmount !== 0.0
                ? round((abs($commission) / abs($netAmount)) * 100 * 100) / 100
                : 0.0;

            $ettirenName  = $get('SIGORTA_ETTIREN_AD_SOYAD');
            $ettirenTc    = $get('SIGORTA_ETTIREN_TC_KIMLIK_NO');
            $ettirenVergi = $get('SIGORTA_ETTIREN_VERGI_NO');
            $ettirenAdres = $this->formatAllianzAddress($get('SIGORTA_ETTIREN_ADRESI'));

            $first        = $sigortaliList[0] ?? null;
            $sigName      = $first['name'] ?? '';
            $sigTc        = $first['tc'] ?? '';
            $sigVergi     = $first['vergi'] ?? '';
            $useSigortali = $sigName !== '' && ($sigTc !== '' || $sigVergi !== '');

            $tcNo            = $useSigortali ? $sigTc           : $ettirenTc;
            $taxNo           = $useSigortali ? $sigVergi        : $ettirenVergi;
            $customerName    = $useSigortali ? $sigName         : $ettirenName;
            $customerAddress = $useSigortali ? ($first['adres'] ?: $ettirenAdres) : $ettirenAdres;
            $taxOffice       = $useSigortali ? ($first['vergiDairesi'] ?: $get('VERGI_DAIRESI')) : $get('VERGI_DAIRESI');
            $insuredOnPolicy = ($useSigortali && $ettirenName) ? $ettirenName : '';

            // Ek sigortalılar: 2. ve sonraki SIGORTALI (isim + TC ile JSON)
            $additionalInsureds = null;
            if (count($sigortaliList) > 1) {
                $extras = [];
                foreach (array_slice($sigortaliList, 1) as $s) {
                    if ($s['name'] !== '') {
                        $extras[] = ['name' => $s['name'], 'tc' => $s['tc']];
                    }
                }
                if (!empty($extras)) {
                    $additionalInsureds = json_encode($extras, JSON_UNESCAPED_UNICODE);
                }
            }

            $identityNumber = $tcNo ?: $taxNo;
            $zeyilType      = $get('ZEYIL_TIPI');

            // insured_no: sigorta ettirenin TC'si — useSigortali true ise ettiren != müşteri
            $insuredNo = ($useSigortali && $ettirenTc) ? $ettirenTc : '';

            $policies[] = [
                'branchCode'              => $get('BRANS_KODU'),
                'productCode'             => $get('URUN_KODU'),
                'altProductCode'          => $getDet('ALT_URUN_KODU'),
                'saglikTipi'              => $get('SAGLIK_POLICE_TIPI'),
                'policyNo'                => $get('POLICE_NO'),
                'zeyilNo'                 => (int) ($get('ZEYIL_NO') ?: 1),
                'issuedAt'                => $fmtDate($get('TANZIM_TARIHI')),
                'startsAt'                => $fmtDate($get('BASLANGIC_TARIHI')),
                'expiresAt'               => $fmtDate($get('BITIS_TARIHI')),
                'customerName'            => $customerName,
                'insuredName'             => $insuredOnPolicy,
                'insuredNo'               => $insuredNo,
                'identityNumber'          => $identityNumber,
                'customerType'            => ($tcNo && strlen($tcNo) === 11) ? 'INDIVIDUAL' : 'CORPORATE',
                'customerAddress'         => $customerAddress,
                'taxOffice'               => $taxOffice,
                'phone'                   => '',
                'birthDate'               => '',
                'grossPremium'            => $grossAmount,
                'netPremium'              => $netAmount,
                'companyCommRate'         => $commRate,
                'companyCommissionAmount' => $commission,
                'isCancelled'             => in_array($zeyilType, ['14', '2']),
                'plateNo'                 => $getDet('PLAKA_NO'),
                'chassisNo'               => $getDet('SASI_NO'),
                'engineNo'                => $getDet('MOTOR_NO'),
                'vehicleYear'             => $getDet('MODEL_YILI'),
                'registrationNo'          => $getDet('RUHSAT_SERI_NO'),
                'uavt'                    => $get('RISK_ADRES_NO'),
                'riskAddress'             => $this->formatAllianzAddress($get('RISK_ADRESI')),
                'daskNo'                  => $getDet('SERI_NO') ?: $get('ESKI_POLICE_NO'),
                'additionalInsureds'      => $additionalInsureds,
            ];
        }

        Response::success([
            'count'    => count($policies),
            'policies' => $policies,
        ], 'XML basariyla okundu');
    }

    public function checkDuplicates(array $user, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        if (empty($input['policies']) || !is_array($input['policies'])) {
            Response::success(['duplicates' => [], 'customers' => (object)[]]);
            return;
        }

        // Tüm policy_no + endorsement_no çiftlerini tek sorguda kontrol et (N query → 1 query)
        $conditions = [];
        $params = [];
        $identityNos = [];
        foreach ($input['policies'] as $p) {
            $policyNo = $p['policyNo'] ?? '';
            $endorsementNo = (int) ($p['endorsementNo'] ?? 1);
            if (!empty($policyNo)) {
                $conditions[] = "(policy_no = ? AND endorsement_no = ?)";
                $params[] = $policyNo;
                $params[] = $endorsementNo;
            }
            if (!empty($p['identityNumber'])) {
                $identityNos[trim($p['identityNumber'])] = true;
            }
        }

        $duplicates = [];
        if (!empty($conditions)) {
            $whereClause = implode(' OR ', $conditions);
            $rows = Database::fetchAll(
                "SELECT policy_no, endorsement_no FROM policies WHERE deleted_at IS NULL AND ($whereClause)",
                $params
            );
            foreach ($rows as $row) {
                $duplicates[] = $row['policy_no'] . '/' . $row['endorsement_no'];
            }
        }

        // Mevcut musterileri getir (telefon + dogum tarihi auto-fill icin)
        $customers = (object) [];
        $ids = array_keys($identityNos);
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::fetchAll(
                "SELECT id, identity_no, phone, birth_date FROM customers WHERE identity_no IN ($placeholders) AND deleted_at IS NULL",
                $ids
            );
            $map = [];
            foreach ($rows as $r) {
                $map[$r['identity_no']] = [
                    'id' => (int) $r['id'],
                    'phone' => $r['phone'] ?? '',
                    'birthDate' => $r['birth_date'] ?? '',
                ];
            }
            if (!empty($map)) $customers = $map;
        }

        Response::success(['duplicates' => $duplicates, 'customers' => $customers]);
    }

    public function save(array $user, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        if (empty($input['policies']) || !is_array($input['policies'])) {
            Response::error('Kaydedilecek police bulunamadi', 400);
        }

        // Find Allianz company once
        $company = Database::fetch("SELECT id FROM companies WHERE name LIKE '%Allianz%' AND deleted_at IS NULL");
        $companyId = $company ? (int) $company['id'] : null;

        $saved = 0;
        $errors = [];

        foreach ($input['policies'] as $idx => $p) {
            try {
                // Find or create customer
                $customerId = null;
                if (!empty($p['identityNumber'])) {
                    $existing = Database::fetch(
                        "SELECT id FROM customers WHERE identity_no = ? AND deleted_at IS NULL",
                        [$p['identityNumber']]
                    );
                    if ($existing) {
                        $customerId = (int) $existing['id'];
                    }
                }

                if (!$customerId && !empty($p['customerName'])) {
                    $customerData = [
                        'customer_type' => $p['customerType'] ?? 'INDIVIDUAL',
                        'name' => $p['customerName'],
                        'identity_no' => $p['identityNumber'] ?? '',
                        'phone' => $p['phone'] ?? '',
                        'phone_alt' => $p['phoneAlt'] ?? '',
                        'email' => $p['email'] ?? '',
                        'birth_date' => !empty($p['birthDate']) ? $p['birthDate'] : null,
                        'address' => $p['customerAddress'] ?? '',
                        'tax_office' => $p['taxOffice'] ?? '',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ];
                    $customerId = Database::insert('customers', $customerData);
                } elseif ($customerId) {
                    // Mevcut musteride eksik alanlari guncelle
                    $updateData = ['updated_at' => date('Y-m-d H:i:s')];
                    $existingCustomer = Database::fetch("SELECT * FROM customers WHERE id = ?", [$customerId]);
                    if ($existingCustomer) {
                        if (empty($existingCustomer['phone']) && !empty($p['phone'])) {
                            $updateData['phone'] = $p['phone'];
                        }
                        if (empty($existingCustomer['birth_date']) && !empty($p['birthDate'])) {
                            $updateData['birth_date'] = $p['birthDate'];
                        }
                        // Adres: boşsa veya eski Allianz formatındaysa (TÜRKİYE ile başlıyor) yeni formatlanmış adresle güncelle
                        $newAddr = !empty($p['customerAddress']) ? $p['customerAddress'] : '';
                        $existingAddr = $existingCustomer['address'] ?? '';
                        $isOldAllianzAddr = stripos($existingAddr, 'TÜRKİYE') !== false;
                        if ($newAddr !== '' && ($existingAddr === '' || $isOldAllianzAddr)) {
                            $updateData['address'] = $newAddr;
                        }
                        if (empty($existingCustomer['tax_office']) && !empty($p['taxOffice'])) {
                            $updateData['tax_office'] = $p['taxOffice'];
                        }
                        if (count($updateData) > 1) {
                            Database::update('customers', $updateData, 'id = ?', [$customerId]);
                        }
                    }
                }

                // Find insurance type by id or name
                $insuranceId = null;
                if (!empty($p['insuranceId'])) {
                    $insuranceId = (int) $p['insuranceId'];
                } elseif (!empty($p['insuranceName'])) {
                    $ins = Database::fetch("SELECT id FROM insurance_types WHERE name LIKE ? AND deleted_at IS NULL", ['%' . $p['insuranceName'] . '%']);
                    if ($ins) $insuranceId = (int) $ins['id'];
                }

                // Check duplicate (policy_no + endorsement_no)
                $endorsementNo = (int) ($p['endorsementNo'] ?? 1);
                if (!empty($p['policyNo'])) {
                    $dup = Database::fetch(
                        "SELECT id FROM policies WHERE policy_no = ? AND endorsement_no = ? AND deleted_at IS NULL",
                        [$p['policyNo'], $endorsementNo]
                    );
                    if ($dup) {
                        // XML'den gelen tüm alanları güncelle (Allianz resmi veri kaynağı).
                        // Korunan alanlar (kullanıcı elle girer): sold_by, reference_source,
                        // created_by, dask_no
                        $dupUpdate = [
                            // Üretim tipi & acente (import tablosundan seçilir)
                            'production_type'      => $p['productionType'] ?? null,
                            'branch_id'            => !empty($p['branchId']) ? (int) $p['branchId'] : null,
                            // Primler & komisyon
                            'gross_premium'        => $p['grossPremium'] ?? null,
                            'net_premium'          => $p['netPremium'] ?? null,
                            'company_comm_rate'    => $p['companyCommRate'] ?? null,
                            'company_comm_amount'  => $p['companyCommAmount'] ?? null,
                            'branch_comm_rate'     => $p['branchCommRate'] ?? null,
                            'branch_comm_amount'   => $p['branchCommAmount'] ?? null,
                            // Tarihler
                            'issued_at'            => $p['issuedAt'] ?? null,
                            'starts_at'            => $p['startsAt'] ?? null,
                            'expires_at'           => $p['expiresAt'] ?? null,
                            // İptal durumu
                            'is_cancelled'         => ($p['isCancelled'] ?? false) ? 1 : 0,
                            // Sigortalı bilgileri
                            'insured_name'         => !empty($p['insuredName']) ? trim($p['insuredName']) : null,
                            'insured_no'           => !empty($p['insuredNo']) ? trim($p['insuredNo']) : null,
                            'additional_insureds'  => !empty($p['additionalInsureds']) ? trim($p['additionalInsureds']) : null,
                            // Araç bilgileri
                            'plate_no'             => !empty($p['plateNo']) ? trim($p['plateNo']) : null,
                            'chassis_no'           => !empty($p['chassisNo']) ? trim($p['chassisNo']) : null,
                            'engine_no'            => !empty($p['engineNo']) ? trim($p['engineNo']) : null,
                            'registration_no'      => !empty($p['registrationNo']) ? trim($p['registrationNo']) : null,
                            'vehicle_year'         => !empty($p['vehicleYear']) ? trim($p['vehicleYear']) : null,
                            // DASK/Konut
                            'uavt_code'            => !empty($p['uavt']) ? trim($p['uavt']) : null,
                            'dask_no'              => !empty($p['daskNo']) ? trim($p['daskNo']) : null,
                            'risk_address'         => !empty($p['riskAddress']) ? $p['riskAddress'] : null,
                            // Meta
                            'updated_at'           => date('Y-m-d H:i:s'),
                        ];
                        // Null değerleri temizle — mevcut veriyi NULL ile ezmemek için
                        $dupUpdate = array_filter($dupUpdate, fn($v) => $v !== null);
                        $dupUpdate['updated_at'] = date('Y-m-d H:i:s'); // her zaman güncelle
                        Database::update('policies', $dupUpdate, 'id = ?', [$dup['id']]);
                        $errors[] = "Satir " . ($idx + 1) . ": Police {$p['policyNo']}/{$endorsementNo} zaten mevcut";
                        continue;
                    }
                }

                $productionType = $p['productionType'] ?? 'SELF';
                $branchId = !empty($p['branchId']) ? (int) $p['branchId'] : null;

                // Zeyil/yenileme: yil bazli zeyilname (endorsement_no > 100, orn. DASK 20251) bagimsiz yillik yenileme sayilir
                $parentId = (!empty($p['policyNo']) && $endorsementNo <= 100)
                    ? PolicyController::resolveParentId($p['policyNo'])
                    : null;

                $newPolicyId = Database::insert('policies', [
                    'production_type' => $productionType,
                    'customer_id' => $customerId,
                    'insurance_type_id' => $insuranceId,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'policy_no' => trim($p['policyNo'] ?? ''),
                    'insured_name' => !empty($p['insuredName']) ? trim($p['insuredName']) : null,
                    'insured_no' => !empty($p['insuredNo']) ? trim($p['insuredNo']) : null,
                    'additional_insureds' => !empty($p['additionalInsureds']) ? trim($p['additionalInsureds']) : null,
                    'parent_id' => $parentId,
                    'endorsement_no' => $endorsementNo,
                    'issued_at' => $p['issuedAt'] ?? $p['startsAt'] ?? date('Y-m-d'),
                    'starts_at' => $p['startsAt'] ?? date('Y-m-d'),
                    'expires_at' => $p['expiresAt'] ?? date('Y-m-d'),
                    'gross_premium' => $p['grossPremium'] ?? 0,
                    'net_premium' => $p['netPremium'] ?? 0,
                    'company_comm_rate' => $p['companyCommRate'] ?? 0,
                    'branch_comm_rate' => $p['branchCommRate'] ?? 0,
                    'branch_comm_amount' => $p['branchCommAmount'] ?? null,
                    'company_comm_amount' => $p['companyCommAmount'] ?? null,
                    'is_approved' => 1,
                    'is_cancelled' => !empty($p['isCancelled']) ? 1 : 0,
                    'plate_no' => isset($p['plateNo']) ? trim($p['plateNo']) : null,
                    'registration_no' => isset($p['registrationNo']) ? trim($p['registrationNo']) : null,
                    'chassis_no' => isset($p['chassisNo']) ? trim($p['chassisNo']) : null,
                    'engine_no' => isset($p['engineNo']) ? trim($p['engineNo']) : null,
                    'vehicle_year' => !empty($p['vehicleYear']) ? trim($p['vehicleYear']) : null,
                    'uavt_code' => $p['uavt'] ?? null,
                    'dask_no' => !empty($p['daskNo']) ? trim($p['daskNo']) : null,
                    'risk_address' => !empty($p['riskAddress']) ? $p['riskAddress'] : null,
                    'created_by' => $user['userId'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                // Kacirilan policeleri otomatik WON yap
                PolicyController::autoWonLostPoliciesStatic([
                    'plate_no'           => $p['plateNo'] ?? '',
                    'customer_id'        => $customerId,
                    'insurance_type_id'  => $insuranceId,
                    'policy_no'          => $p['policyNo'] ?? '',
                    'starts_at'          => $p['startsAt'] ?? date('Y-m-d'),
                ], $newPolicyId);

                $saved++;
            } catch (\Exception $e) {
                $errors[] = "Satir " . ($idx + 1) . ": " . $e->getMessage();
            }
        }

        Response::success([
            'saved' => $saved,
            'errors' => $errors,
        ], "$saved police kaydedildi");
    }
}
