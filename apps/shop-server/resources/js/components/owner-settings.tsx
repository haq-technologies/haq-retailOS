import { useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import { getJson, postJson } from '@/lib/api';

/**
 * The owner's Shop settings (D37): the settings HAQ lets the owner change, such as the closing time. A
 * setting HAQ has locked shows its value and who to ask.
 */
interface Setting {
    key: string;
    label: string;
    help: string | null;
    value: unknown;
    locked: boolean;
}

export function OwnerSettings({ token }: { token: string }) {
    const [settings, setSettings] = useState<Setting[] | null>(null);
    const [drafts, setDrafts] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<{ tone: 'error' | 'ok'; text: string } | null>(null);

    useEffect(() => {
        void getJson<{ settings: Setting[] }>('/api/back-office/settings', token)
            .then((a) => {
                if (a.ok) {
                    setSettings(a.body.settings);
                    setDrafts(Object.fromEntries(a.body.settings.map((s) => [s.key, typeof s.value === 'string' ? s.value : ''])));
                }
            })
            .catch(() => {
                setMessage({ tone: 'error', text: 'The settings could not be loaded. Check the connection, then try again.' });
            });
    }, [token]);

    const save = async (key: string) => {
        setMessage(null);
        try {
            const a = await postJson<{ message?: string; value?: unknown }>('/api/back-office/settings', { key, value: drafts[key] ?? '' }, token);
            if (a.ok) {
                setSettings((list) => list?.map((s) => (s.key === key ? { ...s, value: a.body.value } : s)) ?? null);
                setMessage({ tone: 'ok', text: 'Saved.' });
            } else {
                setMessage({ tone: 'error', text: a.body.message ?? 'That did not save. Try again.' });
            }
        } catch {
            setMessage({ tone: 'error', text: 'The device cannot reach the shop server. Try again.' });
        }
    };

    if (settings === null) {
        return message === null ? <p className="text-text-secondary">Loading the shop settings…</p> : <Notice tone="error">{message.text}</Notice>;
    }

    return (
        <section aria-label="Shop settings" className="flex flex-col gap-4 rounded-xl bg-panel p-6">
            <h2 className="text-2xl font-semibold">Shop settings</h2>
            {settings.map((s) => (
                <div key={s.key} className="flex flex-col gap-2">
                    <label className="text-lg font-semibold" htmlFor={`setting-${s.key}`}>
                        {s.label}
                    </label>
                    {s.help !== null && <p className="text-text-secondary">{s.help}</p>}
                    {s.locked ? (
                        <p className="text-lg">
                            {typeof s.value === 'string' && s.value !== '' ? s.value : 'Not set'}{' '}
                            <span className="text-text-secondary">· set by HAQ support; ask them to change it</span>
                        </p>
                    ) : (
                        <div className="flex flex-wrap items-center gap-3">
                            <input
                                id={`setting-${s.key}`}
                                type={s.key === 'shifts.closing_time' ? 'time' : 'text'}
                                value={drafts[s.key] ?? ''}
                                className="min-h-touch rounded-xl border-2 border-border bg-background px-4 text-lg"
                                onChange={(e) => {
                                    setDrafts((d) => ({ ...d, [s.key]: e.target.value }));
                                }}
                            />
                            <button
                                type="button"
                                className="min-h-touch rounded-xl bg-action px-6 text-lg font-bold text-white"
                                onClick={() => void save(s.key)}
                            >
                                Save
                            </button>
                        </div>
                    )}
                </div>
            ))}
            {message !== null &&
                (message.tone === 'error' ? (
                    <Notice tone="error">{message.text}</Notice>
                ) : (
                    <p role="status" className="text-lg">
                        {message.text}
                    </p>
                ))}
        </section>
    );
}
