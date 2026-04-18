<template>
  <div class="space-y-6">
    <PageHeader
      title="Audit Trail"
      subtitle="Review who performed key SMART-ACSESS actions across users, databases, dashboard reports, chatbot usage, and developer documentation exports."
    >
      <template #actions>
        <button class="sa-btn-ghost" :disabled="loading" @click="load">
          {{ loading ? "Refreshing..." : "Refresh" }}
        </button>
      </template>
    </PageHeader>

    <section class="sa-card p-5 space-y-5">
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <div class="xl:col-span-2">
          <label class="sa-label">Search</label>
          <input v-model="filters.search" class="sa-input" placeholder="User, action, module, or description" />
        </div>

        <div>
          <label class="sa-label">Module</label>
          <select v-model="filters.module" class="sa-input">
            <option value="">All modules</option>
            <option v-for="option in moduleOptions" :key="option" :value="option">{{ option }}</option>
          </select>
        </div>

        <div>
          <label class="sa-label">From Date</label>
          <input v-model="filters.from" type="date" class="sa-input" />
        </div>

        <div>
          <label class="sa-label">To Date</label>
          <input v-model="filters.to" type="date" class="sa-input" />
        </div>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="text-xs text-slate-500">
          Tracks add-user, add-database, dashboard filter usage, dashboard exports, chatbot conversations, chatbot exports, and developer documentation exports.
        </div>
        <div class="flex gap-2">
          <button class="sa-btn-primary" :disabled="loading" @click="applyFilters">
            {{ loading ? "Loading..." : "Apply Filters" }}
          </button>
          <button class="sa-btn-ghost" :disabled="loading" @click="resetFilters">Clear</button>
        </div>
      </div>
    </section>

    <div v-if="error" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ error }}
    </div>

    <section class="sa-card overflow-hidden">
      <div class="border-b border-slate-200 px-5 py-4">
        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Activity Feed</div>
        <div class="mt-1 text-lg font-bold text-slate-950">Recorded system actions</div>
      </div>

      <div class="overflow-auto">
        <table class="min-w-full">
          <thead>
            <tr>
              <th class="sa-th">User Name</th>
              <th class="sa-th">Action</th>
              <th class="sa-th">Module</th>
              <th class="sa-th">Date</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td class="sa-td text-slate-500" colspan="4">Loading audit trail entries...</td>
            </tr>
            <tr v-for="entry in entries" :key="entry.id">
              <td class="sa-td align-top">
                <div class="font-semibold text-slate-900">{{ entry.user_name }}</div>
                <div class="mt-1 text-xs text-slate-500">{{ entry.user_email || "No email snapshot" }}</div>
              </td>
              <td class="sa-td align-top">
                <div class="font-semibold text-slate-900">{{ entry.action }}</div>
                <div class="mt-1 text-xs text-slate-500">{{ entry.description }}</div>
                <div
                  v-for="detail in dashboardDetails(entry)"
                  :key="`${entry.id}-${detail}`"
                  class="mt-1 text-xs text-slate-600"
                >
                  {{ detail }}
                </div>
              </td>
              <td class="sa-td align-top">
                <span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-medium text-slate-700">
                  {{ entry.module }}
                </span>
              </td>
              <td class="sa-td align-top text-slate-600">
                {{ formatDate(entry.created_at) }}
              </td>
            </tr>
            <tr v-if="!loading && entries.length === 0">
              <td class="sa-td text-slate-500" colspan="4">No audit trail entries matched the current filters.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="flex flex-col gap-3 border-t border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="text-xs text-slate-500">
          Showing {{ pagination.from ?? 0 }} to {{ pagination.to ?? 0 }} of {{ pagination.total }} entries
        </div>
        <div class="flex items-center gap-2">
          <button class="sa-btn-ghost" :disabled="loading || pagination.page <= 1" @click="goToPage(pagination.page - 1)">
            Previous
          </button>
          <span class="rounded-xl border border-slate-200 px-3 py-2 text-xs text-slate-600">
            Page {{ pagination.page }} of {{ pagination.last_page }}
          </span>
          <button class="sa-btn-ghost" :disabled="loading || pagination.page >= pagination.last_page" @click="goToPage(pagination.page + 1)">
            Next
          </button>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue"
import { getApiErrorMessage } from "~/composables/useApi"

type AuditTrailEntry = {
  id: number
  user_name: string
  user_email?: string | null
  module: string
  action: string
  description?: string | null
  subject_type?: string | null
  subject_id?: string | null
  metadata: Record<string, any>
  ip_address?: string | null
  user_agent?: string | null
  created_at?: string | null
}

type AuditTrailResponse = {
  data: AuditTrailEntry[]
  current_page: number
  last_page: number
  total: number
  from: number | null
  to: number | null
}

const api = useApi()
const entries = ref<AuditTrailEntry[]>([])
const loading = ref(false)
const error = ref("")
const filters = reactive({
  search: "",
  module: "",
  from: "",
  to: "",
  page: 1,
})

const pagination = reactive({
  page: 1,
  last_page: 1,
  total: 0,
  from: null as number | null,
  to: null as number | null,
})

const moduleOptions = computed(() => {
  return Array.from(new Set(entries.value.map((entry) => entry.module))).sort()
})

onMounted(load)

async function load() {
  loading.value = true
  error.value = ""

  try {
    const query = new URLSearchParams()

    if (filters.search.trim()) query.set("search", filters.search.trim())
    if (filters.module) query.set("module", filters.module)
    if (filters.from) query.set("from", filters.from)
    if (filters.to) query.set("to", filters.to)
    query.set("page", String(filters.page))

    const response = await api.get<AuditTrailResponse>(`/api/audit-trails?${query.toString()}`)
    entries.value = response.data || []
    pagination.page = response.current_page || 1
    pagination.last_page = response.last_page || 1
    pagination.total = response.total || 0
    pagination.from = response.from ?? null
    pagination.to = response.to ?? null
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to load the audit trail.")
  } finally {
    loading.value = false
  }
}

async function applyFilters() {
  filters.page = 1
  await load()
}

async function resetFilters() {
  filters.search = ""
  filters.module = ""
  filters.from = ""
  filters.to = ""
  filters.page = 1
  await load()
}

async function goToPage(page: number) {
  if (page < 1 || page === filters.page) return
  filters.page = page
  await load()
}

function formatDate(value?: string | null) {
  if (!value) return "-"
  return new Date(value).toLocaleString()
}

function dashboardDetails(entry: AuditTrailEntry) {
  if (entry.module !== "Dashboard") {
    return []
  }

  const metadata = entry.metadata || {}
  const details: string[] = []
  const databaseName = firstPresent(metadata.database_name, metadata.database)
  const selectedTable = firstPresent(
    metadata.selected_table,
    metadata.resource_type === "table" ? metadata.resource : null,
    metadata.returned_resource,
  )
  const columnFilters = [
    metadata.date_column ? `date column: ${metadata.date_column}` : "",
    metadata.group_column ? `group column: ${metadata.group_column}` : "",
    metadata.sort_by
      ? `sort by: ${metadata.sort_by}${metadata.sort_direction ? ` (${String(metadata.sort_direction).toUpperCase()})` : ""}`
      : "",
  ].filter(Boolean)

  if (databaseName) {
    details.push(`Database: ${databaseName}`)
  }

  if (selectedTable) {
    details.push(`Table: ${selectedTable}`)
  }

  if (columnFilters.length > 0) {
    details.push(`Column filters: ${columnFilters.join(", ")}`)
  }

  return details
}

function firstPresent(...values: unknown[]) {
  for (const value of values) {
    if (typeof value === "string" && value.trim()) {
      return value.trim()
    }
  }

  return ""
}
</script>
