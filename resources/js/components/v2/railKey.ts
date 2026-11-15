import type { ComputedRef, InjectionKey } from 'vue';

/**
 * The width from which a page's V2WorkLayout shows its right rail beside the main panel (`lg`, `xl` or `2xl`), or
 * `null` when the page has no rail. V2WorkLayout provides it; blocks that must hide when the rail shows (V2SelectionBar)
 * read it, so a page never repeats the breakpoint and the two can never disagree.
 */
export type RailFrom = 'lg' | 'xl' | '2xl';

export const V2_RAIL_FROM: InjectionKey<ComputedRef<RailFrom | null>> =
    Symbol('v2-rail-from');
