<template>
  <div class="space-y-6">
    <PageHeader
      title="Grounded Chatbot"
      subtitle="Ask in English, Tagalog, or Taglish. The assistant uses all indexed databases you can access without requiring manual database or table selection."
    >
      <template #actions>
        <button class="sa-btn-ghost" :disabled="resetting" @click="resetChat">
          {{ resetting ? "Resetting..." : "Reset Chat" }}
        </button>
      </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-[340px,minmax(0,1fr)]">
      <section class="space-y-4">
        <div class="sa-card p-5 space-y-4">
          <div>
            <div class="text-xs text-slate-500">Chat Scope</div>
            <div class="text-lg font-bold">All accessible data sources</div>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
            {{ context?.summary || "Loading grounded chatbot knowledge..." }}
          </div>

          <div class="grid grid-cols-2 gap-3 text-sm">
            <div class="rounded-2xl border border-slate-200 px-4 py-3">
              <div class="text-xs text-slate-500">Accessible DBs</div>
              <div class="mt-1 text-xl font-bold">{{ context?.overview?.accessible_database_count || 0 }}</div>
            </div>
            <div class="rounded-2xl border border-slate-200 px-4 py-3">
              <div class="text-xs text-slate-500">Known Records</div>
              <div class="mt-1 text-xl font-bold">{{ formatMetric(context?.overview?.known_record_total || 0) }}</div>
            </div>
          </div>

          <div v-if="knowledgeStatus.length" class="space-y-2">
            <div class="text-xs text-slate-500">Knowledge Index</div>
            <div
              v-for="item in knowledgeStatus.slice(0, 6)"
              :key="item.database.id"
              class="rounded-2xl border px-4 py-3 text-sm"
              :class="item.status === 'ready' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : item.status === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-amber-200 bg-amber-50 text-amber-800'"
            >
              <div class="font-semibold">{{ item.database.name }}</div>
              <div class="text-xs uppercase">{{ item.status }}</div>
            </div>
          </div>

          <div v-if="context?.suggested_prompts?.length">
            <div class="text-xs text-slate-500">Suggested Questions</div>
            <div class="mt-3 flex flex-wrap gap-2">
              <button
                v-for="suggestion in context.suggested_prompts"
                :key="suggestion"
                class="rounded-full border border-slate-200 bg-white px-3 py-2 text-xs text-slate-700 transition hover:border-uplbGreen hover:text-uplbGreen"
                @click="sendPrompt(suggestion)"
              >
                {{ suggestion }}
              </button>
            </div>
          </div>
        </div>

        <div v-if="error" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {{ error }}
        </div>
      </section>

      <section class="sa-card flex min-h-[680px] flex-col overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
          <div class="flex items-center justify-between gap-3">
            <div>
              <div class="text-xs text-slate-500">Conversation</div>
              <div class="text-lg font-bold">Cross-database grounded chat</div>
            </div>
            <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] text-slate-600">
              {{ sending ? "Responding..." : loadingContext ? "Preparing..." : "Read-only grounded mode" }}
            </span>
          </div>
        </div>

        <div ref="historyContainer" class="flex-1 space-y-4 overflow-y-auto bg-slate-50/70 px-5 py-5">
          <div v-for="message in messages" :key="message.id" class="flex" :class="message.role === 'user' ? 'justify-end' : 'justify-start'">
            <div
              class="max-w-[94%] rounded-3xl px-4 py-3 text-sm shadow-sm"
              :class="message.role === 'user' ? 'bg-uplbYellow text-slate-900' : 'bg-white text-slate-800'"
            >
              <div class="whitespace-pre-wrap leading-6">{{ message.content }}</div>
              <div class="mt-2 text-[11px] text-slate-500">{{ formatTimestamp(message.created_at) }}</div>

              <div v-if="message.facts?.length" class="mt-3 space-y-1 border-t border-slate-200 pt-3 text-xs text-slate-600">
                <div v-for="fact in message.facts" :key="fact">- {{ fact }}</div>
              </div>

              <div v-if="message.sources?.length && message.role === 'assistant'" class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-xs text-slate-600">
                <div class="font-semibold text-slate-700">Grounded Sources</div>
                <div v-for="source in message.sources.slice(0, 5)" :key="`${message.id}-${source.database_name}-${source.resource}`" class="mt-2">
                  {{ source.database_name }}<span v-if="source.resource"> / {{ source.resource }}</span>
                </div>
              </div>

              <div v-if="message.warnings?.length" class="mt-3 space-y-2">
                <div
                  v-for="warning in message.warnings"
                  :key="warning"
                  class="rounded-2xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                >
                  {{ warning }}
                </div>
              </div>

              <div v-if="message.chart?.labels?.length" class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-xs">
                <div class="font-semibold text-slate-700">{{ message.chart.title }}</div>
                <div class="mt-2 space-y-2">
                  <div v-for="(label, index) in message.chart.labels.slice(0, 6)" :key="`${message.id}-${label}`" class="flex items-center justify-between gap-3">
                    <span class="truncate text-slate-600">{{ label }}</span>
                    <span class="font-semibold text-slate-800">{{ formatMetric(message.chart.series[index] || 0) }}</span>
                  </div>
                </div>
              </div>

              <div v-if="message.table?.rows?.length" class="mt-3 overflow-hidden rounded-2xl border border-slate-200">
                <div class="overflow-x-auto">
                  <table class="min-w-full bg-white text-xs">
                    <thead>
                      <tr>
                        <th v-for="column in visibleColumns(message.table.columns)" :key="`${message.id}-${column}`" class="sa-th whitespace-nowrap">
                          {{ column }}
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(row, rowIndex) in message.table.rows.slice(0, 6)" :key="`${message.id}-${rowIndex}`">
                        <td v-for="column in visibleColumns(message.table.columns)" :key="`${message.id}-${rowIndex}-${column}`" class="sa-td align-top">
                          {{ formatCell(row[column]) }}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <div v-if="message.suggestions?.length && message.role === 'assistant'" class="mt-3 flex flex-wrap gap-2">
                <button
                  v-for="suggestion in message.suggestions.slice(0, 3)"
                  :key="`${message.id}-${suggestion}`"
                  class="rounded-full border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] text-slate-700 transition hover:border-uplbGreen hover:text-uplbGreen"
                  @click="sendPrompt(suggestion)"
                >
                  {{ suggestion }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <form class="border-t border-slate-200 bg-white px-5 py-4" @submit.prevent="sendPrompt()">
          <div class="flex flex-col gap-3 sm:flex-row">
            <input
              v-model="prompt"
              class="sa-input flex-1"
              :disabled="sending || loadingContext"
              placeholder="Ask in English, Tagalog, or Taglish..."
            />
            <button class="sa-btn-primary" :disabled="sending || loadingContext || !prompt.trim()">
              {{ sending ? "Sending..." : "Send" }}
            </button>
          </div>
        </form>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
import { nextTick, onMounted, ref } from "vue"
import { getApiErrorMessage } from "~/composables/useApi"

type ChatTable = { columns: string[]; rows: Record<string, any>[] }
type ChatChart = { title: string; labels: string[]; series: number[] }
type ChatSource = { database_name?: string; resource?: string; database?: { id: number; name: string }; status?: string }
type ChatMessage = {
  id: string
  role: "user" | "assistant"
  content: string
  created_at?: string
  facts?: string[]
  warnings?: string[]
  suggestions?: string[]
  sources?: ChatSource[]
  table?: ChatTable | null
  chart?: ChatChart | null
}
type ChatContextResponse = {
  context_id: string
  summary: string
  suggested_prompts: string[]
  overview: { accessible_database_count: number; known_record_total: number }
  history?: ChatMessage[]
}
type KnowledgeStatus = { database: { id: number; name: string }; status: string }
type AskResponse = { context_id: string; history?: ChatMessage[] }

const api = useApi()
const context = ref<ChatContextResponse | null>(null)
const contextId = ref("")
const knowledgeStatus = ref<KnowledgeStatus[]>([])
const prompt = ref("")
const messages = ref<ChatMessage[]>([])
const error = ref("")
const loadingContext = ref(false)
const sending = ref(false)
const resetting = ref(false)
const historyContainer = ref<HTMLElement | null>(null)

onMounted(async () => {
  messages.value = [buildIntroMessage()]
  await Promise.all([loadContext(), loadKnowledgeStatus()])
})

async function loadContext() {
  loadingContext.value = true
  error.value = ""

  try {
    const response = await api.post<ChatContextResponse>("/api/chatbot/context", {})
    context.value = response
    contextId.value = response.context_id
    messages.value = response.history?.length ? response.history : [buildContextMessage(response)]
    await scrollToBottom()
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to prepare grounded chatbot context.")
  } finally {
    loadingContext.value = false
  }
}

async function loadKnowledgeStatus() {
  try {
    const response = await api.get<{ databases?: KnowledgeStatus[] }>("/api/chatbot/knowledge/status")
    knowledgeStatus.value = response.databases || []
  } catch {}
}

async function sendPrompt(override?: string) {
  const message = (override ?? prompt.value).trim()
  if (!message) return

  const optimisticMessages = [...messages.value, { id: `temp-${Date.now()}`, role: "user" as const, content: message, created_at: new Date().toISOString() }]

  prompt.value = ""
  messages.value = optimisticMessages
  sending.value = true
  error.value = ""

  try {
    if (!contextId.value) await loadContext()

    const response = await api.post<AskResponse>("/api/chatbot/ask", {
      context_id: contextId.value || null,
      prompt: message,
    })

    contextId.value = response.context_id
    messages.value = response.history?.length ? response.history : optimisticMessages
    await scrollToBottom()
  } catch (err) {
    const assistantMessage = {
      id: `error-${Date.now()}`,
      role: "assistant" as const,
      content: getApiErrorMessage(err, "Unable to reach the grounded chatbot right now."),
      created_at: new Date().toISOString(),
    }
    messages.value = [...optimisticMessages, assistantMessage]
    error.value = assistantMessage.content
    await scrollToBottom()
  } finally {
    sending.value = false
  }
}

async function resetChat() {
  resetting.value = true
  error.value = ""

  try {
    await api.post("/api/chatbot/reset", {})
    messages.value = context.value ? [buildContextMessage(context.value)] : [buildIntroMessage()]
    await scrollToBottom()
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to reset chatbot history.")
  } finally {
    resetting.value = false
  }
}

function buildIntroMessage(): ChatMessage {
  return {
    id: "intro",
    role: "assistant",
    content: "You can start chatting right away. I'll use the indexed databases that your account can access and keep the grounding read-only.",
    created_at: new Date().toISOString(),
  }
}

function buildContextMessage(value: ChatContextResponse): ChatMessage {
  return {
    id: "context-ready",
    role: "assistant",
    content: value.summary,
    created_at: new Date().toISOString(),
    suggestions: value.suggested_prompts || [],
  }
}

function visibleColumns(columns: string[]) {
  return columns.slice(0, 6)
}

function formatMetric(value: number) {
  return Number(value || 0).toLocaleString()
}

function formatCell(value: unknown) {
  if (value === null || value === undefined || value === "") return "-"
  if (typeof value === "object") return JSON.stringify(value)
  return String(value)
}

function formatTimestamp(value?: string) {
  if (!value) return ""

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return new Intl.DateTimeFormat("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit",
  }).format(date)
}

async function scrollToBottom() {
  await nextTick()
  if (historyContainer.value) historyContainer.value.scrollTop = historyContainer.value.scrollHeight
}
</script>


