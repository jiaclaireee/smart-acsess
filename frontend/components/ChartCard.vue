<template>
  <div class="sa-card p-5 space-y-4">
    <div class="flex items-start justify-between gap-3">
      <div>
        <div class="text-xs text-slate-500">Analytics</div>
        <div class="text-lg font-bold">{{ localConfig.title }}</div>
      </div>
      <div class="flex gap-2">
        <select v-model="localConfig.chartType" class="sa-input text-sm w-24">
          <option value="bar">Bar</option>
          <option value="pie">Pie</option>
          <option value="line">Line</option>
        </select>
        <button class="sa-btn-ghost" :disabled="loading" @click="applyConfig">
          {{ loading ? "Loading..." : "Apply" }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="sa-label">Table</label>
        <select v-model="localConfig.table" class="sa-input">
          <option v-for="t in tableOptions" :key="t" :value="t">{{ t }}</option>
        </select>
      </div>
      <div>
        <label class="sa-label">Metric</label>
        <select v-model="localConfig.metric" class="sa-input">
          <option value="count">count</option>
          <option value="sum">sum</option>
          <option value="avg">avg</option>
        </select>
      </div>
      <div>
        <label class="sa-label">Dimension</label>
        <select v-model="localConfig.dimension" class="sa-input">
          <option value="">(none)</option>
          <option v-for="c in categoricalColumns" :key="c" :value="c">{{ c }}</option>
        </select>
      </div>
      <div>
        <label class="sa-label">Value Column</label>
        <select v-model="localConfig.valueColumn" class="sa-input">
          <option value="">(none)</option>
          <option v-for="c in numericColumns" :key="c" :value="c">{{ c }}</option>
        </select>
      </div>
      <div>
        <label class="sa-label">Date Column</label>
        <select v-model="localConfig.dateColumn" class="sa-input">
          <option value="">(none)</option>
          <option v-for="c in dateColumns" :key="c" :value="c">{{ c }}</option>
        </select>
      </div>
      <div>
        <label class="sa-label">Top N</label>
        <input v-model.number="localConfig.topN" type="number" min="1" max="50" class="sa-input" />
      </div>
    </div>

    <div class="h-80">
      <VChart class="h-full w-full" :option="chartOption" autoresize />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, watch } from "vue"
import { use } from "echarts/core"
import { CanvasRenderer } from "echarts/renderers"
import { BarChart, PieChart, LineChart } from "echarts/charts"
import { TitleComponent, TooltipComponent, LegendComponent, GridComponent } from "echarts/components"
import VChart from "vue-echarts"

use([CanvasRenderer, BarChart, PieChart, LineChart, TitleComponent, TooltipComponent, LegendComponent, GridComponent])

const props = defineProps<{
  config: any
  tables: any[]
  labels: string[]
  series: number[]
  loading: boolean
}>()

const emit = defineEmits<{ (e: "update", value: any): void }>()

const localConfig = reactive({ ...props.config })

watch(() => props.config, (v) => Object.assign(localConfig, v), { deep: true })

const tableOptions = computed(() => props.tables.map((t: any) => t.table))
const currentTable = computed(() => props.tables.find((t: any) => t.table === localConfig.table))

const categoricalColumns = computed(() =>
  ((currentTable.value?.columns || []) as any[])
    .filter((c: any) => /char|text|bool/i.test(c.type))
    .map((c: any) => c.name)
)

const numericColumns = computed(() =>
  ((currentTable.value?.columns || []) as any[])
    .filter((c: any) => /int|numeric|double|real|decimal/i.test(c.type))
    .map((c: any) => c.name)
)

const dateColumns = computed(() =>
  ((currentTable.value?.columns || []) as any[])
    .filter((c: any) => /date|time|timestamp/i.test(c.type) || /date|time|created_at|updated_at/i.test(c.name))
    .map((c: any) => c.name)
)

function applyConfig() {
  emit("update", { ...localConfig })
}

const chartOption = computed(() => {
  if (localConfig.chartType === "pie") {
    return {
      tooltip: { trigger: "item" },
      legend: { bottom: 0 },
      series: [
        {
          type: "pie",
          radius: ["35%", "70%"],
          data: props.labels.map((label, i) => ({ name: label, value: props.series[i] || 0 })),
        },
      ],
    }
  }

  if (localConfig.chartType === "line") {
    return {
      tooltip: { trigger: "axis" },
      xAxis: { type: "category", data: props.labels },
      yAxis: { type: "value" },
      series: [{ type: "line", data: props.series, smooth: true }],
    }
  }

  return {
    tooltip: { trigger: "axis" },
    grid: { left: 40, right: 20, top: 20, bottom: 50 },
    xAxis: { type: "category", data: props.labels, axisLabel: { rotate: 25 } },
    yAxis: { type: "value" },
    series: [{ type: "bar", data: props.series }],
  }
})
</script>
