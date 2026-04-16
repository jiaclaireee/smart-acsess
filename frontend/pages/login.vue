<template>
  <div class="grid lg:grid-cols-2 gap-8 items-center min-h-[75vh]">
    <div class="hidden lg:block">
      <div class="sa-card p-8">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-uplbGreen/10 text-uplbGreen text-xs font-semibold">
          UPLB • ICS • Graduate School
        </div>
        <h1 class="mt-4 text-4xl font-bold">SMART-ACSESS</h1>
        <p class="mt-2 text-slate-600">
          Sign in with your approved UP Mail account to access the dashboard, chatbot, reports,
          and admin modules based on your role.
        </p>
      </div>
    </div>

    <div class="max-w-md mx-auto w-full">
      <div class="sa-card p-6 sm:p-8">
        <h2 class="text-2xl font-bold">Sign in</h2>
        <p class="text-sm text-slate-600 mt-1">
          Use your UP Mail address. Accounts remain blocked until an Admin approves them.
        </p>

        <div v-if="errorMessage" class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {{ errorMessage }}
        </div>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
          <div>
            <label class="sa-label">UP Mail <span class="text-rose-500">*</span></label>
            <input v-model="form.email" type="email" class="sa-input" autocomplete="email" required />
          </div>

          <div>
            <label class="sa-label">Password <span class="text-rose-500">*</span></label>
            <input v-model="form.password" type="password" class="sa-input" autocomplete="current-password" required />
          </div>

          <button class="sa-btn-primary w-full" :disabled="loading">
            {{ loading ? "Signing in..." : "Sign in" }}
          </button>
        </form>

        <div class="my-5 flex items-center gap-3 text-xs text-slate-400">
          <div class="h-px flex-1 bg-slate-200" />
          <span>or</span>
          <div class="h-px flex-1 bg-slate-200" />
        </div>

        <button class="sa-btn-accent w-full" :disabled="googleLoading" @click="startGoogleLogin">
          {{ googleLoading ? "Redirecting..." : "Continue with Google" }}
        </button>

        <div class="mt-6 text-sm text-slate-600">
          Need an account?
          <NuxtLink class="font-semibold text-uplbGreen underline" to="/register">Create one here</NuxtLink>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { getApiErrorMessage } from "~/composables/useApi"

const { login, googleLogin } = useAuth()
const loading = ref(false)
const googleLoading = ref(false)
const route = useRoute()
const error = ref("")

const form = reactive({
  email: "",
  password: "",
})

const routeErrors: Record<string, string> = {
  upmail_only: "Please use a valid UP Mail address that matches the allowed campus domains.",
  missing_token: "Authentication token was not returned.",
  invalid_session: "Sign-in succeeded, but the app could not load your session. Please try again.",
}

const errorMessage = computed(() => {
  const key = String(route.query.error || "")
  return error.value || (key ? (routeErrors[key] || "Unable to sign in.") : "")
})

async function submit() {
  loading.value = true
  error.value = ""

  try {
    const redirectTo = await login({ ...form })
    await navigateTo(redirectTo)
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to sign in. Please check your credentials.")
  } finally {
    loading.value = false
  }
}

function startGoogleLogin() {
  googleLoading.value = true
  googleLogin()
}
</script>
