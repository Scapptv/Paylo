<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pill from '@/Components/Pill.vue';
import StatCard from '@/Components/StatCard.vue';
import { useFormat } from '@/Composables/useFormat.js';

defineProps({
    merchant:    { type: Object, required: true },
    bucketTotal: { type: Number, required: true },
    ledgerCount: { type: Number, required: true },
});

const { aznCompact, compact, date } = useFormat();
</script>

<template>
    <Head :title="merchant.name" />
    <AdminLayout breadcrumb="Merchant Detail">

        <Link :href="route('admin.merchants')" class="font-mono text-xs uppercase tracking-wider text-muted hover:text-accent mb-6 inline-block">
            ← Bütün merchant-lar
        </Link>

        <!-- Header -->
        <div class="flex items-start gap-6 mb-10">
            <div class="w-20 h-20 flex items-center justify-center bg-surface-2 font-serif font-bold text-4xl">
                {{ merchant.name?.[0]?.toUpperCase() }}
            </div>
            <div class="flex-1">
                <div class="font-mono text-[11px] text-accent-blue">{{ merchant.code }}</div>
                <h1 class="font-serif text-4xl font-light tracking-tight mt-1">{{ merchant.name }}</h1>
                <div class="text-sm text-muted mt-1">{{ merchant.legal_name }} · TIN {{ merchant.tin }}</div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Pill>{{ merchant.category }}</Pill>
                    <Pill>MCC {{ merchant.mcc }}</Pill>
                    <Pill :variant="merchant.tier === 'enterprise' ? 'purple' : merchant.tier === 'premium' ? 'accent' : 'default'">{{ merchant.tier }}</Pill>
                    <Pill :variant="merchant.status === 'active' ? 'success' : 'warning'" dot>{{ merchant.status }}</Pill>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-5 mb-10">
            <StatCard accent label="Bonus Locked" :value="aznCompact(bucketTotal)" sub="Cəm bucket-lərdə" />
            <StatCard label="Ledger Entries" :value="compact(ledgerCount)" sub="immutable yazılar" />
            <StatCard label="Branches" :value="merchant.branches?.length" />
            <StatCard label="Staff" :value="merchant.users?.length" />
        </div>

        <!-- Two columns -->
        <div class="grid lg:grid-cols-2 gap-6">

            <!-- Branches -->
            <div class="card">
                <div class="section-title">Filiallar</div>
                <div class="font-serif text-xl mt-1 mb-5">{{ merchant.branches?.length }} ünvan</div>

                <div class="space-y-2">
                    <div v-for="b in merchant.branches" :key="b.id" class="p-3 border border-border flex items-center gap-3">
                        <div class="text-accent-blue">◉</div>
                        <div class="flex-1">
                            <div class="text-sm font-medium">{{ b.name }}</div>
                            <div class="font-mono text-[10px] text-muted">{{ b.code }} · {{ b.pos_terminal_id }}</div>
                        </div>
                    </div>
                    <div v-if="!merchant.branches?.length" class="py-6 text-center text-muted font-mono text-xs">No branches yet</div>
                </div>
            </div>

            <!-- Settlement & meta -->
            <div class="card">
                <div class="section-title">Settlement & Meta</div>
                <div class="font-serif text-xl mt-1 mb-5">Maliyyə</div>

                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">IBAN</dt><dd class="font-mono">{{ merchant.settlement_iban || '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">Cycle</dt><dd class="font-mono text-accent">{{ merchant.settlement_cycle }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">Region</dt><dd>{{ merchant.region }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">Onboarded</dt><dd class="font-mono text-[11px]">{{ date(merchant.onboarded_at) }}</dd></div>
                </dl>

                <div class="mt-6 pt-6 border-t border-border space-y-2 font-mono text-[11px] text-muted">
                    <div>GET /api/v2/merchants/{{ merchant.code }}</div>
                    <div>last_synced · 3 dəq əvvəl · 200 OK</div>
                    <div>webhook · merchant.update → /webhooks/posnet</div>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
