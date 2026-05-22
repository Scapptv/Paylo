<script setup>
import Pill from './Pill.vue';

const props = defineProps({
    type: { type: String, required: true },
});

const variantMap = {
    earn:       'success',
    redeem:     'warning',
    refund:     'danger',
    reversal:   'danger',
    expire:     'default',
    adjustment: 'purple',
    transfer:   'blue',
};

const labelMap = {
    earn:       'EARN',
    redeem:     'REDEEM',
    refund:     'REFUND',
    reversal:   'REVERSAL',
    expire:     'EXPIRED',
    adjustment: 'ADJUST',
    transfer:   'TRANSFER',
};
</script>

<template>
    <Pill :variant="variantMap[type] || 'default'" dot>
        {{ labelMap[type] || type }}
    </Pill>
</template>
