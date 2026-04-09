<script setup>
import { computed } from 'vue';

const props = defineProps({
    actions: {
        type: Array,
        default: () => [],
    },
    ariaLabel: {
        type: String,
        default: 'Ações rápidas',
    },
    desktopStyle: {
        type: String,
        default: 'floating', // floating | edge
    },
    mobileStyle: {
        type: String,
        default: 'none', // none | inline | bottom
    },
    dock: {
        type: Boolean,
        default: false,
    },
    dockOffset: {
        type: String,
        default: '420px',
    },
});

const emit = defineEmits(['action']);

const railClass = computed(() => [
    'quick-rail',
    props.desktopStyle === 'edge' ? 'quick-rail--edge' : 'quick-rail--floating',
    props.dock ? 'quick-rail--docked' : '',
    props.mobileStyle === 'inline' ? 'quick-rail--mobile-inline' : '',
    props.mobileStyle === 'bottom' ? 'quick-rail--mobile-bottom' : '',
]);

const railStyle = computed(() => (
    props.dock
        ? { '--quick-dock-offset': props.dockOffset }
        : {}
));

const onActionClick = (action) => {
    if (action?.disabled) return;
    emit('action', action?.id);
};
</script>

<template>
    <nav :class="railClass" :aria-label="ariaLabel" :style="railStyle">
        <button
            v-for="action in actions"
            :key="action.id"
            type="button"
            :class="{ active: Boolean(action.active) }"
            :title="action.title || ''"
            :disabled="Boolean(action.disabled)"
            @click="onActionClick(action)"
        >
            <slot :name="`icon-${action.id}`" :action="action">
                <span>{{ action.label || '•' }}</span>
            </slot>
        </button>
    </nav>
</template>

<style scoped>
.quick-rail {
    position: fixed;
    right: 1.25rem;
    top: 50%;
    transform: translateY(-50%);
    display: grid;
    gap: 0.35rem;
    z-index: 65;
    padding: 0.3rem 0.2rem;
    border-radius: 999px;
    background: rgba(248, 251, 255, 0.72);
    border: 1px solid rgba(209, 220, 235, 0.9);
    backdrop-filter: blur(4px);
}

.quick-rail--docked {
    right: calc(1.25rem + var(--quick-dock-offset, 0px));
}

.quick-rail--edge {
    right: 0;
    border-top-left-radius: 999px;
    border-bottom-left-radius: 999px;
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
    border-right: 0;
}

.quick-rail button {
    width: 34px;
    height: 34px;
    border-radius: 999px;
    border: 1px solid #dbe5f1;
    background: #f8fbff;
    color: #6b7b90;
    display: grid;
    place-items: center;
    cursor: pointer;
    transition: background-color 140ms ease, color 140ms ease, border-color 140ms ease, transform 140ms ease;
}

.quick-rail button:hover {
    background: #edf3fa;
    color: #334155;
    border-color: #c8d5e6;
    transform: translateX(-1px);
}

.quick-rail button.active {
    background: #e9fbf3;
    color: #1F4E79;
    border-color: #9bd7c0;
}

.quick-rail button:disabled {
    cursor: default;
    opacity: 0.7;
    transform: none;
}

.quick-rail :deep(svg) {
    width: 16px;
    height: 16px;
}

@media (max-width: 900px) {
    .quick-rail--mobile-inline {
        position: sticky;
        top: auto;
        right: auto;
        transform: none;
        margin-top: 0.4rem;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        background: #fff;
        border: 1px solid #d9e2ee;
        border-radius: 12px;
        padding: 0.4rem;
    }

    .quick-rail--mobile-inline button {
        width: 100%;
        border-radius: 10px;
    }
}

@media (max-width: 720px) {
    .quick-rail--mobile-bottom {
        right: 0.5rem;
        top: auto;
        bottom: 1.15rem;
        transform: none;
        border-radius: 999px;
        border-right: 1px solid rgba(209, 220, 235, 0.9);
    }

    .quick-rail--docked {
        right: 0.5rem;
    }
}
</style>
