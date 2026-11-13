/**
 * V2WeekGrid keyboard rules (pure; no DOM). The block calls these and only moves focus or emits; the behaviour tests
 * run them directly (Kavitha's review, 2026-11-13).
 */

/** An index that always names a real row or column, so one cell stays reachable by Tab when rows or days change. */
export const clampIndex = (index: number, length: number): number =>
    length <= 0 ? 0 : Math.max(0, Math.min(length - 1, index));

export type CellKeyAction =
    | { kind: 'move'; row: number; col: number }
    | { kind: 'open' }
    | { kind: 'add' }
    | { kind: 'enter' }
    | { kind: 'none' };

/**
 * A key pressed on a cell. Arrows, Home and End move; Enter or Space opens the only entry, steps into a cell that holds
 * several entries (a split shift), or asks to add one when the cell is empty. Read-only grids only move.
 */
export const cellKeyAction = (
    key: string,
    at: { row: number; col: number },
    size: { rows: number; cols: number },
    entries: number,
    editable: boolean,
): CellKeyAction => {
    const moves: Record<string, [number, number]> = {
        ArrowRight: [at.row, at.col + 1],
        ArrowLeft: [at.row, at.col - 1],
        ArrowDown: [at.row + 1, at.col],
        ArrowUp: [at.row - 1, at.col],
        Home: [at.row, 0],
        End: [at.row, size.cols - 1],
    };
    const to = moves[key];

    if (to) {
        return {
            kind: 'move',
            row: clampIndex(to[0], size.rows),
            col: clampIndex(to[1], size.cols),
        };
    }

    if ((key === 'Enter' || key === ' ') && editable) {
        if (entries === 0) {
            return { kind: 'add' };
        }

        return entries === 1 ? { kind: 'open' } : { kind: 'enter' };
    }

    return { kind: 'none' };
};

export type EntryKeyAction =
    | { kind: 'focus'; index: number }
    | { kind: 'cell' }
    | { kind: 'leave' }
    | { kind: 'none' };

/**
 * A key pressed on one of a cell's entries after stepping in. Tab and Shift+Tab move between the entries; Escape, or
 * Shift+Tab on the first, goes back to the cell; Tab on the last leaves the grid as usual. Nothing traps focus.
 */
export const entryKeyAction = (
    key: string,
    shift: boolean,
    index: number,
    count: number,
): EntryKeyAction => {
    if (key === 'Escape') {
        return { kind: 'cell' };
    }

    if (key !== 'Tab') {
        return { kind: 'none' };
    }

    if (shift) {
        return index > 0
            ? { kind: 'focus', index: index - 1 }
            : { kind: 'cell' };
    }

    return index < count - 1
        ? { kind: 'focus', index: index + 1 }
        : { kind: 'leave' };
};

/** A number and its unit never split across lines ("11 h", "30 min", "48 h"); the rest of the words still wrap. */
export const keepUnits = (text: string): string =>
    text.replace(/(\d) (h|min|mins|hours?|days?|%)(?=\b|$)/g, '$1 $2');
