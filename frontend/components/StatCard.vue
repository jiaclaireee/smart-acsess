<template>
  <div class="sa-card p-5">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0 flex-1">
        <div class="text-xs text-slate-500">{{ label }}</div>
        <div class="mt-2 max-w-full font-bold text-uplbGreen" :class="valueClasses">
          {{ value }}
        </div>
      </div>
      <div class="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-uplbGreen/10 text-uplbGreen">
        <slot name="icon">STAT</slot>
      </div>
    </div>
    <div v-if="hint" class="mt-3 break-words text-xs text-slate-500">{{ hint }}</div>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue"

const props = defineProps<{ label: string, value: string | number, hint?: string }>()

const valueClasses = computed(() => {
  if (typeof props.value === "number") {
    return "text-3xl leading-none"
  }

  const normalized = props.value.trim()

  if (normalized.length > 20) {
    return "text-2xl leading-tight [overflow-wrap:anywhere]"
  }

  if (normalized.length > 12) {
    return "text-[1.7rem] leading-tight [overflow-wrap:anywhere]"
  }

  return "text-3xl leading-none"
})
</script>
