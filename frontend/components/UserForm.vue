<template>
  <form class="grid grid-cols-1 md:grid-cols-2 gap-4" @submit.prevent="submit">
    <div>
      <label class="sa-label">First Name <span class="text-rose-500">*</span></label>
      <input v-model="form.first_name" class="sa-input" required />
    </div>
    <div>
      <label class="sa-label">Middle Name</label>
      <input v-model="form.middle_name" class="sa-input" />
    </div>
    <div>
      <label class="sa-label">Last Name <span class="text-rose-500">*</span></label>
      <input v-model="form.last_name" class="sa-input" required />
    </div>
    <div>
      <label class="sa-label">Contact No.</label>
      <input v-model="form.contact_no" class="sa-input" />
    </div>
    <div>
      <label class="sa-label">Office/Department</label>
      <input v-model="form.office_department" class="sa-input" />
    </div>
    <div>
      <label class="sa-label">College, Course</label>
      <input v-model="form.college_course" class="sa-input" />
    </div>

    <div class="md:col-span-2">
      <label class="sa-label">Email Address (Username) <span class="text-rose-500">*</span></label>
      <input v-model="form.email" type="email" class="sa-input" :required="!edit" />
    </div>

    <div>
      <label class="sa-label">Role</label>
      <select v-model="form.role" class="sa-input">
        <option value="end_user">End-User</option>
        <option value="admin">Admin</option>
      </select>
    </div>

    <div>
      <label class="sa-label">Password <span class="text-rose-500">*</span></label>
      <input v-model="form.password" type="password" class="sa-input" :required="!edit" />
      <p class="text-[11px] text-slate-500 mt-1">Uppercase, lowercase, number, special character.</p>
    </div>

    <div class="md:col-span-2 flex justify-end gap-2 pt-2">
      <button class="sa-btn-primary">{{ edit ? "Save Changes" : "Create User" }}</button>
    </div>
  </form>
</template>

<script setup lang="ts">
const props = defineProps<{ initial?: any, edit?: boolean }>()
const emit = defineEmits<{ (e: "submit", payload: any): void }>()

const form = reactive({
  first_name: props.initial?.first_name ?? "",
  middle_name: props.initial?.middle_name ?? "",
  last_name: props.initial?.last_name ?? "",
  contact_no: props.initial?.contact_no ?? "",
  office_department: props.initial?.office_department ?? "",
  college_course: props.initial?.college_course ?? "",
  email: props.initial?.email ?? "",
  role: props.initial?.role ?? "end_user",
  password: ""
})

watch(() => props.initial, (v) => {
  if (!v) return
  Object.assign(form, {
    first_name: v.first_name ?? "",
    middle_name: v.middle_name ?? "",
    last_name: v.last_name ?? "",
    contact_no: v.contact_no ?? "",
    office_department: v.office_department ?? "",
    college_course: v.college_course ?? "",
    email: v.email ?? "",
    role: v.role ?? "end_user",
    password: ""
  })
})

function submit() {
  const payload: any = { ...form }
  if (props.edit && !payload.password) delete payload.password
  emit("submit", payload)
}
</script>
