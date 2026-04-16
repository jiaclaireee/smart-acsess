<template>
  <div class="max-w-2xl mx-auto mt-12">
    <div class="sa-card p-6 sm:p-8">
      <div class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold" :class="badgeClass">
        {{ statusLabel }}
      </div>

      <h1 class="mt-4 text-3xl font-bold">{{ title }}</h1>
      <p class="mt-2 text-slate-600">{{ description }}</p>

      <div class="mt-6 grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
        <div><span class="text-slate-500">UP Mail:</span> {{ user?.email }}</div>
        <div><span class="text-slate-500">Role:</span> {{ user?.role === "admin" ? "Admin" : "End-User" }}</div>
        <div><span class="text-slate-500">Approval:</span> {{ user?.approval_status }}</div>
      </div>

      <div class="mt-6 flex flex-wrap gap-3">
        <button v-if="user?.approval_status === 'approved'" class="sa-btn-primary" @click="continueToApp">
          Continue to dashboard
        </button>
        <button v-else class="sa-btn-primary" :disabled="refreshing" @click="refreshStatus">
          {{ refreshing ? "Checking..." : "Refresh status" }}
        </button>
        <button class="sa-btn-ghost" @click="logout">Sign out</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const { user, refresh, logout, routeAfterAuth } = useAuth()
const refreshing = ref(false)

const statusLabel = computed(() => {
  if (user.value?.approval_status === "approved") return "Approved"
  if (user.value?.approval_status === "rejected") return "Rejected"
  return "Pending Approval"
})

const title = computed(() => {
  if (user.value?.approval_status === "approved") return "Your account is approved"
  if (user.value?.approval_status === "rejected") return "Your account was rejected"
  return "Your account is waiting for approval"
})

const description = computed(() => {
  if (user.value?.approval_status === "approved") {
    return "You can now access the modules available for your role."
  }

  if (user.value?.approval_status === "rejected") {
    return "This account cannot access protected modules. Please contact an Admin if you need this reviewed."
  }

  return "Registration is complete, but protected modules stay locked until an Admin approves your account."
})

const badgeClass = computed(() => {
  if (user.value?.approval_status === "approved") return "bg-emerald-50 text-emerald-700"
  if (user.value?.approval_status === "rejected") return "bg-rose-50 text-rose-700"
  return "bg-amber-50 text-amber-700"
})

async function continueToApp() {
  await navigateTo(routeAfterAuth(user.value))
}

async function refreshStatus() {
  refreshing.value = true

  try {
    await refresh()

    if (user.value?.approval_status === "approved") {
      await navigateTo(routeAfterAuth(user.value))
    }
  } finally {
    refreshing.value = false
  }
}
</script>
