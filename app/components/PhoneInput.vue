<script setup lang="ts">
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  modelValue?: string
  label?: string
  required?: boolean
  disabled?: boolean
  modal?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const codeOptions = [
  { label: '+90 (Türkiye)', value: '+90' },
  { label: '+1 (ABD)', value: '+1' },
  { label: '+44 (İngiltere)', value: '+44' },
  { label: '+49 (Almanya)', value: '+49' },
  { label: '+33 (Fransa)', value: '+33' },
  { label: '+31 (Hollanda)', value: '+31' },
  { label: '+41 (İsviçre)', value: '+41' },
  { label: '+971 (BAE)', value: '+971' },
  { label: '+966 (S. Arabistan)', value: '+966' },
]

const countryCode = ref('+90')
const digits = ref('')

// Mevcut modelValue'dan alan kodunu ve rakamları ayır
watch(
  () => props.modelValue,
  (val = '') => {
    const match = codeOptions.find(o => val.startsWith(o.value))
    if (match) {
      countryCode.value = match.value
      let d = val.slice(match.value.length).replace(/\D/g, '')
      if (d.startsWith('0')) d = d.slice(1)
      digits.value = d.slice(0, 10)
    } else {
      let d = val.replace(/\D/g, '')
      if (d.startsWith('90') && d.length > 10) d = d.slice(2)
      if (d.startsWith('0')) d = d.slice(1)
      digits.value = d.slice(0, 10)
    }
  },
  { immediate: true }
)

// Görüntü formatı: XXX XXX XX XX
const displayValue = computed(() => {
  const d = digits.value
  const parts: string[] = []
  if (d.length > 0) parts.push(d.slice(0, 3))
  if (d.length > 3) parts.push(d.slice(3, 6))
  if (d.length > 6) parts.push(d.slice(6, 8))
  if (d.length > 8) parts.push(d.slice(8, 10))
  return parts.join(' ')
})

function onInput(val: string | number) {
  let raw = String(val).replace(/\D/g, '')
  // Yapıştırma: başındaki 0, 90 veya +90 prefixlerini temizle
  if (raw.startsWith('90') && raw.length > 10) raw = raw.slice(2)
  if (raw.startsWith('0')) raw = raw.slice(1)
  raw = raw.slice(0, 10)
  digits.value = raw
  emit('update:modelValue', raw ? countryCode.value + raw : '')
}

watch(countryCode, (val) => {
  emit('update:modelValue', digits.value ? val + digits.value : '')
})
</script>

<template>
  <div class="flex gap-2">
    <!-- Alan Kodu -->
    <div :class="['relative w-24 shrink-0 [&_.truncate]:[text-overflow:clip]', modal ? 'fl-select-form' : 'fl-select']">
      <USelectMenu
        v-model="countryCode"
        :items="codeOptions"
        value-key="value"
        label-key="label"
        :disabled="disabled"
        :search-input="{ placeholder: 'Ara...' }"
        :ui="{ content: 'min-w-[12rem]' }"
        class="w-full"
      />
      <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 text-xs text-[var(--ui-text-highlighted)] top-0 -translate-y-1/2">
        Alan Kodu
      </label>
    </div>
    <!-- Telefon Numarası -->
    <div :class="['relative flex-1', modal ? 'fl-form' : 'fl-input']">
      <UInput
        :model-value="displayValue"
        type="tel"
        placeholder=" "
        :disabled="disabled"
        class="w-full peer/fl-ph"
        @update:model-value="onInput"
      />
      <label class="pointer-events-none select-none absolute left-3 z-10 bg-[var(--ui-bg)] px-1 transition-all duration-150 ease-in-out text-sm text-[var(--ui-text-muted)] top-1/2 -translate-y-1/2 peer-focus-within/fl-ph:top-0 peer-focus-within/fl-ph:-translate-y-1/2 peer-focus-within/fl-ph:text-xs peer-focus-within/fl-ph:text-[var(--ui-primary)] peer-has-[input:not(:placeholder-shown)]/fl-ph:top-0 peer-has-[input:not(:placeholder-shown)]/fl-ph:-translate-y-1/2 peer-has-[input:not(:placeholder-shown)]/fl-ph:text-xs peer-has-[input:not(:placeholder-shown)]/fl-ph:text-[var(--ui-text-highlighted)]">
        {{ label || 'Telefon' }}<span v-if="required" class="text-[var(--ui-error)]"> *</span>
      </label>
    </div>
  </div>
</template>
