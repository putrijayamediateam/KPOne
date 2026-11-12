/** One wording for the record count in every pager (DD-47): "1 record", "5 records". */
export const recordsLabel = (total: number): string =>
    `${total} ${total === 1 ? 'record' : 'records'}`;
