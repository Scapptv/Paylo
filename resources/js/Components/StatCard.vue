<script setup>
defineProps({
    label:    { type: String, required: true },
    value:    { type: [String, Number], required: true },
    sub:      { type: String, default: '' },
    delta:    { type: String, default: '' },
    trend:    { type: String, default: '' }, // up | down | flat
    accent:   { type: Boolean, default: false },
});
</script>

<template>
    <div
        class="border border-border bg-surface p-6 transition hover:border-accent/50 relative overflow-hidden"
        :class="accent && 'bg-gradient-to-br from-surface to-accent/5'"
    >
        <div class="font-mono text-[10px] tracking-widest text-muted uppercase">
            {{ label }}
        </div>

        <div class="mt-3 flex items-baseline gap-3">
            <div
                class="font-serif font-semibold leading-none tracking-tight"
                :class="accent ? 'text-accent text-4xl' : 'text-text text-3xl'"
            >
                {{ value }}
            </div>
            <div v-if="delta" class="font-mono text-xs" :class="{
                'text-success': trend === 'up',
                'text-danger':  trend === 'down',
                'text-muted':   trend === 'flat' || !trend,
            }">
                <span v-if="trend === 'up'">▲</span>
                <span v-else-if="trend === 'down'">▼</span>
                {{ delta }}
            </div>
        </div>

        <div v-if="sub" class="mt-2 text-xs text-muted">{{ sub }}</div>

        <slot />
    </div>
</template>
