<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

// Offers are now managed through tasks (görevler)
onMounted(() => {
  navigateTo('/gorevler', { replace: true })
})
</script>

<template>
  <div class="flex items-center justify-center py-20">
    <div class="text-center space-y-3">
      <UIcon name="i-lucide-arrow-right-circle" class="size-10 text-muted mx-auto" />
      <p class="text-muted">Teklifler artik görevler uzerinden yonetilmektedir.</p>
      <NuxtLink to="/gorevler">
        <UButton label="Görevlere Git" icon="i-lucide-arrow-right" />
      </NuxtLink>
    </div>
  </div>
</template>
