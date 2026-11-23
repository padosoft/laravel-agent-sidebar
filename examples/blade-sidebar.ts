import { SidebarClient } from './sidebar-client';

const sidebar = document.querySelector<HTMLElement>('[data-agent-sidebar]');
if (sidebar) {
    const details = sidebar.querySelector('details')!;
    const form = sidebar.querySelector('form')!;
    const message = form.querySelector('textarea')!;
    const send = form.querySelector('button')!;
    const turns = sidebar.querySelector<HTMLOListElement>('[data-turns]')!;
    const status = sidebar.querySelector<HTMLElement>('[data-status]')!;
    const older = sidebar.querySelector<HTMLButtonElement>('[data-older]')!;
    const reset = sidebar.querySelector<HTMLButtonElement>('[data-reset]')!;

    const client = new SidebarClient(sidebar.dataset.sessionUrl!, () => sidebar.dataset.contextToken!, state => {
        const processing = state.turns.some(turn => turn.status === 'processing');
        send.disabled = state.busy || processing;
        reset.disabled = state.busy || processing;
        reset.hidden = state.turns.length === 0;
        older.hidden = state.next_cursor === null;
        older.disabled = state.busy;
        status.textContent = state.error ?? (processing || state.busy ? 'Risposta in preparazione…' : '');

        turns.replaceChildren(...state.turns.map(turn => {
            const item = document.createElement('li');
            const question = document.createElement('p');
            question.textContent = turn.message;
            item.append(question);

            const answer = document.createElement('p');
            answer.textContent = turn.answer ?? (turn.available
                ? (turn.status === 'failed' ? 'Risposta non riuscita. Puoi riprovare.' : 'In preparazione…')
                : 'Risposta non più disponibile.');
            item.append(answer);

            for (const source of turn.sources) {
                const link = document.createElement('a');
                link.href = source.url;
                link.textContent = source.title;
                item.append(link);
            }

            return item;
        }));
    });

    details.addEventListener('toggle', () => {
        if (details.open) void client.load();
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (await client.send(message.value)) message.value = '';
    });
    older.addEventListener('click', () => void client.older());
    reset.addEventListener('click', () => void client.reset());
}
