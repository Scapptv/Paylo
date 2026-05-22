<script setup>
import BrandMark from '@/Components/BrandMark.vue';
import NavItem from '@/Components/NavItem.vue';
import UserMenu from '@/Components/UserMenu.vue';

defineProps({
    breadcrumb: { type: String, default: 'Overview' },
});
</script>

<template>
    <div class="grain">
        <div class="grid grid-cols-[248px_1fr] min-h-screen">

            <!-- Sidebar -->
            <aside class="bg-surface border-r border-border sticky top-0 h-screen overflow-y-auto pt-6 flex flex-col">
                <BrandMark role="Admin" />

                <nav class="flex-1 py-4">
                    <div class="px-6 pb-2 font-mono text-[10px] uppercase tracking-widest text-muted">Overview</div>
                    <NavItem :href="route('admin.dashboard')" icon="▣">Dashboard</NavItem>
                    <NavItem href="#" icon="◉">Analytics</NavItem>

                    <div class="px-6 pb-2 pt-5 font-mono text-[10px] uppercase tracking-widest text-muted">Loyalty Core</div>
                    <NavItem :href="route('admin.ledger')" icon="⊟" badge="12.4k">Ledger</NavItem>
                    <NavItem href="#" icon="◫">Per-merchant Buckets</NavItem>
                    <NavItem href="#" icon="⟳">Redemptions</NavItem>
                    <NavItem href="#" icon="↺">Refunds</NavItem>

                    <div class="px-6 pb-2 pt-5 font-mono text-[10px] uppercase tracking-widest text-muted">Configuration</div>
                    <NavItem href="#" icon="⚙">Rules</NavItem>
                    <NavItem href="#" icon="⊞" badge="v3">Category Tiers</NavItem>
                    <NavItem href="#" icon="★" badge="API">Campaigns</NavItem>
                    <NavItem :href="route('admin.merchants')" icon="◐" badge="API">Merchants</NavItem>
                    <NavItem href="#" icon="◌">Users</NavItem>

                    <div class="px-6 pb-2 pt-5 font-mono text-[10px] uppercase tracking-widest text-muted">Compliance</div>
                    <NavItem href="#" icon="⚠" badge="7">Fraud Signals</NavItem>
                    <NavItem href="#" icon="⊕">Audit Logs</NavItem>
                    <NavItem href="#" icon="◇">Settlements</NavItem>
                    <NavItem href="#" icon="⎈">Manual Adj.</NavItem>
                </nav>

                <div class="px-6 py-5 border-t border-border font-mono text-[10px] uppercase tracking-widest text-muted">
                    v1.0 · Production
                </div>
            </aside>

            <!-- Main -->
            <main>
                <header class="flex items-center justify-between px-10 py-5 border-b border-border sticky top-0 z-40 backdrop-blur" style="background: rgba(10,11,15,0.85)">
                    <div class="flex items-center gap-2 font-mono text-[11px] uppercase tracking-wider text-muted">
                        <span>Paylo</span>
                        <span class="text-border-2">/</span>
                        <span>Admin</span>
                        <span class="text-border-2">/</span>
                        <span class="text-accent">{{ breadcrumb }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <input type="search" placeholder="ledger uid, user, merchant..." class="bg-surface border border-border px-4 py-2 font-mono text-xs text-text w-80 focus:outline-none focus:border-accent placeholder:text-muted" />
                        <UserMenu />
                    </div>
                </header>

                <div class="p-10">
                    <slot />
                </div>
            </main>

        </div>
    </div>
</template>
