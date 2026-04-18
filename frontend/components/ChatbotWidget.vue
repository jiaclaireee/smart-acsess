<template>
  <div v-if="showLauncher" class="fixed bottom-20 right-5 z-50 lg:bottom-5">
    <NuxtLink
      to="/chatbot"
      class="flex items-center gap-3 rounded-full border border-emerald-200 bg-white px-4 py-3 text-sm font-semibold text-uplbGreen shadow-soft transition hover:-translate-y-0.5 hover:border-uplbGreen hover:bg-emerald-50"
      aria-label="Open chatbot"
      title="Open chatbot"
    >
      <span class="grid h-10 w-10 place-items-center rounded-full bg-uplbGreen text-white">AI</span>
      <span class="hidden sm:inline">Open Chatbot</span>
    </NuxtLink>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const { user } = useAuth()

const hiddenPaths = new Set(["/login", "/register", "/auth/callback", "/account-status", "/chatbot"])
const showLauncher = computed(() => user.value?.approval_status === "approved" && !hiddenPaths.has(route.path))
</script>
