<script setup>
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();
const open = ref(false);

const initials = () => {
    const n = page.props.auth.user?.name || '?';
    return n.split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase();
};

const logout = () => router.post(route('logout'));
</script>

<template>
    <div class="relative">
        <button
            class="w-10 h-10 font-bold text-sm flex items-center justify-center text-bg"
            style="background: linear-gradient(135deg, #c8ff3d, #ff7a4d);"
            @click="open = !open"
        >
            {{ initials() }}
        </button>

        <div
            v-if="open"
            class="absolute right-0 top-12 w-64 border border-border bg-surface shadow-xl z-50"
            @click.outside="open = false"
        >
            <div class="p-4 border-b border-border">
                <div class="text-sm font-semibold">{{ page.props.auth.user?.name }}</div>
                <div class="text-xs text-muted">{{ page.props.auth.user?.email }}</div>
                <div class="mt-2 font-mono text-[10px] tracking-widest text-accent uppercase">
                    {{ page.props.auth.user?.role_label }}
                </div>
            </div>
            <button
                class="w-full text-left px-4 py-3 text-sm hover:bg-surface-2 text-danger font-mono uppercase text-xs tracking-wider"
                @click="logout"
            >
                ↩ Logout
            </button>
        </div>
    </div>
</template>
