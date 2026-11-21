import { Component, type ErrorInfo, type ReactNode } from 'react';
import { reportError } from '@/lib/error-report';

/** How long the screen waits before it opens the page again by itself. */
export const RELOAD_AFTER_MS = 15_000;

interface State {
    failed: boolean;
    reference: string | null;
}

/**
 * The app's last guard (finding LG3). React removes the whole page when a render throws, which in kiosk mode
 * left the cashier a white screen with nothing to tap and no record anywhere. This says what happened and
 * how to go on, sends the error to the server's log, and opens the page again by itself after 15 seconds. A
 * reload is safe: an open sale lives in the counter's own journal and comes back with the page.
 */
export class CrashScreen extends Component<{ children: ReactNode }, State> {
    override state: State = { failed: false, reference: null };

    private timer: number | undefined;

    static getDerivedStateFromError(): Partial<State> {
        return { failed: true };
    }

    override componentDidCatch(error: unknown, info: ErrorInfo): void {
        void reportError('crash', error, info.componentStack).then((reference) => {
            if (reference !== null) {
                this.setState({ reference });
            }
        });
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => {
            window.location.reload();
        }, RELOAD_AFTER_MS);
    }

    override componentWillUnmount(): void {
        window.clearTimeout(this.timer);
    }

    override render(): ReactNode {
        if (!this.state.failed) {
            return this.props.children;
        }
        return (
            <main className="flex min-h-screen items-center justify-center bg-background px-4 py-8 text-text">
                <div className="flex w-full max-w-xl flex-col gap-4">
                    <span className="text-lg font-bold">HAQ Retail OS</span>
                    <h1 role="alert" className="text-3xl font-bold">
                        Something went wrong
                    </h1>
                    <p className="text-xl">This screen stopped. It opens again by itself in a few seconds, or tap Reload. A sale that was open is kept.</p>
                    <button
                        type="button"
                        // A white label on the blue fill is at least 18.66px bold (spec section 15): 20px.
                        className="min-h-touch self-start rounded-xl bg-action px-8 text-xl font-bold text-white"
                        onClick={() => {
                            window.location.reload();
                        }}
                    >
                        Reload
                    </button>
                    {this.state.reference !== null && (
                        <p className="text-lg text-text-secondary">
                            If it happens again, tell HAQ support the reference <strong className="text-text">{this.state.reference}</strong>.
                        </p>
                    )}
                </div>
            </main>
        );
    }
}
