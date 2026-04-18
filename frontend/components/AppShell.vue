<template>
  <div v-if="isPlainLayout" class="min-h-screen bg-white">
    <main class="max-w-6xl mx-auto px-4 sm:px-6 py-8">
      <slot />
    </main>
  </div>

  <div v-else class="min-h-screen bg-white">
    <div class="flex">
      <aside
        class="relative hidden min-h-screen border-r border-slate-200 bg-white px-3 py-4 transition-all duration-300 lg:flex"
        :class="isSidebarCollapsed ? 'w-24' : 'w-72'"
      >
        <div class="flex w-full flex-col">
          <div
            class="flex items-center"
            :class="isSidebarCollapsed ? 'justify-center' : 'justify-between gap-3 px-2'"
          >
            <div class="flex items-center" :class="isSidebarCollapsed ? 'justify-center' : 'gap-3'">
              <div class="grid h-10 w-10 place-items-center rounded-2xl bg-uplbGreen text-white font-bold">SA</div>
              <div v-if="!isSidebarCollapsed">
                <div class="font-futura font-bold leading-tight">SMART-ACSESS</div>
                <div class="text-xs text-slate-500">Campus Security &amp; Safety</div>
              </div>
            </div>

            <button
              type="button"
              class="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50 hover:text-slate-900"
              :title="isSidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
              @click="toggleSidebar"
            >
              <span class="h-5 w-5" v-html="isSidebarCollapsed ? Icon.SidebarExpand : Icon.SidebarCollapse" />
            </button>
          </div>

          <nav class="mt-6 space-y-5">
            <section class="space-y-1">
              <div
                v-if="!isSidebarCollapsed"
                class="px-3 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400"
              >
                User Operations
              </div>
              <SideLink to="/dashboard" :icon="Icon.Dashboard" label="Dashboard" :collapsed="isSidebarCollapsed" />
              <SideLink to="/chatbot" :icon="Icon.Chatbot" label="Chatbot" :collapsed="isSidebarCollapsed" />
            </section>

            <section v-if="isAdmin" class="space-y-1">
              <div
                v-if="!isSidebarCollapsed"
                class="px-3 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400"
              >
                System Administration
              </div>
              <SideLink
                to="/admin/developers"
                :icon="Icon.Developers"
                label="SMART-ACSESS for Developers"
                :collapsed="isSidebarCollapsed"
              />
              <SideLink
                to="/admin/audit-trails"
                :icon="Icon.AuditTrail"
                label="Audit Trail"
                :collapsed="isSidebarCollapsed"
              />
              <SideLink to="/admin/users" :icon="Icon.Users" label="User Management" :collapsed="isSidebarCollapsed" />
              <SideLink to="/admin/connections" :icon="Icon.Database" label="Databases" :collapsed="isSidebarCollapsed" />
            </section>
          </nav>

          <div class="mt-auto pt-8">
            <div v-if="!isSidebarCollapsed" class="sa-card p-4">
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

            <div v-else class="flex justify-center">
              <button
                type="button"
                class="grid h-12 w-12 place-items-center rounded-2xl bg-uplbRed text-white shadow-soft transition hover:opacity-90"
                title="Logout"
                @click="logout"
              >
                <span class="h-5 w-5" v-html="Icon.Logout" />
              </button>
            </div>
          </div>
        </div>
      </aside>

      <div class="flex-1 min-w-0">
        <TopBar />
        <main
          class="mx-auto px-4 sm:px-6"
          :class="isChatbotRoute ? 'max-w-[88rem] py-4' : 'max-w-6xl py-6'"
        >
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
          <NuxtLink class="flex flex-col items-center gap-1 px-3 py-2 rounded-xl hover:bg-slate-50" to="/admin/developers">
            <span class="w-5 h-5 text-uplbGreen" v-html="Icon.Developers" />
            Developers
          </NuxtLink>
          <NuxtLink class="flex flex-col items-center gap-1 px-3 py-2 rounded-xl hover:bg-slate-50" to="/admin/audit-trails">
            <span class="w-5 h-5 text-uplbGreen" v-html="Icon.AuditTrail" />
            Audit
          </NuxtLink>
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
import { onMounted, ref, watch } from "vue"

const { user, logout } = useAuth()
const route = useRoute()

const plainLayoutPaths = new Set(["/login", "/register", "/auth/callback", "/account-status", "/forbidden"])

const isPlainLayout = computed(() => plainLayoutPaths.has(route.path))
const isChatbotRoute = computed(() => route.path === "/chatbot")
const isAdmin = computed(() => user.value?.role === "admin")
const isSidebarCollapsed = ref(false)
const statusClass = computed(() => {
  if (user.value?.approval_status === "approved") return "border-emerald-200 bg-emerald-50 text-emerald-700"
  if (user.value?.approval_status === "rejected") return "border-rose-200 bg-rose-50 text-rose-700"
  return "border-amber-200 bg-amber-50 text-amber-700"
})

onMounted(() => {
  const stored = localStorage.getItem("sa-sidebar-collapsed")
  isSidebarCollapsed.value = stored === "true"
})

watch(isSidebarCollapsed, (value) => {
  if (!process.client) return
  localStorage.setItem("sa-sidebar-collapsed", String(value))
})

function toggleSidebar() {
  isSidebarCollapsed.value = !isSidebarCollapsed.value
}
</script>
