<script setup lang="ts">
const props = defineProps<{
  title: string
  storageKey: string
  defaultOpen?: boolean
}>()

const STORAGE_PREFIX = 'home_collapse_'

const open = ref(props.defaultOpen !== false)

onMounted(() => {
  if (!import.meta.client) return
  const v = localStorage.getItem(STORAGE_PREFIX + props.storageKey)
  if (v === '0') open.value = false
  else if (v === '1') open.value = true
})

function toggle() {
  open.value = !open.value
  if (import.meta.client) {
    localStorage.setItem(STORAGE_PREFIX + props.storageKey, open.value ? '1' : '0')
  }
}
</script>

<template>
  <div>
    <button
      type="button"
      class="w-full flex items-center justify-between px-1 py-2 mb-2 text-left group"
      @click="toggle"
    >
      <span class="text-sm font-semibold text-muted uppercase tracking-wide">{{ title }}</span>
      <UIcon
        name="i-lucide-chevron-down"
        class="size-4 text-muted transition-transform"
        :class="open ? '' : '-rotate-90'"
      />
    </button>
    <div v-show="open">
      <slot />
    </div>
  </div>
</template>
