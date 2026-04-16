<template>
  <NuxtLink
    :to="to"
    class="group flex items-center gap-3 px-3 py-2 rounded-xl border border-transparent hover:border-slate-200 hover:bg-slate-50 transition"
    :class="isActive ? 'bg-slate-50 border-slate-200' : ''"
  >
    <span class="w-5 h-5 text-uplbGreen" v-html="icon" />
    <span class="text-sm font-semibold">{{ label }}</span>
  </NuxtLink>
</template>

<script setup lang="ts">
const props = defineProps<{ to: string; icon: string; label: string }>()
const route = useRoute()
const isActive = computed(() => route.path === props.to || route.path.startsWith(`${props.to}/`))
</script>
