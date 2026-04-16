<template>
  <div class="fixed inset-0 z-50 bg-black/30 flex items-center justify-center p-4" @click.self="$emit('close')">
    <div class="w-full max-w-2xl sa-card p-5">
      <div class="flex items-center justify-between mb-3">
        <div class="font-futura font-bold text-lg">{{ title }}</div>
        <button class="sa-btn-ghost" @click="$emit('close')">✕</button>
      </div>
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{ title: string }>()
defineEmits<{ (e:'close'):void }>()
</script>
