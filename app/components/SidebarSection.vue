<script setup lang="ts">
const route = useRoute()

interface SidebarItem {
  label: string
  to: string
  icon?: string
  badge?: number
  color?: string | null
  isDefault?: boolean
  exact?: boolean
}

const props = withDefaults(defineProps<{
  title: string
  items: SidebarItem[]
  collapsible?: boolean
}>(), {
  collapsible: false
})

const collapsed = ref(false)

function isItemActive(item: SidebarItem) {
  if (item.exact) return route.path === item.to
  return route.fullPath === item.to || route.path === item.to
}
</script>

<template>
  <div>
    <!-- Seçtion header -->
    <button
      class="flex items-center justify-between w-full px-2 mb-1"
      :class="collapsible ? 'cursor-pointer' : 'cursor-default'"
      @click="collapsible && (collapsed = !collapsed)"
    >
      <span class="text-[10px] font-semibold text-muted uppercase tracking-wider">
        {{ title }}
      </span>
      <UIcon
        v-if="collapsible"
        name="i-lucide-chevron-up"
        class="size-3.5 text-muted transition-transform"
        :class="collapsed ? 'rotate-180' : ''"
      />
    </button>

    <!-- Items -->
    <nav v-show="!collapsed" class="space-y-px">
      <NuxtLink
        v-for="item in items"
        :key="item.to"
        :to="item.to"
        class="flex items-center gap-2 px-2.5 py-1 rounded-md text-[13px] transition-colors"
        :class="isItemActive(item)
          ? 'bg-primary/10 text-primary font-medium'
          : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800'"
      >
        <!-- Renk noktasi: dolu veya outline (default) -->
        <div
          v-if="item.color"
          class="size-3 rounded-full shrink-0"
          :style="{ backgroundColor: item.color }"
        />
        <div
          v-else-if="item.isDefault"
          class="size-3 rounded-full shrink-0 border-2 border-gray-300 dark:border-gray-500"
        />
        <!-- Icon -->
        <UIcon
          v-else-if="item.icon"
          :name="item.icon"
          class="size-3.5 shrink-0"
        />

        <span class="flex-1 overflow-hidden whitespace-nowrap">{{ item.label }}</span>

        <span
          v-if="item.badge !== undefined"
          class="text-xs text-muted "
        >
          {{ item.badge }}
        </span>
      </NuxtLink>
    </nav>
  </div>
</template>
