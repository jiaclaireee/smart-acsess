<template>
  <div class="space-y-6">
    <PageHeader
      title="Database Connections"
      subtitle="Manage built-in and custom database integrations without exposing saved secrets."
    >
      <template #actions>
        <button class="sa-btn-accent" @click="openCreate">Add Connection</button>
      </template>
    </PageHeader>

    <div class="sa-card p-5">
      <div v-if="error" class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
        {{ error }}
      </div>

      <div class="overflow-auto border border-slate-200 rounded-2xl">
        <table class="min-w-full">
          <thead>
            <tr>
              <th class="sa-th">Name</th>
              <th class="sa-th">Type</th>
              <th class="sa-th">Host</th>
              <th class="sa-th">Database</th>
              <th class="sa-th">User</th>
              <th class="sa-th">Created</th>
              <th class="sa-th">Actions</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="connection in connections" :key="connection.id">
              <tr>
                <td class="sa-td font-semibold">{{ connection.name }}</td>
                <td class="sa-td">
                  <div class="font-medium">{{ labelForType(connection) }}</div>
                  <div v-if="!connection.connector_registered" class="text-[11px] text-amber-600">
                    Saved only. Register a backend connector to test or query this type.
                  </div>
                </td>
                <td class="sa-td">
                  {{ connection.host }}<span v-if="displayPort(connection)">:{{ displayPort(connection) }}</span>
                </td>
                <td class="sa-td">{{ connection.database_name }}</td>
                <td class="sa-td">{{ connection.username || "-" }}</td>
                <td class="sa-td text-slate-500">{{ formatDate(connection.created_at) }}</td>
                <td class="sa-td">
                  <div class="flex flex-wrap gap-2">
                    <button class="sa-btn-ghost" :disabled="testingId === connection.id" @click="testConnection(connection)">
                      {{ testingId === connection.id ? "Testing..." : "Test" }}
                    </button>
                    <button class="sa-btn-ghost" :disabled="tablesId === connection.id" @click="loadTables(connection)">
                      {{ tablesId === connection.id ? "Loading..." : tablesLabel(connection) }}
                    </button>
                    <NuxtLink class="sa-btn-ghost" :to="`/admin/databases/${connection.id}/schema`">Schema</NuxtLink>
                    <button class="sa-btn-ghost" @click="openEdit(connection)">Edit</button>
                    <button class="sa-btn-danger" @click="remove(connection)">Delete</button>
                  </div>
                </td>
              </tr>
              <tr v-if="statusById[connection.id] || resourceLists[connection.id]?.length">
                <td class="sa-td bg-slate-50" colspan="7">
                  <div v-if="statusById[connection.id]" class="text-sm" :class="statusById[connection.id].ok ? 'text-emerald-700' : 'text-rose-700'">
                    {{ statusById[connection.id].message }}
                  </div>
                  <div v-if="resourceLists[connection.id]?.length" class="mt-3">
                    <div class="text-xs text-slate-500 uppercase tracking-wide">
                      Available {{ resourceTypeById[connection.id] || connection.resource_label || "tables" }}
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                      <span
                        v-for="resource in resourceLists[connection.id]"
                        :key="resource"
                        class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs text-slate-700"
                      >
                        {{ resource }}
                      </span>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-if="connections.length === 0">
              <td class="sa-td text-slate-500" colspan="7">No database connections saved yet.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
        PostgreSQL, MySQL, MariaDB, MS SQL, MongoDB, and custom keys can be saved here. Custom types stay manageable in the UI now, and test/schema/query support becomes available once a matching backend connector is registered.
      </div>
    </div>

    <Modal v-if="open" :title="editingId ? 'Edit Connection' : 'Add Connection'" @close="closeModal">
      <form class="space-y-4" @submit.prevent="save">
        <div>
          <label class="sa-label">Connection Name</label>
          <input v-model="form.name" class="sa-input" placeholder="e.g., Security Analytics DB" required />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="sa-label">Database Type</label>
            <select v-model="form.type" class="sa-input">
              <option v-for="option in typeOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </div>
          <div>
            <label class="sa-label">Port</label>
            <input v-model.number="form.port" type="number" class="sa-input" min="1" max="65535" />
          </div>
        </div>

        <div v-if="selectedTypeRequiresCustomKey">
          <label class="sa-label">Custom Type Key</label>
          <input v-model="form.custom_type" class="sa-input" placeholder="e.g., oracle, sqlite, cassandra" required />
          <p class="mt-1 text-[11px] text-slate-500">
            Use a lowercase key. The connection can be saved now, and backend test/schema support will work after a connector is registered for it.
          </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="sa-label">Host</label>
            <input v-model="form.host" class="sa-input" placeholder="127.0.0.1" required />
            <p class="mt-1 text-[11px] text-slate-500">
              Host only is best, but if you paste <code>username@host:port</code> we will split it automatically.
            </p>
          </div>
          <div>
            <label class="sa-label">Database Name</label>
            <input v-model="form.database_name" class="sa-input" placeholder="smart_acsess" required />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="sa-label">Username</label>
            <input v-model="form.username" class="sa-input" placeholder="db_user" />
            <p v-if="resolvedTypeKey() === 'pgsql'" class="mt-1 text-[11px] text-slate-500">
              For Neon, this is usually a role like <code>neondb_owner</code>, not the <code>npg_...</code> secret.
            </p>
          </div>
          <div>
            <label class="sa-label">Password</label>
            <input
              v-model="form.password"
              type="password"
              class="sa-input"
              :placeholder="editingMeta.password_configured ? 'Leave blank to keep saved password' : 'Enter password'"
            />
            <p v-if="resolvedTypeKey() === 'pgsql'" class="mt-1 text-[11px] text-slate-500">
              For Neon, the password is the <code>npg_...</code> value from the connection string.
            </p>
          </div>
        </div>

        <div>
          <label class="sa-label">Extra Config (JSON, optional)</label>
          <textarea
            v-model="form.extra_config_text"
            class="sa-input h-32"
            placeholder='{"sslmode":"prefer","schema":"public","authSource":"admin"}'
          />
          <p class="mt-1 text-[11px] text-slate-500">
            Leave blank on edit to keep the existing extra configuration. Stored values stay encrypted server-side.
          </p>
        </div>

        <div class="flex items-center justify-between gap-3">
          <div class="text-[11px] text-slate-500">
            Saved password: {{ editingMeta.password_configured ? "configured" : "not set" }} |
            Extra config: {{ editingMeta.extra_config_configured ? "configured" : "not set" }}
          </div>
          <div class="flex gap-2">
            <button type="button" class="sa-btn-ghost" @click="closeModal">Cancel</button>
            <button class="sa-btn-primary" :disabled="saving">
              {{ saving ? "Saving..." : editingId ? "Save Changes" : "Save Connection" }}
            </button>
          </div>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup lang="ts">
import { getApiErrorMessage } from "~/composables/useApi"

type DatabaseTypeOption = {
  value: string
  label: string
  default_port?: number | null
  resource_type?: string
  registered: boolean
  requires_custom_key?: boolean
}

type DatabaseConnection = {
  id: number
  name: string
  type: string
  type_label?: string
  host: string
  port?: number | null
  default_port?: number | null
  database_name: string
  username?: string | null
  password_configured: boolean
  extra_config_configured: boolean
  resource_label?: string
  connector_registered: boolean
  created_at?: string | null
}

const api = useApi()
const connections = ref<DatabaseConnection[]>([])
const typeOptions = ref<DatabaseTypeOption[]>([])
const open = ref(false)
const saving = ref(false)
const testingId = ref<number | null>(null)
const tablesId = ref<number | null>(null)
const editingId = ref<number | null>(null)
const error = ref("")

const statusById = ref<Record<number, { ok: boolean; message: string }>>({})
const resourceLists = ref<Record<number, string[]>>({})
const resourceTypeById = ref<Record<number, string>>({})

const customOptionKey = "__custom__"
const fallbackTypeOptions: DatabaseTypeOption[] = [
  { value: "pgsql", label: "PostgreSQL", default_port: 5432, resource_type: "table", registered: true },
  { value: "mysql", label: "MySQL", default_port: 3306, resource_type: "table", registered: true },
  { value: "mariadb", label: "MariaDB", default_port: 3306, resource_type: "table", registered: true },
  { value: "sqlsrv", label: "MS SQL", default_port: 1433, resource_type: "table", registered: true },
  { value: "mongodb", label: "MongoDB", default_port: 27017, resource_type: "collection", registered: true },
  { value: customOptionKey, label: "Other / Custom", registered: false, requires_custom_key: true },
]

const form = reactive({
  name: "",
  type: "pgsql",
  custom_type: "",
  host: "",
  port: 5432 as number | null,
  database_name: "",
  username: "",
  password: "",
  extra_config_text: "",
})

const editingMeta = reactive({
  password_configured: false,
  extra_config_configured: false,
})

const selectedTypeOption = computed(() =>
  typeOptions.value.find((option) => option.value === form.type) || null,
)

const selectedTypeRequiresCustomKey = computed(() => !!selectedTypeOption.value?.requires_custom_key)

watch(() => form.type, (nextType, previousType) => {
  const nextPort = defaultPortFor(nextType)
  const previousPort = previousType ? defaultPortFor(previousType) : null

  if (previousType && form.port === previousPort) {
    form.port = nextPort
    return
  }

  if (form.port === null || form.port === undefined || form.port === 0) {
    form.port = nextPort
  }
})

onMounted(async () => {
  await loadTypeOptions()
  await load()
})

async function load() {
  connections.value = await api.get("/api/databases") as DatabaseConnection[]
}

async function loadTypeOptions() {
  try {
    const response = await api.get<any[]>("/api/databases/options")
    typeOptions.value = response.map((option) => ({
      value: option.key,
      label: option.label,
      default_port: option.default_port,
      resource_type: option.resource_type,
      registered: !!option.registered,
      requires_custom_key: !!option.requires_custom_key,
    }))
  } catch {
    typeOptions.value = fallbackTypeOptions
  }

  if (!typeOptions.value.some((option) => option.value === form.type)) {
    form.type = typeOptions.value[0]?.value || "pgsql"
  }
}

function resetForm() {
  const firstOption = typeOptions.value.find((option) => !option.requires_custom_key) || fallbackTypeOptions[0]
  form.name = ""
  form.type = firstOption?.value || "pgsql"
  form.custom_type = ""
  form.host = ""
  form.port = defaultPortFor(form.type)
  form.database_name = ""
  form.username = ""
  form.password = ""
  form.extra_config_text = ""
  editingMeta.password_configured = false
  editingMeta.extra_config_configured = false
}

function openCreate() {
  editingId.value = null
  resetForm()
  open.value = true
}

async function openEdit(connection: DatabaseConnection) {
  const detail = await api.get(`/api/databases/${connection.id}`) as DatabaseConnection
  const knownOption = typeOptions.value.find((option) => option.value === detail.type)

  editingId.value = connection.id
  form.name = detail.name
  form.type = knownOption ? detail.type : customOptionKey
  form.custom_type = knownOption ? "" : detail.type
  form.host = detail.host
  form.port = detail.port ?? detail.default_port ?? defaultPortFor(detail.type)
  form.database_name = detail.database_name
  form.username = detail.username || ""
  form.password = ""
  form.extra_config_text = ""
  editingMeta.password_configured = detail.password_configured
  editingMeta.extra_config_configured = detail.extra_config_configured
  open.value = true
}

function closeModal() {
  open.value = false
  editingId.value = null
}

function buildPayload() {
  const payload: Record<string, any> = {
    name: form.name,
    type: resolvedTypeKey(),
    host: form.host,
    port: form.port || null,
    database_name: form.database_name,
    username: form.username || null,
  }

  if (form.password) {
    payload.password = form.password
  }

  if (form.extra_config_text.trim()) {
    payload.extra_config = JSON.parse(form.extra_config_text)
  }

  return payload
}

async function save() {
  saving.value = true
  error.value = ""

  try {
    if (selectedTypeRequiresCustomKey.value && !resolvedTypeKey()) {
      error.value = "Custom type key is required."
      return
    }

    const payload = buildPayload()
    if (editingId.value) {
      await api.put(`/api/databases/${editingId.value}`, payload)
    } else {
      await api.post("/api/databases", payload)
    }

    closeModal()
    resetForm()
    await load()
  } catch (err) {
    error.value = err instanceof SyntaxError
      ? "Extra config must be valid JSON."
      : getApiErrorMessage(err, "Unable to save the database connection.")
  } finally {
    saving.value = false
  }
}

async function testConnection(connection: DatabaseConnection) {
  testingId.value = connection.id
  error.value = ""

  try {
    const res = await api.post(`/api/databases/${connection.id}/test`) as any
    statusById.value[connection.id] = {
      ok: true,
      message: res.message || "Connection successful.",
    }
    await loadTables(connection)
  } catch (err) {
    statusById.value[connection.id] = {
      ok: false,
      message: getApiErrorMessage(err, "Connection failed."),
    }
  } finally {
    testingId.value = null
  }
}

async function loadTables(connection: DatabaseConnection) {
  tablesId.value = connection.id

  try {
    const res = await api.get(`/api/databases/${connection.id}/tables`) as any
    resourceLists.value[connection.id] = res.items || res.tables || res.collections || []
    resourceTypeById.value[connection.id] = res.resource_type || connection.resource_label || "table"
  } catch (err) {
    statusById.value[connection.id] = {
      ok: false,
      message: getApiErrorMessage(err, "Unable to load tables or collections."),
    }
  } finally {
    tablesId.value = null
  }
}

async function remove(connection: DatabaseConnection) {
  if (!confirm("Delete this database connection?")) return

  await api.del(`/api/databases/${connection.id}`)
  delete statusById.value[connection.id]
  delete resourceLists.value[connection.id]
  delete resourceTypeById.value[connection.id]
  await load()
}

function resolvedTypeKey() {
  return form.type === customOptionKey
    ? form.custom_type.trim().toLowerCase()
    : form.type
}

function labelForType(connection: DatabaseConnection) {
  return connection.type_label
    || typeOptions.value.find((option) => option.value === connection.type)?.label
    || connection.type
}

function defaultPortFor(type: string) {
  const option = typeOptions.value.find((entry) => entry.value === type)
    || fallbackTypeOptions.find((entry) => entry.value === type)

  return option?.default_port ?? null
}

function displayPort(connection: DatabaseConnection) {
  return connection.port ?? connection.default_port ?? null
}

function tablesLabel(connection: DatabaseConnection) {
  return connection.resource_label === "collection" ? "Collections" : "Tables"
}

function formatDate(value?: string | null) {
  return value ? new Date(value).toLocaleString() : "-"
}
</script>
