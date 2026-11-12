/**
 * Version 2 look. Classic is the default for everyone. A person who switches on "Try the new layout" (and any page
 * inside the spaces shell) gets `data-ui="v2"` on <html>; the new tokens and component looks apply only under it.
 * `app.blade.php` sets the attribute on first paint from the same rule, so there is no flash.
 */
export type UiVersion = 'classic' | 'v2';

export const uiVersionFor = (page: {
    url: string;
    props: { workspace?: { spaces?: { enabled?: boolean } } | null };
}): UiVersion =>
    page.props.workspace?.spaces?.enabled === true ||
    page.url.startsWith('/spaces')
        ? 'v2'
        : 'classic';

export const applyUiVersion = (version: UiVersion): void => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.dataset.ui = version;
};
