<script setup>
const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '',
    },
    ariaLabel: {
        type: String,
        default: '',
    },
    width: {
        type: String,
        default: 'min(420px, calc(100vw - 1.2rem))',
    },
});

const emit = defineEmits(['close']);

const closePanel = () => {
    emit('close');
};
</script>

<template>
    <Transition name="quick-menu-panel-slide">
        <div v-if="open" class="quick-menu-panel-layer">
            <aside
                class="quick-menu-panel-drawer"
                role="region"
                :aria-label="ariaLabel || title"
                :style="{ width }"
            >
                <header class="quick-menu-panel-head">
                    <h3>{{ title }}</h3>
                    <button
                        type="button"
                        class="quick-menu-panel-close"
                        :aria-label="`Fechar ${title || 'painel'}`"
                        @click="closePanel"
                    >
                        ×
                    </button>
                </header>

                <slot />
            </aside>
        </div>
    </Transition>
</template>

<style scoped>
.quick-menu-panel-layer {
    position: fixed;
    inset: 0;
    z-index: 64;
    background: transparent;
    display: flex;
    justify-content: flex-end;
    pointer-events: none;
}

.quick-menu-panel-drawer {
    height: 100%;
    background: #f8fbff;
    border-left: 1px solid #d4deea;
    box-shadow: -16px 0 30px rgba(15, 23, 42, 0.22);
    display: grid;
    grid-template-rows: auto 1fr;
    pointer-events: auto;
}

.quick-menu-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
    padding: 0.8rem 0.9rem;
    border-bottom: 1px solid #dfe8f3;
    background: #fff;
}

.quick-menu-panel-head h3 {
    margin: 0;
    font-size: 1rem;
}

.quick-menu-panel-close {
    width: 30px;
    height: 30px;
    border-radius: 999px;
    border: 1px solid #c7d6e6;
    background: #fff;
    color: #475569;
    font-size: 1rem;
    cursor: pointer;
}

.quick-menu-panel-slide-enter-active,
.quick-menu-panel-slide-leave-active {
    transition: opacity 220ms ease;
}

.quick-menu-panel-slide-enter-active .quick-menu-panel-drawer,
.quick-menu-panel-slide-leave-active .quick-menu-panel-drawer {
    transition: transform 220ms ease;
}

.quick-menu-panel-slide-enter-from,
.quick-menu-panel-slide-leave-to {
    opacity: 0;
}

.quick-menu-panel-slide-enter-from .quick-menu-panel-drawer,
.quick-menu-panel-slide-leave-to .quick-menu-panel-drawer {
    transform: translateX(22px);
}
</style>
