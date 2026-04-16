<template>
  <div class="sa-card p-5">
    <div class="flex items-start justify-between">
      <div>
        <div class="text-xs text-slate-500">{{ label }}</div>
        <div class="mt-2 text-3xl font-bold text-uplbGreen">{{ value }}</div>
      </div>
      <div class="w-10 h-10 rounded-2xl bg-uplbGreen/10 text-uplbGreen grid place-items-center">
        <slot name="icon">📊</slot>
      </div>
    </div>
    <div v-if="hint" class="mt-3 text-xs text-slate-500">{{ hint }}</div>
  </div>
</template>

<script setup lang="ts">
defineProps<{ label: string, value: string | number, hint?: string }>()
</script>
