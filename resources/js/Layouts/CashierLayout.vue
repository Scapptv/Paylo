<script setup>
import UserMenu from '@/Components/UserMenu.vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const merchant = page.props.auth.user?.merchant;
</script>

<template>
    <div class="min-h-screen grain">
        <header class="flex items-center justify-between px-6 py-4 border-b border-border sticky top-0 z-40 backdrop-blur bg-surface/85">
            <div class="flex items-center gap-3">
                <span class="brand-dot" />
                <span class="font-serif font-extrabold text-lg">Paylo</span>
                <span class="ml-2 font-mono text-[9px] tracking-widest text-accent uppercase px-2 py-0.5 border border-accent">Cashier</span>

                <span v-if="merchant" class="ml-6 font-mono text-xs text-muted hidden md:inline">
                    {{ merchant.name }} <span class="text-accent-blue">· {{ merchant.code }}</span>
                </span>
            </div>

            <nav class="flex items-center gap-6">
                <Link :href="route('cashier.shift')" class="font-mono text-xs uppercase tracking-wider text-text-2 hover:text-accent">
                    Shift
                </Link>
                <Link :href="route('pos.sale')" class="font-mono text-xs uppercase tracking-wider text-text-2 hover:text-accent">
                    New Sale →
                </Link>
                <UserMenu />
            </nav>
        </header>

        <main class="p-6 md:p-10 max-w-7xl mx-auto">
            <slot />
        </main>
    </div>
</template>
