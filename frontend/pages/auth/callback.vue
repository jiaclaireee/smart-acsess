<template>
  <div class="max-w-lg mx-auto mt-16">
    <div class="sa-card p-8 text-center">
      <h1 class="text-2xl font-bold">Signing you in...</h1>
      <p class="text-sm text-slate-600 mt-2">Please wait while SMART-ACSESS finishes your UP Mail authentication.</p>
    </div>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const { user, setToken, refresh, routeAfterAuth } = useAuth()

onMounted(async () => {
  const token = String(route.query.token || "")
  if (!token) {
    await navigateTo("/login?error=missing_token")
    return
  }

  setToken(token)
  await refresh()

  if (!user.value) {
    if (process.client) {
      localStorage.removeItem("token")
    }

    await navigateTo("/login?error=invalid_session")
    return
  }

  await navigateTo(routeAfterAuth(user.value))
})
</script>
