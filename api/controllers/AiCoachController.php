<?php

require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../helpers/Response.php';

class AiCoachController
{
    /**
     * GET /api/ai-coach/status
     */
    public function status(array $user): void
    {
        $enabled = Database::fetch("SELECT `value` FROM settings WHERE `key` = 'ai_coach_enabled'");
        $apiKey = Database::fetch("SELECT `value` FROM settings WHERE `key` = 'gemini_api_key'");

        Response::success([
            'enabled' => !empty($enabled['value']) && $enabled['value'] === '1',
            'hasApiKey' => !empty($apiKey['value']) && $apiKey['value'] !== '',
        ]);
    }

    /**
     * POST /api/ai-coach/analyze
     */
    public function analyze(array $user, array $input): void
    {
        // AI Coach aktif mi?
        $enabled = Database::fetch("SELECT `value` FROM settings WHERE `key` = 'ai_coach_enabled'");
        if (empty($enabled['value']) || $enabled['value'] !== '1') {
            Response::error('AI Satış Koçu aktif değil. Ayarlar sayfasından aktif edin.', 400);
        }

        // API key
        $dbKey = Database::fetch("SELECT `value` FROM settings WHERE `key` = 'gemini_api_key'");
        $apiKey = $dbKey['value'] ?? '';
        if (empty($apiKey)) {
            Response::error('Gemini API anahtarı tanımlanmamış. Ayarlar > Acente Bilgileri sayfasından giriniz.', 400);
        }

        // Hangi kullanıcının görevleri analiz edilecek
        $targetUserId = (int) $user['userId'];
        if ((int) $user['role'] === 1 && !empty($input['userId'])) {
            $targetUserId = (int) $input['userId'];
        }

        // Açık görevleri çek
        $tasks = Database::fetchAll(
            "SELECT t.id, t.type, t.status, t.deadline, t.offer_expires_at, t.created_at,
                    cu.name as customer_name, cu.phone as customer_phone,
                    it.name as insurance_name,
                    co.name as company_name,
                    p.plate_no, p.expires_at, p.policy_no,
                    u.name as assigned_to_name
             FROM tasks t
             LEFT JOIN customers cu ON t.customer_id = cu.id
             LEFT JOIN policies p ON t.policy_id = p.id
             LEFT JOIN insurance_types it ON p.insurance_type_id = it.id
             LEFT JOIN companies co ON p.company_id = co.id
             LEFT JOIN users u ON t.assigned_to = u.id
             WHERE t.deleted_at IS NULL
               AND t.status IN ('PENDING', 'IN_PROGRESS')
               AND t.assigned_to = ?
               AND COALESCE(p.expires_at, t.offer_expires_at, t.deadline) <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
             ORDER BY COALESCE(p.expires_at, t.offer_expires_at, t.deadline) ASC
             LIMIT 15",
            [$targetUserId]
        );

        if (empty($tasks)) {
            Response::success([
                'tasks' => [],
                'summary' => 'Tebrikler! Şu anda açık görevin bulunmuyor. Tüm görevlerin tamamlanmış.',
                'analyzedAt' => date('d.m.Y H:i'),
            ]);
            return;
        }

        // Her görevin notlarını çek ve satış aşamasını belirle
        $taskPromptLines = [];
        $typeLabels = [
            'RENEWAL' => 'Yenileme',
            'OFFER' => 'Teklif',
            'CROSS_SELL' => 'Çapraz Satış',
            'FOLLOW_UP_CALL' => 'Takip Araması',
            'REFERENCE' => 'Referans',
            'OTHER' => 'Diğer',
        ];

        // Aşama belirleme anahtar kelimeleri
        $approvalKeywords = ['onay', 'kabul', 'kesildi', 'tahsilat', 'ödeme', 'ödendi', 'poliçeleşti', 'poliçe kesildi', 'poliçe yapıldı', 'poliçe oluşturuldu', 'poliçe oluşturulmuş', 'poliçe hazır', 'satış yapıldı', 'prim alındı', 'tanzim', 'oluşturuldu', 'oluşturulmuş'];
        $deliveryKeywords = ['teslim', 'poliçe gönderildi', 'poliçe iletildi', 'müşteriye gönderildi', 'whatsapp ile gönderildi', 'mail ile gönderildi', 'e-posta ile gönderildi'];
        $sentKeywords = ['teklif iletildi', 'teklif gönderildi', 'fiyat gönderildi', 'fiyat iletildi', 'teklif verildi', 'teklifler iletildi', 'fiyat verildi', 'teklif sunuldu', 'teklifleri ilettim', 'fiyatları ilettim', 'teklif yolladım', 'fiyat yolladım', 'teklifleri gönderdim'];
        // Ulaşılamadı / bekliyor gibi durumlar — AI'ın bilmesi için ek bilgi
        $unreachedKeywords = ['ulaşılamadı', 'ulaşamadım', 'açmadı', 'telefonu açmadı', 'meşgul', 'cevap vermedi', 'kapalı', 'ulaşılmadı'];
        $waitingKeywords = ['düşünecek', 'düşünüyor', 'düşünecekmiş', 'dönüş yapacak', 'eşine soracak', 'karar verecek', 'bekliyoruz', 'bekliyor', 'hafta sonu', 'pazartesi'];

        foreach ($tasks as $idx => $t) {
            $notes = Database::fetchAll(
                "SELECT n.note, n.created_at, u.name as created_by_name
                 FROM task_notes n
                 LEFT JOIN users u ON n.created_by = u.id
                 WHERE n.task_id = ?
                 ORDER BY n.created_at ASC",
                [$t['id']]
            );

            // Satış aşamasını belirle
            $stage = 'PREPARE_OFFER'; // varsayılan
            $noteSignals = []; // AI'a aktarılacak ek sinyaller

            if ($t['status'] === 'PENDING') {
                $stage = 'PREPARE_OFFER';
            } elseif ($t['status'] === 'IN_PROGRESS') {
                // Notlara bakarak aşamayı belirle
                $allNotes = strtolower(implode(' ', array_column($notes, 'note')));

                $hasDelivery = false;
                $hasApproval = false;
                $hasSent = false;
                $hasUnreached = false;
                $hasWaiting = false;

                foreach ($approvalKeywords as $kw) {
                    if (str_contains($allNotes, $kw)) { $hasApproval = true; break; }
                }
                foreach ($deliveryKeywords as $kw) {
                    if (str_contains($allNotes, $kw)) { $hasDelivery = true; break; }
                }
                foreach ($sentKeywords as $kw) {
                    if (str_contains($allNotes, $kw)) { $hasSent = true; break; }
                }
                foreach ($unreachedKeywords as $kw) {
                    if (str_contains($allNotes, $kw)) { $hasUnreached = true; break; }
                }
                foreach ($waitingKeywords as $kw) {
                    if (str_contains($allNotes, $kw)) { $hasWaiting = true; break; }
                }

                if ($hasApproval && $hasDelivery) {
                    $stage = 'CLOSE_TASK';
                } elseif ($hasApproval) {
                    $stage = 'POLICY_DELIVERY';
                } elseif ($hasSent) {
                    $stage = 'FOLLOW_UP';
                    if ($hasWaiting) $noteSignals[] = 'Müşteri düşünüyor/karar aşamasında';
                    if ($hasUnreached) $noteSignals[] = 'Müşteriye ulaşılamadı';
                } else {
                    $stage = 'SEND_OFFER';
                    if ($hasUnreached) $noteSignals[] = 'Müşteriye daha önce ulaşılamamış';
                }
            }

            // Son notun tarihini hesapla (kaç gün önce)
            $lastNoteAge = null;
            if (!empty($notes)) {
                $lastNote = end($notes);
                $lastNoteDate = new DateTime($lastNote['created_at']);
                $lastNoteAge = (int) (new DateTime('today'))->diff($lastNoteDate)->format('%a');
                if ($lastNoteAge >= 2) {
                    $noteSignals[] = "Son not {$lastNoteAge} gün önce yazılmış";
                }
            }

            $expiresAt = $t['expires_at'] ?? $t['offer_expires_at'] ?? $t['deadline'] ?? null;
            $daysLeft = null;
            $isWeekendExpiry = false;
            if ($expiresAt) {
                $now = new DateTime('today');
                $exp = new DateTime($expiresAt);
                $daysLeft = (int) $now->diff($exp)->format('%r%a');
                // Vade Cumartesi(6) veya Pazar(7)'a denk geliyorsa
                $expiryDayOfWeek = (int) $exp->format('N');
                if ($expiryDayOfWeek >= 6) {
                    $isWeekendExpiry = true;
                    $dayName = $expiryDayOfWeek === 6 ? 'Cumartesi' : 'Pazar';
                    $noteSignals[] = "⚠️ Vade {$dayName} gününe denk geliyor — bugün halledilmeli";
                }
            }

            $line = "Görev #" . ($idx + 1) . " (ID: {$t['id']}):\n";
            $line .= "- Müşteri: " . ($t['customer_name'] ?? 'Bilinmiyor') . "\n";
            if ($t['customer_phone']) $line .= "- Telefon: {$t['customer_phone']}\n";
            if ($t['insurance_name']) $line .= "- Sigorta Türü: {$t['insurance_name']}\n";
            if ($t['company_name']) $line .= "- Mevcut Şirket: {$t['company_name']}\n";
            if ($t['plate_no']) $line .= "- Plaka: {$t['plate_no']}\n";
            if ($expiresAt) $line .= "- Vade Tarihi: " . date('d.m.Y', strtotime($expiresAt)) . "\n";
            if ($daysLeft !== null) $line .= "- Kalan Gün: " . ($daysLeft >= 0 ? $daysLeft : abs($daysLeft) . ' gün geçmiş') . "\n";
            $line .= "- Görev Tipi: " . ($typeLabels[$t['type']] ?? $t['type']) . "\n";
            $line .= "- Satış Aşaması: {$stage}\n";

            if (!empty($noteSignals)) {
                $line .= "- Durum Sinyalleri: " . implode(' | ', $noteSignals) . "\n";
            }

            if (!empty($notes)) {
                $line .= "- Notlar:\n";
                foreach ($notes as $n) {
                    $noteDate = date('d.m.Y H:i', strtotime($n['created_at']));
                    $line .= "  [{$noteDate} — " . ($n['created_by_name'] ?? '?') . "]: {$n['note']}\n";
                }
            } else {
                $line .= "- Notlar: Yok\n";
            }

            $taskPromptLines[] = $line;
        }

        $dayNames = [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];
        $todayName = $dayNames[(int) date('N')] ?? '';
        $todayStr = date('d.m.Y') . ' ' . $todayName;

        $dataText = "BUGÜN: {$todayStr}\n\n";
        $dataText .= "GÖREV LİSTESİ:\n\n" . implode("\n", $taskPromptLines);

        $systemPrompt = <<<'PROMPT'
SENİN ROLÜN

Sen deneyimli bir sigorta satış koçusun.

Görevin, satış temsilcisinin kendisine verilen aktif görevleri en doğru
sırayla ve en yüksek satış ihtimaliyle tamamlamasına yardımcı olmaktır.

Sen bir "genel tavsiye veren chatbot" değilsin.

Her görev için:
1. Mevcut satış aşamasını değerlendir.
2. Temsilcinin şimdi yapması gereken TEK ana aksiyonu belirle.
3. Bu aksiyonun neden önemli olduğunu açıkla.
4. Gerekliyse müşteriye söylenecek kısa ve doğal bir konuşma önerisi üret.
5. Branşa ve müşteri tipine uygun satış taktiği ver.
6. Müşterinin olası itirazına karşı kısa bir cevap öner.

==================================================
KESİN KURALLAR
==================================================

- Görev verilerinde bulunmayan hiçbir bilgiyi varsayma.
- Müşteri geçmişi uydurma.
- Fiyat, prim tutarı, indirim, teminat, şirket avantajı uydurma.
- Müşterinin daha önce söylediğini varsayma.
- Gönderilmemiş bir teklifin gönderildiğini varsayma.
- Yapılmamış bir telefon görüşmesini yapılmış kabul etme.
- Verilen notlar dışında müşteri davranışı hakkında kesin çıkarım yapma.
- Eksik bilgi varsa bunu açıkça belirt.
- Manipülatif, yanıltıcı veya gerçeğe aykırı ifadeler kullanma.

==================================================
SATIŞ AŞAMALARI
==================================================

Backend tarafından verilen "Satış Aşaması" değerini esas al.

PREPARE_OFFER:
Temsilcinin görevi teklif hazırlamaktır.
Müşteriyle konuşma önerisi üretme.
Sağlık sigortası ise mevcut şirketinden yenileme teklifi al.
Diğer branşlarda tüm şirketlerden karşılaştırmalı teklif hazırla.

SEND_OFFER:
Teklif müşteriye henüz iletilmemiştir.
Temsilcinin görevi teklifi müşteriye iletmektir.
Telefon veya WhatsApp için kısa, doğal bir iletişim metni üret.

FOLLOW_UP:
Teklif müşteriye iletilmiştir.
Temsilcinin görevi müşterinin karar aşamasını öğrenmektir.
Baskıcı olmayan fakat satışa yönlendiren bir takip konuşması üret.

POLICY_DELIVERY:
Müşteri poliçeyi onaylamıştır.
Temsilcinin görevi poliçeyi sisteme işlemek ve müşteriye teslim etmektir.
Eksik işlem varsa belirt ve müşteriye gönderilecek kısa teslim mesajını üret.

CLOSE_TASK:
Poliçe müşteriye teslim edilmiştir.
Temsilcinin görevi görevi kapatmaktır.
Ekstra satış konuşması üretme.
Öncelik her zaman NORMAL.

==================================================
ÖNCELİK
==================================================

Görevler vadeye 14-15 gün kala otomatik atanır. Temsilci her sabah
ofise geldiğinde o günün görevlerine teklif çalışır. Bu rutin iştir.

Öncelik belirlerken SADECE vadeye bakma, görevin aşamasını ve
gecikmesini de değerlendir:

NORMAL:
- Görev yeni atanmış, henüz çalışılmamış, vade 8+ gün → rutin iş
- CLOSE_TASK aşaması → her zaman NORMAL

YUKSEK:
- Aşama PREPARE_OFFER ama vade 4-7 gün → teklif geç kalmış
- Aşama SEND_OFFER ama 2+ gündür teklif iletilmemiş
- Aşama FOLLOW_UP ama 2+ gündür takip yapılmamış

ACIL:
- Vade 0-3 gün ve hâlâ PREPARE_OFFER veya SEND_OFFER → ciddi gecikme
- Vade geçmiş → acil müdahale
- Teklif iletilmiş ama vade 0-3 gün ve müşteri karar vermemiş

Öncelik sebebi maksimum 1-2 cümle olsun.

==================================================
BRANŞ TAKTİKLERİ
==================================================

KASKO: Fiyat değil, teminat kapsamı, muafiyet, asistans ve hasar avantajlarını karşılaştır.
TRAFİK: Zorunlu sigorta, gereksiz satış baskısı oluşturma. Fiyat, şirket, hizmet ve kolaylığı öne çıkar.
KONUT: Teminat kapsamı, bina/eşya ayrımı ve müşterinin gerçek riskleri üzerinden yaklaş.
İŞYERİ: İşletmenin faaliyet alanına ve olası risklerine odaklan. Varsayım yapma.
SAĞLIK: Teminat kapsamı, network, kullanım kolaylığı ve yenileme koşullarını öne çıkar. Tıbbi iddia üretme.
DASK: Yasal zorunluluk ve hızlı yenileme kolaylığına odaklan.
HAYAT: Güven odaklı, baskısız yaklaşım kullan.

==================================================
MÜŞTERİ TİPİ
==================================================

BİREYSEL: Samimi, doğal, kısa ve anlaşılır dil.
KURUMSAL: Profesyonel, net, ölçülü dil. Gereksiz samimiyetten kaçın.

==================================================
KONUŞMA ÖNERİSİ
==================================================

- Gerçek bir satış temsilcisinin söyleyebileceği kadar doğal olsun.
- Robotik ifadeler kullanma.
- Müşteriyi gereksiz yere sıkıştırma.
- Verilmeyen bilgileri kullanma.
- WhatsApp önerisi maksimum 3-4 cümle.
- Telefon konuşması maksimum 4-5 cümlelik kısa bir açılış.

==================================================
İTİRAZ KARŞILAMA
==================================================

Her görevde yalnızca gerçekten anlamlıysa bir adet olası müşteri itirazı
ve buna verilecek kısa cevap üret.

İtiraz cevabı:
- Baskıcı olmasın.
- Gerçek dışı vaat içermesin.
- Fiyat dışında değer yaratmaya çalışsın.
- Müşteriyi bir sonraki küçük adıma yönlendirsin.

==================================================
ÇIKTI
==================================================

Yalnızca aşağıdaki JSON formatında cevap ver:

{
  "summary": "Günün planı satır satır.\n1) Birinci iş\n2) İkinci iş\n3) Üçüncü iş\nMotivasyonel kapanış.",
  "topAction": {
    "taskId": 456,
    "title": "Müşteri adı ile kısa başlık",
    "action": "Şu anda yapması gereken TEK şey. Maksimum 1-2 cümle.",
    "why": "Neden şimdi bu görev. Maksimum 2 cümle.",
    "conversation": "Müşteriyle konuşma önerisi. PREPARE_OFFER ve CLOSE_TASK aşamalarında boş string."
  },
  "tasks": [
    {
      "taskId": 123,
      "priority": "ACIL",
      "priorityReason": "Vade çok yakın.",
      "stage": "SEND_OFFER",
      "action": "Teklifi bugün müşteriye ilet.",
      "why": "Yenileme tarihine çok az süre kaldı.",
      "conversation": "Müşteriyle telefonda veya WhatsApp'ta kullanılabilecek kısa metin.",
      "channel": "PHONE",
      "branchTip": "Branşa özel satış yaklaşımı.",
      "objection": {
        "customer": "Fiyat yüksek.",
        "response": "Kısa ve doğal cevap."
      }
    }
  ]
}

==================================================
TOP ACTION SEÇİM KURALLARI
==================================================

topAction: Tüm görevler arasından temsilcinin ŞİMDİ yapması gereken
EN ÖNEMLİ TEK GÖREVİ seç.

Seçim öncelik sırası:
1. Vadesi geçmiş görevler
2. Vadesine 0-3 gün kalmış ACIL görevler
3. Vadesine 4-7 gün kalmış YÜKSEK görevler
4. 2+ gündür işlem yapılmamış görevler
5. Müşteriye ulaşılması gereken FOLLOW_UP görevleri
6. Satış sürecinin tamamlanmasına en yakın görevler
7. Diğer NORMAL görevler

topAction kuralları:
- Yalnızca TEK görev seç
- Yalnızca analiz edilen tasklardan seç
- Gerçek taskId kullan, yeni ID üretme
- Backend tarafından verilen stage ve priority değerlerini değiştirme
- PREPARE_OFFER aşamasında conversation boş string olsun
- CLOSE_TASK aşamasında conversation boş string olsun
- Bilgi uydurma, satış ihtimali varsayma

==================================================
NOTLARA GÖRE HAREKET ET
==================================================

Görev notları temsilcinin daha önce ne yaptığını gösterir.
Notları DİKKATLİCE oku ve önerilerini notlara göre şekillendir:

- "ulaşılamadı / açmadı / meşgul" → Tekrar arama zamanı öner, farklı saat dene
- "düşünecek / eşine soracak / karar verecek" → Baskısız ama hatırlatıcı takip öner
- "fiyat yüksek dedi / pahalı buldu" → İtiraz karşılamada bunu kullan, değer odaklı yaklaş
- "başka yerden fiyat alacak" → Rekabetçi avantajları vurgula
- "teklif beğendi ama bekliyor" → Nazik dürtme, kısıtlı süre hatırlatması
- "cevap vermiyor / dönüş yok" → Farklı kanaldan (WhatsApp/SMS) dene
- Son not tarihi eski ise (2+ gün) → "X gündür takip yapılmamış" uyarısı ver

"Durum Sinyalleri" alanı varsa bunu mutlaka dikkate al.

Notlarda yazanları TEKRARLAMA, notlardan çıkan sonuca göre SONRAKİ ADIMI öner.

==================================================
GENEL PRENSİP
==================================================

Amaç temsilciye daha fazla iş vermek değil,
temsilcinin DOĞRU İŞİ DOĞRU ZAMANDA yapmasını sağlamaktır.

Her görev için tek bir ana aksiyon belirle.

Öncelik sıralama:
1. Vadesi en yakın görevler
2. Satış ihtimali yüksek görevler
3. Müşteriye ulaşılması gereken görevler
4. Tamamlanmaya yakın görevler

Temsilciyi gereksiz bilgiyle boğma.
Kısa, net, uygulanabilir ve satış odaklı ol.
Türkçe yaz. İngilizce teknik terim kullanma.
Aynı müşteriye ait birden fazla görev varsa tek aramada halledebileceğini belirt.
Özet kısmında günün planını satır satır yaz (\n ile ayır).

==================================================
HAFTA SONU UYARISI
==================================================

Durum sinyallerinde "Vade Cumartesi/Pazar gününe denk geliyor" uyarısı
varsa bu görevi öne çıkar.

Cumartesi ve Pazar günleri ofis kapalıdır.
Bu görevler BUGÜN halledilmelidir.

Özet kısmında hafta sonu vadeli görevler varsa bunu açıkça belirt:
"Hafta sonu vadesi dolan X görev var, bugün tamamlanmalı."

topAction seçerken hafta sonu vadeli görevlere ekstra öncelik ver.
PROMPT;

        // Gemini API çağrısı
        $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=' . $apiKey;

        $payload = json_encode([
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $dataText]]]
            ],
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 8192,
                'responseMimeType' => 'application/json',
            ],
        ]);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            error_log('[AI-Coach] error=api_failure status=' . $httpCode . ' user=' . $user['userId']);
            if ($httpCode === 429) {
                Response::error('AI Satış Koçu şu anda meşgul. Birkaç saniye bekleyip tekrar deneyin.', 429);
            }
            Response::error('AI Satış Koçu şu anda kullanılamıyor. Daha sonra tekrar deneyin.', 500);
        }

        $result = json_decode($response, true);
        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (!$text) {
            Response::error('AI analiz üretilemedi. Lütfen tekrar deneyin.', 422);
        }

        $parsed = json_decode($text, true);
        if (!$parsed || !is_array($parsed)) {
            // JSON parse hatası — ham metinden kurtarmaya çalış
            if (preg_match('/\{[\s\S]+\}/s', $text, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }

        if (!$parsed || !isset($parsed['tasks'])) {
            error_log('[AI-Coach] error=invalid_json user=' . $user['userId']);
            Response::error('AI yanıtı işlenemedi. Lütfen tekrar deneyin.', 422);
        }

        // Task bilgilerini zenginleştir (müşteri adı, sigorta türü vs.)
        $taskMap = [];
        foreach ($tasks as $t) {
            $taskMap[$t['id']] = $t;
        }

        // Zenginleştirme helper
        $enrichTask = function (array &$aiTask) use ($taskMap) {
            $tid = $aiTask['taskId'] ?? 0;
            $orig = $taskMap[$tid] ?? null;
            if ($orig) {
                $aiTask['customerName'] = $orig['customer_name'] ?? '';
                $aiTask['customerPhone'] = $orig['customer_phone'] ?? '';
                $aiTask['insuranceName'] = $orig['insurance_name'] ?? '';
                $aiTask['companyName'] = $orig['company_name'] ?? '';
                $aiTask['plateNo'] = $orig['plate_no'] ?? '';
                $expiresAt = $orig['expires_at'] ?? $orig['offer_expires_at'] ?? $orig['deadline'] ?? null;
                if ($expiresAt) {
                    $now = new DateTime('today');
                    $exp = new DateTime($expiresAt);
                    $aiTask['daysLeft'] = (int) $now->diff($exp)->format('%r%a');
                    $aiTask['expiresAt'] = date('d.m.Y', strtotime($expiresAt));
                }
            }
        };

        foreach ($parsed['tasks'] as &$aiTask) {
            $enrichTask($aiTask);
        }
        unset($aiTask);

        // topAction validation & fallback
        $topAction = $parsed['topAction'] ?? null;
        $validTaskIds = array_keys($taskMap);

        if ($topAction) {
            $topTaskId = (int) ($topAction['taskId'] ?? 0);
            if (!in_array($topTaskId, $validTaskIds)) {
                // Gemini geçersiz taskId döndü — fallback seç
                $topAction = null;
            }
        }

        if (!$topAction && !empty($parsed['tasks'])) {
            // Fallback: öncelik sırasına göre seç
            $fallbackTask = null;
            $priorityOrder = ['ACIL' => 0, 'YUKSEK' => 1, 'YÜKSEK' => 1, 'NORMAL' => 2];

            // ACIL → YÜKSEK → en yakın vadeli → ilk task
            foreach ($parsed['tasks'] as $at) {
                $atPriority = $priorityOrder[$at['priority'] ?? 'NORMAL'] ?? 2;
                if (!$fallbackTask) {
                    $fallbackTask = $at;
                    continue;
                }
                $fbPriority = $priorityOrder[$fallbackTask['priority'] ?? 'NORMAL'] ?? 2;
                if ($atPriority < $fbPriority) {
                    $fallbackTask = $at;
                } elseif ($atPriority === $fbPriority) {
                    // Aynı öncelikte: kalan gün az olan önce
                    $atDays = $at['daysLeft'] ?? 999;
                    $fbDays = $fallbackTask['daysLeft'] ?? 999;
                    if ($atDays < $fbDays) {
                        $fallbackTask = $at;
                    }
                }
            }

            if ($fallbackTask) {
                $topAction = [
                    'taskId' => $fallbackTask['taskId'],
                    'title' => ($fallbackTask['customerName'] ?? '') . ' — ' . ($fallbackTask['action'] ?? ''),
                    'action' => $fallbackTask['action'] ?? '',
                    'why' => $fallbackTask['why'] ?? $fallbackTask['priorityReason'] ?? '',
                    'conversation' => $fallbackTask['conversation'] ?? '',
                ];
            }
        }

        // topAction'ı zenginleştir
        if ($topAction) {
            $enrichTask($topAction);
            // stage bilgisini tasks'tan al
            $topTaskId = (int) ($topAction['taskId'] ?? 0);
            foreach ($parsed['tasks'] as $at) {
                if (($at['taskId'] ?? 0) == $topTaskId) {
                    $topAction['priority'] = $at['priority'] ?? 'NORMAL';
                    $topAction['stage'] = $at['stage'] ?? '';
                    break;
                }
            }
        }

        $responseData = [
            'tasks' => $parsed['tasks'],
            'summary' => $parsed['summary'] ?? '',
            'totalTasks' => count($tasks),
            'analyzedAt' => date('d.m.Y H:i'),
        ];

        if ($topAction) {
            $responseData['topAction'] = $topAction;
        }

        Response::success($responseData);
    }

    /**
     * GET /api/ai-coach/expired
     * Süresi geçmiş görevleri listele (AI gerektirmez)
     */
    public function expired(array $user): void
    {
        $targetUserId = (int) $user['userId'];
        if ((int) $user['role'] === 1 && !empty($_GET['userId'])) {
            $targetUserId = (int) $_GET['userId'];
        }

        $tasks = Database::fetchAll(
            "SELECT t.id, t.type, t.status, t.deadline, t.offer_expires_at, t.created_at,
                    cu.name as customer_name, cu.phone as customer_phone,
                    it.name as insurance_name,
                    co.name as company_name,
                    p.plate_no, p.expires_at, p.policy_no,
                    u.name as assigned_to_name,
                    (SELECT COUNT(*) FROM task_notes tn WHERE tn.task_id = t.id) as note_count,
                    (SELECT tn2.note FROM task_notes tn2 WHERE tn2.task_id = t.id ORDER BY tn2.created_at DESC LIMIT 1) as last_note,
                    (SELECT tn3.created_at FROM task_notes tn3 WHERE tn3.task_id = t.id ORDER BY tn3.created_at DESC LIMIT 1) as last_note_at
             FROM tasks t
             LEFT JOIN customers cu ON t.customer_id = cu.id
             LEFT JOIN policies p ON t.policy_id = p.id
             LEFT JOIN insurance_types it ON p.insurance_type_id = it.id
             LEFT JOIN companies co ON p.company_id = co.id
             LEFT JOIN users u ON t.assigned_to = u.id
             WHERE t.deleted_at IS NULL
               AND t.status = 'EXPIRED'
               AND t.assigned_to = ?
             ORDER BY COALESCE(p.expires_at, t.offer_expires_at, t.deadline) ASC
             LIMIT 50",
            [$targetUserId]
        );

        $typeLabels = [
            'RENEWAL' => 'Yenileme',
            'OFFER' => 'Teklif',
            'CROSS_SELL' => 'Çapraz Satış',
            'FOLLOW_UP_CALL' => 'Takip Araması',
            'REFERENCE' => 'Referans',
            'OTHER' => 'Diğer',
        ];

        $result = [];
        foreach ($tasks as $t) {
            $expiresAt = $t['expires_at'] ?? $t['offer_expires_at'] ?? $t['deadline'] ?? null;
            $daysLeft = null;
            if ($expiresAt) {
                $now = new DateTime('today');
                $exp = new DateTime($expiresAt);
                $daysLeft = (int) $now->diff($exp)->format('%r%a');
            }

            $result[] = [
                'taskId' => (int) $t['id'],
                'type' => $t['type'],
                'typeLabel' => $typeLabels[$t['type']] ?? $t['type'],
                'customerName' => $t['customer_name'] ?? '',
                'customerPhone' => $t['customer_phone'] ?? '',
                'insuranceName' => $t['insurance_name'] ?? '',
                'companyName' => $t['company_name'] ?? '',
                'plateNo' => $t['plate_no'] ?? '',
                'policyNo' => $t['policy_no'] ?? '',
                'expiresAt' => $expiresAt ? date('d.m.Y', strtotime($expiresAt)) : null,
                'daysLeft' => $daysLeft,
                'noteCount' => (int) $t['note_count'],
                'lastNote' => $t['last_note'],
                'lastNoteAt' => $t['last_note_at'] ? date('d.m.Y H:i', strtotime($t['last_note_at'])) : null,
                'assignedTo' => $t['assigned_to_name'] ?? '',
            ];
        }

        Response::success([
            'tasks' => $result,
            'total' => count($result),
        ]);
    }
}
