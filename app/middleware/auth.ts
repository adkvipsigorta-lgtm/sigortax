export default defineNuxtRouteMiddleware(async (to) => {
  const { isLoggedIn, initialized, fetchMe, user } = useAuth()

  // İlk yüklemede token varsa kullanıcı bilgisini cek
  if (!initialized.value) {
    await fetchMe()
  }

  // Giriş yapmamis kullanıcı korunali sayfaya gidemez
  if (!isLoggedIn.value && to.path !== '/login') {
    return navigateTo('/login')
  }

  // Giriş yapmis kullanıcı login'e gidemez
  if (isLoggedIn.value && to.path === '/login') {
    return navigateTo('/')
  }

  // Onboarding tamamlanmamış kullanıcı — admin hariç
  if (isLoggedIn.value && user.value && !user.value.onboardingCompleted && user.value.role !== 'admin') {
    if (to.path !== '/onboarding') {
      return navigateTo('/onboarding')
    }
  }
})
