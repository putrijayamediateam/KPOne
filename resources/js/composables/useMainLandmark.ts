import { onMounted, onUpdated, ref } from 'vue';

/**
 * Every page needs exactly one main landmark. Many pages draw their own <main>; the rest (HR, Settings,
 * Clinic compliance and others) do not. A layout calls this and puts the returned role on its content
 * wrapper: the wrapper becomes the landmark only when the page did not draw one, so a second one is
 * never produced.
 *
 * Known limit: the check runs when the layout mounts and updates, so a page that adds or removes its own
 * <main> later through a v-if is not re-checked until the layout next updates.
 */
export function useMainLandmark() {
    const content = ref<HTMLElement | null>(null);
    const role = ref<'main' | undefined>(undefined);
    const check = () => {
        role.value =
            content.value?.querySelector('main, [role="main"]') == null
                ? 'main'
                : undefined;
    };

    onMounted(check);
    onUpdated(check);

    return { content, role };
}
