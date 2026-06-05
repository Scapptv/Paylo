<script setup>
import { reactive, ref, computed, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pill from '@/Components/Pill.vue';

const props = defineProps({
    rules: { type: Array, required: true },
});

const page = usePage();

// Lokal redaktə dəyərləri (effektiv dəyərlərdən başlanğıc).
const edits = reactive({});
const sync = (rules) => rules.forEach((r) => { edits[r.key] = r.value; });
sync(props.rules);
watch(() => props.rules, (r) => sync(r), { deep: true });

const savingKey = ref(null);
const errors = computed(() => page.props.errors || {});

const groups = computed(() => {
    const g = {};
    for (const r of props.rules) { (g[r.group] ||= []).push(r); }
    return g;
});

// bp → insan-oxunaqlı hint (earn rate %, tier multiplier x, qəpik AZN).
const hint = (r) => {
    const v = Number(edits[r.key] ?? 0);
    if (r.group === 'Earn rates') return (v / 100).toFixed(2) + '%';
    if (r.group === 'Tier multipliers') return (v / 10000).toFixed(2) + 'x';
    if (r.key === 'redemption.min_sale_cents') return (v / 100).toFixed(2) + ' AZN';
    return '';
};

function save(r) {
    savingKey.value = r.key;
    router.post(route('admin.rules.update'), { key: r.key, value: Number(edits[r.key]) }, {
        preserveScroll: true,
        onFinish: () => { savingKey.value = null; },
    });
}
function reset(r) {
    savingKey.value = r.key;
    router.post(route('admin.rules.reset'), { key: r.key }, {
        preserveScroll: true,
        onFinish: () => { savingKey.value = null; },
    });
}
</script>

<template>
    <Head title="Rules" />
    <AdminLayout breadcrumb="Rules">

        <div class="mb-8">
            <div class="font-mono text-[10px] uppercase tracking-widest text-muted">Configuration</div>
            <h1 class="font-serif font-light text-4xl mt-2 tracking-tight">
                Loyalty <em class="italic font-semibold text-accent">qaydaları</em>
            </h1>
            <p class="mt-2 text-sm text-muted max-w-2xl">
                Earn faizləri (basis points), tier multiplier-ləri, redemption və expiry. Dəyişiklik
                növbəti satışdan tətbiq olunur. Kanonik hesablama (intdiv) toxunulmur — yalnız dəyərlər.
            </p>
        </div>

        <div v-if="page.props.flash?.success" class="card mb-4 border-l-2 border-l-success text-sm text-text">✓ {{ page.props.flash.success }}</div>
        <div v-if="page.props.flash?.error" class="card mb-4 border-l-2 border-l-danger text-sm text-danger">⚠ {{ page.props.flash.error }}</div>

        <div class="space-y-6">
            <div v-for="(rows, group) in groups" :key="group" class="card">
                <div class="section-title mb-0">{{ group }}</div>
                <div class="font-mono text-[10px] text-muted mb-4">{{ rows[0].unit }} vahidi</div>

                <div class="divide-y divide-border">
                    <div v-for="r in rows" :key="r.key" class="flex items-center gap-4 py-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm">{{ r.label }}</span>
                                <Pill v-if="r.source === 'db'" variant="success" dot>custom</Pill>
                                <span v-else class="font-mono text-[10px] text-muted">default</span>
                            </div>
                            <div class="font-mono text-[10px] text-muted mt-0.5">{{ r.key }}</div>
                        </div>

                        <div class="text-right">
                            <div class="flex items-center gap-2">
                                <input v-model="edits[r.key]" type="number" :min="r.min" :max="r.max"
                                       class="w-28 bg-surface-2 border border-border px-3 py-1.5 font-mono text-sm text-right focus:outline-none focus:border-accent" />
                                <span class="font-mono text-[11px] text-muted w-10 text-left">{{ r.unit }}</span>
                            </div>
                            <div class="font-mono text-[10px] text-accent-blue mt-1 h-3">{{ hint(r) }}</div>
                        </div>

                        <div class="flex items-center gap-3 w-40 justify-end">
                            <button @click="save(r)" :disabled="savingKey === r.key || Number(edits[r.key]) === r.value"
                                    class="btn-primary px-4 py-1.5 text-xs disabled:opacity-40">
                                {{ savingKey === r.key ? '...' : 'Yadda saxla' }}
                            </button>
                            <button v-if="r.source === 'db'" @click="reset(r)" :disabled="savingKey === r.key"
                                    class="font-mono text-[11px] text-muted hover:text-accent">↺ default</button>
                        </div>
                    </div>
                </div>

                <div v-if="errors.value" class="mt-3 text-xs text-danger font-mono">⚠ {{ errors.value }}</div>
            </div>
        </div>

        <div class="mt-6 font-mono text-[11px] text-muted">
            Hüdudlar: earn rate 0–10000 bp (0–100%), tier 0–50000 bp (0–5x), redemption 0–100%. Mənfi dəyər qəbul edilmir (EarnCalculator fail-fast).
        </div>

    </AdminLayout>
</template>
