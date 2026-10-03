# Müşteri Portalı (musteri.sigortax.net)

## Amaç
Tüm müşterilere açık, bağımsız bir portal. TC/VKN + telefon son 4 hane + SMS OTP ile giriş yaparak aktif poliçelerini görüntüleyebilir, vade takibi yapabilir ve belge indirebilir.

---

## Veritabanı Değişiklikleri

### Migration: 1.5.0-customer-portal.php
- `users` tablosuna `customer_ids` JSON kolonu (role=3 için bağlı müşteri ID listesi)
- `settings` tablosuna `customer_portal_enabled` varsayılan değer

### Migration: 1.5.1-portal-otp.php
- `portal_otps` tablosu (OTP kodları, doğrulama, deneme sayısı)

### Migration: 1.5.2-portal-otp-rate-limit.php
- `portal_otps` tablosuna `ip` ve `sms_sent` kolonları
- `settings` tablosuna `portal_daily_sms_limit` (varsayılan: 500)

---

## Backend Değişiklikleri (CRM API)

### Yeni: api/controllers/PortalController.php
- `GET /api/portal/status` — Portal açık mı kapalı mı + acente bilgileri
- `POST /api/portal/sms-login` — TC/VKN + telefon son 4 hane doğrulama + SMS OTP gönder
- `POST /api/portal/sms-verify` — OTP doğrulama + JWT token oluştur (30 dk)
- `GET /api/portal/me` — Token doğrulama + müşteri bilgileri
- `GET /api/portal/policies` — Müşterinin poliçeleri (firma/durum/tarih filtresi, arama)
- `GET /api/portal/summary` — Özet istatistikler
- `GET /api/portal/policies/{id}/documents` — Poliçenin belgeleri
- `GET /api/portal/documents/{id}/download` — Belge indirme (şifre çözme dahil)

### Yeni: api/helpers/NetgsmSms.php
- Netgsm XML API ile SMS gönderme
- Telefon numarası normalize etme (sadece TR cep: 5XXXXXXXXX)
- Settings tablosundan API bilgilerini çekme
- Başarı/hata yanıt yönetimi

### api/index.php
- Portal route'ları controller mapping'den önce yakalanır
- Public: status, sms-login, sms-verify
- Protected: role=3 + portal açık kontrolü

### api/helpers/Auth.php
- `generateToken()` fonksiyonuna opsiyonel `$ttl` parametresi eklendi (SMS login 30 dk)

### api/middleware/AuthMiddleware.php
- role=3 (müşteri) kullanıcıları CRM API'sine erişemez (403)

### api/controllers/AuthController.php
- role=3 kullanıcıları CRM login'den giremez (403)

### api/controllers/UserController.php
- role=3 (müşteri) desteği eklendi
- `customer_ids` JSON alanı CRUD'da destekleniyor
- roleMap'e `'musteri' => 3` eklendi

### api/controllers/SettingsController.php
- `netgsm_password` maskeleme eklendi

---

## SMS OTP Güvenlik Mimarisi

### Giriş Akışı
1. Müşteri TC/VKN + telefon son 4 hanesini girer
2. Backend: TC/VKN ile müşteriyi bul → son 4 hane eşleşme kontrolü
3. Rate limit kontrolleri (6 katman)
4. 6 haneli OTP üret (CSPRNG) → DB'ye kaydet → Netgsm SMS gönder
5. Müşteri OTP'yi girer → doğrulama → 30 dk JWT token
6. Başarılı giriş ekranı (2 sn) → poliçe listesine yönlendirme

### Rate Limit Katmanları
| Katman | Limit | Açıklama |
|--------|-------|----------|
| IP saatlik | 10 SMS/saat | Tek IP'den toplu saldırı önleme |
| IP günlük | 30 SMS/gün | Günlük IP limiti |
| Global günlük | 500 SMS/gün | Tüm sistem SMS bakiye koruması |
| Müşteri kademeli | 3 + 30dk bekle + 2 | İlk 3 hak, 30dk sonra 2 hak daha |
| Müşteri günlük | 5 SMS/gün | Toplam günlük müşteri limiti |
| Cooldown | 2 dakika | İki SMS arası minimum bekleme |
| Başarısız deneme | 15 hatalı → 24 saat blok | Brute-force koruması |
| Başarılı giriş | 5 giriş/gün | Günlük giriş limiti |
| OTP deneme | 5 hatalı → yeni SMS gerekli | OTP brute-force koruması |

### Güvenlik Önlemleri
- Enumeration koruması: Tüm hata durumlarında aynı genel mesaj
- customerName bilgi sızıntısı kapatıldı
- SMS başarısız ise OTP silinir (maliyet koruması)
- OTP tek kullanımlık (verified=1)
- OTP 5 dakika geçerlilik süresi
- Yeni OTP oluşturulunca eski doğrulanmamışlar silinir
- PHP/MySQL timezone uyumu (PHP date() ile tutarlı sorgular)
- Yurt dışı numara engeli (sadece TR cep: 05XX)
- SMS mesajı Türkçe karaktersiz (1 segment = düşük maliyet)
- IP adresi kaydediliyor (portal_otps.ip)
- sms_sent flag'i ile gerçek SMS gönderim takibi

### SMS Mesajı
```
123456 tek kullanimlik sifrenizle musteri portalina giris yapabilirsiniz. Lutfen size ozel gelen bu sifreyi hic kimseyle paylasmayiniz.
```
~130 karakter, GSM-7, 1 segment

---

## CRM Admin Paneli Değişiklikleri

### settings/kullanicilar/index.vue
- Rol seçeneklerine "Müşteri (Portal)" eklendi
- Müşteri rolünde "Bağlı Müşteriler" arama + seçim alanı
- Birden fazla müşteri bağlanabilir (chip + arama dropdown)

### settings/acente.vue
- "Müşteri Portalı" kartı — toggle ile açma/kapama
- "Netgsm SMS Entegrasyonu" kartı — Abone No, API Şifresi, Mesaj Başlığı, toggle
- Netgsm şifresi maskelenmiş gösterilir

---

## Müşteri Portalı (Ayrı Nuxt 4 Projesi)

Dizin: `Desktop/musteri-portal/`

### Sayfa Yapısı
- `/login` — TC/VKN + son 4 hane → SMS OTP → başarı ekranı → yönlendirme
- `/` — Poliçe listesi + özet kartları + filtreler + Excel + belge indirme

### Login Sayfası
- Split layout (sol mavi gradient branding + sağ form)
- Adım 1: TC/VKN + telefon son 4 hanesi
- Adım 2: SMS doğrulama kodu (6 hane) + 2dk countdown + tekrar gönder
- Adım 3: Başarılı giriş animasyonu (2 sn geçiş)
- Hata modal: Eşleşmeme durumunda popup (0540 212 05 05 destek numarası)
- Telefon son 4 hane: sadece rakam, max 4 karakter

### Ana Sayfa (Poliçe Listesi)
- 4 özet kartı: Aktif Poliçe, Bu Ay Dolacak, Süresi Dolmuş, Toplam Prim
- Kartlar filtrelere göre dinamik güncellenir
- Filtreler: Arama, firma seçimi, durum (Aktif/Süresi Dolmuş/İptal), tarih aralığı (takvim)
- Excel indirme (CSV, Türkçe karakter destekli)
- Tablo: Poliçe No → Şirket → Poliçe Türü → Plaka → Başlangıç → Bitiş → Kalan Gün → Brüt Prim → İşlem
- Poliçe detay slideover (göz ikonu)
- PDF belge indirme
- Zeyil primleri toplanmış gösterilir (ana + zeyil toplam)
- Arama boşluk duyarsız (34HPF380 = 34 HPF 380)
- Mobil responsive: Poliçe Türü, Plaka, Kalan Gün, İşlem görünür

### Composables
- `usePortalAuth.ts` — smsLogin, smsVerify, logout, fetchMe
- `usePortalApi.ts` — API istekleri, belge indirme

### Middleware
- `auth.global.ts` — Otomatik yönlendirme (login/dashboard)

### Layout
- Header: Logo + müşteri adı + çıkış butonu
- Footer: Copyright + destek telefonu (0540 212 05 05)

---

## Değişen Dosyalar (CRM)

### Backend (8 dosya)
- `api/sql/UPDATES/1.5.0-customer-portal.php` — Migration: users.customer_ids + portal ayarı
- `api/sql/UPDATES/1.5.1-portal-otp.php` — Migration: portal_otps tablosu
- `api/sql/UPDATES/1.5.2-portal-otp-rate-limit.php` — Migration: ip, sms_sent, global limit
- `api/controllers/PortalController.php` — Yeni: tüm portal endpoint'leri + rate limit
- `api/helpers/NetgsmSms.php` — Yeni: SMS gönderme helper
- `api/helpers/Auth.php` — generateToken'a $ttl parametresi
- `api/middleware/AuthMiddleware.php` — role=3 CRM erişim engeli
- `api/controllers/AuthController.php` — role=3 CRM login engeli
- `api/controllers/UserController.php` — role=3 + customer_ids desteği
- `api/controllers/SettingsController.php` — netgsm_password maskeleme
- `api/index.php` — Portal route'ları

### Frontend CRM (2 dosya)
- `app/pages/settings/kullanicilar/index.vue` — Müşteri rolü + bağlı müşteriler
- `app/pages/settings/acente.vue` — Portal toggle + Netgsm ayarları

---

## Müşteri Portalı Dosyaları (musteri-portal/)
- `nuxt.config.ts` — Nuxt 4 yapılandırması
- `app/app.vue` — Ana uygulama bileşeni
- `app/app.config.ts` — UI renk ayarları
- `app/assets/css/main.css` — Tailwind + Nuxt UI CSS
- `app/pages/login.vue` — SMS OTP giriş sayfası
- `app/pages/index.vue` — Poliçe listesi ana sayfa
- `app/layouts/default.vue` — Header + footer layout
- `app/composables/usePortalAuth.ts` — Auth composable
- `app/composables/usePortalApi.ts` — API composable
- `app/middleware/auth.global.ts` — Auth middleware
- `package.json` — Bağımlılıklar
- `tsconfig.json` — TypeScript yapılandırması

---

## Deploy Notları

### CRM (crm2.sigortax.net)
- Backend dosyalarını güncelle
- Migration'lar otomatik çalışır
- Netgsm bilgilerini Ayarlar > Acente'den gir
- Portal toggle'ını aç

### Portal (musteri.sigortax.net)
- `musteri-portal/` dizinini deploy et
- `.env` dosyası: `NUXT_PUBLIC_API_BASE=https://crm2.sigortax.net/api`
- `npm install && npm run generate` ile static build
- cPanel'e yükle

### Deploy Öncesi Yapılacak
- `PortalController.php` satır 245-246: Test SMS yönlendirmesini kaldır
  - `$smsPhone = '5322575605';` → `$smsPhone = $phone;` olarak değiştir

---

## Kullanım

### Müşteri Girişi
1. musteri.sigortax.net'e gir
2. TC Kimlik No veya Vergi No gir
3. Kayıtlı telefon numarasının son 4 hanesini gir
4. "Doğrulama Kodu Gönder" tıkla
5. Telefona gelen 6 haneli kodu gir
6. "Giriş Yap" tıkla
7. Poliçeleri görüntüle, filtrele, Excel indir, PDF indir

### Admin Yönetimi
1. CRM > Ayarlar > Acente > Müşteri Portalı toggle'ını aç
2. CRM > Ayarlar > Acente > Netgsm bilgilerini gir
3. Müşterinin CRM'deki telefon numarası güncel olmalı
4. Telefonu olmayan müşteriler portala giremez — arayınca numarayı güncelle
