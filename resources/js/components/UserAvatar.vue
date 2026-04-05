<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    name: {
        type: String,
        default: '',
    },
    src: {
        type: String,
        default: '',
    },
    size: {
        type: [Number, String],
        default: 32,
    },
});

const fallbackSrc = '/images/avatar-placeholder.svg';
const safeSrc = computed(() => {
    const value = String(props.src || '').trim();
    return value || fallbackSrc;
});

const resolvedSrc = ref(safeSrc.value);
watch(safeSrc, (value) => {
    resolvedSrc.value = value;
});

const dimension = computed(() => {
    if (typeof props.size === 'number') {
        return `${props.size}px`;
    }

    const normalized = String(props.size || '').trim();
    return normalized || '32px';
});

const altText = computed(() => {
    const name = String(props.name || '').trim();
    return name ? `Avatar de ${name}` : 'Avatar';
});

const onImageError = () => {
    if (resolvedSrc.value !== fallbackSrc) {
        resolvedSrc.value = fallbackSrc;
    }
};
</script>

<template>
    <span class="user-avatar" :style="{ width: dimension, height: dimension }">
        <img :src="resolvedSrc" :alt="altText" loading="lazy" @error="onImageError">
    </span>
</template>

<style scoped>
.user-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    overflow: hidden;
    border: 1px solid #b5c7dc;
    background: #f4f8fc;
    flex: 0 0 auto;
}

.user-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
</style>
