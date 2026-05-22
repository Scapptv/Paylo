<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
defineProps({ status: Number });
const page = usePage();
</script>

<template>
    <Head title="403 — Forbidden" />
    <div class="min-h-screen grid place-items-center grain px-6">
        <div class="text-center max-w-md">
            <div class="font-mono text-[10px] tracking-widest text-danger uppercase">403 / Forbidden</div>
            <h1 class="font-serif font-light text-6xl mt-4 tracking-tight">
                <em class="italic font-semibold text-danger">Yox.</em>
            </h1>
            <p class="mt-5 text-sm text-muted leading-relaxed">
                Bu səhifə sizin rolunuza açıq deyil. Hər rolun yalnız öz dünyası var —
                <span class="text-text-2">{{ page.props.auth.user?.role_label || 'qonaq' }}</span> kimi
                bu route-a icazəniz yoxdur.
            </p>
            <div class="mt-8">
                <Link href="/" class="btn btn-primary px-8 py-3">← Öz panelimə qayıt</Link>
            </div>
        </div>
    </div>
</template>
