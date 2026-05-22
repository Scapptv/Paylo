<script setup>
import BrandMark from '@/Components/BrandMark.vue';
import NavItem from '@/Components/NavItem.vue';
import UserMenu from '@/Components/UserMenu.vue';
import { usePage } from '@inertiajs/vue3';

defineProps({
    breadcrumb: { type: String, default: 'Dashboard' },
});

const page = usePage();
const merchant = page.props.auth.user?.merchant;
</script>

<template>
    <div class="grain">
        <div class="grid grid-cols-[248px_1fr] min-h-screen">

            <aside class="bg-surface border-r border-border sticky top-0 h-screen overflow-y-auto pt-6 flex flex-col">
                <BrandMark role="Merchant" />

                <!-- Merchant context card -->
                <div v-if="merchant" class="mx-4 my-5 p-4 border border-border bg-surface-2">
                    <div class="font-mono text-[10px] uppercase tracking-widest text-muted">Active Merchant</div>
                    <div class="font-serif text-base font-semibold mt-1">{{ merchant.name }}</div>
                    <div class="font-mono text-[10px] text-accent-blue mt-1">{{ merchant.code }} · {{ merchant.category }}</div>
                </div>

                <nav class="flex-1">
                    <div class="px-6 pb-2 font-mono text-[10px] uppercase tracking-widest text-muted">Overview</div>
                    <NavItem :href="route('merchant.dashboard')" icon="▣">Dashboard</NavItem>
                    <NavItem href="#" icon="◉">Reports</NavItem>

                    <div class="px-6 pb-2 pt-5 font-mono text-[10px] uppercase tracking-widest text-muted">My Customers</div>
                    <NavItem href="#" icon="◌" badge="8.2k">Customers</NavItem>
                    <NavItem href="#" icon="⊕">Issued Bonuses</NavItem>
                    <NavItem href="#" icon="⊖">Redeemed</NavItem>

                    <div class="px-6 pb-2 pt-5 font-mono text-[10px] uppercase tracking-widest text-muted">Operations</div>
                    <NavItem href="#" icon="◫">POS Activity</NavItem>
                    <NavItem href="#" icon="★" badge="3">Campaigns</NavItem>
                    <NavItem href="#" icon="↺">Refunds</NavItem>

                    <div class="px-6 pb-2 pt-5 font-mono text-[10px] uppercase tracking-widest text-muted">Finance</div>
                    <NavItem href="#" icon="◇">Settlements</NavItem>
                    <NavItem href="#" icon="⊟">Wallet Activity</NavItem>
                </nav>

                <div class="px-6 py-5 border-t border-border font-mono text-[10px] uppercase tracking-widest text-muted">
                    Merchant Panel
                </div>
            </aside>

            <main>
                <header class="flex items-center justify-between px-10 py-5 border-b border-border sticky top-0 z-40 backdrop-blur" style="background: rgba(10,11,15,0.85)">
                    <div class="flex items-center gap-2 font-mono text-[11px] uppercase tracking-wider text-muted">
                        <span>Paylo</span>
                        <span class="text-border-2">/</span>
                        <span>Merchant</span>
                        <span class="text-border-2">/</span>
                        <span class="text-accent">{{ breadcrumb }}</span>
                    </div>
                    <UserMenu />
                </header>

                <div class="p-10">
                    <slot />
                </div>
            </main>

        </div>
    </div>
</template>
