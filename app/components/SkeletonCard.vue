<template>
  <UCard>
    <div class="animate-pulse">
      <slot>
        <div class="flex items-start gap-4">
          <div class="rounded-lg size-11 bg-gray-200 dark:bg-gray-700 shrink-0" />
          <div class="flex-1 space-y-2.5 py-0.5">
            <div class="h-6 bg-gray-200 dark:bg-gray-700 rounded w-20" />
            <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-28" />
          </div>
        </div>
      </slot>
    </div>
  </UCard>
</template>
