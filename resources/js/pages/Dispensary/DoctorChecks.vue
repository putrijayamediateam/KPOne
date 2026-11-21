<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ActionLink } from '@/components/ui/action-link';
import { PageHeader } from '@/components/ui/page-header';
import { EmptyState } from '@/components/ui/state';
import { OperationalTable } from '@/components/ui/table';

/**
 * DSP-23 (owner answer 13 = B): the branch list of cases waiting for the doctor's check, oldest first, for a CA
 * Supervisor who may check instead when the doctor is unavailable. Read only: each row opens its case, where the
 * check happens with a recorded reason. A case the supervisor prepared says someone else must check it.
 */
type Row = {
    publicId: string;
    url: string;
    patient: string | null;
    patientNumber: string | null;
    visitNumber: string;
    doctor: string | null;
    sentBy: string | null;
    sentAt: string | null;
    sentAtIso: string | null;
    preparedByYou: boolean;
};

const props = defineProps<{
    waiting: { rows: Row[]; total: number };
    branch: { id: number; name: string };
}>();

const ownNote = 'You prepared this case, so someone else must check it.';
const help = `${props.branch.name} · Cases waiting for the doctor's check, oldest first`;
</script>

<template>
    <Head title="Waiting for doctor's check" />
    <main
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <PageHeader title="Waiting for doctor's check" :description="help" />
        <p class="text-xs text-muted-foreground">
            Check a case only when the doctor can't. Open the case to verify or
            send it back; you give a reason and it is recorded.
        </p>

        <EmptyState
            v-if="waiting.rows.length === 0"
            title="No cases waiting"
            :description="`No case in ${branch.name} is waiting for the doctor's check.`"
        />

        <template v-else>
            <p
                v-if="waiting.total > waiting.rows.length"
                class="text-xs text-muted-foreground"
                data-testid="doctor-checks-more"
            >
                Showing the oldest {{ waiting.rows.length }} of
                {{ waiting.total }}.
            </p>
            <div class="hidden md:block">
                <OperationalTable
                    label="Waiting for doctor's check"
                    :columns="5"
                    min-width="840px"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Sent</th>
                            <th scope="col">Patient / Visit</th>
                            <th scope="col">Doctor</th>
                            <th scope="col">Sent by</th>
                            <th scope="col" class="text-right">Case</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="row in waiting.rows"
                            :key="row.publicId"
                            data-testid="doctor-check-row"
                        >
                            <td class="whitespace-nowrap">
                                <time :datetime="row.sentAtIso ?? undefined">{{
                                    row.sentAt
                                }}</time>
                            </td>
                            <td>
                                <span class="block font-medium text-foreground">
                                    {{ row.patient }}
                                </span>
                                <span
                                    class="block font-mono text-xs text-muted-foreground"
                                    >{{ row.visitNumber }}</span
                                >
                            </td>
                            <td>{{ row.doctor ?? 'No doctor' }}</td>
                            <td>
                                {{ row.preparedByYou ? 'You' : row.sentBy }}
                            </td>
                            <td class="text-right">
                                <div class="flex flex-col items-end gap-1">
                                    <span
                                        v-if="row.preparedByYou"
                                        class="text-xs text-muted-foreground"
                                        >{{ ownNote }}</span
                                    >
                                    <ActionLink
                                        :href="row.url"
                                        variant="secondary"
                                        size="sm"
                                        :aria-label="`Open case for ${row.visitNumber}`"
                                    >
                                        Open case
                                    </ActionLink>
                                </div>
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
            </div>

            <ul
                class="space-y-3 md:hidden"
                aria-label="Waiting for doctor's check"
            >
                <li
                    v-for="row in waiting.rows"
                    :key="row.publicId"
                    class="space-y-2 rounded-xl border bg-card p-4 text-sm"
                    data-testid="doctor-check-card"
                >
                    <h2 class="text-sm font-medium">{{ row.patient }}</h2>
                    <p>
                        <span class="font-mono text-xs text-muted-foreground">{{
                            row.visitNumber
                        }}</span>
                        · {{ row.doctor ?? 'No doctor' }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Sent {{ row.sentAt }} · by
                        {{ row.preparedByYou ? 'you' : row.sentBy }}
                    </p>
                    <p
                        v-if="row.preparedByYou"
                        class="text-xs text-muted-foreground"
                    >
                        {{ ownNote }}
                    </p>
                    <ActionLink
                        :href="row.url"
                        variant="secondary"
                        class="min-h-11 w-full justify-center"
                        :aria-label="`Open case for ${row.visitNumber}`"
                    >
                        Open case
                    </ActionLink>
                </li>
            </ul>
        </template>
    </main>
</template>
