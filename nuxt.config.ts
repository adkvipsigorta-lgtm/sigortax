export default defineNuxtConfig({
  ssr: false,

  modules: [
    '@nuxt/eslint',
    '@nuxt/ui',
    '@vueuse/nuxt'
  ],

  devtools: {
    enabled: true
  },

  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    public: {
      socketUrl: process.env.NUXT_PUBLIC_SOCKET_URL || 'https://sigorta-socket.onrender.com'
    }
  },

  devServer: {
    port: 3000
  },

  nitro: {
    devProxy: {
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true
      }
    }
  },

  vite: {
    optimizeDeps: {
      include: ['@internationalized/date']
    }
  },

  compatibilityDate: '2025-01-15',

  eslint: {
    config: {
      stylistic: {
        commaDangle: 'never',
        braceStyle: '1tbs'
      }
    }
  }
})
