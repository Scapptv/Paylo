<script setup>
defineProps({
    role: { type: String, default: '' }, // ADMIN, MERCHANT, ...
});
</script>

<template>
    <div class="flex items-center gap-3 px-6 pb-7 border-b border-border">
        <span class="brand-dot" />
        <span class="font-serif font-extrabold text-lg tracking-tight">Paylo</span>
        <span v-if="role" class="ml-auto font-mono text-[9px] tracking-widest text-accent uppercase px-2 py-0.5 border border-accent">
            {{ role }}
        </span>
    </div>
</template>
