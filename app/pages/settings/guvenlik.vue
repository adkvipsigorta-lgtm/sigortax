<script setup lang="ts">
definePageMeta({
  layout: 'default',
  middleware: 'auth'
})

// Eski güvenlik route'u — yeni birleşik sayfaya yönlendir
navigateTo('/settings#guvenlik', { replace: true })
</script>

<template>
  <div />
</template>
