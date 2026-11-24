import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger,
} from '@/components/ui/dialog';
import { SidebarClient, type SidebarState } from './sidebar-client';

type Assistant = { token: string; label: string; sessionUrl: string; identity: string };

export function WorkspaceSidebar({ assistant }: { assistant: Assistant | null }) {
    return assistant && <AssistantPane key={assistant.identity} assistant={assistant} />;
}

function AssistantPane({ assistant }: { assistant: Assistant }) {
    const [open, setOpen] = useState(false);
    const [message, setMessage] = useState('');
    const [state, setState] = useState<SidebarState>();
    const token = useRef(assistant.token);
    const client = useRef<SidebarClient | null>(null);

    useEffect(() => { token.current = assistant.token; }, [assistant.token]);
    useEffect(() => {
        const sidebar = new SidebarClient(assistant.sessionUrl, () => token.current, setState);
        client.current = sidebar;
        void sidebar.load();

        return () => sidebar.dispose();
    }, [assistant.sessionUrl]);

    const processing = state?.turns.some(turn => turn.status === 'processing');
    const busy = !state || state.busy || processing;
    const renderBody = (suffix: string) => (
        <div className="flex min-h-0 flex-col gap-3">
            <p className="text-sm text-muted-foreground">{assistant.label}</p>
            {state?.next_cursor != null && <Button type="button" variant="ghost" disabled={busy}
                onClick={() => void client.current?.older()}>Carica i messaggi precedenti</Button>}
            <ol className="min-h-0 space-y-3 overflow-y-auto" role="log" aria-label="Conversazione">
                {state?.turns.map(turn => (
                    <li key={turn.id} className="space-y-2 border-b pb-3">
                        <p className="whitespace-pre-wrap font-medium">{turn.message}</p>
                        <p className="whitespace-pre-wrap">{turn.answer ?? (turn.available
                            ? (turn.status === 'failed' ? 'Risposta non riuscita. Puoi riprovare.' : 'In preparazione…')
                            : 'Risposta non più disponibile.')}</p>
                        {turn.sources.map(source => <a key={source.id} className="block underline"
                            href={source.url}>{source.title}</a>)}
                    </li>
                ))}
            </ol>
            <p className="text-sm" role="status">{state?.error ?? (busy ? 'Risposta in preparazione…' : '')}</p>
            <form className="space-y-2" onSubmit={async event => {
                event.preventDefault();
                if (await client.current?.send(message)) setMessage('');
            }}>
                <label htmlFor={'assistant-message-' + suffix}>Domanda</label>
                <textarea id={'assistant-message-' + suffix} className="w-full rounded-md border bg-background p-2"
                    required maxLength={3000} value={message} onChange={event => setMessage(event.target.value)} />
                <div className="flex gap-2">
                    <Button disabled={busy || !message.trim()}>Invia</Button>
                    {!!state?.turns.length && <Button type="button" variant="outline" disabled={busy}
                        onClick={() => void client.current?.reset()}>Nuova chat</Button>}
                </div>
            </form>
        </div>
    );

    return (
        <>
            <aside className="sticky top-2 hidden max-h-[calc(100dvh-1rem)] w-92 shrink-0 flex-col border-l bg-card p-4 xl:flex"
                aria-label="Assistente">
                <h2 className="mb-3 text-xl">Assistente</h2>
                {renderBody('desktop')}
            </aside>
            <div className="xl:hidden">
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild>
                        <Button className="fixed right-4 bottom-4">Assistente</Button>
                    </DialogTrigger>
                    <DialogContent className="max-h-[90dvh] overflow-auto">
                        <DialogTitle>Assistente</DialogTitle>
                        <DialogDescription className="sr-only">Conversazione e fonti della pagina</DialogDescription>
                        {renderBody('dialog')}
                    </DialogContent>
                </Dialog>
            </div>
        </>
    );
}
