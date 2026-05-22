<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import LedgerTypeBadge from '@/Components/LedgerTypeBadge.vue';
import { useFormat } from '@/Composables/useFormat.js';

defineProps({ entry: { type: Object, required: true } });

const { azn, date } = useFormat();
</script>

<template>
    <Head :title="`Entry ${entry.uid}`" />
    <AdminLayout breadcrumb="Ledger Entry">

        <Link :href="route('admin.ledger')" class="font-mono text-xs uppercase tracking-wider text-muted hover:text-accent mb-6 inline-block">
            ← Ledger
        </Link>

        <div class="max-w-3xl">
            <LedgerTypeBadge :type="entry.type" />
            <h1 class="font-serif font-light text-5xl mt-4 tracking-tight">
                <em class="italic font-semibold text-accent">{{ azn(entry.amount) }}</em>
            </h1>
            <div class="mt-2 font-mono text-[11px] text-accent-blue">{{ entry.uid }}</div>

            <div class="mt-10 card">
                <div class="section-title">Detal</div>
                <dl class="mt-4 grid grid-cols-2 gap-y-4 gap-x-8 text-sm">
                    <div>
                        <dt class="text-muted text-xs">Müştəri</dt>
                        <dd class="mt-1">{{ entry.user?.name }} <span class="text-muted">·</span> {{ entry.user?.email }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted text-xs">Merchant</dt>
                        <dd class="mt-1">{{ entry.merchant?.name }} <span class="text-accent-blue font-mono text-[11px]">{{ entry.merchant?.code }}</span></dd>
                    </div>
                    <div>
                        <dt class="text-muted text-xs">Branch</dt>
                        <dd class="mt-1">{{ entry.branch?.name || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted text-xs">Cashier</dt>
                        <dd class="mt-1">{{ entry.cashier?.name || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted text-xs">Receipt / Ref</dt>
                        <dd class="mt-1 font-mono text-xs">{{ entry.ref || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted text-xs">Balance After</dt>
                        <dd class="mt-1 font-mono">{{ azn(entry.balance_after) }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted text-xs">Created At</dt>
                        <dd class="mt-1 font-mono text-xs">{{ date(entry.created_at) }}</dd>
                    </div>
                    <div v-if="entry.reverses_entry">
                        <dt class="text-muted text-xs">Reverses</dt>
                        <dd class="mt-1">
                            <Link :href="route('admin.ledger.show', entry.reverses_entry.id)" class="font-mono text-xs text-accent hover:underline">
                                {{ entry.reverses_entry.uid }}
                            </Link>
                        </dd>
                    </div>
                </dl>
            </div>

            <div v-if="entry.meta" class="mt-6 card">
                <div class="section-title">Meta</div>
                <pre class="mt-3 font-mono text-xs text-muted bg-surface-2 p-4 overflow-x-auto">{{ JSON.stringify(entry.meta, null, 2) }}</pre>
            </div>
        </div>

    </AdminLayout>
</template>
