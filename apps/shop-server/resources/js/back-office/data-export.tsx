import { useState } from 'react';
import { Notice } from '@/components/notice';
import type { CounterApi } from '@/counter/use-api';
import { styles } from './pickers';

/**
 * The owner's data export (spec section 14; D57): the shop's whole record as one ZIP of spreadsheets, cost
 * included, made fresh on the server each time. Only the owner sees it.
 */
export function DataExport({ api }: { api: CounterApi }) {
    const [state, setState] = useState<'idle' | 'busy' | 'done' | 'error'>('idle');

    const download = async () => {
        setState('busy');
        try {
            const day = new Date().toLocaleDateString('en-CA');
            setState((await api.download('/api/back-office/exports/shop.zip', `haq-retail-os-data-${day}.zip`)) ? 'done' : 'error');
        } catch {
            setState('error');
        }
    };

    return (
        <section aria-labelledby="data-export" className="flex flex-col gap-3 rounded-2xl border-2 border-border p-5">
            <h2 id="data-export" className="text-xl font-bold">
                Your data
            </h2>
            <p className="text-text-secondary">
                One ZIP file of spreadsheets that open in Excel: every sale, payment, item, price, cost, shift, drawer opening and flag, the audit log and the measured pilot&apos;s record, with a list that proves none of it changed. It holds cost, so keep it safe.
            </p>
            {state === 'error' && <Notice tone="error">The export did not download. Check the connection and try again.</Notice>}
            {state === 'done' && (
                <p role="status" className="rounded-xl bg-panel p-4 text-lg">
                    Downloaded. Keep a copy somewhere other than this device.
                </p>
            )}
            <button type="button" disabled={state === 'busy'} className={`${styles.button} self-start`} onClick={() => void download()}>
                {state === 'busy' ? 'Making the file…' : 'Download all the shop’s data'}
            </button>
        </section>
    );
}
