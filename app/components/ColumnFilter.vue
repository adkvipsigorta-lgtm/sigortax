<script setup lang="ts">
/**
 * Tablo basligi icin tiklanabilir filtre dropdown'i.
 * Tipine gore uygun input gosterir; aktif filtre ozellikle isaretlenir.
 */
import { CalendarDate } from '@internationalized/date'

type FilterType = 'text' | 'select' | 'multiselect' | 'date'

const props = defineProps<{
  label: string
  type: FilterType
  options?: { label: string, value: any }[]
  width?: string
}>()

const model = defineModel<any>()
const open = ref(false)

// Multiselect icin disardan arama: buyuk listelerde iç filtre yavaslar
const searchTerm = ref('')
const displayOptions = computed(() => {
  const opts = props.options || []
  const q = searchTerm.value.toLowerCase().trim()
  const filtered = q ? opts.filter(o => String(o.label).toLowerCase().includes(q)) : opts
  // Secili olanlari her zaman dahil et
  const selected = Array.isArray(model.value) ? model.value : []
  const list = filtered.slice(0, 200)
  for (const v of selected) {
    if (!list.some(o => o.value === v)) {
      const found = opts.find(o => o.value === v)
      if (found) list.unshift(found)
    }
  }
  return list
})

// Tarih: ISO string ('YYYY-MM-DD') <-> CalendarDate donusumu
function strToCal(s: string): CalendarDate | undefined {
  if (!s) return undefined
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/)
  return m ? new CalendarDate(+m[1], +m[2], +m[3]) : undefined
}
function calToStr(c: any): string {
  if (!c) return ''
  return `${c.year}-${String(c.month).padStart(2, '0')}-${String(c.day).padStart(2, '0')}`
}
function fmt(s: string): string {
  if (!s) return ''
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/)
  return m ? `${m[3]}.${m[2]}.${m[1]}` : s
}

const startCal = computed(() => strToCal(model.value?.start))
const endCal = computed(() => strToCal(model.value?.end))
const startOpen = ref(false)
const endOpen = ref(false)

function setStart(v: any) { model.value = { ...(model.value || {}), start: calToStr(v) }; startOpen.value = false }
function setEnd(v: any) { model.value = { ...(model.value || {}), end: calToStr(v) }; endOpen.value = false }

const isActive = computed(() => {
  const v = model.value
  if (v == null) return false
  if (Array.isArray(v)) return v.length > 0
  if (typeof v === 'object') {
    return Object.values(v).some(x => x !== null && x !== undefined && x !== '')
  }
  if (typeof v === 'string') return v.trim() !== ''
  return !!v
})

function clear() {
  if (props.type === 'multiselect') model.value = []
  else if (props.type === 'date') model.value = { start: '', end: '' }
  else model.value = ''
  open.value = false
}

// Multiselect icin secili etiketleri ozet olarak gormek istersek
const selectedLabels = computed(() => {
  if (props.type !== 'multiselect' || !Array.isArray(model.value)) return []
  return (props.options || []).filter(o => model.value.includes(o.value)).map(o => o.label)
})
</script>

<template>
  <UPopover v-model:open="open">
    <button
      type="button"
      class="group flex items-center gap-1.5 px-2 py-1 rounded-md -mx-2 transition-all duration-150"
      :class="isActive
        ? 'bg-primary/15 text-primary'
        : 'text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 hover:text-gray-900 dark:hover:text-white'"
    >
      <span class="text-xs tracking-wide">{{ label }}</span>
      <UIcon
        :name="isActive ? 'i-lucide-list-filter' : 'i-lucide-chevron-down'"
        class="size-3 transition-opacity duration-150"
        :class="isActive ? 'opacity-100' : 'opacity-0 group-hover:opacity-50'"
      />
    </button>
    <template #content>
      <div class="p-3 space-y-2" :class="width || 'w-64'">
        <div class="flex items-center justify-between">
          <p class="text-xs font-semibold uppercase text-muted">{{ label }}</p>
          <button v-if="isActive" type="button" class="text-[11px] text-muted hover:text-primary" @click="clear">
            Temizle
          </button>
        </div>

        <UInput
          v-if="type === 'text'"
          v-model="model"
          :placeholder="`${label} ara...`"
          size="sm"
          autofocus
        />

        <USelect
          v-else-if="type === 'select'"
          v-model="model"
          :items="options || []"
          value-key="value"
          label-key="label"
          placeholder="Seç..."
          size="sm"
          class="w-full"
        />

        <USelectMenu
          v-else-if="type === 'multiselect'"
          v-model="model"
          :items="displayOptions"
          value-key="value"
          label-key="label"
          placeholder="Seç... (yazarak ara)"
          multiple
          ignore-filter
          size="sm"
          class="w-full"
          @update:search-term="(v: string) => searchTerm = v"
        />

        <div v-else-if="type === 'date'" class="space-y-2">
          <div>
            <label class="text-[11px] text-muted">Başlangıç</label>
            <UPopover v-model:open="startOpen" :ui="{ content: 'p-0' }">
              <UButton
                :label="model?.start ? fmt(model.start) : 'Tarih seç'"
                icon="i-lucide-calendar"
                color="neutral"
                variant="outline"
                size="sm"
                class="w-full justify-start"
                :class="{ 'text-muted': !model?.start }"
              />
              <template #content>
                <UCalendar locale="tr-TR" :model-value="startCal" @update:model-value="setStart" />
              </template>
            </UPopover>
          </div>
          <div>
            <label class="text-[11px] text-muted">Bitiş</label>
            <UPopover v-model:open="endOpen" :ui="{ content: 'p-0' }">
              <UButton
                :label="model?.end ? fmt(model.end) : 'Tarih seç'"
                icon="i-lucide-calendar"
                color="neutral"
                variant="outline"
                size="sm"
                class="w-full justify-start"
                :class="{ 'text-muted': !model?.end }"
              />
              <template #content>
                <UCalendar locale="tr-TR" :model-value="endCal" @update:model-value="setEnd" />
              </template>
            </UPopover>
          </div>
        </div>
      </div>
    </template>
  </UPopover>
</template>
