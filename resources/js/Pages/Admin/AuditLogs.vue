<script setup>
import { reactive, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { debounce } from '@/Composables/useDebounce.js';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    logs:    { type: Object, required: true },
    events:  { type: Array, default: () => [] },
    filters: { type: Object, required: true },
});

const state = reactive({
    event: props.filters.event || '',
    from:  props.filters.from || '',
    to:    props.filters.to || '',
});

const apply = debounce(() => {
    router.get(route('admin.audit-logs'), state, { preserveState: true, replace: true });
}, 250);
watch(state, apply, { deep: true });

const fmtTime = (s) => s ? new Date(s).toLocaleString('az-AZ', { dateStyle: 'short', timeStyle: 'medium' }) : '—';

const contextPreview = (ctx) => {
    if (!ctx || typeof ctx !== 'object') return '—';
    const s = JSON.stringify(ctx);
    return s.length > 90 ? s.slice(0, 90) + '…' : s;
};
const contextFull = (ctx) => {
    try { return JSON.stringify(ctx, null, 2); } catch { return ''; }
};
</script>

<template>
    <Head title="Audit Logs" />
    <AdminLayout breadcrumb="Audit Logs">

        <div class="flex items-center justify-between mb-8">
            <div>
                <div class="section-title">Append-only audit stream</div>
                <h1 class="font-serif font-light text-4xl mt-1 tracking-tight">
                    Audit <em class="italic font-semibold text-accent">jurnalı</em>
                </h1>
            </div>
            <div class="font-mono text-xs text-muted"><span class="text-accent">{{ logs.total?.toLocaleString() || 0 }}</span> hadisə</div>
        </div>

        <div class="card mb-6 flex flex-wrap items-center gap-3">
            <select v-model="state.event" class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs focus:outline-none focus:border-accent">
                <option value="">All events</option>
                <option v-for="e in events" :key="e" :value="e">{{ e }}</option>
            </select>
            <label class="font-mono text-[10px] uppercase text-muted">Tarixdən</label>
            <input v-model="state.from" type="date" class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs focus:outline-none focus:border-accent" />
            <label class="font-mono text-[10px] uppercase text-muted">Tarixə</label>
            <input v-model="state.to" type="date" class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs focus:outline-none focus:border-accent" />
            <button @click="state.event = ''; state.from = ''; state.to = ''" class="btn">Sıfırla</button>
        </div>

        <div class="card p-0 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface-2">
                    <tr class="font-mono text-[10px] uppercase tracking-wider text-muted border-b border-border text-left">
                        <th class="py-3 px-4">Vaxt</th>
                        <th class="py-3 px-4">Event</th>
                        <th class="py-3 px-4">Aktor</th>
                        <th class="py-3 px-4">IP</th>
                        <th class="py-3 px-4">Kontekst</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="log in logs.data" :key="log.id" class="border-b border-border/50 hover:bg-surface-2">
                        <td class="py-3 px-4 font-mono text-[11px] text-muted whitespace-nowrap">{{ fmtTime(log.created_at) }}</td>
                        <td class="py-3 px-4 font-mono text-[11px] text-accent-blue">{{ log.event }}</td>
                        <td class="py-3 px-4">
                            <template v-if="log.actor">
                                <div class="text-xs">{{ log.actor.name }}</div>
                                <div class="font-mono text-[10px] text-muted">{{ log.actor.email }}</div>
                            </template>
                            <span v-else class="font-mono text-[10px] text-muted">Sistem</span>
                        </td>
                        <td class="py-3 px-4 font-mono text-[11px] text-muted">{{ log.ip || '—' }}</td>
                        <td class="py-3 px-4 font-mono text-[10px] text-muted max-w-[360px] truncate" :title="contextFull(log.context)">
                            {{ contextPreview(log.context) }}
                        </td>
                    </tr>
                    <tr v-if="!logs.data?.length">
                        <td colspan="5" class="py-16 text-center text-muted font-mono text-xs">Audit yazısı tapılmadı</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="logs.last_page > 1" class="flex items-center justify-between mt-4 font-mono text-xs text-muted">
            <span>{{ logs.from }}–{{ logs.to }} / {{ logs.total }}</span>
            <div class="flex gap-2">
                <button :disabled="!logs.prev_page_url" @click="router.get(logs.prev_page_url, {}, { preserveState: true })"
                        class="px-3 py-1 border border-border disabled:opacity-40 hover:border-accent">← Prev</button>
                <button :disabled="!logs.next_page_url" @click="router.get(logs.next_page_url, {}, { preserveState: true })"
                        class="px-3 py-1 border border-border disabled:opacity-40 hover:border-accent">Next →</button>
            </div>
        </div>

    </AdminLayout>
</template>
