// Read the first sheet of an uploaded spreadsheet into rows of strings (browser only, nothing is uploaded).
// .xlsx via read-excel-file (loaded on demand), .csv / .tsv / .txt via a small RFC-4180 parser.

const cell = (v: unknown): string => {
    if (v === null || v === undefined) return '';
    if (typeof v === 'number') return Number.isInteger(v) ? v.toFixed(0) : String(v); // no 1.2E+11 for long tracking numbers
    if (v instanceof Date) return v.toISOString().slice(0, 10);
    return String(v).trim();
};

export function parseCsv(text: string): string[][] {
    text = text.replace(/^﻿/, '');
    const first = text.split(/\r?\n/, 1)[0] ?? '';
    const delim = [',', ';', '\t'].map((d) => [d, first.split(d).length] as const).sort((a, b) => b[1] - a[1])[0][0];
    const rows: string[][] = [];
    let row: string[] = [], field = '', quoted = false;
    for (let i = 0; i < text.length; i++) {
        const c = text[i];
        if (quoted) {
            if (c === '"' && text[i + 1] === '"') { field += '"'; i++; }
            else if (c === '"') quoted = false;
            else field += c;
        } else if (c === '"' && field === '') quoted = true;
        else if (c === delim) { row.push(field.trim()); field = ''; }
        else if (c === '\n' || c === '\r') {
            if (c === '\r' && text[i + 1] === '\n') i++;
            row.push(field.trim()); rows.push(row); row = []; field = '';
        } else field += c;
    }
    if (field !== '' || row.length) { row.push(field.trim()); rows.push(row); }
    return rows.filter((r) => r.some((x) => x !== ''));
}

export async function readSheetFile(file: File): Promise<string[][]> {
    const name = file.name.toLowerCase();
    if (file.size > 10 * 1024 * 1024) throw new Error('File is larger than 10 MB.');
    if (/\.(csv|tsv|txt)$/.test(name)) return parseCsv(await file.text());
    if (name.endsWith('.xlsx')) {
        const { readSheet } = await import('read-excel-file/browser');
        const rows = await readSheet(file);
        return rows.map((r) => r.map(cell)).filter((r) => r.some((x) => x !== ''));
    }
    if (name.endsWith('.xls')) throw new Error('Old .xls format: open it in Excel and “Save As” .xlsx or CSV, then upload again.');
    throw new Error('Use an Excel (.xlsx) or CSV file.');
}
