<template>
  <div class="flex h-[calc(100vh-7rem)] min-h-[620px] flex-col gap-3 overflow-hidden">
    <div class="flex flex-col gap-3 rounded-[28px] border border-slate-200 bg-white px-5 py-3 shadow-soft sm:px-6 lg:flex-row lg:items-center lg:justify-between">
      <div>
        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">SMART-ACSESS</div>
        <h1 class="mt-1 text-[1.8rem] font-bold tracking-tight text-slate-950 xl:text-[2rem]">Campus Safety Chatbot</h1>
      </div>

      <div class="flex flex-wrap gap-2">
        <button class="sa-btn-primary" :disabled="loadingContext || sending" @click="startNewConversation">
          New Chat
        </button>
        <button
          class="sa-btn-accent"
          :disabled="!selectedConversationId || resetting || sending"
          @click="resetConversation"
        >
          {{ resetting ? "Clearing..." : "Clear Chat" }}
        </button>
      </div>
    </div>

    <div class="grid min-h-0 flex-1 grid-cols-1 gap-3 xl:grid-cols-[260px,minmax(0,1fr)]">
      <aside class="min-h-0">
        <section class="sa-card flex h-full min-h-0 flex-col overflow-hidden">
          <div class="border-b border-slate-200 bg-slate-50/80 px-5 py-4">
            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Saved Chats</div>
          </div>

          <div class="flex min-h-0 flex-1 flex-col gap-4 p-4">
            <div class="space-y-2">
              <label class="sa-label">Search</label>
              <input
                v-model="conversationSearch"
                class="sa-input"
                placeholder="Search titles or message keywords"
                type="search"
              />
            </div>

            <div class="min-h-0 flex-1 space-y-2 overflow-y-auto pr-1">
              <button
                v-for="conversation in conversations"
                :key="conversation.id"
                type="button"
                class="w-full rounded-2xl border px-4 py-3 text-left transition"
                :class="conversation.id === selectedConversationId ? 'border-uplbGreen bg-emerald-50/70 shadow-soft' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'"
                @click="selectConversation(conversation.id)"
              >
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-slate-900">{{ conversation.title }}</div>
                    <div class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">
                      {{ conversation.preview || "No preview available yet." }}
                    </div>
                  </div>
                  <span
                    v-if="conversation.id === selectedConversationId"
                    class="rounded-full bg-uplbGreen px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-white"
                  >
                    Active
                  </span>
                </div>
                <div class="mt-3 flex items-center justify-between text-[11px] text-slate-500">
                  <span>{{ conversation.message_count }} message{{ conversation.message_count === 1 ? "" : "s" }}</span>
                  <span>{{ formatTimestamp(conversation.last_message_at) || "Just now" }}</span>
                </div>
              </button>

              <div v-if="loadingConversations" class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-center text-sm text-slate-500">
                Searching saved chats...
              </div>

              <div v-else-if="conversations.length === 0" class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                {{ conversationSearch ? "No saved conversations matched your search." : "Your saved conversations will appear here after the first chat." }}
              </div>
            </div>
          </div>
        </section>

        <div v-if="error" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {{ error }}
        </div>
      </aside>

      <section class="sa-card flex h-full min-h-0 flex-col overflow-hidden">
        <div
          v-if="!showLandingView"
          class="border-b border-slate-200 bg-white px-5 py-4 sm:px-6"
        >
          <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Conversation</div>
              <div class="mt-1 text-xl font-bold text-slate-900">
                {{ activeConversation?.title || "New conversation" }}
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] text-slate-600">
                {{ sending ? "Responding..." : loadingConversation ? "Loading chat..." : loadingContext ? "Preparing..." : "Grounded mode" }}
              </span>
            </div>
          </div>
        </div>

        <div
          ref="historyContainer"
          class="min-h-0 flex-1 bg-[linear-gradient(180deg,_#fcfdfd_0%,_#f8fbfa_100%)] px-4 py-4 sm:px-5"
          :class="showLandingView ? 'overflow-hidden' : 'overflow-y-auto'"
        >
          <div v-if="showLandingView" class="mx-auto flex h-full max-w-[56rem] flex-col items-center justify-center text-center">
            <div class="rounded-full border border-emerald-200 bg-emerald-50 px-4 py-1.5 text-[10px] font-semibold uppercase tracking-[0.24em] text-emerald-700">
              SMART-ACSESS Assistant
            </div>
            <h2 class="mt-4 max-w-4xl text-[2.8rem] font-bold tracking-tight text-slate-950 sm:text-[3.8rem] sm:leading-[0.92]">
              What would you like to explore today?
            </h2>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600 sm:text-[0.95rem]">
              Ask about campus safety records, trends, approvals, movements, or other indexed data. Your conversations are saved automatically so you can reopen and continue them anytime.
            </p>

            <form class="mt-5 w-full max-w-5xl" @submit.prevent="sendPrompt()">
              <div class="rounded-[30px] border border-slate-200 bg-white p-3 shadow-soft">
                <textarea
                  v-model="prompt"
                  class="min-h-[60px] w-full resize-none border-0 bg-transparent px-3 py-2 text-base leading-6 text-slate-800 outline-none placeholder:text-slate-400"
                  :disabled="sending || loadingContext || loadingConversation"
                  placeholder="Ask anything about the grounded SMART-ACSESS data..."
                  @keydown="handleComposerKeydown"
                />
                <div class="flex items-center justify-between gap-3 border-t border-slate-200 px-3 pt-3">
                  <div class="text-xs text-slate-500">Press Enter to send, Shift + Enter for a new line</div>
                  <button class="sa-btn-primary" :disabled="sending || loadingContext || loadingConversation || !prompt.trim()">
                    {{ sending ? "Sending..." : "Send Message" }}
                  </button>
                </div>
              </div>
            </form>

            <div class="mt-4 flex w-full max-w-4xl flex-wrap justify-center gap-2">
              <button
                v-for="suggestion in landingSuggestions"
                :key="suggestion"
                type="button"
                class="rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-uplbGreen hover:text-uplbGreen hover:shadow-sm"
                @click="sendPrompt(suggestion)"
              >
                {{ suggestion }}
              </button>
            </div>
          </div>

          <div v-else class="mx-auto max-w-4xl space-y-5">
            <div
              v-for="message in messages"
              :key="message.id"
              class="flex"
              :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
            >
              <article
                class="max-w-[95%] rounded-[28px] border px-4 py-4 shadow-sm sm:px-5"
                :class="message.role === 'user' ? 'border-amber-200 bg-amber-50 text-slate-900 sm:max-w-2xl' : 'border-slate-200 bg-white text-slate-800 sm:max-w-3xl'"
              >
                <div class="flex items-start justify-between gap-4">
                  <div class="text-xs font-semibold uppercase tracking-[0.2em]" :class="message.role === 'user' ? 'text-amber-700' : 'text-slate-500'">
                    {{ message.role === "user" ? "You" : "SMART-ACSESS" }}
                  </div>
                  <div class="text-[11px] text-slate-400">{{ formatTimestamp(message.created_at) }}</div>
                </div>

                <div class="mt-3 whitespace-pre-wrap text-sm leading-7 text-inherit">
                  {{ message.content }}
                </div>

                <div v-if="message.facts?.length" class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                  <div class="font-semibold text-slate-700">Quick facts</div>
                  <div v-for="fact in message.facts" :key="fact" class="mt-2">
                    {{ fact }}
                  </div>
                </div>

                <div v-if="message.sources?.length && message.role === 'assistant'" class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                  <div class="font-semibold text-slate-700">Grounded sources</div>
                  <div v-for="source in message.sources.slice(0, 5)" :key="`${message.id}-${source.database_name}-${source.resource}`" class="mt-2">
                    {{ source.database_name }}<span v-if="source.resource"> / {{ source.resource }}</span>
                  </div>
                </div>

                <div v-if="message.warnings?.length" class="mt-4 space-y-2">
                  <div
                    v-for="warning in message.warnings"
                    :key="warning"
                    class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800"
                  >
                    {{ warning }}
                  </div>
                </div>

                <div v-if="message.chart?.labels?.length" class="mt-4 rounded-3xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm">
                  <div class="font-semibold text-slate-800">{{ message.chart.title }}</div>
                  <div class="mt-3 space-y-2">
                    <div
                      v-for="(label, index) in message.chart.labels.slice(0, 6)"
                      :key="`${message.id}-${label}`"
                      class="flex items-center justify-between gap-3 rounded-2xl bg-white px-3 py-2"
                    >
                      <span class="truncate text-slate-600">{{ label }}</span>
                      <span class="font-semibold text-slate-900">{{ formatMetric(message.chart.series[index] || 0) }}</span>
                    </div>
                  </div>
                </div>

                <div v-if="message.table?.rows?.length" class="mt-4 overflow-hidden rounded-3xl border border-slate-200 bg-white">
                  <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <div class="text-sm font-semibold text-slate-800">Tabular Result</div>
                      <div class="text-[11px] text-slate-500">
                        {{ message.table.rows.length }} row{{ message.table.rows.length === 1 ? "" : "s" }} available in this response
                      </div>
                    </div>
                    <button
                      type="button"
                      class="sa-btn-ghost text-xs"
                      :disabled="exportingMessageId === message.id"
                      @click="exportTableResult(message)"
                    >
                      {{ exportingMessageId === message.id ? "Exporting..." : "Export Table Result as PDF" }}
                    </button>
                  </div>
                  <div class="overflow-x-auto">
                    <table class="min-w-full bg-white text-xs">
                      <thead>
                        <tr>
                          <th v-for="column in message.table.columns" :key="`${message.id}-${column}`" class="sa-th whitespace-nowrap">
                            {{ column }}
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(row, rowIndex) in message.table.rows.slice(0, 10)" :key="`${message.id}-${rowIndex}`">
                          <td v-for="column in message.table.columns" :key="`${message.id}-${rowIndex}-${column}`" class="sa-td align-top">
                            {{ formatCell(row[column]) }}
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div v-if="message.suggestions?.length && message.role === 'assistant'" class="mt-4 flex flex-wrap gap-2">
                  <button
                    v-for="suggestion in message.suggestions.slice(0, 4)"
                    :key="`${message.id}-${suggestion}`"
                    type="button"
                    class="rounded-full border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] font-medium text-slate-700 transition hover:border-uplbGreen hover:text-uplbGreen"
                    @click="sendPrompt(suggestion)"
                  >
                    {{ suggestion }}
                  </button>
                </div>
              </article>
            </div>
          </div>
        </div>

        <form v-if="!showLandingView" class="border-t border-slate-200 bg-white px-4 py-4 sm:px-6" @submit.prevent="sendPrompt()">
          <div class="rounded-[28px] border border-slate-200 bg-slate-50 p-3 shadow-sm">
            <textarea
              v-model="prompt"
              class="min-h-[72px] w-full resize-none border-0 bg-transparent px-2 py-2 text-sm leading-6 text-slate-800 outline-none placeholder:text-slate-400"
              :disabled="sending || loadingContext || loadingConversation"
              placeholder="Ask anything about the grounded SMART-ACSESS data..."
              @keydown="handleComposerKeydown"
            />
            <div class="flex flex-col gap-3 border-t border-slate-200 px-2 pt-3 sm:flex-row sm:items-center sm:justify-between">
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="suggestion in quickComposerSuggestions"
                  :key="suggestion"
                  type="button"
                  class="rounded-full border border-slate-200 bg-white px-3 py-2 text-[11px] font-medium text-slate-600 transition hover:border-uplbGreen hover:text-uplbGreen"
                  :disabled="sending"
                  @click="sendPrompt(suggestion)"
                >
                  {{ suggestion }}
                </button>
              </div>
              <button class="sa-btn-primary self-end sm:self-auto" :disabled="sending || loadingContext || loadingConversation || !prompt.trim()">
                {{ sending ? "Sending..." : "Send Message" }}
              </button>
            </div>
          </div>
        </form>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue"
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
type ConversationSummary = {
  id: string
  title: string
  preview: string
  message_count: number
  last_message_at?: string | null
  updated_at?: string | null
}
type ChatContextResponse = {
  context_id: string
  summary: string
  suggested_prompts: string[]
  overview: { accessible_database_count: number; known_record_total: number }
  history?: ChatMessage[]
  conversation?: ConversationSummary | null
}
type AskResponse = { context_id: string; history?: ChatMessage[]; conversation?: ConversationSummary | null }

const api = useApi()
const config = useRuntimeConfig()

const context = ref<ChatContextResponse | null>(null)
const contextId = ref("")
const conversations = ref<ConversationSummary[]>([])
const activeConversation = ref<ConversationSummary | null>(null)
const selectedConversationId = ref<string | null>(null)
const prompt = ref("")
const messages = ref<ChatMessage[]>([])
const conversationSearch = ref("")
const error = ref("")
const loadingContext = ref(false)
const loadingConversations = ref(false)
const loadingConversation = ref(false)
const sending = ref(false)
const resetting = ref(false)
const exportingMessageId = ref<string | null>(null)
const historyContainer = ref<HTMLElement | null>(null)

let conversationSearchTimer: ReturnType<typeof setTimeout> | null = null

const showLandingView = computed(() => messages.value.length === 0 && !loadingConversation.value)
const quickComposerSuggestions = computed(() => (context.value?.suggested_prompts || []).slice(0, 3))
const landingSuggestions = computed(() => (context.value?.suggested_prompts || []).slice(0, 4))

onMounted(async () => {
  await Promise.all([loadContext(), loadConversations()])
})

onBeforeUnmount(() => {
  if (conversationSearchTimer) clearTimeout(conversationSearchTimer)
})

watch(conversationSearch, (value) => {
  if (conversationSearchTimer) clearTimeout(conversationSearchTimer)
  conversationSearchTimer = setTimeout(() => {
    loadConversations(value)
  }, 250)
})

async function loadContext(conversationId?: string | null) {
  loadingContext.value = true
  error.value = ""

  try {
    const response = await api.post<ChatContextResponse>("/api/chatbot/context", {
      conversation_id: conversationId ? Number(conversationId) : null,
    })
    context.value = response
    contextId.value = response.context_id

    if (conversationId && response.conversation) {
      activeConversation.value = response.conversation
    }
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to prepare grounded chatbot context.")
  } finally {
    loadingContext.value = false
  }
}

async function loadConversations(search = conversationSearch.value) {
  loadingConversations.value = true

  try {
    const path = search.trim()
      ? `/api/chatbot/conversations?search=${encodeURIComponent(search.trim())}`
      : "/api/chatbot/conversations"
    const response = await api.get<{ conversations?: ConversationSummary[] }>(path)
    conversations.value = response.conversations || []
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to load saved conversations.")
  } finally {
    loadingConversations.value = false
  }
}

async function selectConversation(conversationId: string) {
  if (!conversationId) return

  loadingConversation.value = true
  error.value = ""

  try {
    const response = await api.get<{ conversation?: ConversationSummary | null; messages?: ChatMessage[] }>(
      `/api/chatbot/conversations/${conversationId}`,
    )

    if (!response.conversation) {
      throw new Error("Conversation not found.")
    }

    selectedConversationId.value = response.conversation.id
    activeConversation.value = response.conversation
    messages.value = response.messages || []
    await loadContext(response.conversation.id)
    await scrollToBottom()
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to open that saved conversation.")
  } finally {
    loadingConversation.value = false
  }
}

function startNewConversation() {
  selectedConversationId.value = null
  activeConversation.value = null
  messages.value = []
  prompt.value = ""
  error.value = ""
}

async function sendPrompt(override?: string) {
  const message = (override ?? prompt.value).trim()
  if (!message) return

  const isNewConversation = !selectedConversationId.value
  const optimisticUserMessage: ChatMessage = {
    id: `temp-${Date.now()}`,
    role: "user",
    content: message,
    created_at: new Date().toISOString(),
  }

  prompt.value = ""
  messages.value = [...messages.value, optimisticUserMessage]
  sending.value = true
  error.value = ""

  try {
    if (!contextId.value) await loadContext(selectedConversationId.value)

    const response = await api.post<AskResponse>("/api/chatbot/ask", {
      context_id: contextId.value || null,
      conversation_id: selectedConversationId.value ? Number(selectedConversationId.value) : null,
      new_conversation: isNewConversation,
      prompt: message,
    })

    contextId.value = response.context_id
    messages.value = response.history || messages.value

    if (response.conversation) {
      selectedConversationId.value = response.conversation.id
      activeConversation.value = response.conversation
    }

    await Promise.all([loadConversations(), scrollToBottom()])
  } catch (err) {
    const assistantMessage: ChatMessage = {
      id: `error-${Date.now()}`,
      role: "assistant",
      content: getApiErrorMessage(err, "Unable to reach the grounded chatbot right now."),
      created_at: new Date().toISOString(),
    }
    messages.value = [...messages.value, assistantMessage]
    error.value = assistantMessage.content
    await scrollToBottom()
  } finally {
    sending.value = false
  }
}

async function resetConversation() {
  if (!selectedConversationId.value) {
    startNewConversation()
    return
  }

  resetting.value = true
  error.value = ""

  try {
    const response = await api.post<{ conversation?: ConversationSummary | null }>("/api/chatbot/reset", {
      conversation_id: Number(selectedConversationId.value),
    })

    activeConversation.value = response.conversation || activeConversation.value
    messages.value = []
    await loadConversations()
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to clear the current conversation.")
  } finally {
    resetting.value = false
  }
}

async function exportTableResult(message: ChatMessage) {
  if (!message.table?.rows?.length) return

  const token = process.client ? localStorage.getItem("token") : null
  exportingMessageId.value = message.id
  error.value = ""

  try {
    const response = await fetch(`${config.public.apiBase}/api/chatbot/export/table-pdf`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/pdf,application/json",
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify({
        title: activeConversation.value?.title || "Chatbot Table Result",
        subtitle: "SMART-ACSESS chatbot table export",
        summary: message.content,
        table: message.table,
      }),
    })

    if (!response.ok) {
      const contentType = response.headers.get("content-type") || ""
      if (contentType.includes("application/json")) {
        const payload = await response.json()
        throw new Error(payload?.message || "Unable to export the table result.")
      }

      throw new Error("Unable to export the table result.")
    }

    const blob = await response.blob()
    const downloadUrl = URL.createObjectURL(blob)
    const anchor = document.createElement("a")
    anchor.href = downloadUrl
    anchor.download = extractFilename(response.headers.get("content-disposition")) || defaultPdfFilename()
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    URL.revokeObjectURL(downloadUrl)
  } catch (err: any) {
    error.value = err?.message || "Unable to export the table result."
  } finally {
    exportingMessageId.value = null
  }
}

function handleComposerKeydown(event: KeyboardEvent) {
  if (event.key !== "Enter" || event.shiftKey) return

  event.preventDefault()
  if (!prompt.value.trim() || sending.value || loadingContext.value || loadingConversation.value) return
  sendPrompt()
}

function extractFilename(contentDisposition: string | null) {
  if (!contentDisposition) return null

  const utfMatch = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i)
  if (utfMatch?.[1]) {
    return decodeURIComponent(utfMatch[1])
  }

  const basicMatch = contentDisposition.match(/filename="?([^"]+)"?/i)
  return basicMatch?.[1] || null
}

function defaultPdfFilename() {
  const title = activeConversation.value?.title || "chatbot-table"
  const slug = title
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
  const today = new Date().toISOString().slice(0, 10)

  return `${slug || "chatbot-table"}-${today}.pdf`
}

function formatMetric(value: number) {
  return Number(value || 0).toLocaleString()
}

function formatCell(value: unknown) {
  if (value === null || value === undefined || value === "") return "-"
  if (typeof value === "object") return JSON.stringify(value)
  return String(value)
}

function formatTimestamp(value?: string | null) {
  if (!value) return ""

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return new Intl.DateTimeFormat("en-PH", {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  }).format(date)
}

async function scrollToBottom() {
  await nextTick()
  if (historyContainer.value) {
    historyContainer.value.scrollTop = historyContainer.value.scrollHeight
  }
}
</script>
