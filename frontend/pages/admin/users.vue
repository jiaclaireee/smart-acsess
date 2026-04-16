<template>
  <div class="space-y-6">
    <PageHeader
      title="User Management"
      subtitle="Review registered users and approve or reject access requests."
    >
      <template #actions>
        <button class="sa-btn-accent" @click="openCreate = true">Add User</button>
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
              <th class="sa-th">UP Mail</th>
              <th class="sa-th">Role</th>
              <th class="sa-th">Approval Status</th>
              <th class="sa-th">Created</th>
              <th class="sa-th">Office/Department</th>
              <th class="sa-th">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td class="sa-td text-slate-500" colspan="7">Loading users...</td>
            </tr>
            <tr v-for="u in users" :key="u.id">
              <td class="sa-td font-semibold">{{ u.first_name }} {{ u.last_name }}</td>
              <td class="sa-td">{{ u.email }}</td>
              <td class="sa-td">
                <span class="rounded-full bg-slate-100 px-2 py-1 text-[11px] uppercase">
                  {{ u.role === "admin" ? "Admin" : "End-User" }}
                </span>
              </td>
              <td class="sa-td">
                <span class="rounded-full px-2 py-1 text-[11px] border" :class="statusClass(u.approval_status)">
                  {{ u.approval_status }}
                </span>
              </td>
              <td class="sa-td text-slate-500">{{ formatDate(u.created_at) }}</td>
              <td class="sa-td">{{ u.office_department || "—" }}</td>
              <td class="sa-td">
                <div class="flex flex-wrap gap-2">
                  <button class="sa-btn-primary" :disabled="isApprovalDisabled(u, 'approved')" @click="updateApproval(u, 'approved')">Approve</button>
                  <button class="sa-btn-danger" :disabled="isApprovalDisabled(u, 'rejected')" @click="updateApproval(u, 'rejected')">Reject</button>
                  <button class="sa-btn-ghost" :disabled="saving" @click="edit(u)">Edit</button>
                  <button class="sa-btn-danger" :disabled="isDeleteDisabled(u)" @click="remove(u)">Delete</button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && users.length===0">
              <td class="sa-td text-slate-500" colspan="7">No users found.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
        Admin accounts stay approved and cannot be deleted from this screen. Role updates are available when editing a user.
      </div>
    </div>

    <Modal v-if="openCreate" title="Add User" @close="openCreate=false">
      <UserForm @submit="create" />
    </Modal>

    <Modal v-if="openEdit" title="Edit User" @close="openEdit=false">
      <UserForm :initial="selected" edit @submit="update" />
    </Modal>
  </div>
</template>

<script setup lang="ts">
import { getApiErrorMessage } from "~/composables/useApi"

const api = useApi()
const { user } = useAuth()
const users = ref<any[]>([])
const openCreate = ref(false)
const openEdit = ref(false)
const selected = ref<any>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref("")

async function load() {
  loading.value = true

  try {
    const res = await api.get<any>("/api/users")
    users.value = res.data || []
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to load users.")
  } finally {
    loading.value = false
  }
}
onMounted(load)

function formatDate(value?: string | null) {
  return value ? new Date(value).toLocaleString() : "-"
}

function statusClass(status: string) {
  if (status === "approved") return "border-emerald-200 bg-emerald-50 text-emerald-700"
  if (status === "rejected") return "border-rose-200 bg-rose-50 text-rose-700"
  return "border-amber-200 bg-amber-50 text-amber-700"
}

function edit(u: any) {
  error.value = ""
  selected.value = u
  openEdit.value = true
}

async function create(payload: any) {
  saving.value = true
  error.value = ""

  try {
    await api.post("/api/users", payload)
    openCreate.value = false
    await load()
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to create the user.")
  } finally {
    saving.value = false
  }
}

async function update(payload: any) {
  if (!selected.value) return

  saving.value = true
  error.value = ""

  try {
    await api.put(`/api/users/${selected.value.id}`, payload)
    openEdit.value = false
    selected.value = null
    await load()
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to update the user.")
  } finally {
    saving.value = false
  }
}

async function updateApproval(u: any, approvalStatus: "approved" | "rejected") {
  saving.value = true
  error.value = ""

  try {
    await api.patch(`/api/users/${u.id}/approval`, {
      approval_status: approvalStatus,
    })
    await load()
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to update approval status.")
  } finally {
    saving.value = false
  }
}

async function remove(u: any) {
  if (isDeleteDisabled(u)) return
  if (!confirm("Delete this user?")) return

  saving.value = true
  error.value = ""

  try {
    await api.del(`/api/users/${u.id}`)
    await load()
  } catch (err) {
    error.value = getApiErrorMessage(err, "Unable to delete the user.")
  } finally {
    saving.value = false
  }
}

function isApprovalDisabled(targetUser: any, status: "approved" | "rejected") {
  if (saving.value) return true
  if (targetUser.role === "admin") return true
  return targetUser.approval_status === status
}

function isDeleteDisabled(targetUser: any) {
  if (saving.value) return true
  if (targetUser.role === "admin") return true
  return targetUser.id === user.value?.id
}
</script>
