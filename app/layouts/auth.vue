<script setup lang="ts">
const { agency, fetchAgency } = useAgency()

onMounted(() => {
  fetchAgency()
})
</script>

<template>
  <div class="h-screen flex">
    <!-- Sol Panel - Branding -->
    <div class="hidden lg:flex lg:w-1/2 bg-gradient-to-br from-blue-900 via-blue-800 to-blue-950 text-white flex-col justify-between p-12 relative overflow-hidden">
      <!-- Dekoratif daireler -->
      <div class="absolute -top-20 -right-20 w-80 h-80 bg-blue-700/30 rounded-full blur-3xl" />
      <div class="absolute -bottom-20 -left-20 w-96 h-96 bg-blue-950/50 rounded-full blur-3xl" />

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
              <p class="text-xl font-bold tracking-wide">{{ agency.name }}</p>
            </div>
          </template>
        </div>
      </div>

      <!-- Slogan -->
      <div class="relative z-10">
        <h1 class="text-4xl xl:text-5xl font-bold leading-tight">
          Acentenizi
          <br>
          <span class="text-blue-300">Geleceğe Taşıyın</span>
        </h1>
        <p class="mt-6 text-blue-200/70 text-lg max-w-md leading-relaxed">
          Sigorta acentelerinin günlük işlerini kolaylaştırmak için
          tasarlanmış modern yönetim platformu.
        </p>

        <!-- Özellik listesi -->
        <ul class="mt-10 space-y-3">
          <li v-for="item in ['Müşteri ve poliçe yönetimi', 'Gelir & prim raporları', 'Görev takibi ve hatırlatıcılar']" :key="item" class="flex items-center gap-3 text-blue-100/80 text-sm">
            <div class="size-5 rounded-full bg-blue-400/20 flex items-center justify-center shrink-0">
              <UIcon name="i-lucide-check" class="size-3 text-blue-300" />
            </div>
            {{ item }}
          </li>
        </ul>
      </div>

      <!-- Copyright -->
      <p class="relative z-10 text-blue-400/50 text-sm">
        &copy; {{ new Date().getFullYear() }} {{ agency.name }}
      </p>
    </div>

    <!-- Sağ Panel - Form -->
    <div class="w-full lg:w-1/2 flex items-center justify-center bg-gradient-to-br from-slate-50 to-blue-50/40 px-6">
      <!-- Mobil Logo -->
      <div class="absolute top-8 left-1/2 -translate-x-1/2 lg:hidden">
        <div class="flex flex-col items-center gap-1">
          <img
            v-if="agency.logo"
            :src="agency.logo"
            :alt="agency.name"
            class="h-10 w-auto object-contain"
          >
          <template v-else>
            <UIcon name="i-lucide-umbrella" class="size-8 text-primary" />
            <p class="text-lg font-bold tracking-wide text-primary">{{ agency.name }}</p>
          </template>
        </div>
      </div>

      <div class="w-full max-w-sm">
        <!-- Form Kartı -->
        <div class="bg-white rounded-2xl shadow-xl shadow-slate-200/80 border border-slate-100 px-8 py-10">
          <slot />
        </div>
        <p class="text-center text-xs text-slate-400 mt-6">
          &copy; {{ new Date().getFullYear() }} {{ agency.name }} &mdash; Tüm hakları saklıdır.
        </p>
      </div>
    </div>
  </div>
</template>
