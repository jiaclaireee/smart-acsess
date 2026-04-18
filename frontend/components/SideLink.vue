<template>
  <NuxtLink
    :to="to"
    class="group flex items-center rounded-xl border border-transparent px-3 py-2 transition hover:border-slate-200 hover:bg-slate-50"
    :class="[
      collapsed ? 'justify-center gap-0' : 'gap-3',
      isActive ? 'bg-slate-50 border-slate-200' : '',
    ]"
    :title="collapsed ? label : undefined"
  >
    <span class="w-5 h-5 text-uplbGreen" v-html="icon" />
    <span v-if="!collapsed" class="text-sm font-semibold">{{ label }}</span>
  </NuxtLink>
</template>

<script setup lang="ts">
const props = withDefaults(defineProps<{ to: string; icon: string; label: string; collapsed?: boolean }>(), {
  collapsed: false,
})
const route = useRoute()
const isActive = computed(() => route.path === props.to || route.path.startsWith(`${props.to}/`))
</script>
