export default defineNuxtRouteMiddleware(async (to) => {
  const { user, refresh, routeAfterAuth } = useAuth()

  const publicPages = new Set(["/login", "/register", "/auth/callback", "/forbidden"])
  const statusPage = "/account-status"

  if (user.value === null) await refresh()

  if (!user.value) {
    if (publicPages.has(to.path)) return
    return navigateTo("/login")
  }

  if (publicPages.has(to.path)) {
    return navigateTo(routeAfterAuth(user.value))
  }

  if (user.value.approval_status !== "approved" && to.path !== statusPage) {
    return navigateTo(statusPage)
  }

  if (user.value.role !== "admin" && to.path.startsWith("/admin")) {
    return navigateTo({
      path: "/forbidden",
      query: { from: to.fullPath },
    })
  }
})
