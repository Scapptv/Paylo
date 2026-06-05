<script setup>
import { reactive, ref, computed, watch } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pill from '@/Components/Pill.vue';

const props = defineProps({
    report:    { type: Object, required: true },
    filters:   { type: Object, required: true },
    scopes:    { type: Array, default: () => [] },
    merchants: { type: Array, default: () => [] },
});

const page = usePage();

const state = reactive({
    for:         props.filters.for || 'today',
    merchant_id: props.filters.merchant_id ?? '',
});

watch(state, () => {
    router.get(route('admin.settlements'), state, { preserveState: true, replace: true });
}, { deep: true });

const azn = (c) => (Number(c || 0) / 100).toFixed(2);

// Mismatch-ları cədvəl üçün düzləşdir: hər diff sahəsi bir sətir.
const rows = computed(() => {
    const out = [];
    for (const m of props.report.mismatches || []) {
        for (const [field, d] of Object.entries(m.diffs || {})) {
            out.push({
                bucket: m.bucket_id, user: m.user_id, merchant: m.merchant_id,
                field, actual: d.actual, expected: d.expected, delta: d.delta,
            });
        }
    }
    return out;
});

// Status: cədvəl yox / scope boş / tam uyğun / uyğunsuzluq.
const status = computed(() => {
    const r = props.report;
    if (r.tables_missing) return { variant: 'warning', text: 'Cədvəllər yoxdur' };
    if (r.checked === 0) return { variant: 'default', text: 'Scope boş' };
    if ((r.mismatches?.length || 0) === 0) return { variant: 'success', text: 'Tam uyğun' };
    return { variant: 'danger', text: `${r.mismatches.length} uyğunsuzluq` };
});

// "İndi işlət" — cari scope üçün reconcile + audit qeydi.
const running = ref(false);
function runNow() {
    running.value = true;
    router.post(route('admin.settlements.run'), {
        for: state.for,
        merchant_id: state.merchant_id || '',
    }, {
        preserveScroll: true,
        onFinish: () => { running.value = false; },
    });
}
</script>

<template>
    <Head title="Settlements" />
    <AdminLayout breadcrumb="Settlements">

        <div class="flex items-center justify-between mb-8">
            <div>
                <div class="section-title">Bucket ↔ ledger reconciliation</div>
                <h1 class="font-serif font-light text-4xl mt-1 tracking-tight">
                    <em class="italic font-semibold text-accent">Settlements</em>
                </h1>
            </div>
            <button type="button" @click="runNow" :disabled="running" class="btn-primary px-5">
                {{ running ? 'İşləyir...' : 'İndi işlət (qeydə al)' }}
            </button>
        </div>

        <div v-if="page.props.flash?.success" class="card mb-4 border-l-2 border-l-success text-sm text-text">✓ {{ page.props.flash.success }}</div>
        <div v-if="page.props.flash?.error" class="card mb-4 border-l-2 border-l-danger text-sm text-danger">⚠ {{ page.props.flash.error }}</div>

        <!-- Filtrlər -->
        <div class="card mb-6 flex flex-wrap items-center gap-3">
            <select v-model="state.for" class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs focus:outline-none focus:border-accent">
                <option v-for="s in scopes" :key="s" :value="s">{{ s }}</option>
            </select>
            <select v-model="state.merchant_id" class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs focus:outline-none focus:border-accent">
                <option value="">All merchants</option>
                <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.code }} · {{ m.name }}</option>
            </select>
            <span class="font-mono text-[11px] text-muted">read-only baxış — audit yazılmır</span>
        </div>

        <!-- Xülasə -->
        <div class="card mb-6 flex flex-wrap items-center gap-8">
            <div>
                <div class="font-mono text-[10px] uppercase text-muted">Status</div>
                <div class="mt-1"><Pill :variant="status.variant" dot>{{ status.text }}</Pill></div>
            </div>
            <div>
                <div class="font-mono text-[10px] uppercase text-muted">Scope</div>
                <div class="font-mono text-sm mt-1">{{ report.scope }}</div>
            </div>
            <div>
                <div class="font-mono text-[10px] uppercase text-muted">Yoxlanılan bucket</div>
                <div class="font-serif text-2xl text-accent mt-0.5">{{ report.checked }}</div>
            </div>
            <div>
                <div class="font-mono text-[10px] uppercase text-muted">Uyğunsuzluq</div>
                <div class="font-serif text-2xl mt-0.5" :class="rows.length ? 'text-danger' : 'text-success'">{{ report.mismatches?.length || 0 }}</div>
            </div>
        </div>

        <!-- Mismatch cədvəli (yalnız uyğunsuzluq olduqda) -->
        <div v-if="rows.length" class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="font-mono text-[10px] uppercase tracking-wider text-muted border-b border-border">
                        <th class="text-left py-2 px-2">Bucket</th>
                        <th class="text-left py-2 px-2">User</th>
                        <th class="text-left py-2 px-2">Merchant</th>
                        <th class="text-left py-2 px-2">Sahə</th>
                        <th class="text-right py-2 px-2">Faktiki (AZN)</th>
                        <th class="text-right py-2 px-2">Gözlənilən (AZN)</th>
                        <th class="text-right py-2 px-2">Delta (AZN)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in rows" :key="i" class="border-b border-border/50 hover:bg-surface-2">
                        <td class="py-2 px-2 font-mono text-[11px]">#{{ r.bucket }}</td>
                        <td class="py-2 px-2 font-mono text-[11px]">{{ r.user }}</td>
                        <td class="py-2 px-2 font-mono text-[11px]">{{ r.merchant }}</td>
                        <td class="py-2 px-2 font-mono text-[11px] text-warning">{{ r.field }}</td>
                        <td class="py-2 px-2 text-right font-mono">{{ azn(r.actual) }}</td>
                        <td class="py-2 px-2 text-right font-mono text-muted">{{ azn(r.expected) }}</td>
                        <td class="py-2 px-2 text-right font-mono text-danger">{{ r.delta > 0 ? '+' : '' }}{{ azn(r.delta) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Uğurlu / boş hallar -->
        <div v-else-if="report.tables_missing" class="card text-center py-16 text-muted font-mono text-xs">
            `buckets` və ya `ledger_entries` cədvəli yoxdur (ilkin install?).
        </div>
        <div v-else-if="report.checked === 0" class="card text-center py-16 text-muted font-mono text-xs">
            Bu scope-da yoxlanılacaq bucket yoxdur.
        </div>
        <div v-else class="card text-center py-16 text-success font-mono text-xs">
            ✓ {{ report.checked }} bucket yoxlanıldı — bütün counter-lər ledger ilə tam uyğundur.
        </div>

    </AdminLayout>
</template>
