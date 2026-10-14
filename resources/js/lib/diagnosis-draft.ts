export type DiagnosisDraft = {
    diagnosis_text: string;
    diagnosis_code: string;
    code_system: string;
    is_primary: boolean;
};

export const appendPresetDiagnosis = (
    rows: DiagnosisDraft[],
    name: string,
): DiagnosisDraft[] => {
    if (rows.length >= 20) {
        return rows;
    }

    return [
        ...rows,
        {
            diagnosis_text: name,
            diagnosis_code: '',
            code_system: '',
            is_primary: rows.length === 0,
        },
    ];
};
