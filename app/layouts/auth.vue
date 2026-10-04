<script setup lang="ts">
const { agency, fetchAgency } = useAgency()

onMounted(() => {
  fetchAgency()
})
</script>

<template>
  <div class="min-h-screen flex flex-col lg:flex-row">
    <!-- Sol Panel - Branding (sadece lg+) -->
    <div class="hidden lg:flex lg:w-1/2 bg-gradient-to-br from-primary-900 via-primary-800 to-primary-950 text-white flex-col justify-between p-12 relative overflow-hidden">
      <!-- Dekoratif daireler -->
      <div class="absolute -top-20 -right-20 w-80 h-80 bg-primary-700/30 rounded-full blur-3xl" />
      <div class="absolute -bottom-20 -left-20 w-96 h-96 bg-primary-950/50 rounded-full blur-3xl" />

      <!-- Logo -->
      <div class="relative z-10">
        <div class="flex items-center gap-3">
          <img
            v-if="agency.logo"
            :src="agency.logo"
            :alt="agency.name"
            class="h-12 w-auto object-contain"
          >
          <template v-else>
            <div class="size-11 rounded-xl bg-white/10 flex items-center justify-center backdrop-blur-sm">
              <UIcon name="i-lucide-umbrella" class="size-6" />
            </div>
            <div class="leading-tight">
              <p class="text-xl font-semibold tracking-wide">{{ agency.name }}</p>
            </div>
          </template>
        </div>
      </div>

      <!-- Slogan -->
      <div class="relative z-10">
        <h1 class="text-4xl xl:text-5xl font-bold leading-tight">
          Acentenizi
          <br>
          <span class="text-primary-300">Geleceğe Taşıyın</span>
        </h1>
        <p class="mt-6 text-primary-200/70 text-lg max-w-md leading-relaxed">
          Sigorta acentelerinin günlük işlerini kolaylaştırmak için
          tasarlanmış modern yönetim platformu.
        </p>

        <!-- Ozellik listesi -->
        <ul class="mt-10 space-y-3">
          <li v-for="item in ['Müşteri ve poliçe yönetimi', 'Gelir & prim raporları', 'Görev takibi ve hatırlatıcılar']" :key="item" class="flex items-center gap-3 text-primary-100/80 text-sm">
            <div class="size-5 rounded-full bg-primary-400/20 flex items-center justify-center shrink-0">
              <UIcon name="i-lucide-check" class="size-3 text-primary-300" />
            </div>
            {{ item }}
          </li>
        </ul>
      </div>

      <div class="relative z-10" />
    </div>

    <!-- Sag Panel - Form -->
    <div class="flex-1 flex flex-col items-center justify-center bg-gradient-to-br from-neutral-50 to-primary-50/40 px-4 sm:px-6 py-8 lg:py-0">
      <!-- Mobil Logo -->
      <div class="mb-8 lg:hidden text-center">
        <div class="flex flex-col items-center gap-1">
          <img
            v-if="agency.logo"
            :src="agency.logo"
            :alt="agency.name"
            class="h-10 w-auto object-contain"
          >
          <template v-else>
            <UIcon name="i-lucide-umbrella" class="size-8 text-primary" />
            <p class="text-lg font-semibold tracking-wide text-primary">{{ agency.name }}</p>
          </template>
        </div>
      </div>

      <div class="w-full max-w-md">
        <!-- Form Karti -->
        <div class="bg-white rounded-2xl shadow-xl shadow-neutral-200/80 border border-neutral-100 px-6 sm:px-8 py-8 sm:py-10">
          <slot />
        </div>
        <p class="text-center text-xs text-neutral-400 mt-6">
          &copy; {{ new Date().getFullYear() }} {{ agency.name }} &mdash; Tüm hakları saklıdır.
        </p>
      </div>
    </div>
  </div>
</template>
