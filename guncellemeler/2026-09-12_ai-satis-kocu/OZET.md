# AI Satış Koçu

## Özellik
CRM anasayfasındaki Görev Takibi bölümüne "Şimdi Ne Yapmalıyım?" butonu eklendi. Temsilci butona bastığında Gemini API tüm açık görevleri ve notları analiz edip satış koçu gibi akıllı öneriler döndürüyor.

### Akış
1. Temsilci anasayfada "Şimdi Ne Yapmalıyım?" butonuna basar
2. Modal açılır, açık görevler ve notlar Gemini'ye gönderilir
3. Gemini her görev için öncelik belirler (ACIL / YÜKSEK / NORMAL)
4. Ne yapması gerektiğini, müşteriye ne söylemesi gerektiğini ve ipuçlarını döndürür
5. Sonuçlar renkli kartlar halinde gösterilir

### Önceliklendirme Kuralları
- Vade 0-3 gün veya geçmiş → ACIL (kırmızı)
- Vade 4-7 gün → YÜKSEK (turuncu)
- Vade 8+ gün → NORMAL (mavi)
- Teklif iletilmiş ama 2+ gündür dönüş yok → 1 kademe yükselt
- Ulaşılamamış ve tekrar aranmamış → 1 kademe yükselt
- Hiç aranmamış → 1 kademe yükselt

### Admin Özelliği
- Admin pop-up'ta temsilci seçerek başka temsilcilerin görevlerini analiz edebilir

### Ayarlar
- Ayarlar > Acente Bilgileri > AI Entegrasyonu bölümünde toggle ile aktif/pasif
- Gemini API anahtarı gerekli

## Değişen Dosyalar
- `api/controllers/AiCoachController.php` — Yeni controller (status + analyze endpoint)
- `api/index.php` — ai-coach route'ları ve controller mapping
- `app/pages/settings/acente.vue` — AI Satış Koçu toggle + form field
- `app/pages/index.vue` — Buton + modal + analiz fonksiyonları
