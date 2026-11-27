<script setup lang="ts">
defineProps<{
    patientName: string;
    context: {
        visitReasonExcerpt: string | null;
        coverageLabel: string | null;
    };
}>();
</script>

<template>
    <details class="mt-2 min-w-0 text-sm">
        <summary
            :aria-label="`Visit details for ${patientName}`"
            class="min-h-11 cursor-pointer rounded-md py-3 text-link outline-none focus-visible:ring-2 focus-visible:ring-ring lg:min-h-9 lg:py-2"
        >
            Visit details
        </summary>
        <dl class="min-w-0 space-y-2 rounded-lg bg-muted/40 p-3">
            <div>
                <dt class="text-xs font-medium text-muted-foreground">
                    Reason
                </dt>
                <dd class="mt-1 break-words whitespace-normal">
                    {{ context.visitReasonExcerpt ?? 'Not recorded' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-muted-foreground">Pays</dt>
                <dd class="mt-1 break-words whitespace-normal">
                    {{ context.coverageLabel ?? 'Not recorded' }}
                </dd>
            </div>
        </dl>
    </details>
</template>
