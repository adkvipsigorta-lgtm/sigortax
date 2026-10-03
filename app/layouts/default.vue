<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

const route = useRoute()
const router = useRouter()
const isNavigating = ref(false)

router.beforeEach(() => { isNavigating.value = true })
router.afterEach(() => { nextTick(() => { isNavigating.value = false }) })

const { user, logout } = useAuth()
const { agency, fetchAgency } = useAgency()
const { categories, totalCustomers: totalCustomerCount, fetchCategories } = useCustomerCategories()
const { subcategories, groupedSubcategories, fetchInsurances } = useInsuranceTypes()
const { notifications, unreadCount, fetchNotifications, markAsRead, markAllRead } = useNotifications()
const { get: apiGet } = useApi()
const { emit: wsEmit } = useSessionStream()
// Bildirim sesi — DOM audio elementi kullanarak
const notifAudioRef = ref<HTMLAudioElement | null>(null)
let soundLooping = false
let soundPlayCount = 0
let soundMaxPlays = 0

function getSoundPref(): string {
  return localStorage.getItem('notif_sound_pref') || 'loop'
}

function playNotifSound() {
  const pref = getSoundPref()
  if (pref === 'off') {
    console.log('[SES] Pref=off, ses kapalı')
    return
  }

  stopNotifSound()
  soundPlayCount = 0
  soundLooping = true
  soundMaxPlays = pref === 'önce' ? 1 : pref === 'twice' ? 2 : 0

  console.log('[SES] playNotifSound çağrıldı, pref=' + pref)
  doPlay()
}

function doPlay() {
  // Test butonuyla aynı yöntem
  const a = new Audio('/notification.mp3')
  a.volume = 0.7
  a.onended = onNotifAudioEnded
  a.play().then(() => {
    console.log('[SES] Çalıyor!')
  }).catch((e) => {
    console.error('[SES] HATA:', e.message)
  })
}

function onNotifAudioEnded() {
  soundPlayCount++
  if (soundLooping) {
    if (soundMaxPlays > 0 && soundPlayCount >= soundMaxPlays) {
      soundLooping = false
      return
    }
    setTimeout(() => { if (soundLooping) doPlay() }, 2000)
  }
}

function stopNotifSound() {
  soundLooping = false
  soundPlayCount = 0
  const el = notifAudioRef.value
  if (el) {
    el.pause()
    el.currentTime = 0
  }
}
const { loadPermissions, can } = usePermissions()
const mobileMenuOpen = ref(false)

// Sidebar aç/kapat — localStorage'a kaydet
const sidebarOpen = ref(
  import.meta.client ? localStorage.getItem('sidebar_open') !== 'false' : true
)
function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
  if (import.meta.client) localStorage.setItem('sidebar_open', String(sidebarOpen.value))
}

// Sidebar menü arama
const sidebarSearch = ref('')

const allMenuItems = computed(() => [
  // Ana sayfalar
  { label: 'Ana Sayfa', icon: 'i-lucide-layout-dashboard', to: '/' },
  can('customers.view') && { label: 'Müşteriler', icon: 'i-lucide-users', to: '/musteriler' },
  can('policies.view') && { label: 'Poliçeler', icon: 'i-lucide-shield-check', to: '/policeler' },
  can('policies.view') && { label: 'Günlük Aktivite', icon: 'i-lucide-calendar-days', to: '/gunluk-aktivite' },
  can('tasks.view') && { label: 'Görevler', icon: 'i-lucide-calendar-check', to: '/gorevler' },
  can('messages.view') && { label: 'Mesajlar', icon: 'i-lucide-send', to: '/mesajlar' },
  can('lost_policies.view') && { label: 'Kaçırılan Poliçeler', icon: 'i-lucide-user-x', to: '/kacirilan-policeler' },
  can('performance.view') && { label: 'Aylık Satış Performansı', icon: 'i-lucide-trending-up', to: '/satis-performansi' },
  can('portfolio.view') && { label: 'Portföyüm', icon: 'i-lucide-briefcase', to: '/portfolyo' },
  can('reports.view') && { label: 'Raporlar', icon: 'i-lucide-bar-chart-3', to: '/raporlar' },
  { label: 'Acentemiz Hakkında', icon: 'i-lucide-building-2', to: '/acentemiz-hakkinda' },
  // Araçlar alt sayfaları
  can('tools.cross_sell') && { label: 'Çapraz Satış', icon: 'i-lucide-repeat-2', to: '/araclar/capraz-satis' },
  can('tools.reconciliation') && { label: 'Mutabakat', icon: 'i-lucide-file-check', to: '/araclar/mutabakat' },
  can('tools.reconciliation') && { label: 'Temsilci Mutabakat', icon: 'i-lucide-user-check', to: '/araclar/calisan-mutabakat' },
  can('tools.allianz_import') && { label: 'Allianz Import', icon: 'i-lucide-file-up', to: '/araclar/allianz-import' },
  can('tools.excel_import') && { label: 'Excel Import', icon: 'i-lucide-file-spreadsheet', to: '/araclar/excel-import' },
  // Müşteriler alt sayfaları
  can('customers.view') && { label: 'Grup Yönetimi', icon: 'i-lucide-settings', to: '/musteriler/gruplar' },
  // Ayarlar
  { label: 'Profil', icon: 'i-lucide-user', to: '/settings' },
  { label: 'Güvenlik', icon: 'i-lucide-shield-check', to: '/settings#guvenlik' },
  { label: 'Bildirimler', icon: 'i-lucide-bell', to: '/settings/bildirimler' },
  { label: 'Oturumlar', icon: 'i-lucide-log-in', to: '/settings/oturumlar' },
  can('settings.companies') && { label: 'Sigorta Şirketleri', icon: 'i-lucide-building-2', to: '/settings/sirketler' },
  can('settings.insurance_types') && { label: 'Poliçe Türleri', icon: 'i-lucide-shield', to: '/settings/sigorta-turleri' },
  can('settings.references') && { label: 'Referans Kaynakları', icon: 'i-lucide-link', to: '/settings/referans-kaynaklari' },
  can('settings.follow_up') && { label: 'Takip Aramaları', icon: 'i-lucide-phone-call', to: '/settings/takip-aramalari' },
  can('settings.users') && { label: 'Takım Yönetimi', icon: 'i-lucide-users', to: '/settings/kullanicilar' },
  can('settings.branches') && { label: 'Tali Acenteler', icon: 'i-lucide-handshake', to: '/settings/acenteler' },
].filter(Boolean) as { label: string; icon: string; to: string }[])

const filteredMenuItems = computed(() => {
  const q = sidebarSearch.value.trim().toLocaleLowerCase('tr')
  if (!q) return []
  return allMenuItems.value.filter(item =>
    item.label.toLocaleLowerCase('tr').includes(q)
  )
})
const notifOpen = ref(false)
const bellRinging = ref(false)

// Socket.IO: yeni bildirim geldiginde bildirimleri yenile ve zili çaldır
const wsNewNotif = useState<number>('ws-new-notification', () => 0)
watch(wsNewNotif, () => {
  fetchNotifications()
  bellRinging.value = true
  playNotifSound()
})

// Bildirimler okunursa zili ve sesi durdur
watch(unreadCount, (val) => {
  if (val === 0) {
    bellRinging.value = false
    stopNotifSound()
  }
})

// Bildirim paneli acilinca 1 saniye sonra hepsini okundu yap
watch(notifOpen, (val) => {
  if (val && unreadCount.value > 0) {
    setTimeout(() => {
      markAllRead()
      wsEmit('notifications-read')
    }, 1000)
  }
})

// Socket.IO: baska ekranda bildirimler okundugunda burada da güncelle
const wsNotifsRead = useState<number>('ws-notifications-read', () => 0)
watch(wsNotifsRead, () => {
  fetchNotifications()
  bellRinging.value = false
  stopNotifSound()
})

const allNotificationsDisabled = useState('all-notifs-disabled', () => false)

async function checkNotificationSettings() {
  try {
    const res = await apiGet('settings')
    const ns = res.data?.notification_settings
    if (ns && typeof ns === 'object') {
      const values = Object.values(ns)
      allNotificationsDisabled.value = values.length > 0 && values.every((v: any) => !v)
    }
  } catch {}
}

onMounted(async () => {
  fetchAgency()
  fetchCategories()
  fetchInsurances()
  await fetchNotifications()
  if (unreadCount.value > 0) {
    bellRinging.value = true
    playNotifSound()
  }
  checkNotificationSettings()
  loadPermissions()
})

function getNotifIcon(type: string) {
  if (type === 'success') return 'i-lucide-check-circle'
  if (type === 'warning') return 'i-lucide-alert-triangle'
  if (type === 'error') return 'i-lucide-x-circle'
  return 'i-lucide-info'
}

function getNotifColor(type: string) {
  if (type === 'success') return 'text-green-500'
  if (type === 'warning') return 'text-orange-500'
  if (type === 'error') return 'text-red-500'
  return 'text-blue-500'
}

function timeAgo(date: string) {
  const now = new Date()
  const d = new Date(date)
  const diff = Math.floor((now.getTime() - d.getTime()) / 1000)
  if (diff < 60) return 'Az önce'
  if (diff < 3600) return Math.floor(diff / 60) + ' dk önce'
  if (diff < 86400) return Math.floor(diff / 3600) + ' saat önce'
  if (diff < 604800) return Math.floor(diff / 86400) + ' gün önce'
  return d.toLocaleDateString('tr-TR')
}

// Global Arama
const searchOpen = useState('global-search-open', () => false)
const aiCoachEnabled = useState('ai-coach-enabled', () => false)
const showAiCoach = useState('ai-coach-open', () => false)
const showAiCoachPromo = ref(false)

// AI Coach status check
apiGet<any>('ai-coach/status').then(res => {
  const d = res.data || res
  aiCoachEnabled.value = d.enabled && d.hasApiKey
}).catch(() => {})
const searchQuery = ref('')
const searchCustomers = ref<any[]>([])
const searchPolicies = ref<any[]>([])
const searchLoading = ref(false)
let searchDebounce: ReturnType<typeof setTimeout> | null = null

async function doSearch(q: string) {
  if (q.length < 2) {
    searchCustomers.value = []
    searchPolicies.value = []
    return
  }
  searchLoading.value = true
  try {
    const res = await apiGet('dashboard/search', { q })
    // Kullanıcı bu arada başka şey yazdıysa eski sonucu yok say
    if (searchQuery.value !== q) return
    const d = res.data || res
    searchCustomers.value = d.customers || []
    searchPolicies.value = d.policies || []
  } catch (err) {
    console.error('[Search] error:', err)
    searchCustomers.value = []
    searchPolicies.value = []
  } finally {
    searchLoading.value = false
  }
}

// Debounced watcher - input v-model ile bağlı, takılma olmaz
watch(searchQuery, (val) => {
  if (searchDebounce) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => doSearch(val), 300)
})

function goToCustomer(id: number) {
  searchOpen.value = false
  searchQuery.value = ''
  navigateTo(`/musteriler/${id}`)
}

function goToPolicy(customerId: number) {
  searchOpen.value = false
  searchQuery.value = ''
  navigateTo(`/musteriler/${customerId}`)
}

// Arama acilinca input'a focusla
const searchInputRef = ref<HTMLInputElement | null>(null)
watch(searchOpen, (val) => {
  if (val) {
    nextTick(() => searchInputRef.value?.focus())
  } else {
    searchQuery.value = ''
    searchCustomers.value = []
    searchPolicies.value = []
  }
})

// Cmd+K kisayolu
if (import.meta.client) {
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
      e.preventDefault()
      searchOpen.value = true
    }
    if (e.key === 'Escape') {
      searchOpen.value = false
    }
  })
}

// Mini Takvim (sidebar, sadece ana sayfa)
const calendarMonth = ref(new Date().getMonth())
const calendarYear = ref(new Date().getFullYear())
const taskCountsByDate = ref<Record<string, number>>({})

async function fetchTaskCounts() {
  try {
    const res = await apiGet('dashboard/task-counts', {
      year: String(calendarYear.value),
      month: String(calendarMonth.value + 1)
    })
    taskCountsByDate.value = (res.data || res) as Record<string, number>
  } catch {}
}

const calendarDays = computed(() => {
  const y = calendarYear.value
  const m = calendarMonth.value
  const firstDay = new Date(y, m, 1).getDay()
  const daysInMonth = new Date(y, m + 1, 0).getDate()
  const startOffset = firstDay === 0 ? 6 : firstDay - 1
  const days: { day: number | null, date: string, isToday: boolean }[] = []
  for (let i = 0; i < startOffset; i++) days.push({ day: null, date: '', isToday: false })
  const now = new Date()
  const todayStr = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    days.push({ day: d, date: dateStr, isToday: dateStr === todayStr })
  }
  return days
})

const calendarMonthLabel = computed(() => {
  const months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık']
  return `${months[calendarMonth.value]} ${calendarYear.value}`
})

function prevMonth() {
  if (calendarMonth.value === 0) { calendarMonth.value = 11; calendarYear.value-- }
  else calendarMonth.value--
  fetchTaskCounts()
}

function nextMonth() {
  if (calendarMonth.value === 11) { calendarMonth.value = 0; calendarYear.value++ }
  else calendarMonth.value++
  fetchTaskCounts()
}

watch(() => route.path, (path) => {
  if (path === '/') fetchTaskCounts()
}, { immediate: true })

const isTaskFormOpen = ref(false)
const { customerModal, policyModal, openCustomerModal, openPolicyModal } = useGlobalModals()

const isMobile = ref(false)
onMounted(() => { isMobile.value = window.innerWidth < 640 })

const addMenuItems = computed(() => [
  [
    ...(!isMobile.value ? [{ label: 'Yeni Poliçe', icon: 'i-lucide-shield-plus', onSelect: () => openPolicyModal({ onSaved: () => navigateTo('/policeler') }) }] : []),
    { label: 'Yeni Müşteri', icon: 'i-lucide-user-plus', onSelect: () => openCustomerModal({ onSaved: () => navigateTo('/musteriler') }) }
  ],
  [
    { label: 'Yeni Teklif / Görev', icon: 'i-lucide-calendar-plus', onSelect: () => { isTaskFormOpen.value = true } }
  ]
])

const userInitials = computed(() => {
  if (!user.value?.name) return '?'
  return user.value.name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
})

const userMenuItems = computed(() => [
  [{ label: user.value?.name || '', type: 'label' as const }],
  [
    { label: 'Profil', icon: 'i-lucide-user', to: '/settings' }
  ],
  [{ label: 'Çıkış Yap', icon: 'i-lucide-log-out', onSelect: () => logout() }]
])

// Icon Rail navigasyon
const navItems = computed<NavigationMenuItem[]>(() => [
  { label: 'Ana Sayfa', icon: 'i-lucide-layout-dashboard', to: '/' },
  can('customers.view') && { label: 'Müşteriler', icon: 'i-lucide-users', to: '/musteriler' },
  can('policies.view') && { label: 'Poliçeler', icon: 'i-lucide-shield-check', to: '/policeler' },
  can('tasks.view') && { label: 'Görevler', icon: 'i-lucide-calendar-check', to: '/gorevler' },
  can('reports.view') && { label: 'Raporlar', icon: 'i-lucide-bar-chart-3', to: '/raporlar' },
  { label: 'Araçlar', icon: 'i-lucide-wrench', to: '/araclar' }
].filter(Boolean) as NavigationMenuItem[])

const bottomNavItems: NavigationMenuItem[] = [
  { label: 'Ayarlar', icon: 'i-lucide-settings', to: '/settings' }
]

const mobileMenuItems = computed(() => [
  { label: 'Ana Sayfa', icon: 'i-lucide-layout-dashboard', to: '/' },
  can('customers.view') && { label: 'Müşteriler', icon: 'i-lucide-users', to: '/musteriler' },
  can('policies.view') && { label: 'Poliçeler', icon: 'i-lucide-shield-check', to: '/policeler' },
  can('tasks.view') && { label: 'Görevler', icon: 'i-lucide-calendar-check', to: '/gorevler' },
  can('portfolio.view') && { label: 'Portföyüm', icon: 'i-lucide-briefcase', to: '/portfolyo' },
  { label: 'Acentemiz Hakkında', icon: 'i-lucide-building-2', to: '/acentemiz-hakkinda' },
].filter(Boolean) as NavigationMenuItem[])

// Sidebar icerik tanimlari (sayfa bazli)
const sidebarConfig = computed(() => {
  const path = route.path

  if (path === '/') {
    return {
      title: 'Hızlı Erişim',
      items: [
        can('customers.view') && { label: 'Müşteriler', icon: 'i-lucide-users', to: '/musteriler' },
        can('policies.view') && { label: 'Poliçeler', icon: 'i-lucide-shield-check', to: '/policeler' },
        can('tasks.view') && { label: 'Görevler', icon: 'i-lucide-calendar-check', to: '/gorevler' },
        can('leads.view') && { label: 'Lead Yönetimi', icon: 'i-lucide-target', to: '/leadler' },
        can('policies.view') && { label: 'Günlük Aktivite', icon: 'i-lucide-calendar-days', to: '/gunluk-aktivite' },
        can('lost_policies.view') && { label: 'Kaçırılan Poliçeler', icon: 'i-lucide-user-x', to: '/kacirilan-policeler' },
        can('reports.view') && { label: 'Raporlar', icon: 'i-lucide-bar-chart-3', to: '/raporlar' },
        can('performance.view') && { label: 'Aylık Satış Performansı', icon: 'i-lucide-trending-up', to: '/satis-performansi' },
        can('portfolio.view') && { label: 'Portföyüm', icon: 'i-lucide-briefcase', to: '/portfolyo' },
        { label: 'Araçlar', icon: 'i-lucide-wrench', to: '/araclar' },
        { label: 'Acentemiz Hakkında', icon: 'i-lucide-building-2', to: '/acentemiz-hakkinda' }
      ].filter(Boolean)
    }
  }

  if (path.startsWith('/musteriler')) {
    const totalCustomers = totalCustomerCount.value
    return {
      title: 'Hızlı Erişim',
      collapsible: true,
      items: [
        { label: 'Tüm Müşteriler', icon: 'i-lucide-list', to: '/musteriler', exact: true, badge: totalCustomers },
        ...categories.value.map(c => ({
          label: c.name,
          to: `/musteriler?categoryId=${c.id}`,
          badge: c.customerCount ?? 0,
          color: c.isDefault ? null : c.color,
          isDefault: c.isDefault
        }))
      ],
      groups: [
        {
          title: 'İşlemler',
          collapsible: true,
          items: [
            { label: 'Grup Yönetimi', icon: 'i-lucide-settings', to: '/musteriler/gruplar' }
          ]
        }
      ]
    }
  }

  if (path.startsWith('/policeler')) {
    const groupMeta: Record<string, { label: string, icon: string, order: number }> = {
      'KASKO': { label: 'Araç', icon: 'i-lucide-car', order: 1 },
      'TRAFİK': { label: 'Araç', icon: 'i-lucide-car', order: 1 },
      'SAĞLIK': { label: 'Sağlık', icon: 'i-lucide-heart-pulse', order: 2 },
      'KONUT': { label: 'Gayrimenkul', icon: 'i-lucide-home', order: 3 },
      'SEVİMLİ DOSTUM': { label: 'Diğer', icon: 'i-lucide-file-text', order: 4 },
      'DİĞER': { label: 'Diğer', icon: 'i-lucide-file-text', order: 4 }
    }

    // Merge DB groups into display groups
    const merged: Record<string, { label: string, icon: string, order: number, items: typeof subcategories.value }> = {}
    for (const i of subcategories.value) {
      const meta = groupMeta[i.branchGroup || 'DİĞER'] || groupMeta['DİĞER']
      if (!merged[meta.label]) {
        merged[meta.label] = { ...meta, items: [] }
      }
      merged[meta.label].items.push(i)
    }

    const groups = Object.values(merged)
      .sort((a, b) => a.order - b.order)
      .map(g => ({
        title: g.label,
        collapsible: true,
        items: g.items
          .sort((a, b) => a.name.localeCompare(b.name, 'tr'))
          .map(i => ({
            label: i.name.split(' ').map((w: string) => w.charAt(0).toLocaleUpperCase('tr-TR') + w.slice(1).toLocaleLowerCase('tr-TR')).join(' '),
            icon: g.icon,
            to: `/policeler?insuranceId=${i.id}`
          }))
      }))

    return {
      title: 'Poliçeler',
      items: [
        { label: 'Tüm Poliçeler', icon: 'i-lucide-list', to: '/policeler', exact: true }
      ],
      groups
    }
  }

  if (path.startsWith('/araclar')) {
    return {
      title: 'Araçlar',
      items: [
        can('tools.cross_sell') && { label: 'Çapraz Satış', icon: 'i-lucide-repeat-2', to: '/araclar/capraz-satis' },
        can('tools.reconciliation') && { label: 'Mutabakat', icon: 'i-lucide-file-check', to: '/araclar/mutabakat' },
        can('tools.reconciliation') && { label: 'Temsilci Mutabakat', icon: 'i-lucide-user-check', to: '/araclar/calisan-mutabakat' },
        can('tools.allianz_import') && { label: 'Allianz Import', icon: 'i-lucide-file-up', to: '/araclar/allianz-import' },
        can('tools.excel_import') && { label: 'Excel Import', icon: 'i-lucide-file-spreadsheet', to: '/araclar/excel-import' }
      ].filter(Boolean)
    }
  }

  if (path.startsWith('/settings')) {
    const genelItems = [
      can('settings.companies') && { label: 'Sigorta Şirketleri', icon: 'i-lucide-building-2', to: '/settings/sirketler' },
      can('settings.insurance_types') && { label: 'Poliçe Türleri', icon: 'i-lucide-shield', to: '/settings/sigorta-turleri' },
      can('settings.references') && { label: 'Referans Kaynakları', icon: 'i-lucide-link', to: '/settings/referans-kaynaklari' },
      can('settings.follow_up') && { label: 'Varsayılan Değerler', icon: 'i-lucide-sliders-horizontal', to: '/settings/alan-ayarlari' },
      can('settings.follow_up') && { label: 'Takip Aramaları', icon: 'i-lucide-phone-call', to: '/settings/takip-aramalari' },
      can('leads.settings') && { label: 'Lead Kaynakları', icon: 'i-lucide-globe', to: '/settings/lead-kaynaklari' },
      can('leads.settings') && { label: 'Lead Ürünleri', icon: 'i-lucide-package', to: '/settings/lead-urunleri' },
      can('leads.settings') && { label: 'Lead Atama Ayarları', icon: 'i-lucide-timer', to: '/settings/lead-atama' },
    ].filter(Boolean)

    const takimItems = [
      can('settings.users') && { label: 'Takım Yönetimi', icon: 'i-lucide-users', to: '/settings/kullanicilar' },
      can('settings.branches') && { label: 'Tali Acenteler', icon: 'i-lucide-handshake', to: '/settings/acenteler' },
    ].filter(Boolean)

    return {
      title: 'Ayarlar',
      groups: [
        ...(genelItems.length > 0 ? [{ title: 'Genel', items: genelItems }] : []),
        {
          title: 'Hesap',
          items: [
            { label: 'Profil', icon: 'i-lucide-user', to: '/settings', exact: true },
            ...((!allNotificationsDisabled.value || user.value?.role === 'admin') ? [{ label: 'Bildirimler', icon: 'i-lucide-bell', to: '/settings/bildirimler' }] : []),
            { label: 'Oturumlar', icon: 'i-lucide-log-in', to: '/settings/oturumlar' }
          ]
        },
        ...(takimItems.length > 0 ? [{ title: 'Takım', items: takimItems }] : []),
        ...(user.value?.role === 'admin' ? [{
          title: 'Acente Bilgileri',
          items: [
            { label: 'Acente Bilgileri', icon: 'i-lucide-building', to: '/settings/acente' }
          ]
        }] : []),
        ...(user.value?.role === 'admin' ? [{
          title: 'Sistem',
          items: [
            { label: 'Güncellemeler', icon: 'i-lucide-download-cloud', to: '/settings/guncelleme' }
          ]
        }] : [])
      ]
    }
  }

  // Diğer sayfalar için genel sidebar
  return {
    title: 'Hızlı Erişim',
    items: [
      { label: 'Ana Sayfa', icon: 'i-lucide-layout-dashboard', to: '/' },
      can('customers.view') && { label: 'Müşteriler', icon: 'i-lucide-users', to: '/musteriler' },
      can('policies.view') && { label: 'Poliçeler', icon: 'i-lucide-shield-check', to: '/policeler' },
      can('tasks.view') && { label: 'Görevler', icon: 'i-lucide-calendar-check', to: '/gorevler' },
      can('leads.view') && { label: 'Lead Yönetimi', icon: 'i-lucide-target', to: '/leadler' },
      can('policies.view') && { label: 'Günlük Aktivite', icon: 'i-lucide-calendar-days', to: '/gunluk-aktivite' },
      can('lost_policies.view') && { label: 'Kaçırılan Poliçeler', icon: 'i-lucide-user-x', to: '/kacirilan-policeler' },
      can('reports.view') && { label: 'Raporlar', icon: 'i-lucide-bar-chart-3', to: '/raporlar' },
      can('performance.view') && { label: 'Aylık Satış Performansı', icon: 'i-lucide-trending-up', to: '/satis-performansi' },
      can('portfolio.view') && { label: 'Portföyüm', icon: 'i-lucide-briefcase', to: '/portfolyo' },
      { label: 'Araçlar', icon: 'i-lucide-wrench', to: '/araclar' },
      { label: 'Acentemiz Hakkında', icon: 'i-lucide-building-2', to: '/acentemiz-hakkinda' }
    ].filter(Boolean)
  }
})

function goHome() {
  if (route.path === '/') {
    window.location.reload()
  } else {
    navigateTo('/')
  }
}

// Breadcrumb
const breadcrumbItems = computed(() => {
  const items = [{ label: 'Ana Sayfa', to: '/', icon: 'i-lucide-house' }]
  const path = route.path

  const pageNames: Record<string, string> = {
    '/musteriler': 'Müşteriler',
    '/musteriler/gruplar': 'Müşteri Grupları',
    '/policeler': 'Poliçeler',
    '/yenileme': 'Yenileme',
    '/hatirlatmalar': 'Hatırlatmalar',
    '/gorevler': 'Görevler',
    '/mesajlar': 'Mesajlar',
    '/raporlar': 'Raporlar',
    '/portfolyo': 'Portföyüm',
    '/gunluk-aktivite': 'Günlük Aktivite',
    '/kacirilan-policeler': 'Kaçırılan Poliçeler',
    '/satis-performansi': 'Aylık Satış Performansı',
    '/acentemiz-hakkinda': 'Acentemiz Hakkında',
    '/settings': 'Ayarlar',
    '/settings/sirketler': 'Sigorta Şirketleri',
    '/settings/sigorta-turleri': 'Poliçe Türleri',
    '/settings/acenteler': 'Tali Acenteler',
    '/settings/kullanicilar': 'Takım Yönetimi',
    '/settings#guvenlik': 'Güvenlik',
    '/settings/oturumlar': 'Oturumlar',
    '/settings/guncelleme': 'Güncellemeler',
    '/settings/referans-kaynaklari': 'Referans Kaynakları',
    '/settings/alan-ayarlari': 'Varsayılan Değerler',
    '/settings/acente': 'Acente Bilgileri',
    '/settings/takip-aramalari': 'Takip Aramaları',
    '/araclar': 'Araçlar',
    '/araclar/capraz-satis': 'Çapraz Satış',
    '/araclar/mutabakat': 'Mutabakat',
    '/araclar/calisan-mutabakat': 'Temsilci Mutabakat',
    '/araclar/allianz-import': 'Allianz Import',
    '/araclar/excel-import': 'Excel Import'
  }

  if (path !== '/') {
    // Araçlar alt sayfalari için parent ekle
    if (path.startsWith('/araclar/')) {
      items.push({ label: 'Araçlar', to: '/araclar', icon: undefined as any })
    }
    // Settings alt sayfalari için parent ekle
    if (path.startsWith('/settings/')) {
      items.push({ label: 'Ayarlar', to: '/settings', icon: undefined as any })
    }
    // Müşteriler alt sayfalari için parent ekle
    if (path.startsWith('/musteriler/')) {
      items.push({ label: 'Müşteriler', to: '/musteriler', icon: undefined as any })
    }
    // Dinamik id sayfasi kontrolu
    if (path.match(/^\/musteriler\/\d+$/)) {
      items.push({ label: 'Müşteri Detay', to: path, icon: undefined as any })
    } else {
      items.push({ label: pageNames[path] || 'Sayfa', to: path, icon: undefined as any })
    }
  }

  return items
})

// Sayfa başlığı
const pageTitle = computed(() => {
  const last = breadcrumbItems.value[breadcrumbItems.value.length - 1]
  return last?.label || 'Ana Sayfa'
})

function isActive(to: string) {
  if (to === '/') return route.path === '/'
  return route.path.startsWith(to)
}

watch(() => route.path, () => {
  mobileMenuOpen.value = false
})
</script>

<template>
  <div class="flex h-screen overflow-hidden bg-gray-50 dark:bg-gray-950">
    <!-- Icon Rail -->
    <nav class="hidden lg:flex w-[60px] bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 flex-col items-center shrink-0 pb-4">
      <!-- h-16 header — sidebar ve main header border-b ile hizalı -->
      <div class="h-16 w-full flex items-center justify-center border-b border-gray-200 dark:border-gray-800 shrink-0">
        <UTooltip :text="sidebarOpen ? 'Menüyü Gizle' : 'Menüyü Göster'" :content="{ side: 'right' }">
          <button
            class="size-10 flex items-center justify-center rounded-lg transition-colors text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white"
            @click="toggleSidebar"
          >
            <UIcon name="i-lucide-menu" class="size-5" />
          </button>
        </UTooltip>
      </div>

      <!-- Nav ikonlari -->
      <div class="flex-1 flex flex-col items-center gap-1 overflow-y-auto pt-2" :class="isNavigating ? 'cursor-wait' : ''">
        <UTooltip
          v-for="item in navItems"
          :key="item.to as string"
          :text="item.label"
          :content="{ side: 'right' }"
        >
          <NuxtLink
            :to="item.to"
            class="size-10 flex items-center justify-center rounded-lg transition-colors"
            :class="isActive(item.to as string)
              ? 'bg-primary text-white'
              : 'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white'"
            @click.prevent="!isNavigating && (item.to === '/' ? goHome() : navigateTo(item.to as string))"
          >
            <UIcon :name="item.icon!" class="size-5" />
          </NuxtLink>
        </UTooltip>
      </div>

      <!-- Alt ikonlar -->
      <div class="flex flex-col items-center gap-1 mt-2">
        <UTooltip
          v-for="item in bottomNavItems"
          :key="item.to as string"
          :text="item.label"
          :content="{ side: 'right' }"
        >
          <NuxtLink
            :to="item.to"
            class="size-10 flex items-center justify-center rounded-lg transition-colors"
            :class="isActive(item.to as string)
              ? 'bg-primary text-white'
              : 'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white'"
          >
            <UIcon :name="item.icon!" class="size-5" />
          </NuxtLink>
        </UTooltip>

        <!-- Avatar -->
        <UDropdownMenu :items="userMenuItems" :content="{ side: 'right', align: 'end' }">
          <button class="mt-1 size-9 rounded-full bg-primary text-white text-xs font-semibold flex items-center justify-center hover:opacity-90 transition-opacity">
            {{ userInitials }}
          </button>
        </UDropdownMenu>
      </div>
    </nav>

    <!-- Metin Sidebar -->
    <transition name="sidebar">
      <aside v-show="sidebarOpen" class="hidden lg:flex w-52 bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 flex-col shrink-0 overflow-y-auto">
        <!-- Acente adı -->
        <div class="px-4 h-16 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
          <div class="flex items-center gap-2 cursor-pointer min-w-0" @click="goHome">
            <img
              v-if="agency.logo"
              :src="agency.logo"
              :alt="agency.name"
              class="size-7 rounded object-contain shrink-0"
            >
            <div v-else class="size-10 rounded-lg bg-green-500 flex items-center justify-center shrink-0">
              <UIcon name="i-lucide-building" class="size-5 text-white" />
            </div>
            <span class="font-semibold text-sm">{{ agency.name }}</span>
          </div>
        </div>

      <!-- Menüde Ara -->
      <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-800">
        <div class="relative">
          <UIcon name="i-lucide-search" class="absolute left-2.5 top-1/2 -translate-y-1/2 size-3.5 text-gray-400" />
          <input
            v-model="sidebarSearch"
            type="text"
            placeholder="Menüde ara..."
            class="w-full pl-8 pr-3 py-1.5 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg outline-none focus:border-primary focus:ring-1 focus:ring-primary/30 transition-colors placeholder:text-gray-400"
          >
        </div>
        <!-- Arama sonuçları -->
        <div v-if="sidebarSearch && filteredMenuItems.length" class="mt-1.5 space-y-0.5">
          <NuxtLink
            v-for="item in filteredMenuItems"
            :key="item.to"
            :to="item.to"
            class="flex items-center gap-2.5 px-2.5 py-1.5 rounded-lg text-sm transition-colors"
            :class="isActive(item.to)
              ? 'bg-primary/10 text-primary font-medium'
              : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800'"
            @click="sidebarSearch = ''"
          >
            <UIcon :name="item.icon" class="size-4 shrink-0" />
            {{ item.label }}
          </NuxtLink>
        </div>
        <p v-else-if="sidebarSearch && !filteredMenuItems.length" class="mt-1.5 px-2 text-xs text-gray-400">
          Sonuç bulunamadı
        </p>
      </div>

      <!-- Sidebar icerigi -->
      <div class="flex-1 px-3 py-1.5 space-y-2 overflow-y-auto">
        <!-- Ana item listesi -->
        <SidebarSection
          v-if="sidebarConfig.items?.length"
          :title="sidebarConfig.title"
          :items="sidebarConfig.items"
          :collapsible="sidebarConfig.collapsible"
        />

        <!-- Gruplu listeler -->
        <SidebarSection
          v-for="group in sidebarConfig.groups"
          :key="group.title"
          :title="group.title"
          :items="group.items"
          :collapsible="group.collapsible"
        />

        <!-- Mini Takvim (sadece ana sayfa) -->
        <div v-if="route.path === '/'" class="mt-2 pt-3 border-t border-gray-200 dark:border-gray-800">
          <div class="flex items-center justify-between mb-1.5 px-0.5">
            <button class="size-5 flex items-center justify-center rounded hover:bg-gray-100 dark:hover:bg-gray-800" @click="prevMonth">
              <UIcon name="i-lucide-chevron-left" class="size-3 text-muted" />
            </button>
            <span class="text-[11px] font-semibold">{{ calendarMonthLabel }}</span>
            <button class="size-5 flex items-center justify-center rounded hover:bg-gray-100 dark:hover:bg-gray-800" @click="nextMonth">
              <UIcon name="i-lucide-chevron-right" class="size-3 text-muted" />
            </button>
          </div>
          <div class="grid grid-cols-7">
            <div v-for="d in ['Pt', 'Sa', 'Ça', 'Pe', 'Cu', 'Ct', 'Pz']" :key="d" class="text-center text-[10px] font-semibold text-gray-500 dark:text-gray-400 py-1">{{ d }}</div>
          </div>
          <div class="grid grid-cols-7">
            <div v-for="(cell, idx) in calendarDays" :key="idx" class="flex justify-center py-[2px]">
              <UTooltip
                v-if="cell.day && taskCountsByDate[cell.date]"
                :text="`${taskCountsByDate[cell.date]} Aktif Görev`"
                :content="{ side: 'top' }"
              >
                <div class="flex flex-col items-center gap-[2px] cursor-default">
                  <span
                    class="size-6 flex items-center justify-center rounded-full text-[11px] leading-none font-semibold text-gray-800 dark:text-gray-200"
                    :class="cell.isToday ? 'bg-primary !text-white !font-bold' : ''"
                  >{{ cell.day }}</span>
                  <div class="flex items-center gap-[2px] h-[5px] mt-[2px]">
                    <span
                      v-for="n in Math.min(taskCountsByDate[cell.date], 3)"
                      :key="n"
                      class="size-[3px] rounded-full"
                      :class="taskCountsByDate[cell.date] >= 5
                        ? 'bg-red-400'
                        : taskCountsByDate[cell.date] >= 3
                          ? 'bg-amber-400'
                          : 'bg-blue-400'"
                    />
                    <span
                      v-if="taskCountsByDate[cell.date] > 3"
                      class="text-[8px] leading-none text-gray-500 dark:text-gray-400 font-semibold"
                    >+{{ taskCountsByDate[cell.date] - 3 }}</span>
                  </div>
                </div>
              </UTooltip>
              <div v-else-if="cell.day" class="flex flex-col items-center gap-[2px]">
                <span
                  class="size-6 flex items-center justify-center rounded-full text-[11px] leading-none text-gray-400 dark:text-gray-500"
                  :class="cell.isToday ? 'bg-primary !text-white !font-bold' : ''"
                >{{ cell.day }}</span>
                <div class="h-[4px]" />
              </div>
            </div>
          </div>
        </div>

      </div>
      </aside>
    </transition>

    <!-- Ana İçerik -->
    <div class="flex-1 flex flex-col overflow-hidden">
      <!-- Top Header -->
      <header class="h-16 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex items-center px-4 lg:px-8 shrink-0">
        <!-- Sol: Hamburger -->
        <div class="shrink-0 lg:hidden">
          <button
            class="size-9 flex items-center justify-center rounded-md hover:bg-gray-100 dark:hover:bg-gray-800"
            @click="mobileMenuOpen = true"
          >
            <UIcon name="i-lucide-menu" class="size-5" />
          </button>
        </div>

        <!-- Orta: Arama -->
        <div class="flex-1 flex items-center justify-center px-3 pl-24 lg:pl-40">
          <button
            v-show="!searchOpen"
            class="w-full max-w-[480px] h-9 flex items-center gap-2 px-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 hover:bg-white dark:hover:bg-gray-700 hover:border-primary/50 transition-all cursor-pointer"
            @click="searchOpen = true"
          >
            <UIcon name="i-lucide-search" class="size-4 text-muted shrink-0" />
            <span class="text-sm text-muted flex-1 text-left hidden sm:block">TC, Ad Soyad, Poliçe Ara...</span>
          </button>
        </div>

        <!-- Sag: Aksiyonlar -->
        <div class="shrink-0 flex items-center gap-2">

          <UPopover v-if="!allNotificationsDisabled || user?.role === 'admin'" v-model:open="notifOpen" class="hidden sm:flex">
            <UButton
              icon="i-lucide-bell"
              color="neutral"
              variant="ghost"
              size="sm"
              class="relative"
              :class="{ 'bell-ringing': bellRinging }"
              @click="bellRinging = false; stopNotifSound()"
            >
              <template #trailing>
                <span
                  v-if="unreadCount > 0"
                  class="absolute -top-0.5 -right-0.5 size-4 rounded-full bg-red-500 text-white text-[10px] flex items-center justify-center font-bold"
                >
                  {{ unreadCount > 9 ? '9+' : unreadCount }}
                </span>
              </template>
            </UButton>
            <template #content>
              <div class="w-80 max-h-96 flex flex-col">
                <div class="flex items-center justify-between px-4 py-3 border-b border-default">
                  <h4 class="font-semibold text-sm">Bildirimler</h4>
                  <UButton
                    v-if="unreadCount > 0"
                    label="Tümünü oku"
                    variant="ghost"
                    size="xs"
                    color="primary"
                    @click="markAllRead(); wsEmit('notifications-read')"
                  />
                </div>
                <div class="overflow-y-auto flex-1">
                  <div v-if="notifications.length === 0" class="py-8 text-center text-sm text-muted">
                    Bildirim yok
                  </div>
                  <div
                    v-for="n in notifications"
                    :key="n.id"
                    class="px-4 py-3 border-b border-default last:border-b-0 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
                    :class="{ 'bg-blue-50/50 dark:bg-blue-950/20': n.unread }"
                    @click="markAsRead(n.id); wsEmit('notifications-read'); if (n.data) { notifOpen = false; navigateTo(n.data) }"
                  >
                    <div class="flex items-start gap-3">
                      <UIcon :name="getNotifIcon(n.type)" :class="[getNotifColor(n.type), 'size-4 mt-0.5 shrink-0']" />
                      <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                          <p class="text-sm font-medium" :class="{ 'font-bold': n.unread }">{{ n.title }}</p>
                          <span v-if="n.unread" class="size-2 rounded-full bg-blue-500 shrink-0" />
                        </div>
                        <p class="text-xs text-muted mt-0.5 line-clamp-2">{{ n.message }}</p>
                        <p class="text-xs text-muted mt-1">{{ timeAgo(n.date) }}</p>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="border-t border-default px-4 py-2">
                  <NuxtLink to="/settings/bildirimler" class="text-xs text-primary hover:underline" @click="notifOpen = false">
                    Bildirim Ayarlari
                  </NuxtLink>
                </div>
              </div>
            </template>
          </UPopover>

          <UColorModeButton size="sm" class="hidden sm:flex" />

          <UButton
            label="Şimdi Ne Yapmalıyım?"
            icon="i-lucide-bot"
            :color="aiCoachEnabled ? 'primary' : 'neutral'"
            :variant="aiCoachEnabled ? 'soft' : 'outline'"
            size="lg"
            class="hidden md:flex"
            @click="aiCoachEnabled ? (showAiCoach = true) : (showAiCoachPromo = true)"
          />

          <UButton
            label="Raporlar"
            icon="i-lucide-bar-chart-3"
            color="neutral"
            variant="outline"
            size="lg"
            to="/raporlar"
            class="hidden md:flex"
          />

          <UButton
            v-if="user?.role === 'admin'"
            label="İçe Aktar"
            icon="i-lucide-file-up"
            color="primary"
            size="lg"
            class="hidden md:flex"
            to="/araclar/allianz-import"
          />

          <UDropdownMenu :items="addMenuItems">
            <UButton
              label="+ Ekle"
              color="neutral"
              size="lg"
            />
          </UDropdownMenu>
        </div>
      </header>

      <!-- Page Content -->
      <main class="flex-1 overflow-y-auto p-3 lg:p-4">
        <slot />
      </main>
    </div>

    <!-- Global Modaller -->
    <UpdateNotice />
    <PolicyFormModal
      v-model:open="policyModal.open"
      :customer-id="policyModal.customerId"
      :policy="policyModal.policy"
      @saved="() => { policyModal.onSaved?.(); policyModal.onSaved = null }"
    />
    <CustomerFormModal
      v-model:open="customerModal.open"
      :customer="customerModal.customer"
      @saved="(c?: any) => { customerModal.onSaved?.(c); customerModal.onSaved = null }"
    />
    <TaskFormModal v-model:open="isTaskFormOpen" @saved="navigateTo('/gorevler')" />

    <!-- Mobil Overlay Sidebar -->
    <USlideover v-model:open="mobileMenuOpen" side="left" title="Menü">
      <template #body>
        <!-- Nav linkleri -->
        <nav class="space-y-1">
          <NuxtLink
            v-for="item in mobileMenuItems"
            :key="item.to as string"
            :to="item.to"
            class="flex items-center gap-3 px-3 py-2.5 rounded-md text-sm transition-colors"
            :class="isActive(item.to as string)
              ? 'bg-primary/10 text-primary font-medium'
              : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800'"
          >
            <UIcon :name="item.icon!" class="size-5" />
            {{ item.label }}
          </NuxtLink>
        </nav>

        <USeparator class="my-4" />

        <NuxtLink
          v-for="item in bottomNavItems"
          :key="item.to as string"
          :to="item.to"
          class="flex items-center gap-3 px-3 py-2.5 rounded-md text-sm transition-colors"
          :class="isActive(item.to as string)
            ? 'bg-primary/10 text-primary font-medium'
            : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800'"
        >
          <UIcon :name="item.icon!" class="size-5" />
          {{ item.label }}
        </NuxtLink>
      </template>
    </USlideover>
  </div>

    <!-- Global Arama -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="searchOpen" class="fixed inset-0 z-[100] flex items-start justify-center pt-[10vh]" @click.self="searchOpen = false">
          <div class="fixed inset-0 bg-black/50" @click="searchOpen = false" />
          <div class="relative w-full max-w-2xl mx-4 bg-white dark:bg-gray-900 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <!-- Arama Input -->
            <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-200 dark:border-gray-700">
              <UIcon name="i-lucide-search" class="size-5 text-muted shrink-0" />
              <input
                ref="searchInputRef"
                v-model="searchQuery"
                type="text"
                class="w-full bg-transparent border-none outline-none text-base placeholder:text-muted"
                placeholder="TC, Ad Soyad, Poliçe No, Plaka, Telefon ara..."
              />
              <button class="text-xs text-muted border border-gray-300 dark:border-gray-600 rounded px-1.5 py-0.5" @click="searchOpen = false">ESC</button>
            </div>

            <!-- Sonuçlar -->
            <div class="max-h-80 overflow-y-auto">
              <div v-if="searchLoading" class="flex items-center justify-center py-8">
                <UIcon name="i-lucide-loader-2" class="size-5 animate-spin text-muted" />
              </div>

              <template v-else-if="searchQuery.length >= 2">
                <div v-if="searchCustomers.length" class="py-2">
                  <p class="text-xs font-semibold text-muted uppercase px-4 py-1">Müşteriler</p>
                  <button
                    v-for="cu in searchCustomers"
                    :key="'c-' + cu.id"
                    class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors text-left"
                    @click="goToCustomer(cu.id)"
                  >
                    <div class="size-8 rounded-full bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center shrink-0">
                      <UIcon name="i-lucide-user" class="size-4 text-blue-500" />
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium">{{ cu.name }}</p>
                      <p class="text-xs text-muted">{{ cu.phone || '' }}</p>
                    </div>
                    <UIcon name="i-lucide-chevron-right" class="size-4 text-muted" />
                  </button>
                </div>

                <div v-if="searchPolicies.length" class="py-2">
                  <p class="text-xs font-semibold text-muted uppercase px-4 py-1">Poliçeler</p>
                  <button
                    v-for="po in searchPolicies"
                    :key="'p-' + po.id"
                    class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors text-left"
                    @click="goToPolicy(po.customerId)"
                  >
                    <div class="size-8 rounded-full bg-green-50 dark:bg-green-900/30 flex items-center justify-center shrink-0">
                      <UIcon name="i-lucide-shield-check" class="size-4 text-green-600" />
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium">{{ po.policyNo }}</p>
                      <p class="text-xs text-muted truncate">
                        {{ po.customerName }}{{ po.plateNo ? ' · ' + po.plateNo : '' }}
                      </p>
                      <p v-if="po.matchedInsured" class="text-xs text-primary truncate">
                        Sigortalı: {{ po.matchedInsured }}
                      </p>
                    </div>
                    <UIcon name="i-lucide-chevron-right" class="size-4 text-muted" />
                  </button>
                </div>

                <div v-if="!searchCustomers.length && !searchPolicies.length" class="text-center py-8">
                  <UIcon name="i-lucide-search-x" class="size-8 text-muted mx-auto mb-2" />
                  <p class="text-sm text-muted">Sonuç bulunamadı</p>
                </div>
              </template>

              <div v-else class="text-center py-8">
                <UIcon name="i-lucide-search" class="size-8 text-muted mx-auto mb-2" />
                <p class="text-sm text-muted">En az 2 karakter yazın</p>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- AI Satış Koçu Tanıtım Modalı -->
    <UModal v-model:open="showAiCoachPromo" title="AI Satış Koçu" class="sm:max-w-md">
      <template #body>
        <div class="flex flex-col items-center text-center py-4 gap-4">
          <div class="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center">
            <UIcon name="i-lucide-bot" class="size-8 text-primary" />
          </div>
          <div class="space-y-2">
            <h3 class="text-lg font-semibold">AI Satış Koçu Aktif Değil</h3>
            <p class="text-sm text-muted">
              Bu özellik, yapay zeka destekli satış önerileri sunarak ekibinizin performansını artırır.
              Hangi müşteriyi aramanız gerektiğini, ne söylemeniz gerektiğini ve önceliklendirmeyi sizin için yapar.
            </p>
          </div>
          <div class="w-full border border-default rounded-lg p-3 space-y-2 text-left">
            <div class="flex items-start gap-2">
              <UIcon name="i-lucide-sparkles" class="size-4 text-amber-500 mt-0.5 shrink-0" />
              <span class="text-sm">Görevlerinizi öncelik sırasına göre analiz eder</span>
            </div>
            <div class="flex items-start gap-2">
              <UIcon name="i-lucide-message-circle" class="size-4 text-blue-500 mt-0.5 shrink-0" />
              <span class="text-sm">Müşteriye ne söyleyeceğinizi önerir</span>
            </div>
            <div class="flex items-start gap-2">
              <UIcon name="i-lucide-target" class="size-4 text-green-500 mt-0.5 shrink-0" />
              <span class="text-sm">Satış stratejisi ve ipuçları sunar</span>
            </div>
          </div>
          <p class="text-xs text-muted">
            Bu özelliği aktif etmek için yazılımcınızla iletişime geçin ve destek alın.
          </p>
          <UButton label="Tamam" color="primary" class="w-full" @click="showAiCoachPromo = false" />
        </div>
      </template>
    </UModal>

    <!-- Bildirim sesi -->
    <audio ref="notifAudioRef" src="/notification.mp3" preload="auto" @ended="onNotifAudioEnded" />
</template>

<style scoped>
.bell-ringing {
  animation: bell-ring 0.8s ease-in-out infinite;
  transform-origin: top center;
}

@keyframes bell-ring {
  0%, 100% { transform: rotate(0deg); }
  10% { transform: rotate(14deg); }
  20% { transform: rotate(-12deg); }
  30% { transform: rotate(10deg); }
  40% { transform: rotate(-8deg); }
  50% { transform: rotate(6deg); }
  60% { transform: rotate(-4deg); }
  70% { transform: rotate(2deg); }
  80% { transform: rotate(0deg); }
}

/* Sidebar aç/kapat animasyonu */
.sidebar-enter-active,
.sidebar-leave-active {
  transition: width 0.25s ease, opacity 0.25s ease;
  overflow: hidden;
}
.sidebar-enter-from,
.sidebar-leave-to {
  width: 0 !important;
  opacity: 0;
}
.sidebar-enter-to,
.sidebar-leave-from {
  width: 256px;
  opacity: 1;
}
</style>
