<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import api from '../api/client';

const loading = ref(false);
const error = ref('');
const summary = ref(null);

const numberFormatter = new Intl.NumberFormat('pt-PT');

const totalTickets = computed(() => Number(summary.value?.totals?.all_tickets || 0));
const unresolvedTickets = computed(() => {
    const totals = summary.value?.totals;
    if (!totals) return 0;
    return Number(totals.open || 0) + Number(totals.in_progress || 0) + Number(totals.pending || 0);
});
const solvedTickets = computed(() => Number(summary.value?.totals?.closed || 0));

const statusSeries = computed(() => {
    const totals = summary.value?.totals || {};

    return [
        { key: 'open', label: 'Abertos', value: Number(totals.open || 0), color: '#3b82f6' },
        { key: 'in_progress', label: 'Em curso', value: Number(totals.in_progress || 0), color: '#8b5cf6' },
        { key: 'pending', label: 'Pendentes', value: Number(totals.pending || 0), color: '#f59e0b' },
        { key: 'closed', label: 'Fechados', value: Number(totals.closed || 0), color: '#10b981' },
        { key: 'cancelled', label: 'Cancelados', value: Number(totals.cancelled || 0), color: '#ef4444' },
    ];
});

const maxStatusValue = computed(() => Math.max(1, ...statusSeries.value.map((item) => item.value)));

const inboxBaseColors = ['#2563eb', '#06b6d4', '#10b981', '#f59e0b', '#a855f7', '#ef4444'];
const inboxSeries = computed(() => {
    const rows = summary.value?.by_inbox || [];
    if (!rows.length) return [];

    const top = rows.slice(0, 5).map((row, index) => ({
        name: row.inbox_name,
        value: Number(row.total || 0),
        color: inboxBaseColors[index % inboxBaseColors.length],
    }));
    const othersTotal = rows.slice(5).reduce((acc, row) => acc + Number(row.total || 0), 0);

    if (othersTotal > 0) {
        top.push({
            name: 'Outras',
            value: othersTotal,
            color: '#94a3b8',
        });
    }

    return top;
});

const formatPercent = (value, decimals = 1) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return `${Number(value).toFixed(decimals)}%`;
};

const minutesToLabel = (minutes) => {
    if (minutes === null || minutes === undefined) return '-';
    if (minutes < 60) return `${Math.round(minutes)} min`;
    if (minutes < 1440) return `${(minutes / 60).toFixed(1)} h`;
    return `${(minutes / 1440).toFixed(1)} dias`;
};

const donutBackground = (segments) => {
    const total = segments.reduce((acc, segment) => acc + segment.value, 0);

    if (total <= 0) {
        return 'conic-gradient(#dbe7f4 0 100%)';
    }

    let cursor = 0;
    const stops = segments
        .filter((segment) => segment.value > 0)
        .map((segment) => {
            const start = (cursor / total) * 100;
            cursor += segment.value;
            const end = (cursor / total) * 100;
            return `${segment.color} ${start}% ${end}%`;
        });

    return `conic-gradient(${stops.join(', ')})`;
};

const responseSlaSegments = computed(() => {
    const within = Number(summary.value?.sla?.first_response_compliance_percent ?? 0);
    const hasMetric = summary.value?.sla?.first_response_compliance_percent !== null
        && summary.value?.sla?.first_response_compliance_percent !== undefined;

    if (!hasMetric) {
        return [{ label: 'Sem dados', value: 1, color: '#cbd5e1' }];
    }

    return [
        { label: 'Dentro SLA', value: within, color: '#10b981' },
        { label: 'Fora SLA', value: Math.max(0, 100 - within), color: '#ef4444' },
    ];
});

const inboxDonutTotal = computed(() => inboxSeries.value.reduce((acc, item) => acc + item.value, 0));
const inboxDonutStyle = computed(() => donutBackground(inboxSeries.value));
const responseSlaDonutStyle = computed(() => donutBackground(responseSlaSegments.value));
const criticalBacklog = computed(() => summary.value?.critical_backlog || null);
const criticalThresholdHours = computed(() => Number(criticalBacklog.value?.threshold_hours || 24));
const criticalOpenTickets = computed(() => Number(criticalBacklog.value?.open_tickets || 0));
const criticalTickets = computed(() => Number(criticalBacklog.value?.critical_tickets || 0));
const criticalPercent = computed(() => Number(criticalBacklog.value?.critical_percent || 0));

const dailyFlowSeries = computed(() => {
    const series = summary.value?.daily_flow?.series;
    return Array.isArray(series) ? series : [];
});
const dailyFlowDays = computed(() => Number(summary.value?.daily_flow?.days || dailyFlowSeries.value.length || 0));
const dailyFlowMax = computed(() => {
    const values = dailyFlowSeries.value.flatMap((item) => [Number(item.created || 0), Number(item.closed || 0)]);
    return Math.max(1, ...values);
});
const dailyFlowCreatedTotal = computed(() => dailyFlowSeries.value.reduce((sum, item) => sum + Number(item.created || 0), 0));
const dailyFlowClosedTotal = computed(() => dailyFlowSeries.value.reduce((sum, item) => sum + Number(item.closed || 0), 0));
const dailyFlowStartLabel = computed(() => dailyFlowSeries.value[0]?.label || '-');
const dailyFlowEndLabel = computed(() => dailyFlowSeries.value[dailyFlowSeries.value.length - 1]?.label || '-');

const responseSlaMain = computed(() => {
    const value = summary.value?.sla?.first_response_compliance_percent;
    return value === null || value === undefined ? '-' : `${Number(value).toFixed(0)}%`;
});

const flowPointX = (index, total) => {
    if (total <= 1) return 50;
    return (index / (total - 1)) * 100;
};

const flowPointY = (value, max) => {
    const normalized = max > 0 ? Number(value || 0) / max : 0;
    return 90 - (normalized * 78);
};

const flowLinePoints = (field) => {
    const series = dailyFlowSeries.value;
    if (!series.length) return '';

    return series
        .map((item, index) => `${flowPointX(index, series.length)},${flowPointY(item[field], dailyFlowMax.value)}`)
        .join(' ');
};

const kpis = computed(() => [
    {
        id: 'created',
        title: 'Tickets criados',
        value: numberFormatter.format(totalTickets.value),
        hint: 'Volume total visível',
        tone: 'blue',
    },
    {
        id: 'unresolved',
        title: 'Tickets em aberto',
        value: numberFormatter.format(unresolvedTickets.value),
        hint: 'Abertos + em curso + pendentes',
        tone: 'orange',
    },
    {
        id: 'solved',
        title: 'Tickets resolvidos',
        value: numberFormatter.format(solvedTickets.value),
        hint: 'Estado fechado',
        tone: 'green',
    },
    {
        id: 'first-response',
        title: '1.ª resposta média',
        value: minutesToLabel(summary.value?.averages?.first_response_minutes),
        hint: `SLA ${summary.value?.sla?.first_response_hours ?? '-'}h • ${formatPercent(summary.value?.sla?.first_response_compliance_percent)}`,
        tone: 'purple',
    },
]);

const inboxStatusLink = (inboxId, status = '') => {
    const query = { inbox_id: String(inboxId) };
    if (status) {
        query.status = status;
    }

    return {
        name: 'tickets.index',
        query,
    };
};

const loadSummary = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/dashboard/summary');
        summary.value = response.data.data;
    } catch {
        error.value = 'Não foi possível carregar o dashboard.';
    } finally {
        loading.value = false;
    }
};

onMounted(loadSummary);
</script>

<template>
    <section class="dashboard-page">

        <!-- Header -->
        <header class="db-header">
            <div class="db-header-left">
                <div class="db-header-icon">
                    <svg viewBox="0 0 20 20" fill="none">
                        <rect x="2" y="2" width="7" height="7" rx="2" stroke="currentColor" stroke-width="1.6"/>
                        <rect x="11" y="2" width="7" height="4" rx="1.5" stroke="currentColor" stroke-width="1.6"/>
                        <rect x="11" y="8" width="7" height="10" rx="1.5" stroke="currentColor" stroke-width="1.6"/>
                        <rect x="2" y="11" width="7" height="7" rx="2" stroke="currentColor" stroke-width="1.6"/>
                    </svg>
                </div>
                <div>
                    <h1 class="db-h1">Dashboard</h1>
                    <p class="db-subtitle">Visão operacional de tickets e desempenho de SLA</p>
                </div>
            </div>
            <button type="button" class="db-refresh-btn" :disabled="loading" @click="loadSummary">
                <svg viewBox="0 0 16 16" fill="none" :class="{ 'is-spinning': loading }">
                    <path d="M13.5 8A5.5 5.5 0 1 1 8 2.5a5.5 5.5 0 0 1 4 1.72" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    <path d="M12 1v3.5H8.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                {{ loading ? 'A atualizar…' : 'Atualizar' }}
            </button>
        </header>

        <!-- Error / loading -->
        <div v-if="error" class="db-error">
            <svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.4"/><path d="M8 5v3m0 2.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            {{ error }}
        </div>
        <div v-else-if="loading && !summary" class="db-loading">
            <span class="db-spinner"></span>
            A carregar métricas…
        </div>

        <div v-if="summary" class="db-grid">

            <!-- KPIs -->
            <section class="db-kpi-row">
                <article v-for="kpi in kpis" :key="kpi.id" class="db-kpi" :class="`db-kpi--${kpi.tone}`">
                    <div class="db-kpi-icon-wrap">
                        <svg v-if="kpi.id === 'created'" viewBox="0 0 18 18" fill="none">
                            <path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h9A1.5 1.5 0 0 1 15 4.5v9a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 3 13.5v-9Z" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M9 6.5v5M6.5 9h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        <svg v-else-if="kpi.id === 'unresolved'" viewBox="0 0 18 18" fill="none">
                            <circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M9 6v3.5l2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <svg v-else-if="kpi.id === 'solved'" viewBox="0 0 18 18" fill="none">
                            <circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M6 9.5l2 2 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <svg v-else viewBox="0 0 18 18" fill="none">
                            <circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M9 6v3l1.5 1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="db-kpi-body">
                        <p class="db-kpi-label">{{ kpi.title }}</p>
                        <strong class="db-kpi-value">{{ kpi.value }}</strong>
                        <p class="db-kpi-hint">{{ kpi.hint }}</p>
                    </div>
                </article>
            </section>

            <!-- Row 2: Status bars + SLA donut -->
            <article class="db-panel db-status-panel">
                <div class="db-panel-head">
                    <div class="db-panel-title-group">
                        <svg viewBox="0 0 16 16" fill="none" class="db-panel-icon"><rect x="1" y="1" width="14" height="14" rx="3" stroke="currentColor" stroke-width="1.3"/><path d="M4 8.5l2.5 2.5L12 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <h2>Tickets por estado</h2>
                    </div>
                    <span class="db-panel-meta">{{ numberFormatter.format(totalTickets) }} total</span>
                </div>

                <div class="db-status-list">
                    <div v-for="item in statusSeries" :key="item.key" class="db-status-row">
                        <span class="db-status-label">{{ item.label }}</span>
                        <div class="db-status-bar-wrap">
                            <div
                                class="db-status-bar-fill"
                                :style="{
                                    width: item.value ? `${Math.max((item.value / maxStatusValue) * 100, 4)}%` : '0%',
                                    backgroundColor: item.color,
                                }"
                            />
                        </div>
                        <span class="db-status-count">{{ numberFormatter.format(item.value) }}</span>
                        <span class="db-status-pct">{{ totalTickets ? `${((item.value / totalTickets) * 100).toFixed(0)}%` : '–' }}</span>
                    </div>
                </div>
            </article>

            <article class="db-panel db-sla-panel">
                <div class="db-panel-head">
                    <div class="db-panel-title-group">
                        <svg viewBox="0 0 16 16" fill="none" class="db-panel-icon"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.3"/><path d="M8 5v3l2 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <h2>1.ª resposta SLA</h2>
                    </div>
                    <span class="db-panel-meta">meta {{ summary.sla.first_response_hours }}h</span>
                </div>

                <div class="db-donut-layout">
                    <div class="db-donut" :style="{ background: responseSlaDonutStyle }">
                        <div class="db-donut-hole">
                            <strong>{{ responseSlaMain }}</strong>
                            <small>dentro SLA</small>
                        </div>
                    </div>
                    <ul class="db-legend">
                        <li v-for="segment in responseSlaSegments" :key="segment.label">
                            <span class="db-legend-dot" :style="{ background: segment.color }"></span>
                            <span class="db-legend-name">{{ segment.label }}</span>
                            <strong class="db-legend-val">{{ formatPercent(segment.value) }}</strong>
                        </li>
                    </ul>
                </div>
            </article>

            <!-- Row 3: Flow chart + Critical backlog -->
            <article class="db-panel db-flow-panel">
                <div class="db-panel-head">
                    <div class="db-panel-title-group">
                        <svg viewBox="0 0 16 16" fill="none" class="db-panel-icon"><path d="M2 12 6 7l3 3 5-6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <h2>Fluxo diário</h2>
                    </div>
                    <div class="db-flow-legend-inline">
                        <span><i class="db-legend-chip db-legend-chip--created"></i>Criados <strong>{{ numberFormatter.format(dailyFlowCreatedTotal) }}</strong></span>
                        <span><i class="db-legend-chip db-legend-chip--closed"></i>Fechados <strong>{{ numberFormatter.format(dailyFlowClosedTotal) }}</strong></span>
                    </div>
                </div>

                <div v-if="dailyFlowSeries.length" class="db-flow-body">
                    <svg class="db-flow-svg" viewBox="0 0 100 100" preserveAspectRatio="none">
                        <line x1="0" y1="90" x2="100" y2="90" class="db-flow-axis" />
                        <line x1="0" y1="64" x2="100" y2="64" class="db-flow-grid" />
                        <line x1="0" y1="38" x2="100" y2="38" class="db-flow-grid" />
                        <line x1="0" y1="12" x2="100" y2="12" class="db-flow-grid" />
                        <polyline :points="flowLinePoints('created')" class="db-flow-line db-flow-line--created" />
                        <polyline :points="flowLinePoints('closed')" class="db-flow-line db-flow-line--closed" />
                        <circle
                            v-for="(item, index) in dailyFlowSeries"
                            :key="`c-${item.date}`"
                            class="db-flow-dot db-flow-dot--created"
                            :cx="flowPointX(index, dailyFlowSeries.length)"
                            :cy="flowPointY(item.created, dailyFlowMax)"
                            r="1.3"
                        />
                        <circle
                            v-for="(item, index) in dailyFlowSeries"
                            :key="`x-${item.date}`"
                            class="db-flow-dot db-flow-dot--closed"
                            :cx="flowPointX(index, dailyFlowSeries.length)"
                            :cy="flowPointY(item.closed, dailyFlowMax)"
                            r="1.3"
                        />
                    </svg>
                    <div class="db-flow-range">
                        <span>{{ dailyFlowStartLabel }}</span>
                        <span>Últimos {{ dailyFlowDays }} dias</span>
                        <span>{{ dailyFlowEndLabel }}</span>
                    </div>
                </div>
                <p v-else class="db-empty">Sem dados de fluxo diário para apresentar.</p>
            </article>

            <article class="db-panel db-critical-panel" :class="{ 'db-critical-panel--alert': criticalPercent > 25 }">
                <div class="db-panel-head">
                    <div class="db-panel-title-group">
                        <svg viewBox="0 0 16 16" fill="none" class="db-panel-icon"><path d="M8 2L1.5 13h13L8 2z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M8 6.5v3M8 11v.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                        <h2>Backlog crítico</h2>
                    </div>
                    <span class="db-panel-meta">&gt; {{ criticalThresholdHours }}h</span>
                </div>

                <div class="db-critical-pct">
                    <strong :class="criticalPercent > 25 ? 'is-danger' : 'is-ok'">{{ formatPercent(criticalPercent, 0) }}</strong>
                    <p>
                        <template v-if="criticalOpenTickets > 0">dos tickets em aberto ultrapassaram {{ criticalThresholdHours }}h sem resposta</template>
                        <template v-else>Sem tickets em aberto neste momento</template>
                    </p>
                </div>

                <div class="db-critical-track">
                    <div class="db-critical-fill" :style="{ width: `${Math.max(0, Math.min(100, criticalPercent))}%` }"></div>
                </div>

                <div class="db-critical-stats">
                    <div class="db-cstat">
                        <span>Críticos</span>
                        <strong>{{ numberFormatter.format(criticalTickets) }}</strong>
                    </div>
                    <div class="db-cstat">
                        <span>Em aberto</span>
                        <strong>{{ numberFormatter.format(criticalOpenTickets) }}</strong>
                    </div>
                </div>
            </article>

            <!-- Row 4: Inbox donut + table -->
            <article class="db-panel db-inbox-donut-panel">
                <div class="db-panel-head">
                    <div class="db-panel-title-group">
                        <svg viewBox="0 0 16 16" fill="none" class="db-panel-icon"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.3"/><circle cx="8" cy="8" r="2.5" stroke="currentColor" stroke-width="1.3"/></svg>
                        <h2>Por inbox</h2>
                    </div>
                    <span class="db-panel-meta">{{ numberFormatter.format(inboxDonutTotal) }} tickets</span>
                </div>

                <div v-if="inboxSeries.length" class="db-donut-layout">
                    <div class="db-donut" :style="{ background: inboxDonutStyle }">
                        <div class="db-donut-hole">
                            <strong>{{ numberFormatter.format(inboxDonutTotal) }}</strong>
                            <small>total</small>
                        </div>
                    </div>
                    <ul class="db-legend">
                        <li v-for="item in inboxSeries" :key="item.name">
                            <span class="db-legend-dot" :style="{ background: item.color }"></span>
                            <span class="db-legend-name">{{ item.name }}</span>
                            <strong class="db-legend-val">{{ numberFormatter.format(item.value) }}</strong>
                        </li>
                    </ul>
                </div>
                <p v-else class="db-empty">Sem dados de inbox para apresentar.</p>
            </article>

            <article class="db-panel db-table-panel">
                <div class="db-panel-head">
                    <div class="db-panel-title-group">
                        <svg viewBox="0 0 16 16" fill="none" class="db-panel-icon"><rect x="1" y="1" width="14" height="14" rx="2" stroke="currentColor" stroke-width="1.3"/><path d="M1 5.5h14M5.5 5.5v9" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                        <h2>Distribuição por inbox</h2>
                    </div>
                    <span class="db-panel-meta">detalhe por estado</span>
                </div>

                <div class="db-table-wrap">
                    <table class="db-table">
                        <thead>
                            <tr>
                                <th>Inbox</th>
                                <th class="is-num">Total</th>
                                <th class="is-num">Abertos</th>
                                <th class="is-num">Em curso</th>
                                <th class="is-num">Pendentes</th>
                                <th class="is-num">Fechados</th>
                                <th class="is-num">Cancelados</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in summary.by_inbox" :key="row.inbox_id" class="db-table-row">
                                <td class="db-inbox-name">
                                    <span class="db-inbox-dot" :style="{ background: inboxBaseColors[summary.by_inbox.indexOf(row) % inboxBaseColors.length] }"></span>
                                    {{ row.inbox_name }}
                                </td>
                                <td class="is-num db-total-cell">
                                    <RouterLink :to="inboxStatusLink(row.inbox_id)">{{ numberFormatter.format(row.total) }}</RouterLink>
                                </td>
                                <td class="is-num">
                                    <RouterLink class="db-cell-link db-cell--open" :to="inboxStatusLink(row.inbox_id, 'open')">{{ numberFormatter.format(row.open) }}</RouterLink>
                                </td>
                                <td class="is-num">
                                    <RouterLink class="db-cell-link db-cell--in-progress" :to="inboxStatusLink(row.inbox_id, 'in_progress')">{{ numberFormatter.format(row.in_progress) }}</RouterLink>
                                </td>
                                <td class="is-num">
                                    <RouterLink class="db-cell-link db-cell--pending" :to="inboxStatusLink(row.inbox_id, 'pending')">{{ numberFormatter.format(row.pending) }}</RouterLink>
                                </td>
                                <td class="is-num">
                                    <RouterLink class="db-cell-link db-cell--closed" :to="inboxStatusLink(row.inbox_id, 'closed')">{{ numberFormatter.format(row.closed) }}</RouterLink>
                                </td>
                                <td class="is-num">
                                    <RouterLink class="db-cell-link db-cell--cancelled" :to="inboxStatusLink(row.inbox_id, 'cancelled')">{{ numberFormatter.format(row.cancelled) }}</RouterLink>
                                </td>
                            </tr>
                            <tr v-if="!summary.by_inbox.length">
                                <td colspan="7" class="db-empty">Sem dados para apresentar.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>

        </div>
    </section>
</template>

<style scoped>
/* ── Page shell ───────────────────────────────────────────── */

.dashboard-page {
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}

/* ── Header ───────────────────────────────────────────────── */

.db-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.db-header-left {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.db-header-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #e8f0fb;
    color: #1F4E79;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.db-header-icon svg {
    width: 22px;
    height: 22px;
}

.db-h1 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
}

.db-subtitle {
    margin: 0.1rem 0 0;
    font-size: 0.84rem;
    color: #64748b;
}

.db-refresh-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    border: 1px solid #c6d5e8;
    border-radius: 10px;
    background: #f4f8fd;
    color: #1d4d86;
    padding: 0.48rem 0.85rem;
    font: inherit;
    font-size: 0.84rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 120ms, border-color 120ms;
}

.db-refresh-btn svg {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
}

.db-refresh-btn:hover:not(:disabled) {
    background: #e8f2fc;
    border-color: #94b8d4;
}

.db-refresh-btn:disabled {
    opacity: 0.6;
    cursor: default;
}

.db-refresh-btn .is-spinning {
    animation: db-spin 0.7s linear infinite;
}

@keyframes db-spin { to { transform: rotate(360deg); } }

/* ── Error / loading ──────────────────────────────────────── */

.db-error {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.65rem 0.8rem;
    background: #fef2f2;
    border: 1px solid #fca5a5;
    border-radius: 10px;
    color: #991b1b;
    font-size: 0.88rem;
}

.db-error svg { width: 16px; height: 16px; flex-shrink: 0; }

.db-loading {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    color: #64748b;
    font-size: 0.9rem;
    padding: 1rem 0;
}

.db-spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid #d4e4f5;
    border-top-color: #1F4E79;
    border-radius: 50%;
    animation: db-spin 0.7s linear infinite;
    flex-shrink: 0;
}

.db-empty {
    margin: 0;
    color: #64748b;
    font-size: 0.87rem;
    padding: 1rem 0;
}

/* ── Grid ─────────────────────────────────────────────────── */

.db-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 0.85rem;
}

/* ── KPI row ──────────────────────────────────────────────── */

.db-kpi-row {
    grid-column: 1 / -1;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.75rem;
}

.db-kpi {
    background: #fff;
    border: 1px solid #dde8f4;
    border-radius: 14px;
    padding: 0.9rem 1rem;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    position: relative;
    overflow: hidden;
}

.db-kpi::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    border-radius: 14px 14px 0 0;
}

.db-kpi--blue::before   { background: #3b82f6; }
.db-kpi--orange::before { background: #f59e0b; }
.db-kpi--green::before  { background: #10b981; }
.db-kpi--purple::before { background: #8b5cf6; }

.db-kpi-icon-wrap {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.db-kpi-icon-wrap svg { width: 18px; height: 18px; }

.db-kpi--blue   .db-kpi-icon-wrap { background: #eff6ff; color: #2563eb; }
.db-kpi--orange .db-kpi-icon-wrap { background: #fffbeb; color: #d97706; }
.db-kpi--green  .db-kpi-icon-wrap { background: #f0fdf4; color: #16a34a; }
.db-kpi--purple .db-kpi-icon-wrap { background: #faf5ff; color: #7c3aed; }

.db-kpi-body {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    min-width: 0;
}

.db-kpi-label {
    margin: 0;
    font-size: 0.78rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.db-kpi-value {
    font-size: 1.8rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.05;
}

.db-kpi-hint {
    margin: 0;
    font-size: 0.76rem;
    color: #94a3b8;
}

/* ── Panel base ───────────────────────────────────────────── */

.db-panel {
    background: #fff;
    border: 1px solid #dde8f4;
    border-radius: 14px;
    padding: 1rem;
}

.db-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
    margin-bottom: 1rem;
}

.db-panel-title-group {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.db-panel-icon {
    width: 16px;
    height: 16px;
    color: #64748b;
    flex-shrink: 0;
}

.db-panel-head h2 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
}

.db-panel-meta {
    font-size: 0.78rem;
    color: #94a3b8;
    font-weight: 500;
    white-space: nowrap;
}

/* ── Status panel (horizontal bars) ──────────────────────── */

.db-status-panel { grid-column: span 7; }

.db-status-list {
    display: flex;
    flex-direction: column;
    gap: 0.55rem;
}

.db-status-row {
    display: grid;
    grid-template-columns: 110px 1fr 48px 40px;
    align-items: center;
    gap: 0.6rem;
}

.db-status-label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #334155;
    white-space: nowrap;
}

.db-status-bar-wrap {
    height: 10px;
    background: #f1f5f9;
    border-radius: 999px;
    overflow: hidden;
}

.db-status-bar-fill {
    height: 100%;
    border-radius: inherit;
    transition: width 300ms ease;
    min-width: 0;
}

.db-status-count {
    font-size: 0.82rem;
    font-weight: 700;
    color: #1e293b;
    text-align: right;
}

.db-status-pct {
    font-size: 0.75rem;
    color: #94a3b8;
    text-align: right;
}

/* ── SLA panel ────────────────────────────────────────────── */

.db-sla-panel { grid-column: span 5; }

.db-donut-layout {
    display: grid;
    grid-template-columns: 150px 1fr;
    gap: 1.1rem;
    align-items: center;
}

.db-donut {
    width: 150px;
    aspect-ratio: 1;
    border-radius: 50%;
    position: relative;
    flex-shrink: 0;
}

.db-donut-hole {
    position: absolute;
    inset: 50%;
    transform: translate(-50%, -50%);
    width: 56%;
    aspect-ratio: 1;
    border-radius: 50%;
    background: #fff;
    border: 1px solid #dde8f4;
    display: grid;
    place-content: center;
    text-align: center;
    gap: 0.08rem;
}

.db-donut-hole strong {
    font-size: 1.1rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1;
}

.db-donut-hole small {
    font-size: 0.66rem;
    color: #94a3b8;
}

.db-legend {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.db-legend li {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 0.45rem;
}

.db-legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

.db-legend-name {
    font-size: 0.83rem;
    color: #475569;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.db-legend-val {
    font-size: 0.83rem;
    font-weight: 700;
    color: #1e293b;
    white-space: nowrap;
}

/* ── Daily flow panel ─────────────────────────────────────── */

.db-flow-panel { grid-column: span 8; }

.db-flow-legend-inline {
    display: flex;
    gap: 0.85rem;
    font-size: 0.8rem;
    color: #475569;
}

.db-flow-legend-inline span {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}

.db-flow-legend-inline strong {
    color: #0f172a;
    font-weight: 700;
}

.db-legend-chip {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}

.db-legend-chip--created { background: #2563eb; }
.db-legend-chip--closed  { background: #10b981; }

.db-flow-body {
    background: #f8fbff;
    border: 1px solid #dde8f4;
    border-radius: 12px;
    padding: 0.75rem;
}

.db-flow-svg {
    width: 100%;
    height: 180px;
    display: block;
}

.db-flow-axis { stroke: #94a3b8; stroke-width: 0.6; }
.db-flow-grid { stroke: #dde8f4; stroke-width: 0.5; stroke-dasharray: 2 2; }

.db-flow-line {
    fill: none;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.db-flow-line--created { stroke: #2563eb; }
.db-flow-line--closed  { stroke: #10b981; }

.db-flow-dot { stroke-width: 0.8; }
.db-flow-dot--created { fill: #2563eb; stroke: #dbeafe; }
.db-flow-dot--closed  { fill: #10b981; stroke: #dcfce7; }

.db-flow-range {
    display: flex;
    justify-content: space-between;
    margin-top: 0.45rem;
    font-size: 0.74rem;
    color: #94a3b8;
}

/* ── Critical backlog panel ───────────────────────────────── */

.db-critical-panel { grid-column: span 4; }

.db-critical-panel--alert {
    border-color: #fca5a5;
    background: #fffafa;
}

.db-critical-pct strong {
    font-size: 2.2rem;
    font-weight: 800;
    line-height: 1;
    display: block;
}

.db-critical-pct strong.is-ok     { color: #0d6e4e; }
.db-critical-pct strong.is-danger { color: #b91c1c; }

.db-critical-pct p {
    margin: 0.4rem 0 0;
    font-size: 0.83rem;
    color: #64748b;
    line-height: 1.4;
}

.db-critical-track {
    margin: 0.85rem 0 0.7rem;
    height: 8px;
    background: #f1f5f9;
    border-radius: 999px;
    overflow: hidden;
}

.db-critical-fill {
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #f59e0b, #dc2626);
    transition: width 350ms ease;
}

.db-critical-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.55rem;
}

.db-cstat {
    border: 1px solid #e8f0f8;
    border-radius: 10px;
    background: #f8fbff;
    padding: 0.5rem 0.65rem;
    display: flex;
    flex-direction: column;
    gap: 0.12rem;
}

.db-cstat span {
    font-size: 0.72rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.db-cstat strong {
    font-size: 1.3rem;
    font-weight: 800;
    color: #0f172a;
}

/* ── Inbox donut panel ────────────────────────────────────── */

.db-inbox-donut-panel { grid-column: span 4; }

/* ── Table panel ──────────────────────────────────────────── */

.db-table-panel { grid-column: span 8; }

.db-table-wrap {
    overflow-x: auto;
    border: 1px solid #e8f0f8;
    border-radius: 10px;
}

.db-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 600px;
}

.db-table th,
.db-table td {
    padding: 0.55rem 0.7rem;
    text-align: left;
    border-bottom: 1px solid #eef3fa;
    white-space: nowrap;
}

.db-table thead th {
    font-size: 0.74rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    background: #f8fbff;
}

.db-table thead th:first-child { border-radius: 10px 0 0 0; }
.db-table thead th:last-child  { border-radius: 0 10px 0 0; }

.db-table tbody tr:last-child td { border-bottom: none; }

.db-table-row:hover td { background: #f8fbff; }

.db-table th.is-num,
.db-table td.is-num { text-align: right; }

.db-inbox-name {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.86rem;
    font-weight: 600;
    color: #1e293b;
}

.db-inbox-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.db-total-cell a {
    font-size: 0.88rem;
    font-weight: 800;
    color: #0f172a;
    text-decoration: none;
}

.db-total-cell a:hover { text-decoration: underline; }

.db-cell-link {
    display: inline-block;
    font-size: 0.83rem;
    font-weight: 600;
    text-decoration: none;
    border-radius: 5px;
    padding: 0.08rem 0.38rem;
    transition: background 100ms;
}

.db-cell--open        { color: #15803d; background: #f0fdf4; }
.db-cell--open:hover  { background: #dcfce7; }
.db-cell--in-progress { color: #1d4ed8; background: #eff6ff; }
.db-cell--in-progress:hover { background: #dbeafe; }
.db-cell--pending     { color: #6d28d9; background: #faf5ff; }
.db-cell--pending:hover { background: #ede9fe; }
.db-cell--closed      { color: #0f766e; background: #f0fdfa; }
.db-cell--closed:hover { background: #ccfbf1; }
.db-cell--cancelled   { color: #b91c1c; background: #fff5f5; }
.db-cell--cancelled:hover { background: #fee2e2; }

/* ── Responsive ───────────────────────────────────────────── */

@media (max-width: 1200px) {
    .db-kpi-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }

    .db-status-panel,
    .db-sla-panel,
    .db-flow-panel,
    .db-critical-panel,
    .db-inbox-donut-panel,
    .db-table-panel { grid-column: 1 / -1; }

    .db-donut-layout { grid-template-columns: 140px 1fr; }
}

@media (max-width: 760px) {
    .db-header { flex-direction: column; align-items: flex-start; }
    .db-refresh-btn { align-self: flex-start; }
    .db-kpi-row { grid-template-columns: 1fr 1fr; }
    .db-status-row { grid-template-columns: 80px 1fr 40px 36px; }
    .db-donut-layout { grid-template-columns: 1fr; justify-items: center; }
    .db-critical-stats { grid-template-columns: 1fr; }
    .db-flow-legend-inline { flex-direction: column; gap: 0.3rem; }
}

@media (max-width: 480px) {
    .db-kpi-row { grid-template-columns: 1fr; }
}
</style>
