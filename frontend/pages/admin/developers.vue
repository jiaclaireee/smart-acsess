<template>
  <div class="space-y-6">
    <PageHeader
      title="SMART-ACSESS for Developers"
      subtitle="Admin-only integration reference for Dashboard and Chatbot services."
    >
      <template #actions>
        <button
          class="sa-btn-accent"
          :disabled="loading || downloadingPdf"
          @click="downloadPdf"
        >
          {{ downloadingPdf ? "Preparing PDF..." : "Download Documentation as PDF" }}
        </button>
      </template>
    </PageHeader>

    <section class="overflow-hidden rounded-[32px] border border-emerald-200 bg-[radial-gradient(circle_at_top_left,_rgba(21,94,59,0.14),_transparent_40%),linear-gradient(180deg,_#f7fcf9_0%,_#ffffff_60%)] p-6 shadow-soft sm:p-8">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="max-w-3xl">
          <h1 class="mt-4 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
            Secure integration guides for SMART-ACSESS consumers
          </h1>
          <p class="mt-3 text-sm leading-7 text-slate-600 sm:text-base">
            This portal is generated from the backend documentation source so the page and the downloadable PDF stay aligned with the current Dashboard and Chatbot API contracts.
          </p>
        </div>

        <div v-if="documentation" class="grid gap-3 lg:min-w-[12rem]">
          <div class="rounded-2xl border border-white/70 bg-white/85 p-4">
            <div class="text-xs uppercase tracking-[0.2em] text-slate-500">Generated</div>
            <div class="mt-2 text-lg font-semibold text-slate-900">{{ formattedGeneratedAt }}</div>
          </div>
        </div>
      </div>

      <div v-if="documentation" class="mt-6 flex flex-wrap gap-2">
        <span
          v-for="item in documentation.scope"
          :key="item"
          class="rounded-full border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700"
        >
          {{ item }}
        </span>
      </div>
    </section>

    <div v-if="error" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ error }}
    </div>

    <div v-if="loading" class="rounded-[28px] border border-slate-200 bg-white px-6 py-10 text-center text-sm text-slate-500 shadow-soft">
      Loading developer documentation...
    </div>

    <div v-else-if="documentation" class="space-y-4">
      <details open class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-soft">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5">
          <div>
            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Overview</div>
            <div class="mt-1 text-xl font-bold text-slate-950">Module purpose and scope</div>
          </div>
          <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs text-slate-600">Open</span>
        </summary>
        <div class="border-t border-slate-200 px-6 py-6">
          <div class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
              <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">SMART-ACSESS</div>
              <p class="mt-3 text-sm leading-7 text-slate-700">{{ documentation.overview.description }}</p>
            </article>
            <article class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
              <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Developer Module</div>
              <p class="mt-3 text-sm leading-7 text-slate-700">{{ documentation.overview.purpose }}</p>
            </article>
          </div>

          <div class="mt-5 rounded-3xl border border-slate-200 bg-white p-5">
            <div class="text-sm font-semibold text-slate-900">Coverage</div>
            <ul class="mt-3 space-y-2 text-sm leading-7 text-slate-600">
              <li v-for="item in documentation.overview.coverage" :key="item" class="flex gap-3">
                <span class="mt-2 h-2 w-2 rounded-full bg-emerald-500" />
                <span>{{ item }}</span>
              </li>
            </ul>
          </div>
        </div>
      </details>

      <details open class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-soft">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5">
          <div>
            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Database Columns</div>
            <div class="mt-1 text-xl font-bold text-slate-950">Required, recommended, and optional integration fields</div>
          </div>
          <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs text-slate-600">Open</span>
        </summary>
        <div class="space-y-5 border-t border-slate-200 px-6 py-6">
          <article class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm leading-7 text-slate-700">{{ documentation.database_columns.summary }}</p>
          </article>

          <article
            v-for="group in databaseColumnGroups"
            :key="group.title"
            class="rounded-3xl border border-slate-200 bg-white p-5"
          >
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div class="text-lg font-semibold text-slate-900">{{ group.title }}</div>
              <div class="rounded-full border px-3 py-1 text-xs font-medium" :class="group.badgeClass">
                {{ group.items.length }} column{{ group.items.length === 1 ? "" : "s" }}
              </div>
            </div>

            <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200">
              <table class="min-w-full">
                <thead>
                  <tr>
                    <th class="sa-th w-40">Column</th>
                    <th class="sa-th w-52">Aliases / Examples</th>
                    <th class="sa-th">Purpose</th>
                    <th class="sa-th">Integration Value</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in group.items" :key="`${group.title}-${item.column}`">
                    <td class="sa-td font-semibold">{{ item.column }}</td>
                    <td class="sa-td text-slate-600">{{ item.aliases.join(", ") }}</td>
                    <td class="sa-td text-slate-600">{{ item.purpose }}</td>
                    <td class="sa-td text-slate-600">{{ item.why_it_matters }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </article>
        </div>
      </details>

      <details open class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-soft">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5">
          <div>
            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Authentication</div>
            <div class="mt-1 text-xl font-bold text-slate-950">Sanctum token flow and required headers</div>
          </div>
          <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs text-slate-600">Open</span>
        </summary>
        <div class="space-y-5 border-t border-slate-200 px-6 py-6">
          <article class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm leading-7 text-slate-700">{{ documentation.authentication.summary }}</p>
          </article>

          <div class="grid gap-5 xl:grid-cols-2">
            <article class="rounded-3xl border border-slate-200 bg-white p-5">
              <div class="text-sm font-semibold text-slate-900">
                Obtain token via {{ documentation.authentication.obtain_token.method }} {{ documentation.authentication.obtain_token.endpoint }}
              </div>
              <div class="mt-4 space-y-4">
                <div>
                  <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Headers</div>
                  <pre class="sa-code-block mt-2">{{ formatJson(documentation.authentication.obtain_token.headers) }}</pre>
                </div>
                <div>
                  <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Sample Request</div>
                  <pre class="sa-code-block mt-2">{{ formatJson(documentation.authentication.obtain_token.request_body) }}</pre>
                </div>
              </div>
            </article>

            <article class="rounded-3xl border border-slate-200 bg-white p-5">
              <div class="text-sm font-semibold text-slate-900">Sample response and reuse pattern</div>
              <div class="mt-4 space-y-4">
                <div>
                  <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Login Response</div>
                  <pre class="sa-code-block mt-2">{{ formatJson(documentation.authentication.obtain_token.response_body) }}</pre>
                </div>
                <div>
                  <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Authenticated Headers</div>
                  <pre class="sa-code-block mt-2">{{ formatJson(documentation.authentication.authenticated_request.headers) }}</pre>
                </div>
              </div>
            </article>
          </div>

          <article class="rounded-3xl border border-slate-200 bg-white p-5">
            <div class="text-sm font-semibold text-slate-900">Operational notes</div>
            <ul class="mt-3 space-y-2 text-sm leading-7 text-slate-600">
              <li v-for="note in documentation.authentication.authenticated_request.notes" :key="note" class="flex gap-3">
                <span class="mt-2 h-2 w-2 rounded-full bg-emerald-500" />
                <span>{{ note }}</span>
              </li>
            </ul>
          </article>
        </div>
      </details>

      <details open class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-soft">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5">
          <div>
            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Standard Methods</div>
            <div class="mt-1 text-xl font-bold text-slate-950">GET, POST, PUT/PATCH, and DELETE conventions</div>
          </div>
          <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs text-slate-600">Open</span>
        </summary>
        <div class="space-y-5 border-t border-slate-200 px-6 py-6">
          <article
            v-for="method in documentation.standard_methods"
            :key="method.method"
            class="rounded-3xl border border-slate-200 bg-slate-50 p-5"
          >
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <div class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">
                  {{ method.method }}
                </div>
                <p class="mt-3 text-sm leading-7 text-slate-700">{{ method.description }}</p>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-600">
                Endpoint format: <span class="font-semibold text-slate-900">{{ method.endpoint_format }}</span>
              </div>
            </div>

            <div class="mt-5 grid gap-5 xl:grid-cols-3">
              <div>
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Headers</div>
                <pre class="sa-code-block mt-2">{{ formatJson(method.headers) }}</pre>
              </div>
              <div>
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Request Body</div>
                <pre class="sa-code-block mt-2">{{ formatBody(method.request_body) }}</pre>
              </div>
              <div>
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Sample Response</div>
                <pre class="sa-code-block mt-2">{{ formatJson(method.sample_response) }}</pre>
              </div>
            </div>

            <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white">
              <table class="min-w-full">
                <thead>
                  <tr>
                    <th class="sa-th w-40">Status</th>
                    <th class="sa-th">Handling Guidance</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(details, status) in method.error_handling" :key="status">
                    <td class="sa-td font-semibold">{{ status }}</td>
                    <td class="sa-td text-slate-600">{{ details }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </article>
        </div>
      </details>

      <details open class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-soft">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5">
          <div>
            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Dashboard APIs</div>
            <div class="mt-1 text-xl font-bold text-slate-950">Data retrieval, chart payloads, and drill-down rows</div>
          </div>
          <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs text-slate-600">Open</span>
        </summary>
        <div class="space-y-5 border-t border-slate-200 px-6 py-6">
          <article class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm leading-7 text-slate-700">{{ documentation.dashboard.summary }}</p>
            <ul class="mt-3 space-y-2 text-sm leading-7 text-slate-600">
              <li v-for="note in documentation.dashboard.chart_consumption_notes" :key="note" class="flex gap-3">
                <span class="mt-2 h-2 w-2 rounded-full bg-emerald-500" />
                <span>{{ note }}</span>
              </li>
            </ul>
          </article>

          <article
            v-for="endpoint in documentation.dashboard.endpoints"
            :key="`${endpoint.method}-${endpoint.endpoint}`"
            class="rounded-3xl border border-slate-200 bg-white p-5"
          >
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <div class="text-lg font-semibold text-slate-900">{{ endpoint.name }}</div>
                <p class="mt-2 text-sm text-slate-500">{{ endpoint.method }} {{ endpoint.endpoint }}</p>
              </div>
              <div class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-600">
                {{ parameterCount(endpoint.parameters) }} parameter{{ parameterCount(endpoint.parameters) === 1 ? "" : "s" }}
              </div>
            </div>

            <div class="mt-5 grid gap-5 xl:grid-cols-[1.1fr,1fr,1fr]">
              <div class="overflow-hidden rounded-2xl border border-slate-200">
                <table class="min-w-full">
                  <thead>
                    <tr>
                      <th class="sa-th w-44">Parameter</th>
                      <th class="sa-th">Description</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="parameterCount(endpoint.parameters) === 0">
                      <td class="sa-td text-slate-500" colspan="2">No required parameters.</td>
                    </tr>
                    <tr v-for="(details, name) in endpoint.parameters" v-else :key="name">
                      <td class="sa-td font-semibold">{{ name }}</td>
                      <td class="sa-td text-slate-600">{{ details }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div>
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Example Request</div>
                <pre class="sa-code-block mt-2">{{ formatBody(endpoint.request) }}</pre>
              </div>

              <div>
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Example Response</div>
                <pre class="sa-code-block mt-2">{{ formatJson(endpoint.response) }}</pre>
              </div>
            </div>

            <ul class="mt-5 space-y-2 text-sm leading-7 text-slate-600">
              <li v-for="note in endpoint.notes" :key="note" class="flex gap-3">
                <span class="mt-2 h-2 w-2 rounded-full bg-amber-500" />
                <span>{{ note }}</span>
              </li>
            </ul>
          </article>
        </div>
      </details>

      <details open class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-soft">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5">
          <div>
            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Chatbot APIs</div>
            <div class="mt-1 text-xl font-bold text-slate-950">Queries, responses, conversations, and multilingual behavior</div>
          </div>
          <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs text-slate-600">Open</span>
        </summary>
        <div class="space-y-5 border-t border-slate-200 px-6 py-6">
          <article class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <p class="text-sm leading-7 text-slate-700">{{ documentation.chatbot.summary }}</p>
            <ul class="mt-3 space-y-2 text-sm leading-7 text-slate-600">
              <li v-for="note in documentation.chatbot.multilingual_support" :key="note" class="flex gap-3">
                <span class="mt-2 h-2 w-2 rounded-full bg-emerald-500" />
                <span>{{ note }}</span>
              </li>
            </ul>
          </article>

          <article
            v-for="endpoint in documentation.chatbot.endpoints"
            :key="`${endpoint.method}-${endpoint.endpoint}`"
            class="rounded-3xl border border-slate-200 bg-white p-5"
          >
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <div class="text-lg font-semibold text-slate-900">{{ endpoint.name }}</div>
                <p class="mt-2 text-sm text-slate-500">{{ endpoint.method }} {{ endpoint.endpoint }}</p>
              </div>
              <div class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-600">
                Structured response ready
              </div>
            </div>

            <div class="mt-5 grid gap-5 xl:grid-cols-[1.1fr,1fr,1fr]">
              <div class="overflow-hidden rounded-2xl border border-slate-200">
                <table class="min-w-full">
                  <thead>
                    <tr>
                      <th class="sa-th w-44">Parameter</th>
                      <th class="sa-th">Description</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="parameterCount(endpoint.parameters) === 0">
                      <td class="sa-td text-slate-500" colspan="2">No required parameters.</td>
                    </tr>
                    <tr v-for="(details, name) in endpoint.parameters" v-else :key="name">
                      <td class="sa-td font-semibold">{{ name }}</td>
                      <td class="sa-td text-slate-600">{{ details }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div>
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Example Request</div>
                <pre class="sa-code-block mt-2">{{ formatBody(endpoint.request) }}</pre>
              </div>

              <div>
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Example Response</div>
                <pre class="sa-code-block mt-2">{{ formatJson(endpoint.response) }}</pre>
              </div>
            </div>

            <ul class="mt-5 space-y-2 text-sm leading-7 text-slate-600">
              <li v-for="note in endpoint.notes" :key="note" class="flex gap-3">
                <span class="mt-2 h-2 w-2 rounded-full bg-amber-500" />
                <span>{{ note }}</span>
              </li>
            </ul>
          </article>
        </div>
      </details>

      <details open class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-soft">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5">
          <div>
            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Integration Guide</div>
            <div class="mt-1 text-xl font-bold text-slate-950">Web service flow and integration examples</div>
          </div>
          <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs text-slate-600">Open</span>
        </summary>
        <div class="space-y-5 border-t border-slate-200 px-6 py-6">
          <article class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <div class="text-sm font-semibold text-slate-900">Step-by-step integration flow</div>
            <ol class="mt-3 space-y-3 text-sm leading-7 text-slate-600">
              <li v-for="(step, index) in documentation.integration_guide.steps" :key="step" class="flex gap-4">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-emerald-600 text-xs font-semibold text-white">
                  {{ index + 1 }}
                </span>
                <span>{{ step }}</span>
              </li>
            </ol>
          </article>

          <div class="grid gap-5 xl:grid-cols-2">
            <article
              v-for="useCase in documentation.integration_guide.use_cases"
              :key="useCase.title"
              class="rounded-3xl border border-slate-200 bg-white p-5"
            >
              <div class="text-lg font-semibold text-slate-900">{{ useCase.title }}</div>
              <ul class="mt-4 space-y-2 text-sm leading-7 text-slate-600">
                <li v-for="step in useCase.flow" :key="step" class="flex gap-3">
                  <span class="mt-2 h-2 w-2 rounded-full bg-emerald-500" />
                  <span>{{ step }}</span>
                </li>
              </ul>
            </article>
          </div>
        </div>
      </details>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from "vue"
import { getApiErrorMessage } from "~/composables/useApi"

type CodeValue = Record<string, any> | any[] | string | number | boolean | null
type DocumentationEndpoint = {
  name: string
  method: string
  endpoint: string
  parameters: Record<string, string>
  request: CodeValue
  response: CodeValue
  notes: string[]
}

type DocumentationPayload = {
  title: string
  subtitle: string
  generated_at: string
  audience: string
  scope: string[]
  overview: {
    description: string
    purpose: string
    plain_language: string
    coverage: string[]
  }
  database_columns: {
    summary: string
    required: Array<{
      column: string
      aliases: string[]
      purpose: string
      why_it_matters: string
    }>
    recommended: Array<{
      column: string
      aliases: string[]
      purpose: string
      why_it_matters: string
    }>
    optional: Array<{
      column: string
      aliases: string[]
      purpose: string
      why_it_matters: string
    }>
  }
  authentication: {
    summary: string
    obtain_token: {
      method: string
      endpoint: string
      headers: Record<string, string>
      request_body: Record<string, any>
      response_body: Record<string, any>
    }
    authenticated_request: {
      headers: Record<string, string>
      notes: string[]
    }
  }
  standard_methods: Array<{
    method: string
    description: string
    endpoint_format: string
    headers: Record<string, string>
    request_body: CodeValue
    sample_response: CodeValue
    error_handling: Record<string, string>
  }>
  dashboard: {
    summary: string
    chart_consumption_notes: string[]
    endpoints: DocumentationEndpoint[]
  }
  chatbot: {
    summary: string
    multilingual_support: string[]
    endpoints: DocumentationEndpoint[]
  }
  integration_guide: {
    steps: string[]
    use_cases: Array<{
      title: string
      flow: string[]
    }>
  }
}

const api = useApi()
const config = useRuntimeConfig()
const { user } = useAuth()

const documentation = ref<DocumentationPayload | null>(null)
const loading = ref(false)
const downloadingPdf = ref(false)
const error = ref("")

const formattedGeneratedAt = computed(() => {
  if (!documentation.value?.generated_at) return "-"

  const date = new Date(documentation.value.generated_at)
  if (Number.isNaN(date.getTime())) return documentation.value.generated_at

  return new Intl.DateTimeFormat("en-PH", {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  }).format(date)
})

const databaseColumnGroups = computed(() => {
  if (!documentation.value) return []

  return [
    {
      title: "Required DB Columns",
      items: documentation.value.database_columns.required,
      badgeClass: "border-rose-200 bg-rose-50 text-rose-700",
    },
    {
      title: "Recommended DB Columns",
      items: documentation.value.database_columns.recommended,
      badgeClass: "border-amber-200 bg-amber-50 text-amber-700",
    },
    {
      title: "Other DB Columns",
      items: documentation.value.database_columns.optional,
      badgeClass: "border-emerald-200 bg-emerald-50 text-emerald-700",
    },
  ]
})

onMounted(async () => {
  if (user.value?.role !== "admin") {
    await navigateTo("/forbidden")
    return
  }

  await loadDocumentation()
})

async function loadDocumentation() {
  loading.value = true
  error.value = ""

  try {
    documentation.value = await api.get<DocumentationPayload>("/api/developers/documentation")
  } catch (err: any) {
    if ((err?.statusCode || err?.status) === 403) {
      await navigateTo("/forbidden")
      return
    }

    error.value = getApiErrorMessage(err, "Unable to load the developer documentation.")
  } finally {
    loading.value = false
  }
}

async function downloadPdf() {
  const token = process.client ? localStorage.getItem("token") : null
  downloadingPdf.value = true
  error.value = ""

  try {
    const response = await fetch(`${config.public.apiBase}/api/developers/documentation/pdf`, {
      method: "GET",
      headers: {
        Accept: "application/pdf,application/json",
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    })

    if (!response.ok) {
      const contentType = response.headers.get("content-type") || ""

      if (contentType.includes("application/json")) {
        const payload = await response.json()
        throw new Error(payload?.message || "Unable to download the documentation PDF.")
      }

      throw new Error("Unable to download the documentation PDF.")
    }

    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement("a")
    anchor.href = url
    anchor.download = extractFilename(response.headers.get("content-disposition")) || "smart-acsess-for-developers.pdf"
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    URL.revokeObjectURL(url)
  } catch (err: any) {
    error.value = err?.message || "Unable to download the documentation PDF."
  } finally {
    downloadingPdf.value = false
  }
}

function formatJson(value: CodeValue) {
  if (value === null) return "null"
  if (typeof value === "string") return value
  return JSON.stringify(value, null, 2)
}

function formatBody(value: CodeValue) {
  return value === null ? "No request body required." : formatJson(value)
}

function parameterCount(parameters: Record<string, string>) {
  return Object.keys(parameters || {}).length
}

function extractFilename(contentDisposition: string | null) {
  if (!contentDisposition) return null

  const utfMatch = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i)
  if (utfMatch?.[1]) return decodeURIComponent(utfMatch[1])

  const basicMatch = contentDisposition.match(/filename=\"?([^\"]+)\"?/i)
  return basicMatch?.[1] || null
}
</script>

<style scoped>
.sa-code-block {
  white-space: pre-wrap;
  word-break: break-word;
  border-radius: 1.25rem;
  border: 1px solid #d7dee5;
  background: #0f172a;
  color: #e2e8f0;
  padding: 1rem;
  font-size: 0.75rem;
  line-height: 1.5;
}

summary::-webkit-details-marker {
  display: none;
}
</style>
