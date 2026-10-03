# Dashboard ve İptal Poliçe İyileştirmeleri

## 1. Takip Aramaları Ayrı Buton

Görev Takibi bölümünde takip aramaları artık yenileme/teklif görevlerinden ayrıldı.

### Değişiklikler
- "Takip Aramaları" butonu eklendi (telefon ikonu + aktif görev sayısı badge)
- Butona tıklanınca sadece takip araması görevleri listeleniyor
- Normal modda takip aramaları tabloda görünmüyor (karışıklık önlendi)
- Takip aramalarında sıralama: Bugün > Yarın > Bu Hafta > Gelecek Hafta > Süresi Geçen
- Normal modda sıralama: Süresi Geçen > Bugün > Yarın > Bu Hafta > Gelecek Hafta

### Buton Sırası
Bekleyen | Devam Eden | Takip Aramaları | Süresi Geçen (admin)

### Davranış
- Takip Aramaları aktifken diğer butonlar pasif görünür ama yerinde kalır
- Diğer butonlara tıklayınca takip aramaları modu kapanır
- Geçişler anlık (client-side filtre, API çağrısı yok)
- "Tüm Görevler" butonu kaldırıldı

## 2. AI Satış Koçu Tanıtım Popup'ı

"Şimdi Ne Yapmalıyım?" butonu artık AI Koçu pasif olsa bile görünüyor.

### Davranış
- AI Koçu aktifse: normal analiz modalı açılır
- AI Koçu pasifse: tanıtım popup'ı açılır
  - Özellik açıklaması (3 madde)
  - "Bu özelliği aktif etmek için yazılımcınızla iletişime geçin" mesajı

## 3. İptal Poliçelerinde Alan Mirası

İptal poliçelerinde İş Türü, Kaynak ve Satış Yapan alanları ana poliçeden otomatik geliyor.

### Backend
- İptal zeyili oluşturulurken `business_type` artık ana poliçeden kopyalanıyor
- Listeleme sorgusunda sadece iptal poliçeleri için fallback eklendi (effective_business_type, effective_reference_source)
- Eksik alanlar tespit edilince DB'ye otomatik yazılıyor (bir kerelik backfill)
- Normal poliçelere dokunulmuyor

### Frontend — Günlük Aktivite
- İptal/düzenlenemez poliçelerde Üretim Yeri, Tali Acente, Satış Yapan, İş Türü, Kaynak alanları artık disabled USelect/USelectMenu olarak gösteriliyor (düz text yerine)
- Görsel tutarlılık sağlandı (düzenlenebilir satırlarla aynı görünüm)

### Frontend — Poliçe Düzenle Modalı
- İptal poliçelerinde İş Türü, Kaynak, Satış Temsilcisi alanları disabled

## Değişen Dosyalar
- `app/pages/index.vue` — Takip aramaları butonu, filtre, sıralama, Tüm Görevler kaldırma
- `app/layouts/default.vue` — AI Koçu tanıtım popup'ı, buton her zaman görünür
- `app/pages/gunluk-aktivite.vue` — İptal/düzenlenemez satırlarda disabled select alanları
- `app/components/PolicyFormModal.vue` — İptal poliçelerinde alanlar disabled
- `api/controllers/PolicyController.php` — business_type kopyalama, fallback sorguları, backfill
