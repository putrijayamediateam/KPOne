import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { uiVersionFor } from '@/lib/ui-version';
import type { UiVersion } from '@/lib/ui-version';

/**
 * Which look the person is in: 'classic' (default) or 'v2' ("Try the new layout" on, or any page in the spaces
 * shell). Use it where a look change is more than classes (markup, a different component); otherwise use the
 * `v2:` class variant. The same rule sets `data-ui` on <html>.
 */
export function useUiVersion() {
    const page = usePage();
    const version = computed<UiVersion>(() => uiVersionFor(page));

    return { version, isV2: computed(() => version.value === 'v2') };
}
