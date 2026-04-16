export type ApprovalStatus = "pending" | "approved" | "rejected"
export type UserRole = "admin" | "end_user"

export type AuthUser = {
  id: number
  first_name: string
  middle_name?: string | null
  last_name: string
  email: string
  avatar_url?: string | null
  role: UserRole
  approval_status: ApprovalStatus
}

export function useAuth() {
  const user = useState<AuthUser | null>("auth_user", () => null)
  const api = useApi()

  const setToken = (token: string) => process.client && localStorage.setItem("token", token)
  const getToken = () => (process.client ? localStorage.getItem("token") : null)
  const clearToken = () => process.client && localStorage.removeItem("token")

  function routeAfterAuth(currentUser: AuthUser | null = user.value) {
    if (!currentUser) return "/login"
    if (currentUser.approval_status !== "approved") return "/account-status"
    return "/dashboard"
  }

  async function refresh() {
    try {
      if (!getToken()) {
        user.value = null
        return
      }

      user.value = await api.get<AuthUser>("/api/auth/me")
    } catch {
      user.value = null
      clearToken()
    }
  }

  async function register(payload: Record<string, any>) {
    const response = await api.post<{ user: AuthUser; token: string }>("/api/auth/register", payload)
    setToken(response.token)
    user.value = response.user
    return routeAfterAuth(response.user)
  }

  async function login(payload: { email: string; password: string }) {
    const response = await api.post<{ user: AuthUser; token: string }>("/api/auth/login", payload)
    setToken(response.token)
    user.value = response.user
    return routeAfterAuth(response.user)
  }

  function googleLogin() {
    const config = useRuntimeConfig()
    window.location.href = `${config.public.backendBase}/auth/google/redirect`
  }

  async function logout() {
    try {
      await api.post("/api/auth/logout")
    } catch {}

    clearToken()
    user.value = null
    await navigateTo("/login")
  }

  return { user, refresh, register, login, googleLogin, logout, setToken, routeAfterAuth }
}
