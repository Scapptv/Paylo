<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import BrandMark from '@/Components/BrandMark.vue';
import NavItem from '@/Components/NavItem.vue';
import UserMenu from '@/Components/UserMenu.vue';

defineProps({
    breadcrumb: { type: String, default: 'Overview' },
});

// Roadmap Phase 2.3: Redemptions/Refunds nav-ları Ledger-i `?type=` ilə preset edir.
// Highlight üçün cari URL-dən `type`-ı oxuyuruq — bu, Ziggy-nin route()-u
// absolute və ya relative qaytarmasından asılı olmadan dəqiq işləyir.
const page = usePage();
const ledgerType = computed(() => {
    const qi = page.url.indexOf('?');
    return qi === -1 ? null : new URLSearchParams(page.url.slice(qi + 1)).get('type');
});
</script>

<template>
    <div class="grain">
        <div class="grid grid-cols-[248px_1fr] min-h-screen">

            <!-- Sidebar -->
            <aside class="bg-surface border-r border-border sticky top-0 h-screen overflow-y-auto pt-6 flex flex-col">
                <BrandMark role="Admin" />

                <nav class="flex-1 py-4">
                    <!--
                        Audit FE-3: MVP-də implement olunmamış admin element-ləri
                        disabled + badge="Tezliklə" işarələnir. Bu sayədə demo
                        görünüş itmir, lakin istifadəçi click-ə basanda heç bir
                        404 və ya `#` axını yaranmır.
                    -->
                    <div class="px-6 pb-2 font-mono text-[10px] uppercase tracking-widest text-muted">Overview</div>
                    <NavItem :href="route('admin.dashboard')" icon="▣">Dashboard</NavItem>
                    <NavItem icon="◉" badge="Tezliklə" disabled>Analytics</NavItem>

                    <div class="px-6 pb-2 pt-5 font-mono text-[10px] uppercase tracking-widest text-muted">Loyalty Core</div>
                    <NavItem :href="route('admin.ledger')" icon="⊟" badge="12.4k">Ledger</NavItem>
                    <!-- Roadmap Phase 1.2: Transactions aktivləşdirildi (siyahı + reverse). -->
                    <NavItem :href="route('admin.transactions')" icon="⇆">Transactions</NavItem>
                    <!-- Roadmap Phase 2.1: Buckets read-view aktivləşdirildi. -->
                    <NavItem :href="route('admin.buckets')" icon="◫">Per-merchant Buckets</NavItem>
                    <!-- Roadmap Phase 2.3: Ledger-in type-filter preset-ləri (yeni səhifə yox, mövcud filtr). -->
                    <NavItem :href="route('admin.ledger', { type: 'redeem' })" :active="ledgerType === 'redeem'" icon="⟳">Redemptions</NavItem>
                    <NavItem :href="route('admin.ledger', { type: 'refund' })" :active="ledgerType === 'refund'" icon="↺">Refunds</NavItem>

                    <div class="px-6 pb-2 pt-5 font-mono text-[10px] uppercase tracking-widest text-muted">Configuration</div>
                    <NavItem icon="⚙" badge="Tezliklə" disabled>Rules</NavItem>
                    <NavItem icon="⊞" badge="Tezliklə" disabled>Category Tiers</NavItem>
                    <NavItem icon="★" badge="Tezliklə" disabled>Campaigns</NavItem>
                    <NavItem :href="route('admin.merchants')" icon="◐">Merchants</NavItem>
                    <!-- Roadmap Phase 2.2: Users idarəetməsi aktivləşdirildi (siyahı + aktivlik toggle). -->
                    <NavItem :href="route('admin.users')" icon="◌">Users</NavItem>

                    <div class="px-6 pb-2 pt-5 font-mono text-[10px] uppercase tracking-widest text-muted">Compliance</div>
                    <NavItem icon="⚠" badge="Tezliklə" disabled>Fraud Signals</NavItem>
                    <NavItem icon="⊕" badge="Tezliklə" disabled>Audit Logs</NavItem>
                    <!-- Roadmap Phase 2.4: Settlement reconciliation read-view + "İndi işlət". -->
                    <NavItem :href="route('admin.settlements')" icon="◇">Settlements</NavItem>
                    <!-- Roadmap Phase 1.1: Manual Adj. aktivləşdirildi (CANON-4 backend + UI). -->
                    <NavItem :href="route('admin.bonus-adjustments.create')" icon="⎈">Manual Adj.</NavItem>
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
