<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

useSeoMeta({ title: 'Takip Aramaları Ayarları' })

const toast = useToast()
const { user } = useAuth()
const { get, post } = useApi()

const isAdmin = computed(() => user.value?.role === 'admin')
const loading = ref(true)
const saving = ref(false)

interface StageConfig {
  key: string
  days: number
  enabled: boolean
}

interface FollowUpRule {
  branchGroup: string
  enabled: boolean
  stages: StageConfig[]
}

interface StageTemplate {
  key: string
  titleTemplate: string
  descriptionTemplate: string
}

const defaultStages: { key: string; label: string; defaultDays: number; description: string; icon: string; color: string }[] = [
  { key: '2ND_MONTH', label: '2. Ay - Memnuniyet Araması', defaultDays: 60, description: 'Poliçe kullanımı, mobil uygulama, şikayet/ihtiyaç kontrolü', icon: 'i-lucide-smile', color: 'text-blue-500' },
  { key: '6TH_MONTH', label: '6. Ay - Yarı Yıl Kontrolü', defaultDays: 180, description: 'Anlaşmalı kurum memnuniyeti, hasar/provizyon durumu', icon: 'i-lucide-calendar-check', color: 'text-amber-500' },
  { key: '10TH_MONTH', label: '10. Ay - Yenileme Öncesi Isıtma', defaultDays: 300, description: 'Sağlık durumu, hasar/prim oranı, fiyat artışı bilgilendirmesi', icon: 'i-lucide-flame', color: 'text-red-500' },
]

const hardcodedTemplates: Record<string, StageTemplate> = {
  '2ND_MONTH': {
    key: '2ND_MONTH',
    titleTemplate: '2. Ay Memnuniyet Araması - {customerName}',
    descriptionTemplate: 'Müşterinin poliçeyi kullanıp kullanmadığını, mobil uygulamayı indirip indirmediğini sorun. Varsa bir şikayet veya ihtiyacını dinleyin.',
  },
  '6TH_MONTH': {
    key: '6TH_MONTH',
    titleTemplate: '6. Ay Yarı Yıl Kontrolü - {customerName}',
    descriptionTemplate: '6 aylık süreci değerlendirin. Anlaşmalı kurumlardan memnun mu, bir hasar/provizyon sıkıntısı yaşadı mı kontrol edin.',
  },
  '10TH_MONTH': {
    key: '10TH_MONTH',
    titleTemplate: '10. Ay Yenileme Öncesi Isıtma - {customerName}',
    descriptionTemplate: '2 ay sonra yenileme var. Güncel durumu yoklayın, hasar/prim oranını kontrol edin ve müşteriyi yeni dönem fiyat artışlarına psikolojik olarak hazırlayın.',
  },
}

const branchGroups = ref<{ label: string; value: string }[]>([])
const rules = ref<FollowUpRule[]>([])
const stageTemplates = ref<Record<string, StageTemplate>>(
  Object.fromEntries(Object.entries(hardcodedTemplates).map(([k, v]) => [k, { ...v }]))
)
const expandedTemplateKey = ref<string | null>(null)

function ensureStages(rule: FollowUpRule): void {
  for (const ds of defaultStages) {
    if (!rule.stages.find(s => s.key === ds.key)) {
      rule.stages.push({ key: ds.key, days: ds.defaultDays, enabled: true })
    }
  }
}

async function fetchConfig() {
  loading.value = true
  try {
    const insRes = await get('insurance-types')
    const groups = new Set<string>()
    for (const ins of (insRes.data || [])) {
      if (ins.branchGroup) groups.add(ins.branchGroup)
    }
    branchGroups.value = Array.from(groups).sort().map(g => ({ label: g, value: g }))

    const settingsRes = await get('settings')
    const config = settingsRes.data?.follow_up_call_config
    if (config && config.rules) {
      rules.value = config.rules.map((r: any) => ({
        branchGroup: r.branchGroup,
        enabled: r.enabled ?? false,
        stages: r.stages || [],
      }))

      // Kayıtlı şablonları yükle
      stageTemplates.value = Object.fromEntries(Object.entries(hardcodedTemplates).map(([k, v]) => [k, { ...v }]))
      const savedTemplates: StageTemplate[] = config.stageTemplates || []
      for (const t of savedTemplates) {
        if (stageTemplates.value[t.key]) {
          if (t.titleTemplate) stageTemplates.value[t.key].titleTemplate = t.titleTemplate
          if (t.descriptionTemplate) stageTemplates.value[t.key].descriptionTemplate = t.descriptionTemplate
        }
      }
    }

    for (const bg of branchGroups.value) {
      let rule = rules.value.find(r => r.branchGroup === bg.value)
      if (!rule) {
        rule = { branchGroup: bg.value, enabled: false, stages: [] }
        rules.value.push(rule)
      }
      ensureStages(rule)
    }
  } catch (error: any) {
    toast.add({ title: error.message || 'Ayarlar yüklenemedi', color: 'error' })
  } finally {
    loading.value = false
  }
}

async function saveConfig() {
  saving.value = true
  try {
    await post('settings', {
      follow_up_call_config: JSON.stringify({
        rules: rules.value,
        stageTemplates: Object.values(stageTemplates.value),
      })
    })
    toast.add({ title: 'Takip araması ayarları kaydedildi', color: 'success' })
  } catch (error: any) {
    toast.add({ title: error.message || 'Kaydedilemedi', color: 'error' })
  } finally {
    saving.value = false
  }
}

function getStageInfo(key: string) {
  return defaultStages.find(s => s.key === key)
}

function getStageConfig(rule: FollowUpRule, stageKey: string): StageConfig {
  return rule.stages.find(s => s.key === stageKey)!
}

function activeStageCount(rule: FollowUpRule): number {
  if (!rule.enabled) return 0
  return rule.stages.filter(s => s.enabled).length
}

function resetTemplate(key: string) {
  stageTemplates.value[key] = { ...hardcodedTemplates[key] }
}

onMounted(fetchConfig)
</script>

<template>
  <div class="space-y-4">
    <!-- Sayfa Başlığı -->
    <div class="flex items-center justify-between pb-4 border-b border-default">
      <div>
        <h1 class="text-xl">Takip Aramaları</h1>
        <p class="text-sm text-muted mt-1">Poliçe satışı/yenilemesi sonrası otomatik müşteri arama görevi oluşturma ayarları.</p>
      </div>
      <UButton v-if="isAdmin" label="Kaydet" icon="i-lucide-check" size="xl"  :loading="saving" @click="saveConfig" />
    </div>

    <!-- Loading -->
    <div v-if="loading" class="space-y-4">
      <SkeletonCard v-for="i in 2" :key="i">
        <div class="space-y-3">
          <div class="h-5 bg-neutral-200 rounded w-40" />
          <div class="h-16 bg-neutral-200 rounded w-full" />
          <div class="h-16 bg-neutral-200 rounded w-full" />
        </div>
      </SkeletonCard>
    </div>

    <template v-else>
      <!-- Bilgi -->
      <UCard>
        <div class="flex items-start gap-3">
          <UIcon name="i-lucide-info" class="size-5 text-primary shrink-0 mt-0.5" />
          <div class="text-xs text-muted space-y-1">
            <p>Her branş için <strong>3 kritik dönem</strong> tanımlanabilir. Sistem her dönemde otomatik görev oluşturur ve temsilciye ne konuşacağına dair rehber sunar.</p>
            <p>Görevler <strong>iş günlerine</strong> atanır (hafta sonu ve resmi tatiller otomatik atlanır).</p>
            <p>Ulaşılamazsa görev otomatik ertelenir, müşteri sayfasına not düşülür.</p>
          </div>
        </div>
      </UCard>

      <!-- Branş Kuralları -->
      <UCard v-for="rule in rules" :key="rule.branchGroup">
        <template #header>
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <USwitch v-model="rule.enabled" size="xs" :disabled="!isAdmin" />
              <div>
                <h3 >{{ rule.branchGroup }}</h3>
                <p class="text-xs text-muted">
                  {{ rule.enabled ? `${activeStageCount(rule)} dönem aktif` : 'Devre dışı' }}
                </p>
              </div>
            </div>
          </div>
        </template>

        <div v-if="rule.enabled" class="space-y-3">
          <div
            v-for="ds of defaultStages"
            :key="ds.key"
            class="rounded-lg border border-default p-3 transition-all"
            :class="getStageConfig(rule, ds.key).enabled ? 'bg-white' : 'bg-neutral-50 opacity-60'"
          >
            <div class="flex items-center justify-between gap-3">
              <div class="flex items-center gap-3 flex-1 min-w-0">
                <USwitch v-model="getStageConfig(rule, ds.key).enabled" :disabled="!isAdmin" size="xs" />
                <UIcon :name="ds.icon" class="size-4 shrink-0" :class="ds.color" />
                <div class="min-w-0">
                  <p class="text-sm font-medium">{{ ds.label }}</p>
                  <p class="text-xs text-muted">{{ ds.description }}</p>
                </div>
              </div>
              <div class="relative shrink-0 w-24 fl-input">
                <UInput
                  v-model.number="getStageConfig(rule, ds.key).days"
                  type="number"
                  :min="1"
                  :max="365"
                  placeholder=" "
                  :disabled="!isAdmin || !getStageConfig(rule, ds.key).enabled"
                  class="w-full peer/fl-days"
                />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-days:top-0 peer-focus-within/fl-days:-translate-y-1/2 peer-focus-within/fl-days:text-xs peer-focus-within/fl-days:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-days:top-0 peer-has-[input:not(:placeholder-shown)]/fl-days:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-days:text-xs peer-has-[input:not(:placeholder-shown)]/fl-days:text-[var(--ui-text-highlighted)]">Gün</label>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="text-xs text-muted text-center py-4">
          Bu branş için takip araması devre dışı.
        </div>
      </UCard>

      <!-- Görev Şablonları -->
      <UCard>
        <template #header>
          <div>
            <h3 >Görev Başlık ve İçerik Şablonları</h3>
            <p class="text-xs text-muted mt-0.5">
              Kullanılabilir değişkenler:
              <code class="bg-neutral-100 px-1 rounded text-xs">{branchGroup}</code>
              <code class="bg-neutral-100 px-1 rounded text-xs ml-1">{customerName}</code>
              <code class="bg-neutral-100 px-1 rounded text-xs ml-1">{policyNo}</code>
              <code class="bg-neutral-100 px-1 rounded text-xs ml-1">{startsAt}</code>
            </p>
          </div>
        </template>

        <div class="space-y-2">
          <div v-for="ds in defaultStages" :key="ds.key" class="border border-default rounded-lg overflow-hidden">
            <!-- Accordion başlık -->
            <button
              class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-neutral-50 transition-colors"
              @click="expandedTemplateKey = expandedTemplateKey === ds.key ? null : ds.key"
            >
              <div class="flex items-center gap-2">
                <UIcon :name="ds.icon" class="size-4 shrink-0" :class="ds.color" />
                <span class="text-sm font-medium">{{ ds.label }}</span>
              </div>
              <UIcon
                :name="expandedTemplateKey === ds.key ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                class="size-4 text-muted"
              />
            </button>

            <!-- Accordion içerik -->
            <div v-if="expandedTemplateKey === ds.key" class="px-4 pb-4 space-y-4 border-t border-default pt-4">
              <div class="relative fl-input">
                <UInput v-model="stageTemplates[ds.key].titleTemplate" :disabled="!isAdmin" placeholder=" " class="w-full peer/fl-ttitle" />
                <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-ttitle:top-0 peer-focus-within/fl-ttitle:-translate-y-1/2 peer-focus-within/fl-ttitle:text-xs peer-focus-within/fl-ttitle:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-ttitle:top-0 peer-has-[input:not(:placeholder-shown)]/fl-ttitle:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-ttitle:text-xs peer-has-[input:not(:placeholder-shown)]/fl-ttitle:text-[var(--ui-text-highlighted)]">Görev Başlığı</label>
              </div>
              <div class="relative fl-input">
                <UTextarea v-model="stageTemplates[ds.key].descriptionTemplate" :disabled="!isAdmin" :rows="4" placeholder=" " class="w-full peer/fl-tdesc" />
                <label :class="['pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm', stageTemplates[ds.key].descriptionTemplate ? 'top-0 -translate-y-1/2 text-xs text-[var(--ui-text-highlighted)]' : 'top-3 text-[var(--ui-text-muted)]']">Görev İçeriği</label>
              </div>
              <div class="flex justify-end">
                <UButton v-if="isAdmin" label="Varsayılana Sıfırla" variant="outline" size="xl"  color="neutral" icon="i-lucide-rotate-ccw" @click="resetTemplate(ds.key)" />
              </div>
            </div>
          </div>
        </div>
      </UCard>

      <!-- Nasıl Çalışır -->
      <UCard>
        <template #header>
          <h3 >Nasıl Çalışır?</h3>
        </template>

        <div class="space-y-3 text-xs text-muted">
          <div class="flex items-start gap-2">
            <span class="size-5 rounded-full bg-blue-500/10 text-blue-500 flex items-center justify-center shrink-0 text-xs font-bold">1</span>
            <p><strong>2. Ay:</strong> Müşteri memnuniyet araması. Poliçeyi kullanıp kullanmadığı, şikayet/ihtiyaç sorulur.</p>
          </div>
          <div class="flex items-start gap-2">
            <span class="size-5 rounded-full bg-amber-500/10 text-amber-500 flex items-center justify-center shrink-0 text-xs font-bold">2</span>
            <p><strong>6. Ay:</strong> Yarı yıl durum kontrolü. Anlaşmalı kurumlardan memnuniyet, hasar/provizyon durumu değerlendirilir.</p>
          </div>
          <div class="flex items-start gap-2">
            <span class="size-5 rounded-full bg-red-500/10 text-red-500 flex items-center justify-center shrink-0 text-xs font-bold">3</span>
            <p><strong>10. Ay:</strong> Yenileme öncesi ısıtma araması. Sağlık durumu, fiyat artışı bilgilendirmesi yapılır.</p>
          </div>
          <div class="border-t border-default pt-3 mt-3 space-y-2">
            <p><strong>Akıllı Erteleme:</strong> Ulaşılamazsa görev 1 iş günü, müşteri müsait değilse 3 iş günü sonraya otomatik ertelenir.</p>
            <p><strong>İş Günü Koruması:</strong> Görevler asla hafta sonuna veya resmi tatile atanmaz.</p>
            <p><strong>Otomatik Not:</strong> Her arama sonucu müşteri sayfasına otomatik kaydedilir.</p>
          </div>
        </div>
      </UCard>
    </template>
  </div>
</template>
