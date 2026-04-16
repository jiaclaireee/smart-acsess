<template>
  <div class="space-y-6">
    <PageHeader
      title="Databases"
      subtitle="Add multiple PostgreSQL databases via DSN connection string."
    >
      <template #actions>
        <button class="sa-btn-accent" @click="open = true">Add Database</button>
      </template>
    </PageHeader>

    <div class="sa-card p-5">
      <div class="overflow-auto border border-slate-200 rounded-2xl">
        <table class="min-w-full">
          <thead>
            <tr>
              <th class="sa-th">Name</th>
              <th class="sa-th">Created</th>
              <th class="sa-th">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in dbs" :key="d.id">
              <td class="sa-td font-semibold">{{ d.name }}</td>
              <td class="sa-td text-slate-500">{{ d.created_at ? new Date(d.created_at).toLocaleString() : "—" }}</td>
              <td class="sa-td">
                <div class="flex gap-2">
                  <NuxtLink class="sa-btn-ghost" :to="`/admin/databases/${d.id}/schema`">Schema</NuxtLink>
                  <button class="sa-btn-danger" @click="remove(d)">Delete</button>
                </div>
              </td>
            </tr>
            <tr v-if="dbs.length===0">
              <td class="sa-td text-slate-500" colspan="3">No connected databases yet.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mt-4 p-3 rounded-2xl border border-slate-200 bg-slate-50 text-xs text-slate-600">
        DSN format: <b>postgresql://username:password@host:5432/dbname?sslmode=prefer</b>
      </div>
    </div>

    <Modal v-if="open" title="Add Database" @close="open=false">
      <form class="space-y-4" @submit.prevent="create">
        <div>
          <label class="sa-label">Name</label>
          <input v-model="name" class="sa-input" placeholder="e.g., CCTV_DB" required />
        </div>
        <div>
          <label class="sa-label">Connection String (DSN)</label>
          <textarea v-model="dsn" class="sa-input h-28" placeholder="postgresql://user:pass@127.0.0.1:5432/mydb?sslmode=prefer" required />
        </div>
        <div class="flex justify-end">
          <button class="sa-btn-primary">Save</button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup lang="ts">
await navigateTo("/admin/connections")

const api = useApi()
const dbs = ref<any[]>([])
const open = ref(false)
const name = ref("")
const dsn = ref("")

async function load() {
  dbs.value = await api.get("/api/databases") as any
}
onMounted(load)

async function create() {
  await api.post("/api/databases", { name: name.value, connection_string: dsn.value })
  open.value = false
  name.value = ""
  dsn.value = ""
  await load()
}

async function remove(d: any) {
  if (!confirm("Delete this database connection?")) return
  await api.del(`/api/databases/${d.id}`)
  await load()
}
</script>
