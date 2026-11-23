<aside aria-label="Assistente" data-agent-sidebar
    data-session-url="{{ route('agent-sidebar.session.show', absolute: false) }}"
    data-context-token="{{ $assistant['token'] }}">
    <details>
        <summary>Assistente</summary>
        <p>{{ $assistant['label'] }}</p>
        <button type="button" data-older hidden>Carica i messaggi precedenti</button>
        <ol aria-live="polite" data-turns></ol>
        <p role="status" data-status></p>
        <form>
            <label for="assistant-question">Domanda</label>
            <textarea id="assistant-question" name="message" maxlength="3000" required></textarea>
            <button type="submit">Invia</button>
        </form>
        <button type="button" data-reset hidden>Nuova chat</button>
    </details>
</aside>
