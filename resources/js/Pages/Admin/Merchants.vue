<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { debounce } from '@/Composables/useDebounce.js';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pill from '@/Components/Pill.vue';

const props = defineProps({
    merchants: { type: Object, required: true },
    filters:   { type: Object, required: true },
});

const state = reactive({
    status:   props.filters.status   || '',
    category: props.filters.category || '',
    q:        props.filters.q        || '',
});

const apply = debounce(() => {
    router.get(route('admin.merchants'), state, { preserveState: true, replace: true });
}, 250);

watch(state, apply, { deep: true });

const statusVariant = {
    active: 'success', pending: 'warning', paused: 'default', revoked: 'danger',
};
</script>

<template>
    <Head title="Merchants" />
    <AdminLayout breadcrumb="Merchants">

        <div class="flex items-center justify-between mb-8">
            <div>
                <div class="section-title">POSNET mirror via API</div>
                <h1 class="font-serif font-light text-4xl mt-1 tracking-tight">
                    Bütün <em class="italic font-semibold text-accent">merchant-lar</em>
                </h1>
            </div>
            <div class="font-mono text-xs text-muted"><span class="text-accent">{{ merchants.total }}</span> merchants</div>
        </div>

        <div class="card mb-6 flex flex-wrap gap-3">
            <input v-model="state.q" type="search" placeholder="search by name, TIN, code..." class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs flex-1 min-w-[260px] focus:outline-none focus:border-accent" />
            <select v-model="state.status" class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs focus:outline-none focus:border-accent">
                <option value="">All status</option>
                <option value="active">Active</option>
                <option value="pending">Pending</option>
                <option value="paused">Paused</option>
                <option value="revoked">Revoked</option>
            </select>
            <select v-model="state.category" class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs focus:outline-none focus:border-accent">
                <option value="">All categories</option>
                <option>grocery</option>
                <option>restaurant</option>
                <option>fuel</option>
                <option>pharmacy</option>
                <option>retail</option>
            </select>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
            <Link
                v-for="m in merchants.data"
                :key="m.id"
                :href="route('admin.merchants.show', m.id)"
                class="card card-hover cursor-pointer block"
            >
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 flex items-center justify-center bg-surface-3 font-serif font-bold text-xl">
                        {{ m.name?.[0]?.toUpperCase() }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-serif text-lg font-semibold truncate">{{ m.name }}</div>
                        <div class="font-mono text-[11px] text-accent-blue">{{ m.code }}</div>
                    </div>
                    <Pill :variant="statusVariant[m.status]" dot>{{ m.status }}</Pill>
                </div>

                <div class="mt-5 pt-4 border-t border-border grid grid-cols-3 gap-3 text-center">
                    <div>
                        <div class="font-mono text-[9px] uppercase text-muted">Branches</div>
                        <div class="font-serif text-xl font-semibold mt-1">{{ m.branches_count }}</div>
                    </div>
                    <div>
                        <div class="font-mono text-[9px] uppercase text-muted">Staff</div>
                        <div class="font-serif text-xl font-semibold mt-1">{{ m.users_count }}</div>
                    </div>
                    <div>
                        <div class="font-mono text-[9px] uppercase text-muted">Buckets</div>
                        <div class="font-serif text-xl font-semibold mt-1 text-accent">{{ m.buckets_count }}</div>
                    </div>
                </div>

                <div class="mt-4 flex items-center gap-2">
                    <Pill>{{ m.category }}</Pill>
                    <Pill>MCC {{ m.mcc }}</Pill>
                    <Pill :variant="m.tier === 'enterprise' ? 'purple' : m.tier === 'premium' ? 'accent' : 'default'">{{ m.tier }}</Pill>
                </div>
            </Link>

            <div v-if="!merchants.data?.length" class="col-span-full py-16 text-center text-muted font-mono text-xs">
                No merchants match filters
            </div>
        </div>

    </AdminLayout>
</template>
