# Mutabakat Kilitli Policede Plaka/Ruhsat Seri No Duzenleme Hatasi

## Sorun
Mutabakat kilitli (RECONCILED) policelerde plaka ve ruhsat seri no degisikligi yapilmasina izin verilmesine ragmen, "Mutabakati kilitli policede degisiklik yapilamaz" hatasi aliniyordu.

## Sebep
`PolicyFormModal.vue` icindeki `savePolicy()` fonksiyonu, mutabakat kilitli policelerde bile tum form alanlarini (soldBy, referenceSource, productionType, branchId vb.) backend'e gonderiyordu. Backend'deki mutabakat kilit kontrolu bu alanlarda tip/format farki (undefined vs null, string vs number) tespit edince guncellemeyi reddediyordu.

Frontend'de alanlar disabled yapilmis olsa da, form state'indeki mevcut degerler yine de PUT istegine dahil ediliyordu.

## Cozum
`app/components/PolicyFormModal.vue` - `savePolicy()` fonksiyonuna mutabakat filtresi eklendi (satir 1000-1007):

```js
// Mutabakat kilitli policelerde sadece plaka ve ruhsat seri no gonder
if (isEditMode.value && isReconciled.value) {
  const plateNo = data.plateNo
  const registrationNo = data.registrationNo
  Object.keys(data).forEach(k => delete data[k])
  if (plateNo !== undefined) data.plateNo = plateNo
  if (registrationNo !== undefined) data.registrationNo = registrationNo
}
```

Boylece mutabakat kilitli policelerde backend'e sadece `plateNo` ve `registrationNo` gonderiliyor, kilitli alanlara dokunulmuyor.

## Degisen Dosyalar
- `app/components/PolicyFormModal.vue` -- savePolicy() mutabakat filtresi eklendi
