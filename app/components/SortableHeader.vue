<script setup lang="ts">
const props = defineProps<{
  label: string
  column: any
}>()

const isSorted = computed(() => props.column.getIsSorted())
</script>

<template>
  <button
    class="flex items-center gap-1 w-full text-left cursor-pointer select-none"
    @click.stop.prevent="column.toggleSorting()"
  >
    <span>{{ label }}</span>
    <UIcon
      v-if="isSorted === 'asc'"
      name="i-lucide-arrow-up"
      class="size-3.5 text-primary"
    />
    <UIcon
      v-else-if="isSorted === 'desc'"
      name="i-lucide-arrow-down"
      class="size-3.5 text-primary"
    />
    <UIcon
      v-else
      name="i-lucide-arrow-up-down"
      class="size-3.5 text-muted opacity-40"
    />
  </button>
</template>
