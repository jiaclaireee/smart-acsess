<template>
  <div class="space-y-6">
    <PageHeader
      title="Dashboard Reporting"
      subtitle="Build a generic report from any connected database, table, or collection."
    >
      <template #actions>
        <button
          class="sa-btn-accent"
          :disabled="!canExportReport"
          @click="exportPdfReport"
        >
          {{ exportingPdf ? "Exporting..." : "Export PDF Report" }}
        </button>
      </template>
    </PageHeader>

    <div class="sa-card p-5 space-y-5">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div class="text-xs text-slate-500">Filters</div>
          <div class="text-lg font-bold">Select a database, refine the scope, and apply the filters when you are ready.</div>
        </div>
        <div class="flex items-center gap-3">
          <div class="text-xs text-slate-500">
            {{
              loadingReport
                ? "Refreshing report..."
                : isFilterDirty
                  ? "Filters changed. Click Set Filter to refresh."
                  : hasDatabase
                    ? "Report is up to date."
                    : "Choose a database to begin."
            }}
          </div>
          <button
            class="sa-btn-primary"
            :disabled="!canApplyFilters"
            @click="applyFilters"
          >
            {{ loadingReport ? "Applying..." : "Set Filter" }}
          </button>
          <button
            class="sa-btn-ghost"
            :disabled="!canClearFilters"
            @click="clearFilters"
          >
            Clear
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6">
        <div class="xl:col-span-2">
          <label class="sa-label">Select Database <span class="text-rose-500">*</span></label>
          <select v-model="filters.dbId" class="sa-input">
            <option value="">Choose a database</option>
            <option v-for="database in databases" :key="database.id" :value="String(database.id)">
              {{ database.name }}
            </option>
          </select>
        </div>

        <div class="xl:col-span-2">
          <label class="sa-label">Select {{ resourceLabel }}</label>
          <select v-model="filters.resource" class="sa-input" :disabled="!hasDatabase || loadingResources">
            <option value="">{{ hasDatabase ? `All ${resourceLabelPlural}` : "Choose a database first" }}</option>
            <option v-for="resource in resources" :key="resource" :value="resource">
              {{ resource }}
            </option>
          </select>
        </div>

        <div>
          <label class="sa-label">From Date</label>
          <input v-model="filters.from" type="date" class="sa-input" :disabled="!hasDatabase" />
        </div>

        <div>
          <label class="sa-label">To Date</label>
          <input v-model="filters.to" type="date" class="sa-input" :disabled="!hasDatabase" />
        </div>

        <div>
          <label class="sa-label">Report Period</label>
          <select v-model="filters.period" class="sa-input" :disabled="!hasDatabase">
            <option value="none">None</option>
            <option value="daily">Daily</option>
            <option value="weekly">Weekly</option>
            <option value="monthly">Monthly</option>
            <option value="semiannual">Semi-Annual</option>
            <option value="annual">Annually</option>
          </select>
        </div>

        <div>
          <label class="sa-label">Graphical Representation</label>
          <select v-model="filters.graphType" class="sa-input" :disabled="!hasDatabase">
            <option value="table">Table Report</option>
            <option value="bar">Bar Graph</option>
            <option value="pie">Pie Chart</option>
            <option value="line">Line Graph</option>
          </select>
        </div>

        <div>
          <label class="sa-label">Visualization Column</label>
          <select
            v-model="filters.visualizationColumn"
            class="sa-input"
            :disabled="!hasDatabase || !filters.resource || loadingSchema || resourceColumns.length === 0"
          >
            <option value="">
              {{
                !filters.resource
                  ? `Select a ${resourceLabel.toLowerCase()} first`
                  : loadingSchema
                    ? "Loading columns..."
                    : resourceColumns.length === 0
                      ? "No columns available"
                      : "Auto-detect"
              }}
            </option>
            <option v-for="column in resourceColumns" :key="column.name" :value="column.name">
              {{ column.name }} ({{ column.type }})
            </option>
          </select>
        </div>

        <div>
          <label class="sa-label">Rows Per Page</label>
          <select v-model.number="filters.perPage" class="sa-input" :disabled="!hasDatabase">
            <option :value="10">10</option>
            <option :value="25">25</option>
            <option :value="50">50</option>
          </select>
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
        Empty date fields keep reporting unfiltered. When a period is selected and a date-like column is detected, results are grouped by that period automatically.
      </div>
      <div v-if="filters.resource" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-600">
        Choose a visualization column to control which field the chart groups by. Date-like columns work best with a report period.
      </div>
    </div>

    <div v-if="error" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ error }}
    </div>

    <div v-if="warnings.length" class="space-y-2">
      <div
        v-for="warning in warnings"
        :key="warning"
        class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
      >
        {{ warning }}
      </div>
    </div>

    <div v-if="!hasDatabase" class="sa-card p-10 text-center text-slate-500">
      Select a database connection to generate a dashboard report.
    </div>

    <div v-else-if="loadingReport && !report" class="sa-card p-10 text-center text-slate-500">
      Loading report data...
    </div>

    <div v-else-if="hasDatabase && !report" class="sa-card p-10 text-center text-slate-500">
      Filters are ready. Click <span class="font-semibold text-slate-700">Set Filter</span> to generate the report.
    </div>

    <template v-else-if="report">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatCard
          v-for="kpi in report.kpis"
          :key="kpi.label"
          :label="kpi.label"
          :value="formatMetric(kpi.value)"
          :hint="kpi.hint"
        >
          <template #icon>
            <span class="text-xs font-bold tracking-wide">{{ shortLabel(kpi.label) }}</span>
          </template>
        </StatCard>
      </div>

      <div v-if="filters.graphType !== 'table'" class="sa-card p-5">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <div class="text-xs text-slate-500">Visualization</div>
            <div class="text-lg font-bold">{{ report.chart.title }}</div>
            <div class="mt-1 text-xs text-slate-500">{{ chartSubtitle }}</div>
          </div>
          <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] text-slate-600">
            {{ filters.graphType === "pie" ? "Top grouped segments" : "Chart-ready dataset" }}
          </span>
        </div>

        <div v-if="chartHasData" class="mt-4 h-[360px]">
          <ClientOnly>
            <VChart class="h-full w-full" :option="chartOption" autoresize />
          </ClientOnly>
        </div>
        <div v-else class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">
          {{ report.chart.empty_message || "No chart data is available for the selected filters." }}
        </div>
      </div>

      <div class="sa-card p-5">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <div class="text-xs text-slate-500">{{ filters.graphType === "table" ? "Table Report" : "Report Table" }}</div>
            <div class="text-lg font-bold">
              {{ report.selected_resource ? `Data from ${report.selected_resource}` : `${resourceLabelPlural} overview` }}
            </div>
            <div class="mt-1 text-xs text-slate-500">{{ tableSubtitle }}</div>
          </div>

          <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div>
              <label class="sa-label">Sort By</label>
              <select v-model="filters.sortBy" class="sa-input" :disabled="!sortableColumns.length || !report.selected_resource">
                <option value="">Default</option>
                <option v-for="column in sortableColumns" :key="column" :value="column">{{ column }}</option>
              </select>
            </div>
            <div>
              <label class="sa-label">Direction</label>
              <select v-model="filters.sortDirection" class="sa-input" :disabled="!sortableColumns.length || !report.selected_resource">
                <option value="asc">Ascending</option>
                <option value="desc">Descending</option>
              </select>
            </div>
            <div class="col-span-2">
              <label class="sa-label">Detected Fields</label>
              <div class="mt-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                Date: {{ report.schema?.detected?.date_column || "Not detected" }} |
                Group: {{ report.schema?.detected?.group_column || "Not detected" }}
              </div>
            </div>
          </div>
        </div>

        <div class="mt-4 overflow-auto rounded-2xl border border-slate-200">
          <table class="min-w-full">
            <thead>
              <tr>
                <th v-for="column in report.table.columns" :key="column" class="sa-th whitespace-nowrap">
                  <button
                    type="button"
                    class="flex items-center gap-2"
                    :disabled="!canSortColumn(column)"
                    @click="toggleSort(column)"
                  >
                    <span>{{ column }}</span>
                    <span v-if="filters.sortBy === column" class="text-[10px]">
                      {{ filters.sortDirection === "asc" ? "ASC" : "DESC" }}
                    </span>
                  </button>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in report.table.rows" :key="index">
                <td v-for="column in report.table.columns" :key="column" class="sa-td align-top">
                  <span class="break-words">{{ formatCell(row[column]) }}</span>
                </td>
              </tr>
              <tr v-if="report.table.rows.length === 0">
                <td class="sa-td text-slate-500" :colspan="report.table.columns.length || 1">
                  No records were returned for the selected filters.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div class="text-xs text-slate-500">
            Showing {{ report.table.pagination.from ?? 0 }} to {{ report.table.pagination.to ?? 0 }} of {{ report.table.pagination.total }} results
          </div>

          <div class="flex items-center gap-2">
            <button class="sa-btn-ghost" :disabled="filters.page <= 1 || loadingReport || isFilterDirty" @click="goToPage(filters.page - 1)">
              Previous
            </button>
            <span class="rounded-xl border border-slate-200 px-3 py-2 text-xs text-slate-600">
              Page {{ report.table.pagination.page }} of {{ report.table.pagination.last_page }}
            </span>
            <button
              class="sa-btn-ghost"
              :disabled="filters.page >= report.table.pagination.last_page || loadingReport || isFilterDirty"
              @click="goToPage(filters.page + 1)"
            >
              Next
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue"
import { use } from "echarts/core"
import { CanvasRenderer } from "echarts/renderers"
import { BarChart, LineChart, PieChart } from "echarts/charts"
import { GridComponent, LegendComponent, TooltipComponent } from "echarts/components"
import VChart from "vue-echarts"
import { getApiErrorMessage } from "~/composables/useApi"

use([CanvasRenderer, BarChart, LineChart, PieChart, GridComponent, LegendComponent, TooltipComponent])

type DatabaseItem = {
  id: number
  name: string
  resource_label?: "table" | "collection"
}

type SchemaColumn = {
  name: string
  type: string
}

type ReportResponse = {
  resource_type: "table" | "collection"
  resources: string[]
  selected_resource: string | null
  warnings: string[]
  chart: {
    type: "table" | "bar" | "pie" | "line"
    title: string
    labels: string[]
    series: number[]
    empty_message?: string | null
    meta?: {
      mode?: string
      group_by?: string | null
    }
  }
  schema: null | {
    detected?: {
      date_column?: string | null
      group_column?: string | null
    }
  }
  kpis: Array<{
    label: string
    value: string | number
    hint?: string
  }>
  table: {
    columns: string[]
    rows: Record<string, any>[]
    pagination: {
      page: number
      per_page: number
      total: number
      last_page: number
      from: number | null
      to: number | null
    }
  }
}

const api = useApi()

const databases = ref<DatabaseItem[]>([])
const resources = ref<string[]>([])
const report = ref<ReportResponse | null>(null)
const error = ref("")
const loadingResources = ref(false)
const loadingReport = ref(false)
const loadingSchema = ref(false)
const exportingPdf = ref(false)
const requestToken = ref(0)
const resourceColumns = ref<SchemaColumn[]>([])
const isFilterDirty = ref(false)
const resourceType = ref<"table" | "collection">("table")

const filters = reactive({
  dbId: "",
  resource: "",
  from: "",
  to: "",
  period: "none",
  graphType: "table",
  visualizationColumn: "",
  page: 1,
  perPage: 25,
  sortBy: "",
  sortDirection: "asc" as "asc" | "desc",
})

const hasDatabase = computed(() => filters.dbId !== "")
const selectedDatabase = computed(() =>
  databases.value.find((database) => String(database.id) === filters.dbId) || null,
)
const canApplyFilters = computed(() => hasDatabase.value && !loadingResources.value && !loadingReport.value && (isFilterDirty.value || !report.value))
const canExportReport = computed(() => !!report.value && !loadingReport.value && !exportingPdf.value && !isFilterDirty.value)
const canClearFilters = computed(() =>
  loadingReport.value || exportingPdf.value
    ? false
    : Boolean(
        filters.dbId
        || filters.resource
        || filters.from
        || filters.to
        || filters.period !== "none"
        || filters.graphType !== "table"
        || filters.visualizationColumn
        || filters.page !== 1
        || filters.perPage !== 25
        || filters.sortBy
        || filters.sortDirection !== "asc"
        || report.value,
      ),
)
const activeResourceType = computed(() => report.value?.resource_type || selectedDatabase.value?.resource_label || resourceType.value)
const resourceLabel = computed(() => activeResourceType.value === "collection" ? "Collection" : "Table")
const resourceLabelPlural = computed(() => activeResourceType.value === "collection" ? "collections" : "tables")
const warnings = computed(() => report.value?.warnings || [])
const sortableColumns = computed(() => report.value?.table.columns || [])
const chartHasData = computed(() => (report.value?.chart.labels.length || 0) > 0)
const selectedVisualizationColumn = computed(
  () => resourceColumns.value.find((column) => column.name === filters.visualizationColumn) || null,
)
const selectedVisualizationIsDate = computed(
  () => selectedVisualizationColumn.value ? isDateColumn(selectedVisualizationColumn.value) : false,
)

const chartSubtitle = computed(() => {
  if (!report.value) return ""

  if (report.value.chart.meta?.mode === "date") {
    return `Grouped using ${report.value.chart.meta.group_by || "the detected date field"} and the selected reporting period.`
  }

  if (report.value.chart.meta?.mode === "group") {
    return `Grouped by ${report.value.chart.meta.group_by || "the detected field"}.`
  }

  return "Generated from the current report payload."
})

const tableSubtitle = computed(() => {
  if (!report.value) return ""

  if (report.value.selected_resource) {
    return "Paginated rows update whenever the selected filters change."
  }

  return `No specific ${resourceLabel.value.toLowerCase()} is selected, so the table shows an overview of available ${resourceLabelPlural.value}.`
})

const chartOption = computed(() => {
  const labels = report.value?.chart.labels || []
  const series = report.value?.chart.series || []
  const palette = ["#155e3b", "#efb21a", "#2b7fff", "#d9485f", "#4a9f74", "#7a4ef7"]

  if (filters.graphType === "pie") {
    return {
      color: palette,
      tooltip: { trigger: "item" },
      legend: { bottom: 0 },
      series: [
        {
          type: "pie",
          radius: ["35%", "72%"],
          center: ["50%", "45%"],
          data: labels.map((label, index) => ({ name: label, value: series[index] || 0 })),
          label: { formatter: "{b}: {d}%" },
        },
      ],
    }
  }

  if (filters.graphType === "line") {
    return {
      color: [palette[0]],
      tooltip: { trigger: "axis" },
      grid: { left: 40, right: 20, top: 20, bottom: 50 },
      xAxis: { type: "category", data: labels, axisLabel: { rotate: labels.length > 6 ? 25 : 0 } },
      yAxis: { type: "value" },
      series: [
        {
          type: "line",
          data: series,
          smooth: true,
          areaStyle: { opacity: 0.12 },
        },
      ],
    }
  }

  return {
    color: [palette[0]],
    tooltip: { trigger: "axis" },
    grid: { left: 40, right: 20, top: 20, bottom: 70 },
    xAxis: { type: "category", data: labels, axisLabel: { rotate: labels.length > 5 ? 30 : 0 } },
    yAxis: { type: "value" },
    series: [
      {
        type: "bar",
        data: series,
        barMaxWidth: 48,
        itemStyle: { borderRadius: [10, 10, 0, 0] },
      },
    ],
  }
})

onMounted(async () => {
  await loadDatabases()
})

watch(() => filters.dbId, async (nextDbId) => {
  filters.resource = ""
  filters.page = 1
  filters.sortBy = ""
  filters.sortDirection = "asc"
  filters.visualizationColumn = ""
  report.value = null
  error.value = ""
  resources.value = []
  resourceColumns.value = []
  isFilterDirty.value = !!nextDbId

  if (!nextDbId) {
    isFilterDirty.value = false
    return
  }

  await loadResources(nextDbId)
})

watch(() => filters.resource, async (nextResource) => {
  filters.visualizationColumn = ""

  if (!hasDatabase.value) return

  if (!nextResource) {
    resourceColumns.value = []
    isFilterDirty.value = true
    return
  }

  await loadResourceSchema(nextResource)
  isFilterDirty.value = true
})

watch(
  () => [filters.from, filters.to, filters.period, filters.graphType, filters.visualizationColumn, filters.perPage, filters.sortBy, filters.sortDirection],
  () => {
    if (!hasDatabase.value) return
    isFilterDirty.value = true
  },
)

async function loadDatabases() {
  try {
    databases.value = await api.get<DatabaseItem[]>("/api/databases")
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to load connected databases.")
  }
}

async function loadResources(dbId: string) {
  loadingResources.value = true

  try {
    const response = await api.get<{ items?: string[]; resource_type?: "table" | "collection" }>(`/api/databases/${dbId}/tables`)
    resources.value = response.items || []
    resourceType.value = response.resource_type || selectedDatabase.value?.resource_label || "table"
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to load tables or collections for the selected database.")
  } finally {
    loadingResources.value = false
  }
}

async function loadResourceSchema(resource: string) {
  if (!filters.dbId) return

  loadingSchema.value = true

  try {
    const response = await api.get<{ schema?: Array<{ columns?: SchemaColumn[] }> }>(
      `/api/databases/${filters.dbId}/schema?resource=${encodeURIComponent(resource)}`,
    )
    resourceColumns.value = response.schema?.[0]?.columns || []
  } catch (err) {
    resourceColumns.value = []
    error.value = getApiErrorMessage(err, "Unable to load columns for the selected table or collection.")
  } finally {
    loadingSchema.value = false
  }
}

async function fetchReport() {
  if (!filters.dbId) return

  if (!validateDateRange()) return

  const currentToken = ++requestToken.value
  loadingReport.value = true
  error.value = ""

  try {
    const response = await api.post<ReportResponse>("/api/analytics/report", {
      db_id: Number(filters.dbId),
      resource: filters.resource || null,
      from: filters.from || null,
      to: filters.to || null,
      period: filters.period,
      graph_type: filters.graphType,
      group_column: filters.visualizationColumn || null,
      date_column: selectedVisualizationIsDate.value ? filters.visualizationColumn || null : null,
      page: filters.page,
      per_page: filters.perPage,
      sort_by: filters.sortBy || null,
      sort_direction: filters.sortDirection,
    })

    if (currentToken !== requestToken.value) return

    report.value = response
    isFilterDirty.value = false
    resources.value = response.resources || resources.value

    if (filters.resource && !resources.value.includes(filters.resource)) {
      filters.resource = ""
    }

    if (filters.page > response.table.pagination.last_page) {
      filters.page = response.table.pagination.last_page || 1
    }
  } catch (err) {
    if (currentToken !== requestToken.value) return
    report.value = null
    error.value = getApiErrorMessage(err, "Unable to generate the dashboard report.")
  } finally {
    if (currentToken === requestToken.value) {
      loadingReport.value = false
    }
  }
}

async function applyFilters() {
  if (!hasDatabase.value) return
  if (!validateDateRange()) return

  filters.page = 1
  await fetchReport()
}

async function exportPdfReport() {
  if (!report.value || !filters.dbId || isFilterDirty.value) return

  const config = useRuntimeConfig()
  const token = process.client ? localStorage.getItem("token") : null

  exportingPdf.value = true
  error.value = ""

  try {
    if (!validateDateRange()) return

    const response = await fetch(`${config.public.apiBase}/api/reports/dashboard-pdf`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/pdf,application/json",
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify({
        db_id: Number(filters.dbId),
        resource: filters.resource || null,
        from: filters.from || null,
        to: filters.to || null,
        period: filters.period,
        graph_type: filters.graphType,
        group_column: filters.visualizationColumn || null,
        date_column: selectedVisualizationIsDate.value ? filters.visualizationColumn || null : null,
        page: filters.page,
        per_page: filters.perPage,
        sort_by: filters.sortBy || null,
        sort_direction: filters.sortDirection,
      }),
    })

    if (!response.ok) {
      const contentType = response.headers.get("content-type") || ""
      if (contentType.includes("application/json")) {
        const payload = await response.json()
        throw new Error(payload?.message || "Unable to export the PDF report.")
      }

      throw new Error("Unable to export the PDF report.")
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
    error.value = err?.message || "Unable to export the PDF report."
  } finally {
    exportingPdf.value = false
  }
}

function clearFilters() {
  filters.resource = ""
  filters.from = ""
  filters.to = ""
  filters.period = "none"
  filters.graphType = "table"
  filters.visualizationColumn = ""
  filters.page = 1
  filters.perPage = 25
  filters.sortBy = ""
  filters.sortDirection = "asc"

  report.value = null
  resourceColumns.value = []
  error.value = ""
  isFilterDirty.value = hasDatabase.value
}

function validateDateRange() {
  if (!filters.from || !filters.to) return true

  if (filters.from <= filters.to) return true

  error.value = "The end date must be on or after the start date."
  return false
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
  const dbName = databases.value.find((database) => String(database.id) === filters.dbId)?.name || "report"
  const slug = dbName
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")

  const today = new Date().toISOString().slice(0, 10)

  return `report-${slug || "dashboard"}-${today}.pdf`
}

function shortLabel(label: string) {
  return label
    .split(" ")
    .map((part) => part[0] || "")
    .join("")
    .slice(0, 3)
    .toUpperCase()
}

function formatMetric(value: string | number) {
  if (typeof value === "number") return value.toLocaleString()
  return value
}

function formatCell(value: unknown) {
  if (value === null || value === undefined || value === "") return "-"
  if (typeof value === "object") return JSON.stringify(value)
  return String(value)
}

function canSortColumn(column: string) {
  return sortableColumns.value.includes(column) && !!report.value?.selected_resource
}

function toggleSort(column: string) {
  if (!canSortColumn(column)) return

  if (filters.sortBy !== column) {
    filters.sortBy = column
    filters.sortDirection = "asc"
    return
  }

  filters.sortDirection = filters.sortDirection === "asc" ? "desc" : "asc"
}

async function goToPage(page: number) {
  if (isFilterDirty.value || page < 1 || page === filters.page) return

  filters.page = page
  await fetchReport()
}

function isDateColumn(column: SchemaColumn) {
  return /date|time|timestamp/i.test(column.type) || /(^|_)(date|time|created_at|updated_at|deleted_at)$/i.test(column.name)
}
</script>
