<template>
  <div class="space-y-6">
    <PageHeader
      title="Schema Viewer"
      subtitle="Detected tables or collections and their fields from the selected connection."
    >
      <template #actions>
        <NuxtLink class="sa-btn-ghost" to="/admin/connections">Back</NuxtLink>
      </template>
    </PageHeader>

    <div v-if="loading" class="sa-card p-5">Loading...</div>

    <div v-else class="space-y-4">
      <div v-for="t in schema" :key="t.resource || t.table" class="sa-card p-5">
        <div class="flex items-center justify-between">
          <div class="text-lg font-bold">{{ t.resource || t.table }}</div>
          <span class="text-[11px] px-2 py-1 rounded-full bg-slate-100 border border-slate-200">
            {{ t.columns.length }} fields
          </span>
        </div>

        <div class="mt-4 overflow-auto border border-slate-200 rounded-2xl">
          <table class="min-w-full">
            <thead>
              <tr>
                <th class="sa-th">Column</th>
                <th class="sa-th">Type</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in t.columns" :key="c.name">
                <td class="sa-td font-semibold">{{ c.name }}</td>
                <td class="sa-td text-slate-600">{{ c.type }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="schema.length===0" class="sa-card p-5 text-sm text-slate-600">
        No tables or collections detected. Check the saved connection details and permissions.
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const api = useApi()
const route = useRoute()
const loading = ref(true)
const schema = ref<any[]>([])

onMounted(async () => {
  try {
    const res = await api.get(`/api/databases/${route.params.id}/schema`) as any
    schema.value = res.schema
  } finally {
    loading.value = false
  }
})
</script>
