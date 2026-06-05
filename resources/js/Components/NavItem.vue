<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    href:     { type: String, default: null },
    icon:     { type: String, default: '◌' },
    badge:    { type: [String, Number], default: null },
    active:   { type: Boolean, default: false },
    // Audit FE-3: MVP scope-undan kənar item-lər disabled görünür və
    // klik-ə cavab vermir. badge="Tezliklə" ilə birlikdə istifadə olunur.
    disabled: { type: Boolean, default: false },
});

const page = usePage();
const isActive = computed(() => {
    if (props.disabled) return false;
    if (props.active) return true;
    if (!props.href) return false;
    return page.url === props.href || page.url.startsWith(props.href + '/');
});

const isNavigable = computed(() => !!props.href && !props.disabled);
</script>

<template>
    <component
        :is="isNavigable ? Link : 'span'"
        :href="isNavigable ? href : null"
        class="flex items-center gap-3 px-6 py-2.5 text-sm font-medium border-l-2 transition"
        :class="[
            isActive
                ? 'bg-surface-2 text-accent border-accent'
                : 'border-transparent text-text-2',
            disabled
                ? 'opacity-40 cursor-not-allowed select-none'
                : 'cursor-pointer hover:bg-surface-2 hover:text-text',
        ]"
        :aria-disabled="disabled || null"
    >
        <span class="w-4 opacity-70">{{ icon }}</span>
        <span class="flex-1"><slot /></span>
        <span
            v-if="badge !== null && badge !== undefined"
            class="font-mono text-[10px] px-2 py-0.5"
            :class="isActive ? 'bg-accent text-bg' : 'bg-surface-3 text-muted'"
        >
            {{ badge }}
        </span>
    </component>
</template>
