# CRM Tasarim Tutarlilik Standartlastirmasi

## Amac
Tum sayfalardaki tablo, badge, baslik, filtre, ikon, bosluk ve renk kullanimlarini tek bir standarta cekme.

## Belirlenen Standartlar

### Tablo
- Table: `text-xs w-full table-fixed`
- Wrapper: `border border-default rounded-lg overflow-hidden`
- Thead: `sticky top-0 z-10`, tr: `bg-gray-50 dark:bg-gray-800/50 border-b border-default`
- Th: `py-2 px-3 text-xs font-semibold tracking-wide text-muted`
- Td: `py-2 px-3`
- Hover: `hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors`

### Badge
- `.badge-cell`: sabit 90px, 11px font, 600 weight, 6px radius, `text-overflow: clip`
- `.badge-sm`: sabit 50px (kalan gun gibi kisa degerler icin)
- Renk class'lari: `.badge-error`, `.badge-warning`, `.badge-info`, `.badge-success`, `.badge-neutral`
- Police Turu: `badge-cell` + `toHex(insuranceColor)` inline style

### Baslik
- Sayfa/kart basligi: `h3.font-semibold`
- Alt aciklama: `p.text-xs.text-muted`
- Baslik + filtreler UCard `#header` icinde

### Filtreler
- Ay secici / USelect / UInput: `w-[180px] h-[30px] size="xs"`
- Filtreler solda, Excel/aksiyon butonlari sagda
- Butonlar: `size="xs"`

### Genel
- Sayfa boslugu: `space-y-4`
- Stat kartlari: `UCard :ui="{ body: 'p-3' }"`, `text-2xl font-bold`, `gap-3`
- Muted metin: `text-muted` (text-gray-500, text-gray-400 kullanilmaz)
- Ikon format: `size-X` (w-X h-X kullanilmaz)
- Tracking: `tracking-wide` (tracking-wider kullanilmaz)
- Dark mode: tum Tailwind renklere `dark:` prefix
- Ad/Soyad: `font-semibold text-primary truncate block` + `:title`
- Temsilci: `shortName()` formati ("Aykut O.")
- Bos deger: `text-muted` + "-" veya "Atanmamis"
- Switch: `size="xs"`
- Settings sayfalari: pagination yok, `limit=999`, altta "Toplam X kayit"

## Degisen Dosyalar (31 sayfa)

### Elle Duzeltilen (16 sayfa)
- `app/pages/index.vue` -- Ana sayfa gorev tablosu, stat kartlari, badge'ler
- `app/pages/gorevler/index.vue` -- Gorev tablosu, filtreler, badge'ler, pagination
- `app/pages/gunluk-aktivite.vue` -- Gunluk tablo, filtreler, Bu Ay filtresi eklendi
- `app/pages/kacirilan-policeler.vue` -- Tablo, stat kartlari, filtreler, badge'ler
- `app/pages/acentemiz-hakkinda.vue` -- Stat kartlari, tablo, ikonlar
- `app/pages/portfolyo.vue` -- Tablolar, kart basliklari, filtreler
- `app/pages/satis-performansi.vue` -- Tablo, stat kartlari, badge'ler, filtreler
- `app/pages/mesajlar.vue` -- Tablo, tab yapisi, badge'ler, musteri listesi
- `app/pages/musteriler/index.vue` -- UTable stili, baslik, filtreler, truncate
- `app/pages/musteriler/[id].vue` -- Police/teklif tablolari, badge'ler
- `app/pages/raporlar.vue` -- Tablolar, stat kartlari, badge'ler
- `app/pages/settings/sirketler.vue` -- UTable stili, baslik, pagination kaldirildi
- `app/pages/settings/sigorta-turleri.vue` -- UTable stili, badge'ler, pagination kaldirildi
- `app/pages/settings/referans-kaynaklari.vue` -- UTable stili, badge'ler, pagination kaldirildi
- `app/pages/settings/alan-ayarlari.vue` -- Basliklar, alt aciklamalar
- `app/pages/settings/acenteler/index.vue` -- UTable stili, baslik, pagination kaldirildi

### Agent ile Duzeltilen (15 sayfa)
- `app/pages/personel/index.vue` -- Tablo stili, Ad/Soyad truncate
- `app/pages/personel/[id].vue` -- Basliklar, tablolar, etiketler
- `app/pages/settings/kullanicilar/index.vue` -- UTable stili, pagination kaldirildi
- `app/pages/settings/acenteler/[id].vue` -- Tablolar, basliklar, filtreler
- `app/pages/settings/acente.vue` -- Basliklar, alt aciklamalar
- `app/pages/settings/bildirimler.vue` -- Basliklar, switch boyutu
- `app/pages/settings/guvenlik.vue` -- Basliklar, alt aciklamalar
- `app/pages/settings/guncelleme.vue` -- Basliklar, versyon boyutu
- `app/pages/settings/takip-aramalari.vue` -- Basliklar, switch boyutu
- `app/pages/settings/oturumlar.vue` -- Basliklar, alt aciklamalar
- `app/pages/araclar/capraz-satis.vue` -- UTable stili, baslik
- `app/pages/araclar/mutabakat.vue` -- UTable + native tablo stili
- `app/pages/araclar/calisan-mutabakat.vue` -- UTable + native tablo stili
- `app/pages/musteriler/gruplar.vue` -- UTable stili
- `app/pages/login.vue` -- Muted renk standardi

### Dokunulmayan
- `app/pages/policeler/index.vue`
- `app/pages/araclar/allianz-import.vue`
- `app/pages/araclar/excel-import.vue`

## Backend Degisiklikleri
- `api/controllers/PolicyController.php` -- daily() zeyilname dedup filtresi, Bu Ay filtresi
- `api/controllers/DashboardController.php` -- salesPerformance() zeyilname dedup filtresi (parent_id IS NULL OR is_cancelled)
- `api/controllers/CustomerController.php` -- listAll() phone filtresi eklendi (?hasPhone=1)
- `api/controllers/AiCoachController.php` -- AI Satis Kocu prompt guncellendi, expired endpoint eklendi, hafta sonu uyarisi, stage hesaplama, not analizi
- `api/index.php` -- listAll route'una $query parametresi, ai-coach/expired route eklendi

## AI Satis Kocu Guncellemeleri
- Profesyonel satis kocu prompt'u yazildi (5 asamali gorev akisi, brans taktikleri, itiraz karsilama)
- Stage backend'de hesaplaniyor (PREPARE_OFFER, SEND_OFFER, FOLLOW_UP, POLICY_DELIVERY, CLOSE_TASK)
- Not analizi: keyword tespiti (ulasilamadi, dusunecek, fiyat yuksek vb.) + durum sinyalleri
- Hafta sonu uyarisi: Cumartesi/Pazar vadeli gorevlere sinyal ekleniyor
- topAction: En onemli tek gorev modal ustunde "Simdi Bunu Yap" karti
- Suresi Gecenler: Ayri tab, AI gerektirmez, direkt DB'den
- Onceliklendirme: Is akisina uygun (yeni gorev=NORMAL, geciken=ACIL)
- Accordion yapisi: Diger gorevler katlanabilir

## Frontend Degisiklikleri
- `app/pages/index.vue` -- AI Coach modal (tab yapisi, topAction karti, accordion gorevler, ozet parse)
- `app/layouts/default.vue` -- Arama kutusu hizalama, AI Coach butonu
- `app/components/PolicyFormModal.vue` -- PDF'den bitis tarihi override sorunu duzeltildi (isInitializing flag)

## Teklif Silme Ozelligi
- `app/pages/musteriler/[id].vue` -- Teklif detay slideover'ina sil butonu eklendi
- Onay modali ile silme (soft delete, DELETE /api/tasks/:id)
- Silme sonrasi slideover kapanir, teklif listesi yenilenir

## PDF Bitis Tarihi Duzeltmesi
- Sorun: PDF yuklediginde startsAt watch'i tetiklenip expiresAt'i +1 yil override ediyordu
- Cozum: PDF fill sirasinda isInitializing=true ile watch devre disi birakiliyor
- expiresAt PDF'den ne geliyorsa o set ediliyor (1 yillik, 6 aylik, 3 aylik fark etmez)
