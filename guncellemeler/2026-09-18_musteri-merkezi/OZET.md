# ADK VIP Müşteri Merkezi — Kapsamlı Güncelleme

## Amaç
Müşteri portalını basit bir "poliçe görüntüleme ekranı"ndan profesyonel bir "ADK VIP Müşteri Merkezi"ne dönüştürme.

---

## AŞAMA 1: Menü + Ana Sayfa

### Layout (default.vue)
- Desktop menü: Ana Sayfa, Poliçelerim, Taleplerim, Profilim
- Aktif sayfa mavi vurgulu
- Mobil hamburger menü (md breakpoint)
- Bildirim zil ikonu (badge + dropdown)
- "Müşteri Portalı" → "Müşteri Merkezi"

### Ana Sayfa (index.vue)
- 4 özet kartı (Aktif Poliçe, Yakında Sona Erecek, Süresi Dolmuş, Toplam Prim)
- İlk 3 kart tıklanabilir → Poliçelerim'e yönlendirir
- "Bu Ay Dolacak" → "Yakında Sona Erecek"
- "Yakında Sona Erecek Poliçeler" listesi (en yakın 5, 90 gün içi)
- Vade renkleri: yeşil (60+), turuncu (30), kırmızı (7) — eşikler değişken
- "Acenteme Ulaş" kartı: Ara + WhatsApp butonları (0540 212 05 05)
- "Yeni Sigorta İhtiyacınız mı Var?" kartı → Taleplerim'e yönlendirir

---

## AŞAMA 2: Poliçelerim + Poliçe Detay

### Poliçelerim (policelerim.vue)
- Mevcut tablo birebir korundu (filtreler, arama, Excel, tarih aralığı)
- Başlık: "Poliçelerim"

### Poliçe Detay Slideover (yeniden yapılandırıldı)
- Durum + Kalan Gün Kartı (renk kodlu arka plan)
- Yenileme Bilgilendirmesi (30 gün ve altı: "Bitiş tarihine X gün kaldı")
- Gruplandırılmış bilgiler:
  - Poliçe Bilgileri (No, Tür, Şirket, Prim)
  - Tarih Bilgileri (Tanzim, Başlangıç, Bitiş)
  - Sigortalı Bilgileri (Ad, Plaka, Ruhsat — varsa)
  - Poliçe Belgeleri (detay açıldığında otomatik yüklenir)

---

## AŞAMA 3: Taleplerim

### Veritabanı
- `portal_requests` tablosu (migration: 1.6.0)
- CRM tasks tablosundan tamamen bağımsız

### API Endpoint'leri
- `GET /portal/requests` — Talep listesi
- `POST /portal/requests` — Yeni talep oluştur
- `GET /portal/requests/{id}` — Talep detayı
- `PUT /portal/requests/{id}/cancel` — Talep iptal (sadece NEW)

### Talep Türleri
- phone_change, address_change, vehicle_change, family_member
- policy_document, health_insurance, new_insurance, other

### Durum Akışı
- NEW → IN_REVIEW → COMPLETED / CANCELLED

### Güvenlik
- customer_id token'dan — frontend'den alınmıyor
- IDOR koruması tüm endpoint'lerde
- Type/status whitelist kontrolü
- Günde max 10 talep rate limit
- Mesaj max 2000 karakter

### Yeni Sigorta Talebi
- Ana sayfadaki buton → /taleplerim?type=new_insurance
- Sigorta türü seçimi: Sağlık, Trafik, Kasko, Konut, İşyeri, Diğer

---

## AŞAMA 4: Profilim

### API Endpoint
- `GET /portal/profile` — Müşteri profil bilgileri (maskelenmiş)

### Gösterilen Bilgiler
- Kimlik: TC/VKN (maskelenmiş: ******5606), Vergi Dairesi, İlgili Kişi
- İletişim: Telefon (maskelenmiş: 9054 *** 97), E-posta
- Adres: Adres, İl/İlçe

### Güvenlik
- TC/VKN son 4 hane gösterilir
- Telefon ilk 4 + son 2 gösterilir
- Doğum tarihi, müşteri notu, komisyon vb. gösterilmiyor
- Profil düzenleme yok — talep yoluyla güncelleme

---

## AŞAMA 5: Bildirimler

### Veritabanı
- `portal_notifications` tablosu (migration: 1.6.1)
- CRM notifications tablosundan tamamen bağımsız

### API Endpoint'leri
- `GET /portal/notifications` — Bildirim listesi + okunmamış sayısı
- `PUT /portal/notifications` — Tümünü okundu işaretle

### Otomatik Bildirim Üretimi
- Poliçe vade yaklaşma: 30 gün içinde bitecek poliçeler
- Her poliçe için günde 1 kez (duplicate kontrolü)
- Fake bildirim yok — sadece gerçek olay

### Header UI
- Zil ikonu + kırmızı badge (okunmamış sayısı)
- Dropdown: son 50 bildirim, zaman gösterimi
- Okunmamış: mavi arka plan vurgulu
- Dropdown açıldığında otomatik okundu

---

## AŞAMA 6: Mobil + Son Kontrol

### Mobil İyileştirmeler
- Bildirim dropdown: mobilde tam genişlik (fixed inset-x-4)
- WhatsApp floating buton: mobilde sağ alt köşede (md:hidden)
- Tüm sayfalar responsive test edildi

---

## Değişen Dosyalar

### Backend (CRM)
| Dosya | Değişiklik |
|-------|-----------|
| api/controllers/PortalController.php | profile, notification, request endpoint'leri |
| api/index.php | Yeni route'lar |
| api/sql/UPDATES/1.6.0-portal-requests.php | Talep tablosu |
| api/sql/UPDATES/1.6.1-portal-notifications.php | Bildirim tablosu |

### Frontend (müşteri portalı)
| Dosya | Değişiklik |
|-------|-----------|
| app/layouts/default.vue | Menü + bildirim + WhatsApp |
| app/pages/index.vue | Müşteri merkezi ana sayfa |
| app/pages/policelerim.vue | Poliçe listesi + gelişmiş detay |
| app/pages/taleplerim.vue | Talep sistemi |
| app/pages/profilim.vue | Profil sayfası |
| app/composables/usePortalApi.ts | POST/PUT desteği |
