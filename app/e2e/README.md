# E2E Test Suite

## Test Kategorileri

### 1. State Contract Tests (auth gerektirmez)
```bash
npx playwright test --project=state-contract
```
State logic, sentinel detection, tab transition simülasyonları.

### 2. Authenticated E2E Tests (auth gerektirir)
```bash
# Adım 1: Auth state oluştur (bir kez)
npx playwright test e2e/auth-setup.ts --headed

# Adım 2: Açılan browser'da CRM'e login + 2FA yap
# Dashboard'a ulaşınca otomatik kaydedilir

# Adım 3: E2E testleri çalıştır
npx playwright test --project=authenticated-e2e
```

### 3. Tüm testler
```bash
npx playwright test --project=state-contract --project=authenticated-e2e
```

## Auth State
- `e2e/.auth/user.json` — Playwright storageState
- `.gitignore`'da — commit edilmez
- Süresi dolunca tekrar `auth-setup.ts` çalıştır

## Dosyalar
| Dosya | Kategori | Kapsam |
|-------|----------|--------|
| helpers.ts | Shared | Sentinel assertion, select validity |
| auth-setup.ts | Setup | İnteraktif login, storageState kaydetme |
| task-form-modal.spec.ts | State | TaskFormModal -1 bug regression |
| login-state.spec.ts | State | Login reset completeness |
| customer-detail-tabs.spec.ts | State | Sub-tab reset |
| multi-state-smoke.spec.ts | State | 13 yapı smoke coverage |
| real-task-form-modal.spec.ts | E2E | Gerçek modal tab transitions |
| real-modal-lifecycle.spec.ts | E2E | Modal open/close, select validity |
