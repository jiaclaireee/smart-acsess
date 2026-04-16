export function getApiErrorMessage(error: any, fallback = "Something went wrong.") {
  const validationErrors = error?.data?.errors
  const firstValidation = validationErrors
    ? (Object.values(validationErrors)[0] as string[] | undefined)?.[0]
    : null

  return firstValidation || error?.data?.message || error?.message || fallback
}

export function useApi() {
  const config = useRuntimeConfig()

  const getToken = () => {
    if (process.client) return localStorage.getItem("token")
    return null
  }

  const authHeaders = () => {
    const token = getToken()

    return {
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    }
  }

  async function request<T>(method: string, path: string, body?: any) {
    return await $fetch<T>(`${config.public.apiBase}${path}`, {
      method,
      headers: {
        ...authHeaders(),
        ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
      },
      ...(body !== undefined ? { body } : {}),
    })
  }

  async function get<T>(path: string) {
    return await request<T>("GET", path)
  }

  async function post<T>(path: string, body?: any) {
    return await request<T>("POST", path, body)
  }

  async function put<T>(path: string, body?: any) {
    return await request<T>("PUT", path, body)
  }

  async function patch<T>(path: string, body?: any) {
    return await request<T>("PATCH", path, body)
  }

  async function del<T>(path: string) {
    return await request<T>("DELETE", path)
  }

  return { get, post, put, patch, del }
}
