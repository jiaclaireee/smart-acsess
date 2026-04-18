<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-[100] flex items-center justify-center p-4"
      style="background-color: rgba(15, 23, 42, 0.52);"
      @click.self="$emit('close')"
    >
      <div class="w-full max-w-2xl rounded-[28px] border border-slate-200 bg-white p-5 shadow-[0_28px_90px_rgba(15,23,42,0.24)]">
        <div class="mb-3 flex items-center justify-between">
          <div class="font-futura text-lg font-bold">{{ title }}</div>
          <button class="sa-btn-ghost" @click="$emit('close')">x</button>
        </div>
        <slot />
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
defineProps<{ title: string }>()
defineEmits<{ (e: "close"): void }>()
</script>
