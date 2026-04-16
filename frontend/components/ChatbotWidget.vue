<template>
  <div v-if="showLauncher" class="fixed bottom-20 lg:bottom-5 right-5 z-50">
    <NuxtLink to="/chatbot" class="grid h-14 w-14 place-items-center rounded-full bg-uplbGreen text-[0] text-white shadow-soft transition hover:opacity-95 before:text-base before:font-semibold before:text-white before:content-['AI']" aria-label="Open chatbot" title="Open chatbot">
      💬
    </NuxtLink>

    <div v-if="false" class="w-[24rem] h-[500px] mt-3 sa-card overflow-hidden">
      <div class="px-4 py-3 bg-uplbGreen text-white flex items-center justify-between">
        <div>
          <div class="font-semibold leading-tight">SMART-ACSESS Bot</div>
          <div class="text-[11px] text-white/80">Database-aware + UPLB campus query support</div>
        </div>
        <button class="text-white/90 hover:text-white" @click="open = false">✕</button>
      </div>

      <div class="p-3 h-[360px] overflow-y-auto space-y-3 bg-white">
        <div v-for="(m, i) in messages" :key="i" class="text-sm">
          <div :class="m.role === 'user' ? 'text-right' : 'text-left'">
            <div :class="m.role === 'user' ? 'inline-block bg-uplbYellow px-3 py-2 rounded-2xl max-w-[90%]' : 'inline-block bg-slate-100 px-3 py-2 rounded-2xl max-w-[90%]'">
              <div>{{ m.content }}</div>
              <div v-if="m.sources?.length" class="mt-2 text-[11px] text-slate-500">External sources used: {{ m.sources.length }}</div>
            </div>
          </div>
        </div>
      </div>

      <form class="p-3 border-t border-slate-200 flex gap-2 bg-white" @submit.prevent="send">
        <input v-model="input" class="sa-input text-sm" placeholder="Ask about selected DB or UPLB campus queries..." />
        <button class="sa-btn-danger" :disabled="loading">{{ loading ? "..." : "Send" }}</button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
const open = ref(false)
const input = ref("")
const loading = ref(false)
const messages = ref<{ role: "user" | "assistant"; content: string; sources?: any[] }[]>([
  { role: "assistant", content: "Hi! I can answer using the selected database context and reliable UPLB-related sources for campus queries." }
])

const api = useApi()
const route = useRoute()
const { user } = useAuth()

const hiddenPaths = new Set(["/login", "/register", "/auth/callback", "/account-status", "/chatbot"])
const showLauncher = computed(() => user.value?.approval_status === "approved" && !hiddenPaths.has(route.path))

async function send() {
  const msg = input.value.trim()
  if (!msg) return

  const selectedDbId = process.client ? localStorage.getItem("selected_db_id") : null
  const selectedTable = process.client ? localStorage.getItem("selected_table") : null

  if (!selectedDbId) {
    messages.value.push({ role: "assistant", content: "Please select a database first in the dashboard." })
    return
  }

  messages.value.push({ role: "user", content: msg })
  input.value = ""
  loading.value = true

  try {
    const res: any = await api.post("/api/chat", {
      dbId: Number(selectedDbId),
      selectedTable,
      message: msg,
    })

    messages.value.push({
      role: "assistant",
      content: res.reply || "No response generated.",
      sources: res.externalSources || [],
    })
  } catch {
    messages.value.push({ role: "assistant", content: "Unable to reach the chatbot backend right now." })
  } finally {
    loading.value = false
  }
}
</script>
