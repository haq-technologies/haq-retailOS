import { Head } from '@inertiajs/react';

interface Props {
    version: string;
    database: string;
}

// The server's own page: it is up, which release it runs, and that its database answers.
export default function Home({ version, database }: Props) {
    return (
        <>
            <Head title="Shop server" />
            <main className="mx-auto flex min-h-screen max-w-xl flex-col justify-center gap-6 px-4">
                <h1 className="text-3xl font-semibold">HAQ Retail OS</h1>
                <p className="text-lg">The shop server is running. Counters open /counter.</p>
                <dl className="rounded-xl bg-panel p-4 text-text-secondary">
                    <div className="flex min-h-touch items-center justify-between border-b border-border">
                        <dt>Version</dt>
                        <dd className="text-text">{version}</dd>
                    </div>
                    <div className="flex min-h-touch items-center justify-between">
                        <dt>Database</dt>
                        <dd className="text-text">{database}</dd>
                    </div>
                </dl>
            </main>
        </>
    );
}
