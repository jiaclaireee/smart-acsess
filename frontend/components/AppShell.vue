<template>
  <div v-if="isPlainLayout" class="min-h-screen bg-white">
    <main class="max-w-6xl mx-auto px-4 sm:px-6 py-8">
      <slot />
    </main>
  </div>

  <div v-else class="min-h-screen bg-white">
    <div class="flex">
      <aside class="hidden lg:flex w-72 border-r border-slate-200 min-h-screen p-4">
        <div class="w-full">
          <div class="flex items-center gap-3 px-2 py-2">
            <div class="w-10 h-10 rounded-2xl bg-uplbGreen grid place-items-center text-white font-bold">SA</div>
            <div>
              <div class="font-futura font-bold leading-tight">SMART-ACSESS</div>
              <div class="text-xs text-slate-500">Campus Security &amp; Safety</div>
            </div>
          </div>

          <nav class="mt-6 space-y-1">
            <SideLink to="/dashboard" :icon="Icon.Dashboard" label="Dashboard" />
            <SideLink to="/chatbot" :icon="Icon.Chatbot" label="Chatbot" />
            <template v-if="isAdmin">
              <SideLink to="/admin/users" :icon="Icon.Users" label="User Management" />
              <SideLink to="/admin/connections" :icon="Icon.Database" label="Databases" />
            </template>
          </nav>

          <div class="mt-8 sa-card p-4">
            <div class="text-xs text-slate-500">Logged in as</div>
            <div class="text-sm font-semibold truncate">{{ user?.email }}</div>
            <div class="mt-2 flex flex-wrap gap-2 text-[11px]">
              <span class="px-2 py-1 rounded-full bg-slate-100 border border-slate-200 uppercase">
                {{ user?.role === "admin" ? "Admin" : "End-User" }}
              </span>
              <span class="px-2 py-1 rounded-full border" :class="statusClass">
                {{ user?.approval_status }}
              </span>
            </div>
            <button class="mt-3 sa-btn-danger w-full" @click="logout">
              <span class="w-4 h-4" v-html="Icon.Logout" />
              Logout
            </button>
          </div>
        </div>
      </aside>

      <div class="flex-1 min-w-0">
        <TopBar />
        <main class="max-w-6xl mx-auto px-4 sm:px-6 py-6">
          <slot />
        </main>
      </div>
    </div>

    <div class="lg:hidden fixed bottom-0 inset-x-0 border-t border-slate-200 bg-white">
      <div class="max-w-6xl mx-auto px-3 py-2 flex items-center justify-around text-xs">
        <NuxtLink class="flex flex-col items-center gap-1 px-3 py-2 rounded-xl hover:bg-slate-50" to="/dashboard">
          <span class="w-5 h-5 text-uplbGreen" v-html="Icon.Dashboard" />
          Dashboard
        </NuxtLink>
        <NuxtLink class="flex flex-col items-center gap-1 px-3 py-2 rounded-xl hover:bg-slate-50" to="/chatbot">
          <span class="w-5 h-5 text-uplbGreen" v-html="Icon.Chatbot" />
          Chatbot
        </NuxtLink>
        <template v-if="isAdmin">
          <NuxtLink class="flex flex-col items-center gap-1 px-3 py-2 rounded-xl hover:bg-slate-50" to="/admin/users">
            <span class="w-5 h-5 text-uplbGreen" v-html="Icon.Users" />
            Users
          </NuxtLink>
          <NuxtLink class="flex flex-col items-center gap-1 px-3 py-2 rounded-xl hover:bg-slate-50" to="/admin/connections">
            <span class="w-5 h-5 text-uplbGreen" v-html="Icon.Database" />
            DB
          </NuxtLink>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { Icon } from "~/components/icons"

const { user, logout } = useAuth()
const route = useRoute()

const plainLayoutPaths = new Set(["/login", "/register", "/auth/callback", "/account-status", "/forbidden"])

const isPlainLayout = computed(() => plainLayoutPaths.has(route.path))
const isAdmin = computed(() => user.value?.role === "admin")
const statusClass = computed(() => {
  if (user.value?.approval_status === "approved") return "border-emerald-200 bg-emerald-50 text-emerald-700"
  if (user.value?.approval_status === "rejected") return "border-rose-200 bg-rose-50 text-rose-700"
  return "border-amber-200 bg-amber-50 text-amber-700"
})
</script>
