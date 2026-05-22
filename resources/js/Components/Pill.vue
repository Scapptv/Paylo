<script setup>
defineProps({
    variant: { type: String, default: 'default' }, // default | success | danger | warning | accent
    dot:     { type: Boolean, default: false },
});

const variantClass = {
    default: 'border-border text-muted',
    success: 'border-success/40 text-success bg-success/10',
    danger:  'border-danger/40 text-danger bg-danger/10',
    warning: 'border-warning/40 text-warning bg-warning/10',
    accent:  'border-accent/40 text-accent bg-accent/10',
    blue:    'border-accent-blue/40 text-accent-blue bg-accent-blue/10',
    purple:  'border-accent-purple/40 text-accent-purple bg-accent-purple/10',
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 px-2 py-1 border font-mono text-[10px] uppercase tracking-wider"
        :class="variantClass[variant] || variantClass.default"
    >
        <span v-if="dot" class="w-1.5 h-1.5 rounded-full bg-current opacity-80" />
        <slot />
    </span>
</template>
