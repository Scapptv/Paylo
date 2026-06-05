<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import Pill from '@/Components/Pill.vue';

defineProps({
    staff: { type: Array, required: true },
});

function remove(id, name) {
    if (! confirm(`${name} silinsin? Bu əməliyyat geri qaytarıla bilməz.`)) return;
    router.delete(route('merchant.staff.destroy', id));
}
</script>

<template>
    <Head title="Staff" />
    <MerchantLayout breadcrumb="Staff">

        <div class="flex items-center justify-between mb-8">
            <div>
                <div class="section-title">Mağazanın işçi heyəti</div>
                <h1 class="font-serif font-light text-4xl mt-1 tracking-tight">
                    <em class="italic font-semibold text-accent">Staff</em> idarəetməsi
                </h1>
            </div>
            <Link :href="route('merchant.staff.create')"
                  class="btn-primary text-xs px-4 py-2 font-mono uppercase tracking-wider">
                + Yeni işçi
            </Link>
        </div>

        <div class="card overflow-hidden p-0">
            <table class="w-full text-sm">
                <thead class="bg-surface-2 text-[11px] uppercase font-mono tracking-wider text-muted">
                    <tr>
                        <th class="px-4 py-3 text-left">Ad</th>
                        <th class="px-4 py-3 text-left">Email</th>
                        <th class="px-4 py-3 text-left">Telefon</th>
                        <th class="px-4 py-3 text-left">Rol</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-right">Əməliyyat</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="s in staff" :key="s.id" class="hover:bg-surface-2/40">
                        <td class="px-4 py-3 font-medium">{{ s.name }}</td>
                        <td class="px-4 py-3 text-muted">{{ s.email }}</td>
                        <td class="px-4 py-3 text-muted font-mono">{{ s.phone || '—' }}</td>
                        <td class="px-4 py-3"><Pill>{{ s.role }}</Pill></td>
                        <td class="px-4 py-3">
                            <Pill :variant="s.is_active ? 'success' : 'danger'" dot>
                                {{ s.is_active ? 'aktiv' : 'deaktiv' }}
                            </Pill>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link v-if="s.role !== 'merchant_owner'"
                                  :href="route('merchant.staff.edit', s.id)"
                                  class="text-xs text-accent hover:text-accent-blue font-mono uppercase tracking-wider mr-3">
                                Redaktə
                            </Link>
                            <button v-if="s.role !== 'merchant_owner'"
                                    @click="remove(s.id, s.name)"
                                    class="text-xs text-danger hover:text-red-600 font-mono uppercase tracking-wider">
                                Sil
                            </button>
                            <span v-else class="text-xs text-muted font-mono">(owner)</span>
                        </td>
                    </tr>
                    <tr v-if="staff.length === 0">
                        <td colspan="6" class="px-4 py-10 text-center text-muted text-sm">
                            Hələ heç bir işçi əlavə edilməyib.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </MerchantLayout>
</template>
