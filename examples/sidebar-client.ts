export type Turn = {
    id: string;
    message: string;
    status: 'processing' | 'completed' | 'failed';
    available: boolean;
    answer: string | null;
    sources: { id: string; title: string; url: string }[];
    error_code: string | null;
};

type Conversation = {
    session_id: string | null;
    turns: Turn[];
    next_cursor: number | null;
};

type PendingMessage = {
    session_id: string | null;
    message: string;
    client_message_id: string;
};

export type SidebarState = Conversation & {
    busy: boolean;
    error: string | null;
};

class SidebarHttpError extends Error {
    constructor(public readonly status: number, message: string) {
        super(message);
    }
}

export class SidebarClient {
    private state: SidebarState = {
        session_id: null, turns: [], next_cursor: null, busy: false, error: null,
    };

    private readonly lifetime = new AbortController();
    private poll?: ReturnType<typeof setTimeout>;
    private pending?: PendingMessage;

    constructor(
        private readonly sessionUrl: string,
        private readonly contextToken: () => string,
        private readonly render: (state: SidebarState) => void,
    ) {}

    async load(): Promise<void> {
        await this.work(async () => {
            this.update(await this.request<Conversation>(this.sessionUrl));
        });
    }

    async older(): Promise<void> {
        if (this.state.next_cursor === null) return;

        await this.work(async () => {
            const older = await this.request<Conversation>(this.sessionUrl + '?before=' + this.state.next_cursor);
            this.update(older.session_id === this.state.session_id
                ? { ...older, turns: [...older.turns, ...this.state.turns] }
                : await this.request<Conversation>(this.sessionUrl));
        });
    }

    async send(message: string): Promise<boolean> {
        if (!message.trim() || this.state.busy || this.state.turns.some(turn => turn.status === 'processing')) {
            return false;
        }

        const pending = this.pending?.message === message
            ? this.pending
            : { message, session_id: this.state.session_id, client_message_id: crypto.randomUUID() };
        this.pending = pending;

        return this.work(async () => {
            try {
                await this.request(this.sessionUrl + '/turns', {
                    method: 'POST',
                    body: JSON.stringify({ ...pending, context_token: this.contextToken() }),
                });
            } catch (error) {
                if (error instanceof SidebarHttpError) {
                    if (error.status === 409) {
                        this.pending = undefined;
                        this.update(await this.request<Conversation>(this.sessionUrl));
                    }

                    throw error;
                }

                // The POST may have reached the server before the connection dropped.
                try {
                    await this.request(this.sessionUrl + '/turns/' + pending.client_message_id);
                } catch {
                    throw error;
                }
            }

            this.update(await this.request<Conversation>(this.sessionUrl));
            this.pending = undefined;
        });
    }

    async reset(): Promise<void> {
        if (!this.state.session_id) return;

        await this.work(async () => {
            await this.request(this.sessionUrl + '/' + this.state.session_id, { method: 'DELETE' });
            this.pending = undefined;
            this.update(await this.request<Conversation>(this.sessionUrl));
        });
    }

    dispose(): void {
        this.lifetime.abort();
        clearTimeout(this.poll);
    }

    private async work(operation: () => Promise<void>): Promise<boolean> {
        if (this.state.busy || this.lifetime.signal.aborted) return false;

        clearTimeout(this.poll);
        this.update({ busy: true, error: null });

        try {
            await operation();
            return true;
        } catch (error) {
            if (error instanceof SidebarHttpError && [401, 403].includes(error.status)) {
                this.pending = undefined;
                this.update({ session_id: null, turns: [], next_cursor: null });
            }
            this.update({ error: error instanceof SidebarHttpError ? error.message : 'Connessione interrotta. Riprova.' });
            return false;
        } finally {
            this.update({ busy: false });

            if (!this.lifetime.signal.aborted && this.state.turns.some(turn => turn.status === 'processing')) {
                this.poll = setTimeout(() => void this.load(), 1500);
            }
        }
    }

    private update(values: Partial<SidebarState>): void {
        if (this.lifetime.signal.aborted) return;

        this.state = { ...this.state, ...values };
        this.render(this.state);
    }

    private async request<T>(url: string, init: RequestInit = {}): Promise<T> {
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        const response = await fetch(url, {
            ...init,
            signal: this.lifetime.signal,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
            },
        });

        if (response.status === 204) return undefined as T;

        const data = await response.json();
        if (!response.ok) {
            throw new SidebarHttpError(response.status, data.errors?.context_token?.[0] ?? data.code ?? data.message ?? 'Richiesta non riuscita.');
        }

        return data as T;
    }
}
