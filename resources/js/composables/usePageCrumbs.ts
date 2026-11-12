import type { InjectionKey, MaybeRefOrGetter, Ref } from 'vue';
import {
    computed,
    inject,
    onBeforeUnmount,
    onMounted,
    provide,
    ref,
    toValue,
    watchEffect,
} from 'vue';

/**
 * UX-20: the Version 2 breadcrumb (Daniel, 2026-11-12). The V2 shell provides it; a page sets its own trail with
 * `usePageCrumbs`, and the shell's space label is the fallback. In Classic nothing provides it, so every call here is a
 * no-op and nothing renders.
 *
 *     usePageCrumbs(() => [
 *         { title: 'Registration', href: '/registration' },
 *         { title: props.visit.number },
 *     ]);
 *
 * Every item with an `href` is a link back; the last item may leave `href` out and is then the current page.
 */
export type PageCrumb = { title: string; href?: string };

type CrumbContext = {
    pageCrumbs: Ref<PageCrumb[] | null>;
    owner: Ref<symbol | null>;
    /** Crumbs placed inside a PageFrame, in mount order; only the first one draws. */
    placed: Ref<symbol[]>;
    /** Set by the shell: true in focus mode. */
    hidden: Ref<boolean>;
    trail: Readonly<Ref<PageCrumb[]>>;
};

const KEY: InjectionKey<CrumbContext> = Symbol('v2-page-crumbs');

/** Used by the V2 shell only: makes the crumb available to the page below it. */
export function providePageCrumbs(
    fallback: MaybeRefOrGetter<PageCrumb | null>,
): CrumbContext {
    const pageCrumbs = ref<PageCrumb[] | null>(null);
    const owner = ref<symbol | null>(null);
    const placed = ref<symbol[]>([]);
    const hidden = ref(false);
    const trail = computed<PageCrumb[]>(() => {
        if (pageCrumbs.value && pageCrumbs.value.length > 0) {
            return pageCrumbs.value;
        }

        const current = toValue(fallback);

        return current ? [current] : [];
    });
    const context = { pageCrumbs, owner, placed, hidden, trail };

    provide(KEY, context);

    return context;
}

/** A page's own trail. Cleared when the page leaves, so the next page falls back to the shell label. */
export function usePageCrumbs(source: MaybeRefOrGetter<PageCrumb[]>): void {
    const context = inject(KEY, null);

    if (!context) {
        return;
    }

    const me = Symbol('page');

    watchEffect(() => {
        context.owner.value = me;
        context.pageCrumbs.value = toValue(source).filter(
            (crumb) => crumb.title.trim() !== '',
        );
    });

    onBeforeUnmount(() => {
        if (context.owner.value === me) {
            context.owner.value = null;
            context.pageCrumbs.value = null;
        }
    });
}

/**
 * Used by the crumb component: whether to draw, and the trail. A crumb inside a PageFrame registers itself, so the shell
 * stops drawing its own copy; with two PageFrames on one page only the first draws. Hidden in focus mode.
 */
export function usePageCrumbTrail(placedInFrame: boolean) {
    const context = inject(KEY, null);

    if (!context) {
        return {
            show: computed(() => false),
            trail: computed<PageCrumb[]>(() => []),
        };
    }

    const me = Symbol('crumb');

    if (placedInFrame) {
        onMounted(() => (context.placed.value = [...context.placed.value, me]));
        onBeforeUnmount(
            () =>
                (context.placed.value = context.placed.value.filter(
                    (token) => token !== me,
                )),
        );
    }

    const show = computed(
        () =>
            !context.hidden.value &&
            context.trail.value.length > 0 &&
            (placedInFrame
                ? context.placed.value[0] === me
                : context.placed.value.length === 0),
    );

    return { show, trail: context.trail };
}
