<template>
  <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
    <div>
      <h1 class="text-2xl sm:text-3xl font-bold">{{ title }}</h1>
      <p v-if="subtitle" class="text-sm text-slate-600 mt-1">{{ subtitle }}</p>
    </div>
    <div class="flex items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{ title: string, subtitle?: string }>()
</script>
