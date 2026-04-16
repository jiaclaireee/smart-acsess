<template>
  <div class="max-w-2xl mx-auto mt-12">
    <div class="sa-card p-6 sm:p-8">
      <div class="inline-flex items-center gap-2 rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700">
        Access Denied
      </div>

      <h1 class="mt-4 text-3xl font-bold">You do not have access to this page</h1>
      <p class="mt-2 text-slate-600">
        This route is limited to administrators. If you believe your role should allow access,
        please contact an administrator for review.
      </p>

      <div v-if="fromPath" class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
        Attempted route: <span class="font-semibold text-slate-800">{{ fromPath }}</span>
      </div>

      <div class="mt-6 flex flex-wrap gap-3">
        <button class="sa-btn-primary" @click="goHome">Go to dashboard</button>
        <button class="sa-btn-ghost" @click="logout">Sign out</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const { logout } = useAuth()

const fromPath = computed(() => {
  const value = route.query.from
  return typeof value === "string" ? value : ""
})

async function goHome() {
  await navigateTo("/dashboard")
}
</script>
