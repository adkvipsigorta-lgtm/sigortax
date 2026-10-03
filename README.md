# Nuxt Starter Template

[![Nuxt UI](https://img.shields.io/badge/Made%20with-Nuxt%20UI-00DC82?logo=nuxt&labelColor=020420)](https://ui.nuxt.com)

Use this template to get started with [Nuxt UI](https://ui.nuxt.com) quickly.

- [Live demo](https://starter-template.nuxt.dev/)
- [Documentation](https://ui.nuxt.com/docs/getting-started/installation/nuxt)

<a href="https://starter-template.nuxt.dev/" target="_blank">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://ui.nuxt.com/assets/templates/nuxt/starter-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="https://ui.nuxt.com/assets/templates/nuxt/starter-light.png">
    <img alt="Nuxt Starter Template" src="https://ui.nuxt.com/assets/templates/nuxt/starter-light.png" width="830" height="466">
  </picture>
</a>

> The starter template for Vue is on https://github.com/nuxt-ui-templates/starter-vue.

## Quick Start

```bash [Terminal]
npm create nuxt@latest -- -t github:nuxt-ui-templates/starter
```

## Deploy your own

[![Deploy with Vercel](https://vercel.com/button)](https://vercel.com/new/clone?repository-name=starter&repository-url=https%3A%2F%2Fgithub.com%2Fnuxt-ui-templates%2Fstarter&demo-image=https%3A%2F%2Fui.nuxt.com%2Fassets%2Ftemplates%2Fnuxt%2Fstarter-dark.png&demo-url=https%3A%2F%2Fstarter-template.nuxt.dev%2F&demo-title=Nuxt%20Starter%20Template&demo-description=A%20minimal%20template%20to%20get%20started%20with%20Nuxt%20UI.)

## Setup

Make sure to install the dependencies:

```bash
pnpm install
```

## Development Server

Start the development server on `http://localhost:3000`:

```bash
pnpm dev
```

## Production

Build the application for production:

```bash
pnpm build
```

Locally preview production build:

```bash
pnpm preview
```

Check out the [deployment documentation](https://nuxt.com/docs/getting-started/deployment) for more information.

---

## Mobil Uyumluluk (Responsive Design)

Bu proje tüm sayfalarda mobil uyumluluk için kapsamlı bir güncelleme geçirmiştir. Aşağıda kullanılan teknikler ve güncellenen sayfalar belgelenmiştir.

### Kullanılan Teknikler

#### 1. Native `<table>` — Kolon Gizleme
Sabit kolonlu native HTML tablolarda gereksiz kolonlar mobilde `hidden` sınıfıyla gizlenir:
```html
<th class="hidden md:table-cell ...">Kolon Başlığı</th>
<td class="hidden md:table-cell ...">{{ veri }}</td>
```

#### 2. UTable (Nuxt UI) — CSS `:deep()` Media Query
Nuxt UI'nin `<UTable>` bileşeninde doğrudan sınıf eklenemediği için scoped CSS içinde `nth-child` hedeflemesi kullanılır:
```css
@media (max-width: 767px) {
  :deep(.tablo-sinifi th:nth-child(3)),
  :deep(.tablo-sinifi td:nth-child(3)) { display: none; }
}
```

#### 3. Dinamik Kolonlu Tablolar — Masaüstüne Saklama
`v-for` ile oluşturulan dinamik kolonlarda `nth-child` güvenilir çalışmadığından tablo sadece masaüstünde gösterilir, mobilde bilgi mesajı görünür:
```html
<div class="flex items-center gap-2 p-3 rounded-lg bg-blue-50 md:hidden">
  <UIcon name="i-lucide-monitor" />
  <p>Bu tablo tablet veya masaüstünde görüntülenebilir.</p>
</div>
<div class="hidden md:block overflow-x-auto">
  <table class="w-full text-sm">...</table>
</div>
```

#### 4. Yatay Kaydırma — `w-full min-w-[XXX]`
Tablo masaüstünde tam genişliği doldurmalı, mobilde ise kaydırılabilir olmalıdır:
```html
<!-- YANLIŞ: w-full tek başına overflow'u engeller -->
<div class="overflow-x-auto"><table class="w-full">

<!-- DOĞRU: w-full masaüstünde tam genişlik, min-w mobilde kaydırmayı tetikler -->
<div class="overflow-x-auto"><table class="w-full min-w-[480px]">
```

#### 5. Mobil Sayfalama
Küçük ekranlarda `← X/Y →` yön tuşlu kompakt sayfalama:
```html
<!-- Sadece mobil -->
<div class="flex items-center gap-2 sm:hidden">
  <UButton icon="i-lucide-chevron-left" :disabled="page <= 1" @click="setPage(page - 1)" />
  <span class="text-xs text-muted">{{ page }} / {{ totalPages }}</span>
  <UButton icon="i-lucide-chevron-right" @click="setPage(page + 1)" />
</div>
<!-- Sadece masaüstü -->
<UPagination class="hidden sm:flex" ... />
```

#### 6. Esnek Başlık Çubuğu
```html
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
```

---

### Güncellenen Sayfalar

| Sayfa | Dosya | Yapılan Değişiklik |
|-------|-------|-------------------|
| Ana Sayfa | `pages/index.vue` | Sonuç seçim butonları grid: `grid-cols-1 sm:grid-cols-3` |
| Müşteri Detay | `pages/musteriler/[id].vue` | Teklifler tablosunda Plaka, Temsilci, Bitiş, Oluşturma kolonları mobilde gizlendi |
| Müşteri Grupları | `pages/musteriler/gruplar.vue` | Mobil sayfalama eklendi |
| Portfolyo | `pages/portfolyo.vue` | Başlık `flex-col sm:flex-row`; aylık karşılaştırma tablosunda 6 kolon mobilde gizlendi; "Ürün Bazında Aylık Üretim" tablosu dinamik kolon nedeniyle masaüstüne alındı; tahmin tabloları `w-full min-w-[400px]` ile kaydırılabilir yapıldı |
| Kullanıcılar | `pages/settings/kullanicilar.vue` | Şube ve Kayıt Tarihi kolonları (3. ve 5.) mobilde gizlendi; mobil sayfalama eklendi |
| Sigorta Şirketleri | `pages/settings/sirketler.vue` | Renk, Web Sitesi, Toplam Poliçe, Kayıt Tarihi kolonları mobilde gizlendi; mobil sayfalama eklendi |
| Acenteler | `pages/settings/acenteler.vue` | Telefon ve IBAN kolonları mobilde gizlendi; mobil sayfalama eklendi |
| Personel Detay | `pages/personel/[id].vue` | İzin tablosunda Gün ve Not kolonları mobilde gizlendi; `overflow-x-auto` eklendi |
| Mesajlar | `pages/mesajlar.vue` | İçerik ve Gönderen kolonları mobilde gizlendi; mobil sayfalama eklendi |
| Mutabakat | `pages/araclar/mutabakat.vue` | Excel butonu mobilde gizlendi (`hidden sm:flex`); özet tabloda Poliçe, İptal, Net Prim kolonları mobilde gizlendi |
| Çalışan Mutabakat | `pages/araclar/calisan-mutabakat.vue` | Excel butonu mobilde gizlendi |
| Çapraz Satış | `pages/araclar/capraz-satis.vue` | Mobil sayfalama eklendi |

### Breakpoint Referansı

| Tailwind Sınıfı | Ekran Genişliği |
|----------------|----------------|
| (varsayılan) | < 640px — Mobil |
| `sm:` | ≥ 640px — Büyük Mobil / Küçük Tablet |
| `md:` | ≥ 768px — Tablet / Masaüstü |
