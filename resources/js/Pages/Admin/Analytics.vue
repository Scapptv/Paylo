<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import { useFormat } from '@/Composables/useFormat.js';

const props = defineProps({
    analytics: { type: Object, required: true },
    filters:   { type: Object, required: true },
    options:   { type: Array, default: () => [7, 30, 90] },
});

const { azn, aznCompact, compact } = useFormat();

const k = computed(() => props.analytics.kpis);

function setDays(d) {
    router.get(route('admin.analytics'), { days: d }, { preserveState: true, replace: true });
}

// Period-over-period faiz dəyişimi (display-only; pul integer qəpik olaraq qalır).
const pct = (cur, prev) => {
    if (!prev) return { txt: cur > 0 ? 'yeni' : '—', trend: cur > 0 ? 'up' : 'flat' };
    const p = ((cur - prev) / prev) * 100;
    return { txt: (p >= 0 ? '+' : '') + p.toFixed(1) + '%', trend: p > 0 ? 'up' : p < 0 ? 'down' : 'flat' };
};
const earnedDelta = computed(() => pct(k.value.earnedPeriod, k.value.earnedPrev));
const redeemedDelta = computed(() => pct(k.value.redeemedPeriod, k.value.redeemedPrev));

// Kumulativ redemption rate = redeem / earn (kanonik, faizlə).
const redemptionRate = computed(() => {
    const e = k.value.earnedAll;
    return e > 0 ? ((k.value.redeemedAll / e) * 100).toFixed(1) + '%' : '0.0%';
});

const fmtDay = (iso) => { const d = new Date(iso); return ('0' + d.getDate()).slice(-2) + '.' + ('0' + (d.getMonth() + 1)).slice(-2); };

// ── SVG xətt/sahə qrafik riyaziyyatı ──────────────────────────────────────
const VW = 720, VH = 200, PAD = 10;

function buildPath(values, maxV, { area = false } = {}) {
    const n = values.length;
    if (n === 0 || maxV <= 0) {
        const y = VH - PAD;
        return area ? `M${PAD} ${y} L${VW - PAD} ${y} Z` : `M${PAD} ${y} L${VW - PAD} ${y}`;
    }
    const iw = VW - 2 * PAD, ih = VH - 2 * PAD;
    const pt = (v, i) => {
        const x = PAD + (n === 1 ? iw / 2 : (i / (n - 1)) * iw);
        const y = PAD + ih - (v / maxV) * ih;
        return [x, y];
    };
    let d = values.map((v, i) => { const [x, y] = pt(v, i); return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`; }).join(' ');
    if (area) {
        const [lx] = pt(values[n - 1], n - 1);
        const [fx] = pt(values[0], 0);
        d += ` L${lx.toFixed(1)} ${VH - PAD} L${fx.toFixed(1)} ${VH - PAD} Z`;
    }
    return d;
};

// Daily flow: earned vs redeemed (ortaq miqyas).
const flow = computed(() => props.analytics.dailyFlow || []);
const flowMax = computed(() => Math.max(1, ...flow.value.map(d => Math.max(d.earned, d.redeemed))));
const earnedPath = computed(() => buildPath(flow.value.map(d => d.earned), flowMax.value));
const redeemedPath = computed(() => buildPath(flow.value.map(d => d.redeemed), flowMax.value));

// Liability trendi (kanonik kumulativ Σcredits−Σdebits).
const trend = computed(() => props.analytics.liabilityTrend || []);
const trendMax = computed(() => Math.max(1, ...trend.value.map(d => d.liability)));
const liabilityArea = computed(() => buildPath(trend.value.map(d => d.liability), trendMax.value, { area: true }));
const liabilityLine = computed(() => buildPath(trend.value.map(d => d.liability), trendMax.value));

const axisDates = computed(() => {
    const arr = flow.value;
    if (!arr.length) return [];
    const idx = [0, Math.floor(arr.length / 2), arr.length - 1];
    return [...new Set(idx)].map(i => fmtDay(arr[i].date));
});

// Tip bölgüsü — maksimuma görə bar eni.
const breakdown = computed(() => props.analytics.typeBreakdown || []);
const breakdownMax = computed(() => Math.max(1, ...breakdown.value.map(b => b.total)));

const topMerchants = computed(() => props.analytics.topMerchants || []);
const merchMax = computed(() => Math.max(1, ...topMerchants.value.map(m => m.liability)));
</script>

<template>
    <Head title="Analytics" />
    <AdminLayout breadcrumb="Analytics">

        <!-- Hero + period selector -->
        <div class="flex items-end justify-between mb-8">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-widest text-muted">02 / Deep Analytics</div>
                <h1 class="font-serif font-light text-5xl mt-3 tracking-tight">
                    Bonus <em class="italic font-semibold text-accent">analitikası</em>
                </h1>
                <p class="mt-2 text-sm text-muted max-w-xl">Bütün metriklər immutable ledger-dən kanonik hesablanır (Σcredits − Σdebits).</p>
            </div>
            <div class="flex gap-1 border border-border p-1">
                <button v-for="d in options" :key="d" @click="setDays(d)"
                        class="px-3 py-1.5 font-mono text-xs transition"
                        :class="filters.days === d ? 'bg-accent text-bg' : 'text-muted hover:text-text'">
                    {{ d }}g
                </button>
            </div>
        </div>

        <!-- KPI grid -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            <StatCard accent label="Outstanding Liability" :value="aznCompact(k.liability)" sub="Σ bucket balansı (kanonik)" />
            <StatCard label="Earned" :value="aznCompact(k.earnedPeriod)" :sub="`son ${analytics.days} gün`"
                      :delta="earnedDelta.txt" :trend="earnedDelta.trend" />
            <StatCard label="Redeemed" :value="aznCompact(k.redeemedPeriod)" :sub="`son ${analytics.days} gün`"
                      :delta="redeemedDelta.txt" :trend="redeemedDelta.trend" />
            <StatCard label="Redemption Rate" :value="redemptionRate" sub="kumulativ redeem / earn" />
        </div>

        <!-- Liability trend (kanonik hero qrafik) -->
        <div class="card mb-6">
            <div class="flex items-center justify-between mb-1">
                <div>
                    <div class="section-title mb-0">Outstanding liability trendi</div>
                    <div class="font-serif text-xl mt-1">Kumulativ Σcredits − Σdebits</div>
                </div>
                <div class="text-right">
                    <div class="font-mono text-[10px] uppercase text-muted">Cari</div>
                    <div class="font-serif text-2xl text-accent">{{ azn(k.liability) }}</div>
                </div>
            </div>
            <svg :viewBox="`0 0 ${720} ${200}`" preserveAspectRatio="none" class="w-full h-44 mt-3">
                <path :d="liabilityArea" fill="currentColor" class="text-accent opacity-10" />
                <path :d="liabilityLine" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" class="text-accent" />
            </svg>
            <div class="flex justify-between font-mono text-[10px] text-muted mt-1">
                <span v-for="(d, i) in axisDates" :key="i">{{ d }}</span>
            </div>
        </div>

        <!-- Daily flow: earned vs redeemed -->
        <div class="card mb-6">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <div class="section-title mb-0">Günlük axın</div>
                    <div class="font-serif text-xl mt-1">Earned vs Redeemed</div>
                </div>
                <div class="flex items-center gap-4 font-mono text-[11px]">
                    <span class="flex items-center gap-1.5 text-success"><span class="w-3 h-0.5 bg-success inline-block"></span>Earned</span>
                    <span class="flex items-center gap-1.5 text-warning"><span class="w-3 h-0.5 bg-warning inline-block"></span>Redeemed</span>
                </div>
            </div>
            <svg :viewBox="`0 0 ${720} ${200}`" preserveAspectRatio="none" class="w-full h-44">
                <path :d="earnedPath" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" class="text-success" />
                <path :d="redeemedPath" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" class="text-warning" />
            </svg>
            <div class="flex justify-between font-mono text-[10px] text-muted mt-1">
                <span v-for="(d, i) in axisDates" :key="i">{{ d }}</span>
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-6">
            <!-- Tip bölgüsü -->
            <div class="card">
                <div class="section-title">Tip bölgüsü</div>
                <div class="font-serif text-xl mt-1 mb-5">Son {{ analytics.days }} gün</div>
                <div class="space-y-3">
                    <div v-for="b in breakdown" :key="b.type">
                        <div class="flex items-center justify-between font-mono text-[11px] mb-1">
                            <span :class="b.flow === 'credit' ? 'text-success' : 'text-warning'">
                                {{ b.label }}
                                <span class="text-muted">· {{ b.count }}</span>
                            </span>
                            <span class="text-text">{{ azn(b.total) }}</span>
                        </div>
                        <div class="h-1.5 bg-surface-2 overflow-hidden">
                            <div class="h-full" :class="b.flow === 'credit' ? 'bg-success' : 'bg-warning'"
                                 :style="{ width: Math.max(b.total > 0 ? 2 : 0, (b.total / breakdownMax) * 100) + '%' }"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top merchants by liability -->
            <div class="card">
                <div class="section-title">Top merchant-lər</div>
                <div class="font-serif text-xl mt-1 mb-5">Outstanding liability üzrə</div>
                <div class="space-y-3">
                    <div v-for="m in topMerchants" :key="m.id" class="flex items-center gap-3">
                        <div class="w-8 h-8 flex items-center justify-center bg-surface-3 font-serif font-bold text-sm shrink-0">
                            {{ m.name?.[0]?.toUpperCase() }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between">
                                <div class="text-sm truncate">{{ m.name }}</div>
                                <div class="font-mono text-xs text-accent shrink-0 ml-2">{{ azn(m.liability) }}</div>
                            </div>
                            <div class="h-1 bg-surface-2 mt-1 overflow-hidden">
                                <div class="h-full bg-accent" :style="{ width: Math.max(m.liability > 0 ? 2 : 0, (m.liability / merchMax) * 100) + '%' }"></div>
                            </div>
                        </div>
                    </div>
                    <div v-if="!topMerchants.length" class="py-8 text-center text-muted font-mono text-xs">Data yoxdur</div>
                </div>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-6 font-mono text-[11px] text-muted">
            <span>Aktiv müştəri: <span class="text-text">{{ compact(k.activeCustomers) }}</span></span>
            <span>Aktiv merchant: <span class="text-text">{{ compact(k.activeMerchants) }}</span></span>
            <span>Ledger ({{ analytics.days }}g): <span class="text-text">{{ compact(k.ledgerEntries) }}</span></span>
        </div>

    </AdminLayout>
</template>
