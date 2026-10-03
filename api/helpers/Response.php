<?php

// PhpSpreadsheet autoload (Composer)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success($data = null, string $message = 'OK', int $status = 200): void
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    public static function error(string $message, int $status = 400, $errors = null): void
    {
        $response = ['success' => false, 'message' => $message];
        if ($errors !== null) {
            // 401 icin reason bilgisini ust seviyeye tasii
            if ($status === 401 && is_array($errors) && isset($errors['reason'])) {
                $response['reason'] = $errors['reason'];
            } else {
                $response['errors'] = $errors;
            }
        }
        self::json($response, $status);
    }

    public static function paginated(array $result): void
    {
        self::json([
            'success' => true,
            'data' => $result['data'],
            'pagination' => $result['pagination'],
        ]);
    }

    // ─── COLUMN TYPE SABİTLERİ ───
    const COL_STRING     = 'string';
    const COL_IDENTIFIER = 'identifier';
    const COL_NUMBER     = 'number';
    const COL_CURRENCY   = 'currency';
    const COL_PERCENTAGE = 'percentage';
    const COL_DATE       = 'date';
    const COL_DATETIME   = 'datetime';
    const COL_TEXT       = 'text'; // uzun metin, wrap text

    /**
     * Türkçe locale-aware uppercase.
     * PHP strtoupper() Türkçe i→İ, ı→I dönüşümünü yapmaz.
     */
    private static function trUpper(string $s): string
    {
        $map = ['i' => 'İ', 'ı' => 'I', 'ş' => 'Ş', 'ğ' => 'Ğ', 'ç' => 'Ç', 'ö' => 'Ö', 'ü' => 'Ü'];
        $s = str_replace(array_keys($map), array_values($map), $s);
        return mb_strtoupper($s, 'UTF-8');
    }

    /**
     * Formula injection koruması.
     * Kullanıcı kaynaklı string =, +, -, @, TAB, CR ile başlıyorsa önüne boşluk ekler.
     */
    private static function sanitizeCell(string $val): string
    {
        if ($val !== '' && in_array($val[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            // Gerçek negatif sayı kontrolü: -123.45 gibi değerlere dokunma
            if ($val[0] === '-' && is_numeric($val)) {
                return $val;
            }
            return "'" . $val;
        }
        return $val;
    }

    /**
     * Gerçek XLSX workbook oluşturur ve indirir.
     *
     * @param array  $rows     Veri dizisi (assoc array)
     * @param array  $columns  Kolon tanımları:
     *   - key      (string)  Veri anahtarı
     *   - label    (string)  Başlık metni (otomatik BÜYÜK HARF yapılır)
     *   - type     (string)  COL_STRING|COL_IDENTIFIER|COL_NUMBER|COL_CURRENCY|COL_PERCENTAGE|COL_DATE|COL_DATETIME|COL_TEXT
     *   - width    (int)     Sabit genişlik (opsiyonel)
     *   - wrap     (bool)    Wrap text (opsiyonel, COL_TEXT için otomatik true)
     * @param string $filename Dosya adı (.xlsx uzantısız da olur, otomatik eklenir)
     * @param array  $options  Ek seçenekler:
     *   - title       (string)  Sayfa başlığı (sheet name)
     *   - freezePane  (bool)    Header satırını sabitle (default: true)
     *   - autoFilter  (bool)    Filtre ekle (default: true)
     */
    public static function xlsx(array $rows, array $columns, string $filename, array $options = []): void
    {
        $sheetTitle  = $options['title']      ?? 'Sayfa1';
        $freezePane  = $options['freezePane'] ?? true;
        $autoFilter  = $options['autoFilter'] ?? true;

        // Dosya adı .xlsx ile bitsin
        if (!str_ends_with(strtolower($filename), '.xlsx')) {
            $filename = preg_replace('/\.(xls|csv|txt)$/i', '', $filename) . '.xlsx';
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($sheetTitle, 0, 31, 'UTF-8')); // Excel max 31 karakter

        $colCount = count($columns);
        $lastColLetter = self::colLetter($colCount);

        // ─── HEADER SATIRI ───
        foreach ($columns as $ci => $col) {
            $letter = self::colLetter($ci + 1);
            $cell = $letter . '1';
            $label = self::trUpper($col['label'] ?? '');
            $sheet->setCellValue($cell, $label);
        }

        // Header stili: Segoe UI Bold 8, beyaz yazı, mavi arka plan
        $headerRange = 'A1:' . $lastColLetter . '1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'name' => 'Segoe UI',
                'bold' => true,
                'size' => 8,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E40AF'],
            ],
            'alignment' => [
                'vertical'   => Alignment::VERTICAL_CENTER,
                'horizontal' => Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '1E3A8A'],
                ],
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // ─── VERİ SATIRLARI ───
        $rowNum = 2;
        foreach ($rows as $row) {
            foreach ($columns as $ci => $col) {
                $letter = self::colLetter($ci + 1);
                $cell = $letter . $rowNum;
                $rawVal = $row[$col['key']] ?? '';
                $type = $col['type'] ?? self::COL_STRING;

                self::writeCell($sheet, $cell, $rawVal, $type);
            }
            $rowNum++;
        }

        $lastRow = $rowNum - 1;
        $dataRange = 'A2:' . $lastColLetter . $lastRow;

        // ─── VERİ STİLİ: Aptos Narrow 11, dikey orta ───
        if ($lastRow >= 2) {
            $sheet->getStyle($dataRange)->applyFromArray([
                'font' => [
                    'name' => 'Aptos Narrow',
                    'bold' => false,
                    'size' => 11,
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'D6DCE4'],
                    ],
                ],
            ]);

            // Zebra renk (çift satırlar)
            for ($r = 2; $r <= $lastRow; $r++) {
                if ($r % 2 === 0) {
                    $sheet->getStyle('A' . $r . ':' . $lastColLetter . $r)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('F1F5F9');
                }
            }
        }

        // ─── KOLON BAZLI FORMAT VE GENİŞLİK ───
        foreach ($columns as $ci => $col) {
            $letter = self::colLetter($ci + 1);
            $type   = $col['type'] ?? self::COL_STRING;
            $colRange = $letter . '2:' . $letter . $lastRow;

            // Yatay hizalama
            $hAlign = Alignment::HORIZONTAL_LEFT;
            if (in_array($type, [self::COL_NUMBER, self::COL_CURRENCY, self::COL_PERCENTAGE], true)) {
                $hAlign = Alignment::HORIZONTAL_RIGHT;
            } elseif (in_array($type, [self::COL_DATE, self::COL_DATETIME], true)) {
                $hAlign = Alignment::HORIZONTAL_CENTER;
            }
            if ($lastRow >= 2) {
                $sheet->getStyle($colRange)->getAlignment()->setHorizontal($hAlign);
            }

            // Number format
            if ($lastRow >= 2) {
                $fmt = match ($type) {
                    self::COL_CURRENCY   => '#,##0.00',
                    self::COL_PERCENTAGE => '0.00%',
                    self::COL_DATE       => 'DD.MM.YYYY',
                    self::COL_DATETIME   => 'DD.MM.YYYY HH:MM',
                    self::COL_IDENTIFIER => '@',
                    default              => null,
                };
                if ($fmt) {
                    $sheet->getStyle($colRange)->getNumberFormat()->setFormatCode($fmt);
                }
            }

            // Wrap text
            $wrap = $col['wrap'] ?? ($type === self::COL_TEXT);
            if ($wrap && $lastRow >= 2) {
                $sheet->getStyle($colRange)->getAlignment()->setWrapText(true);
            }

            // Sütun genişliği
            if (!empty($col['width'])) {
                $sheet->getColumnDimension($letter)->setWidth((int) $col['width']);
            } else {
                // Tip bazlı varsayılan genişlikler
                $defaultWidth = match ($type) {
                    self::COL_IDENTIFIER => 22,
                    self::COL_CURRENCY   => 16,
                    self::COL_PERCENTAGE => 12,
                    self::COL_DATE       => 14,
                    self::COL_DATETIME   => 18,
                    self::COL_TEXT       => 30,
                    default              => null,
                };
                if ($defaultWidth) {
                    $sheet->getColumnDimension($letter)->setWidth($defaultWidth);
                } else {
                    // İçeriğe göre — max 40 karakter
                    $maxLen = mb_strlen($col['label'] ?? '', 'UTF-8');
                    $sampleRows = array_slice($rows, 0, min(50, count($rows)));
                    foreach ($sampleRows as $sRow) {
                        $v = (string) ($sRow[$col['key']] ?? '');
                        $len = mb_strlen($v, 'UTF-8');
                        if ($len > $maxLen) $maxLen = $len;
                    }
                    $w = min(max($maxLen + 3, 10), 40);
                    $sheet->getColumnDimension($letter)->setWidth($w);
                }
            }
        }

        // ─── AUTOFILTER ───
        if ($autoFilter && $lastRow >= 2) {
            $sheet->setAutoFilter('A1:' . $lastColLetter . $lastRow);
        }

        // ─── FREEZE PANE ───
        if ($freezePane) {
            $sheet->freezePane('A2');
        }

        // ─── ÇIKTI ───
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        exit;
    }

    /**
     * Hücreye veri tipine göre doğru şekilde yazar.
     */
    private static function writeCell($sheet, string $cell, $val, string $type): void
    {
        if ($val === null || $val === '') {
            $sheet->setCellValue($cell, '');
            return;
        }

        switch ($type) {
            case self::COL_IDENTIFIER:
                // Explicit string — Excel sayıya dönüştürmez, başındaki sıfırlar korunur
                $sheet->setCellValueExplicit($cell, self::sanitizeCell((string) $val), DataType::TYPE_STRING);
                break;

            case self::COL_NUMBER:
            case self::COL_CURRENCY:
                // Gerçek numeric — number_format() ile string gelmişse temizle
                $numeric = $val;
                if (is_string($val)) {
                    $numeric = str_replace(['.', ','], ['', '.'], $val);
                }
                $sheet->setCellValue($cell, is_numeric($numeric) ? (float) $numeric : $val);
                break;

            case self::COL_PERCENTAGE:
                // 0.15 = %15, 15 = %1500 — gelen değer 0-1 aralığında olmalı
                $numeric = $val;
                if (is_string($val)) {
                    $numeric = str_replace(['.', ','], ['', '.'], $val);
                }
                if (is_numeric($numeric)) {
                    $f = (float) $numeric;
                    // Eğer 1'den büyükse (15 gibi) 100'e böl
                    if ($f > 1) $f = $f / 100;
                    $sheet->setCellValue($cell, $f);
                } else {
                    $sheet->setCellValue($cell, $val);
                }
                break;

            case self::COL_DATE:
                // YYYY-MM-DD veya DD.MM.YYYY string → Excel date serial
                $ts = is_numeric($val) ? (int) $val : strtotime((string) $val);
                if ($ts && $ts > 0) {
                    $sheet->setCellValue($cell, ExcelDate::PHPToExcel($ts));
                } else {
                    $sheet->setCellValue($cell, (string) $val);
                }
                break;

            case self::COL_DATETIME:
                $ts = is_numeric($val) ? (int) $val : strtotime((string) $val);
                if ($ts && $ts > 0) {
                    $sheet->setCellValue($cell, ExcelDate::PHPToExcel($ts));
                } else {
                    $sheet->setCellValue($cell, (string) $val);
                }
                break;

            case self::COL_TEXT:
                $sheet->setCellValueExplicit($cell, self::sanitizeCell((string) $val), DataType::TYPE_STRING);
                break;

            case self::COL_STRING:
            default:
                $strVal = (string) $val;
                // Uzun rakamsal string'leri (10+ hane, başında 0) otomatik identifier olarak yaz
                if (preg_match('/^0\d{5,}$/', $strVal) || (strlen($strVal) >= 12 && ctype_digit($strVal))) {
                    $sheet->setCellValueExplicit($cell, self::sanitizeCell($strVal), DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($cell, self::sanitizeCell($strVal));
                }
                break;
        }
    }

    /**
     * Kolon numarasından Excel harf karşılığı: 1→A, 2→B, 27→AA
     */
    private static function colLetter(int $num): string
    {
        $letter = '';
        while ($num > 0) {
            $num--;
            $letter = chr(65 + ($num % 26)) . $letter;
            $num = intdiv($num, 26);
        }
        return $letter;
    }
}
