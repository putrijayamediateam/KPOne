<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatAuditEventLabel } from '@/lib/displayLabels';

/**
 * Version 2 Audit Logs (Classic is `pages/AuditLogs/Index.vue`, untouched). Same props, read only. The server pages the
 * log (newest first), so Previous and Next are real links. The box only narrows the rows of the page on screen; nothing
 * is sent anywhere and nothing is stored.
 */
const props = defineProps<{
    logs: {
        data: Array<{
            id: number;
            event: string;
            actor: string;
            branch: string | null;
            subjectType: string | null;
            subjectId: number | null;
            occurredAt: string;
            roleNames: string[];
            /** Q-4c: a short structural note, for example "Via Call next, from the Queue board". */
            detail?: string | null;
        }>;
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
}>();
usePageCrumbs(() => [{ title: 'Audit Logs' }]);

const formatTime = (value: string) =>
    new Intl.DateTimeFormat('en-MY', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Kuala_Lumpur',
    }).format(new Date(value));

const search = ref('');
const term = computed(() => search.value.trim().toLowerCase());
const rows = computed(() =>
    props.logs.data.map((log) => ({
        log,
        label: formatAuditEventLabel(log.event, log.roleNames),
        subject: log.subjectType
            ? `${log.subjectType}${log.subjectId ? ` #${log.subjectId}` : ''}`
            : '',
        time: formatTime(log.occurredAt),
    })),
);
const shown = computed(() =>
    term.value
        ? rows.value.filter((row) =>
              [
                  row.label,
                  row.log.detail ?? '',
                  row.subject,
                  row.log.actor,
                  row.log.branch ?? 'Organisation',
                  row.time,
              ]
                  .join(' ')
                  .toLowerCase()
                  .includes(term.value),
          )
        : rows.value,
);

// The server sends no page number: read it from the previous link ("page=N") and add one.
const page = computed(() => {
    if (!props.logs.prev_page_url) {
        return 1;
    }

    const match = /[?&]page=(\d+)/.exec(props.logs.prev_page_url);

    return match ? Number(match[1]) + 1 : 2;
});

const button =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9';
const buttonOff =
    'inline-flex h-11 cursor-not-allowed items-center justify-center rounded-[10px] border border-dashed border-border px-4 text-sm font-medium text-muted-foreground lg:h-9';
</script>

<template>
    <Head title="Audit Logs" />
    <PageFrame>
        <V2WorkLayout
            title="Audit Logs"
            description="Append-oriented security and administration history."
        >
            <template #main>
                <section
                    aria-labelledby="audit-title"
                    class="grid min-w-0 gap-4 rounded-[14px] border bg-card p-4 sm:p-5"
                >
                    <header>
                        <h2 id="audit-title" class="text-base font-semibold">
                            {{ logs.total }} recorded event{{
                                logs.total === 1 ? '' : 's'
                            }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Sensitive credentials and token values are never
                            recorded.
                        </p>
                    </header>
                    <V2Field
                        v-slot="{ id, describedBy }"
                        label="Find on this page"
                    >
                        <input
                            :id="id"
                            v-model="search"
                            type="search"
                            autocomplete="off"
                            :aria-describedby="describedBy"
                            class="min-h-11 w-full rounded-[10px] border bg-background px-3 text-sm lg:min-h-9"
                        />
                    </V2Field>
                    <p
                        role="status"
                        class="text-sm text-muted-foreground"
                        data-testid="filter-status"
                    >
                        Showing {{ shown.length }} of
                        {{ logs.data.length }} events on this page. Search
                        covers this page only.
                    </p>
                    <V2Table
                        label="Audit log"
                        :columns="4"
                        cards="xl"
                        min-width="880px"
                        empty-message="No events on this page match your search."
                        :empty="shown.length === 0"
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Event</th>
                                <th scope="col">Actor</th>
                                <th scope="col">Context</th>
                                <th scope="col">Time</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="row in shown" :key="row.log.id">
                                <td data-primary data-label="Event">
                                    <div class="font-medium">
                                        {{ row.label }}
                                    </div>
                                    <div
                                        v-if="row.log.detail"
                                        class="mt-0.5 text-sm font-normal text-muted-foreground"
                                        data-testid="audit-detail"
                                    >
                                        {{ row.log.detail }}
                                    </div>
                                    <div
                                        v-if="row.log.subjectType"
                                        class="mt-0.5 text-sm font-normal [overflow-wrap:anywhere] text-muted-foreground"
                                    >
                                        {{ row.log.subjectType
                                        }}<template v-if="row.log.subjectId">
                                            #{{ row.log.subjectId }}</template
                                        >
                                    </div>
                                </td>
                                <td data-label="Actor">
                                    <span
                                        class="[overflow-wrap:anywhere] max-xl:text-right"
                                        >{{ row.log.actor }}</span
                                    >
                                </td>
                                <td data-label="Context">
                                    <V2Chip
                                        v-if="row.log.branch"
                                        tone="neutral"
                                        :label="row.log.branch"
                                    />
                                    <span v-else class="text-muted-foreground"
                                        >Organisation</span
                                    >
                                </td>
                                <td
                                    data-label="Time"
                                    class="text-muted-foreground xl:whitespace-nowrap"
                                >
                                    {{ row.time }}
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <nav
                        aria-label="Pagination"
                        class="grid grid-cols-2 items-center gap-2 text-sm sm:flex sm:justify-end"
                    >
                        <p
                            class="order-first col-span-2 text-center text-[13px] text-muted-foreground tabular-nums sm:order-none sm:col-span-1 sm:mr-2 sm:text-left"
                            aria-current="page"
                        >
                            Page {{ page }}
                        </p>
                        <Link
                            v-if="logs.prev_page_url"
                            :href="logs.prev_page_url"
                            :class="button"
                            >Previous</Link
                        >
                        <span v-else aria-disabled="true" :class="buttonOff"
                            >Previous</span
                        >
                        <Link
                            v-if="logs.next_page_url"
                            :href="logs.next_page_url"
                            :class="button"
                            >Next</Link
                        >
                        <span v-else aria-disabled="true" :class="buttonOff"
                            >Next</span
                        >
                    </nav>
                </section>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
