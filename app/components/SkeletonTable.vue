<script setup lang="ts">
const props = withDefaults(defineProps<{
  rows?: number
  cols?: number
}>(), {
  rows: 5,
  cols: 6
})
</script>

<template>
  <div class="animate-pulse">
    <!-- Header -->
    <div class="flex gap-4 px-3 py-3 border-b border-default">
      <div
        v-for="c in cols"
        :key="'h' + c"
        class="h-3 bg-gray-200 dark:bg-gray-700 rounded flex-1"
      />
    </div>
    <!-- Rows -->
    <div
      v-for="r in rows"
      :key="'r' + r"
      class="flex gap-4 px-3 py-4 border-b border-default last:border-b-0"
    >
      <div
        v-for="c in cols"
        :key="'c' + c"
        class="h-4 bg-gray-200 dark:bg-gray-700 rounded flex-1"
        :class="c === 1 ? 'w-32' : 'w-20'"
      />
    </div>
  </div>
</template>
