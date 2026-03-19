<script setup>
import { onMounted, ref } from 'vue';
import api from '../api/client';

const loading = ref(false);
const error = ref('');
const summary = ref(null);

const loadSummary = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/dashboard/summary');
        summary.value = response.data.data;
    } catch (exception) {
        error.value = 'Nao foi possivel carregar o dashboard.';
    } finally {
        loading.value = false;
    }
};

const minutesToLabel = (minutes) => {
    if (minutes === null || minutes === undefined) return '-';
    if (minutes < 60) return `${minutes.toFixed(0)} min`;
    const hours = minutes / 60;
    return `${hours.toFixed(2)} h`;
};

onMounted(loadSummary);
</script>

<template>
    <section class="page">
        <div class="header-row">
            <h1>Dashboard Operacional</h1>
            <button class="btn-secondary" @click="loadSummary">Atualizar</button>
        </div>

        <p v-if="loading" class="muted">A carregar...</p>
        <p v-if="error" class="error">{{ error }}</p>

        <template v-if="summary">
            <div class="cards">
                <article class="card stat-card">
                    <h2>Total Tickets</h2>
                    <strong>{{ summary.totals.all_tickets }}</strong>
                </article>
                <article class="card stat-card">
                    <h2>Abertos</h2>
                    <strong>{{ summary.totals.open }}</strong>
                </article>
                <article class="card stat-card">
                    <h2>Pendentes</h2>
                    <strong>{{ summary.totals.pending }}</strong>
                </article>
                <article class="card stat-card">
                    <h2>Resolvidos</h2>
                    <strong>{{ summary.totals.resolved }}</strong>
                </article>
                <article class="card stat-card">
                    <h2>Fechados</h2>
                    <strong>{{ summary.totals.closed }}</strong>
                </article>
            </div>

            <div class="cards">
                <article class="card stat-card">
                    <h2>Tempo médio 1 resposta</h2>
                    <strong>{{ minutesToLabel(summary.averages.first_response_minutes) }}</strong>
                    <p class="muted">
                        SLA {{ summary.sla.first_response_hours }}h:
                        {{ summary.sla.first_response_compliance_percent ?? '-' }}%
                    </p>
                </article>
                <article class="card stat-card">
                    <h2>Tempo médio resolução</h2>
                    <strong>{{ minutesToLabel(summary.averages.resolution_minutes) }}</strong>
                    <p class="muted">
                        SLA {{ summary.sla.resolution_hours }}h:
                        {{ summary.sla.resolution_compliance_percent ?? '-' }}%
                    </p>
                </article>
            </div>

            <article class="card">
                <h2 style="margin-top:0;">Distribuição por Inbox</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Inbox</th>
                            <th>Total</th>
                            <th>Abertos</th>
                            <th>Pendentes</th>
                            <th>Resolvidos</th>
                            <th>Fechados</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in summary.by_inbox" :key="row.inbox_id">
                            <td>{{ row.inbox_name }}</td>
                            <td>{{ row.total }}</td>
                            <td>{{ row.open }}</td>
                            <td>{{ row.pending }}</td>
                            <td>{{ row.resolved }}</td>
                            <td>{{ row.closed }}</td>
                        </tr>
                        <tr v-if="!summary.by_inbox.length">
                            <td colspan="6" class="muted">Sem dados para apresentar.</td>
                        </tr>
                    </tbody>
                </table>
            </article>
        </template>
    </section>
</template>

<style scoped>
.page { display: grid; gap: 1rem; }
.header-row { display: flex; justify-content: space-between; align-items: center; gap: 0.8rem; }
h1, h2 { margin: 0; }

.cards {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 0.75rem;
}

.card {
    background: #fff;
    border: 1px solid #dbe4ee;
    border-radius: 12px;
    padding: 0.9rem;
}

.stat-card strong {
    font-size: 1.6rem;
    margin-top: 0.4rem;
    display: inline-block;
}

.btn-secondary {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.45rem 0.75rem;
    background: #fff;
    cursor: pointer;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th, td {
    text-align: left;
    border-bottom: 1px solid #e5edf5;
    padding: 0.55rem 0.45rem;
}

.muted { color: #475569; }
.error { color: #991b1b; }

@media (max-width: 1100px) {
    .cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
