import { Head, useForm } from '@inertiajs/react';
import { Notice } from '@/components/notice';

// Enrols this PC as a counter with the one-time code from `php artisan shop:enrol-terminal`.
export default function Enrol() {
    const form = useForm({ code: '' });

    return (
        <>
            <Head title="Enrol this counter" />
            <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-6 px-4">
                <h1 className="text-3xl font-semibold">Enrol this counter</h1>
                <p className="text-lg text-text-secondary">
                    Type the code from HAQ support. It works once, for 30 minutes.
                </p>
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/enrol');
                    }}
                >
                    <label className="flex flex-col gap-2 text-lg" htmlFor="code">
                        Code
                        <input
                            id="code"
                            inputMode="numeric"
                            autoComplete="off"
                            autoFocus
                            className="min-h-touch rounded-xl border-2 border-border px-4 text-2xl tracking-widest"
                            value={form.data.code}
                            onChange={(event) => {
                                form.setData('code', event.target.value);
                            }}
                        />
                    </label>
                    {form.errors.code !== undefined && <Notice tone="error">{form.errors.code}</Notice>}
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="min-h-touch rounded-xl bg-action px-6 text-lg font-bold text-white disabled:opacity-60"
                    >
                        Enrol counter
                    </button>
                </form>
            </main>
        </>
    );
}
