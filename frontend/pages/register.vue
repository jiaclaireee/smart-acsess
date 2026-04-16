<template>
  <div class="max-w-3xl mx-auto">
    <div class="sa-card p-6 sm:p-8">
      <h1 class="text-3xl font-bold">Create Account</h1>
      <p class="text-sm text-slate-600 mt-1">
        Use your UP Mail address. New accounts start as pending until an Admin approves them.
      </p>

      <form class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4" @submit.prevent="submit">
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
          <label class="sa-label">UP Mail <span class="text-rose-500">*</span></label>
          <input v-model="form.email" type="email" class="sa-input" required />
          <p class="mt-1 text-[11px] text-slate-500">
            Accepted domains include <span class="font-semibold">@up.edu.ph</span> and the configured UP constituent domains.
          </p>
        </div>

        <div>
          <label class="sa-label">Password <span class="text-rose-500">*</span></label>
          <input v-model="form.password" type="password" class="sa-input" required />
          <p class="mt-1 text-[11px] text-slate-500">
            Must include uppercase, lowercase, number, and special character.
          </p>
        </div>
        <div>
          <label class="sa-label">Confirm Password <span class="text-rose-500">*</span></label>
          <input v-model="form.password_confirmation" type="password" class="sa-input" required />
        </div>

        <div v-if="error" class="md:col-span-2 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

        <div class="md:col-span-2 flex items-center justify-between mt-2">
          <NuxtLink class="text-sm text-slate-600 underline" to="/login">Back to login</NuxtLink>
          <button class="sa-btn-primary" :disabled="loading">{{ loading ? "Creating..." : "Create account" }}</button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { getApiErrorMessage } from "~/composables/useApi"

const { register } = useAuth()
const loading = ref(false)
const error = ref("")

const form = reactive({
  first_name: "",
  middle_name: "",
  last_name: "",
  contact_no: "",
  office_department: "",
  college_course: "",
  email: "",
  password: "",
  password_confirmation: ""
})

async function submit() {
  loading.value = true
  error.value = ""
  try {
    const redirectTo = await register({ ...form })
    await navigateTo(redirectTo)
  } catch (err) {
    error.value = getApiErrorMessage(err, "Registration failed. Please review the form and try again.")
  } finally {
    loading.value = false
  }
}
</script>
