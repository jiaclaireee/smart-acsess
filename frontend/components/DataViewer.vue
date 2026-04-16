<template>
  <div class="sa-card p-5">
    <div class="flex items-center justify-between mb-3">
      <div>
        <div class="text-xs text-slate-500">Data Viewer</div>
        <div class="text-lg font-bold">{{ title }}</div>
      </div>
      <div class="text-xs text-slate-500">{{ rows.length }} rows shown</div>
    </div>

    <div class="overflow-auto border border-slate-200 rounded-2xl">
      <table class="min-w-full">
        <thead>
          <tr>
            <th v-for="col in columns" :key="col" class="sa-th">{{ col }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, idx) in rows" :key="idx">
            <td v-for="col in columns" :key="col" class="sa-td">{{ row[col] }}</td>
          </tr>
          <tr v-if="rows.length === 0">
            <td class="sa-td text-slate-500" :colspan="columns.length || 1">No records loaded.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  title: string
  columns: string[]
  rows: any[]
}>()
</script>
