<?php
/**
 * XLSX Export Altyapı Testi
 * Kullanım: php test-xlsx.php (CLI)
 * Web erişimi engellendi — sadece CLI'dan çalışır.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/../helpers/Response.php';

// Test verileri — tüm veri tiplerini kapsıyor
$columns = [
    ['key' => 'sno',          'label' => 'S.No',              'type' => Response::COL_NUMBER,     'width' => 8],
    ['key' => 'policyNo',     'label' => 'Poliçe No',         'type' => Response::COL_IDENTIFIER],
    ['key' => 'tckn',         'label' => 'TC Kimlik No',      'type' => Response::COL_IDENTIFIER],
    ['key' => 'phone',        'label' => 'Telefon',           'type' => Response::COL_IDENTIFIER, 'width' => 16],
    ['key' => 'customerName', 'label' => 'Müşteri Adı',       'type' => Response::COL_STRING],
    ['key' => 'insuranceName','label' => 'Sigorta Türü',      'type' => Response::COL_STRING,     'width' => 16],
    ['key' => 'companyName',  'label' => 'Şirket',            'type' => Response::COL_STRING],
    ['key' => 'issuedAt',     'label' => 'Tanzim Tarihi',     'type' => Response::COL_DATE],
    ['key' => 'expiresAt',    'label' => 'Bitiş Tarihi',      'type' => Response::COL_DATE],
    ['key' => 'grossPremium', 'label' => 'Brüt Prim',         'type' => Response::COL_CURRENCY],
    ['key' => 'netPremium',   'label' => 'Net Prim',           'type' => Response::COL_CURRENCY],
    ['key' => 'commRate',     'label' => 'Komisyon Oranı',     'type' => Response::COL_PERCENTAGE],
    ['key' => 'description',  'label' => 'Açıklama',           'type' => Response::COL_TEXT,       'wrap' => true, 'width' => 35],
    ['key' => 'status',       'label' => 'Durum',              'type' => Response::COL_STRING,     'width' => 14],
];

$rows = [
    [
        'sno'           => 1,
        'policyNo'      => '0001071010647482',
        'tckn'          => '12345678901',
        'phone'         => '05301234567',
        'customerName'  => 'Çağrı ŞİRKETİ İletişim Özgür',
        'insuranceName' => 'KASKO',
        'companyName'   => 'Allianz Sigorta',
        'issuedAt'      => '2026-10-02',
        'expiresAt'     => '2027-10-02',
        'grossPremium'  => 39063.46,
        'netPremium'    => 37203.30,
        'commRate'      => 15,
        'description'   => 'Bu poliçe test amaçlı oluşturulmuştur. Uzun metin wrap text doğrulaması için yazılmıştır. Türkçe karakterler: İşçi Ğüneş Öğretmen Çiçek Üzüm Şeker.',
        'status'        => 'Aktif',
    ],
    [
        'sno'           => 2,
        'policyNo'      => '0001019085442426',
        'tckn'          => '98765432109',
        'phone'         => '05059876543',
        'customerName'  => 'MEHMET ŞAHÎN GÜNEŞ',
        'insuranceName' => 'TRAFİK',
        'companyName'   => 'Corpus Sigorta',
        'issuedAt'      => '2026-09-15',
        'expiresAt'     => '2027-09-15',
        'grossPremium'  => 18072.45,
        'netPremium'    => 16136.11,
        'commRate'      => 11,
        'description'   => 'Yenileme poliçesi.',
        'status'        => 'Aktif',
    ],
    [
        'sno'           => 3,
        'policyNo'      => '6467021006898961',
        'tckn'          => '00123456789',
        'phone'         => '02121234567',
        'customerName'  => 'YAPI KREDİ FİNANSAL KİRALAMA A.Ş.',
        'insuranceName' => 'DASK',
        'companyName'   => 'Allianz Sigorta',
        'issuedAt'      => '2026-01-10',
        'expiresAt'     => '2027-01-10',
        'grossPremium'  => 1250.50,
        'netPremium'    => 1187.98,
        'commRate'      => 5,
        'description'   => 'DASK poliçesi — zorunlu deprem sigortası.',
        'status'        => 'Süresi Dolmuş',
    ],
    [
        'sno'           => 4,
        'policyNo'      => '0001071009316696',
        'tckn'          => '45865395106',
        'phone'         => '05426943697',
        'customerName'  => 'ÖZGE CİNBAL ÇELİK',
        'insuranceName' => 'TSS',
        'companyName'   => 'Allianz Sigorta',
        'issuedAt'      => '2025-12-24',
        'expiresAt'     => '2026-12-24',
        'grossPremium'  => 83500.01,
        'netPremium'    => 79523.82,
        'commRate'      => 15,
        'description'   => '',
        'status'        => 'Aktif',
    ],
    [
        'sno'           => 5,
        'policyNo'      => '0000000000000001',
        'tckn'          => '00000000001',
        'phone'         => '00000000000',
        'customerName'  => '=CMD("test")',
        'insuranceName' => '+HYPERLINK("x")',
        'companyName'   => '@SUM(A1)',
        'issuedAt'      => '2026-06-01',
        'expiresAt'     => '2027-06-01',
        'grossPremium'  => 0,
        'netPremium'    => 0,
        'commRate'      => 0,
        'description'   => '-formula injection test',
        'status'        => 'İptal',
    ],
];

echo "=== XLSX ALTYAPI TESTİ ===\n\n";

    // 1. PhpSpreadsheet yüklenebiliyor mu?
    echo "1. PhpSpreadsheet: ";
    if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        echo "OK\n";
    } else {
        echo "HATA — sınıf bulunamadı\n";
        exit(1);
    }

    // 2. Türkçe uppercase testi
    echo "2. Türkçe uppercase: ";
    $r = new ReflectionMethod('Response', 'trUpper');
    $r->setAccessible(true);
    $result = $r->invoke(null, 'müşteri adı sigorta şirketi çağrı güneş ödeme işlem ısparta');
    $expected = 'MÜŞTERİ ADI SİGORTA ŞİRKETİ ÇAĞRI GÜNEŞ ÖDEME İŞLEM ISPARTA';
    echo ($result === $expected) ? "OK\n" : "HATA → '$result'\n";

    // 3. Formula injection testi
    echo "3. Formula injection: ";
    $r2 = new ReflectionMethod('Response', 'sanitizeCell');
    $r2->setAccessible(true);
    $t1 = $r2->invoke(null, '=CMD("test")');
    $t2 = $r2->invoke(null, '+HYPERLINK("x")');
    $t3 = $r2->invoke(null, '-1250.50');
    $ok = (str_starts_with($t1, "'") && str_starts_with($t2, "'") && $t3 === '-1250.50');
    echo $ok ? "OK\n" : "HATA\n";

    // 4. Dosya oluşturma testi
    echo "4. XLSX dosya oluşturma: ";
    $tmpFile = sys_get_temp_dir() . '/test_crm_xlsx_' . time() . '.xlsx';

    // Response::xlsx() yerine doğrudan PhpSpreadsheet ile test — çünkü xlsx() exit yapıyor
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Header yaz
    foreach ($columns as $ci => $col) {
        $letter = chr(65 + $ci);
        if ($ci >= 26) $letter = 'A' . chr(65 + $ci - 26);
        $cell = $letter . '1';
        $label = (new ReflectionMethod('Response', 'trUpper'))->invoke(null, $col['label']);
        $sheet->setCellValue($cell, $label);
    }

    // Veri yaz
    $rowNum = 2;
    foreach ($rows as $row) {
        foreach ($columns as $ci => $col) {
            $letter = chr(65 + $ci);
            if ($ci >= 26) $letter = 'A' . chr(65 + $ci - 26);
            $cell = $letter . $rowNum;
            $rawVal = $row[$col['key']] ?? '';
            $type = $col['type'] ?? Response::COL_STRING;

            $writeCell = new ReflectionMethod('Response', 'writeCell');
            $writeCell->setAccessible(true);
            $writeCell->invoke(null, $sheet, $cell, $rawVal, $type);
        }
        $rowNum++;
    }

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save($tmpFile);
    echo file_exists($tmpFile) ? "OK → $tmpFile\n" : "HATA\n";

    // 5. Dosyayı geri oku ve doğrula
    echo "\n=== DOĞRULAMA TESTLERİ ===\n\n";
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmpFile);
    $wb = $reader->load($tmpFile);
    $ws = $wb->getActiveSheet();

    // 5a. Header kontrolü
    echo "5. Header büyük harf: ";
    $h1 = $ws->getCell('A1')->getValue();
    $h2 = $ws->getCell('B1')->getValue();
    echo ($h1 === 'S.NO' && $h2 === 'POLİÇE NO') ? "OK\n" : "HATA → A1='$h1' B1='$h2'\n";

    // 5b. Identifier korunma testi
    echo "6. Poliçe no korunma (0001071010647482): ";
    $policyNo = $ws->getCell('B2')->getValue();
    echo ($policyNo === '0001071010647482') ? "OK\n" : "HATA → '$policyNo'\n";

    // 5c. Başında 0 olan TCKN
    echo "7. TCKN başında 0 (00123456789): ";
    $tckn = $ws->getCell('C4')->getValue();
    echo ($tckn === '00123456789') ? "OK\n" : "HATA → '$tckn'\n";

    // 5d. Identifier veri tipi
    echo "8. Identifier veri tipi (string): ";
    $dt = $ws->getCell('B2')->getDataType();
    echo ($dt === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING) ? "OK\n" : "HATA → '$dt'\n";

    // 5e. Currency numeric testi
    echo "9. Brüt Prim numeric (39063.46): ";
    $prim = $ws->getCell('J2')->getValue();
    echo (is_numeric($prim) && abs((float)$prim - 39063.46) < 0.01) ? "OK\n" : "HATA → '$prim' (" . gettype($prim) . ")\n";

    // 5f. Percentage testi
    echo "10. Komisyon oranı (%15): ";
    $perc = $ws->getCell('L2')->getValue();
    echo (is_numeric($perc) && abs((float)$perc - 0.15) < 0.01) ? "OK\n" : "HATA → '$perc'\n";

    // 5g. Date testi
    echo "11. Tarih Excel date serial: ";
    $dateVal = $ws->getCell('H2')->getValue();
    echo (is_numeric($dateVal) && $dateVal > 40000) ? "OK (serial=$dateVal)\n" : "HATA → '$dateVal'\n";

    // 5h. Türkçe karakter testi
    echo "12. Türkçe karakter (Çağrı ŞİRKETİ): ";
    $trName = $ws->getCell('E2')->getValue();
    echo (str_contains($trName, 'Çağrı') && str_contains($trName, 'ŞİRKETİ')) ? "OK\n" : "HATA → '$trName'\n";

    // 5i. Formula injection testi
    echo "13. Formula injection koruması: ";
    $fCell = $ws->getCell('E6')->getValue();
    echo (!str_starts_with($fCell, '=')) ? "OK → '$fCell'\n" : "HATA — formül olarak yazıldı\n";

    // 5j. Dosya boyutu
    echo "14. Dosya boyutu: ";
    $size = filesize($tmpFile);
    echo round($size / 1024, 1) . " KB\n";

    // 5k. MIME type doğrulaması
    echo "15. Dosya formatı (XLSX magic bytes): ";
    $magic = file_get_contents($tmpFile, false, null, 0, 4);
    echo ($magic === "PK\x03\x04") ? "OK (ZIP/XLSX)\n" : "HATA\n";

    // Temizle
    unlink($tmpFile);
    $wb->disconnectWorksheets();

echo "\n=== TEST TAMAMLANDI ===\n";
