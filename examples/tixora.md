# Usare il pacchetto in Tixora

Questa integrazione di riferimento usa la sessione web di Tixora, il suo catalogo pannelli e la ricerca ticket già usata dall'agente Knowledge. Per il collaudo usare uno spazio di prova e un provider Laravel AI autorizzato per i dati di quello spazio. Le connessioni AI configurate nel pannello Tixora richiedono una mappatura esplicita verso Laravel AI prima di adottare l'esempio con più tenant.

1. Copiare [TixoraHost.php](TixoraHost.php) e [SearchTixoraTickets.php](SearchTixoraTickets.php) in `app/Domains/Knowledge/Services`. Impostare `host` a `TixoraHost::class` e `tools` a `[SearchTixoraTickets::class]` nella configurazione del pacchetto. Inserire in `pages` le route dei pannelli da abilitare, a partire da `'helpdesk.tickets.index' => 'Ticket'`.
2. Dentro il gruppo Knowledge con `web`, `auth`, `verified`, `EnsureTenantMembership` ed `EnforceTenantMfa`, registrare le API con `AgentSidebar::routes('assistant/sidebar/v1', 'assistant.sidebar.')`. Il prefisso di nome del gruppo è `knowledge.`. Il catalogo esistente `agent-chat` include `knowledge.assistant.*`, quindi si applica il suo livello di rilascio. Eseguire `PanelFeaturesTest` sul sito integrato.
3. Esporre la prop Inertia `sidebarAssistant` da `HandleInertiaRequests::share()` dopo la risoluzione del tenant.

```php
'sidebarAssistant' => function () use ($request, $tenant, $user): ?array {
    if ($tenant === null || $user === null) {
        return null;
    }

    $context = app(\Padosoft\AgentSidebar\PageContext::class)->share($request);

    return $context === null ? null : [
        ...$context,
        'sessionUrl' => route('knowledge.assistant.sidebar.session.show', ['tenant' => $tenant], false),
        'identity' => $tenant->id.':'.$user->id,
    ];
},
```

4. Copiare [TixoraSidebar.tsx](TixoraSidebar.tsx) e [sidebar-client.ts](sidebar-client.ts) nella stessa cartella in `resources/js`. Aggiungere `sidebarAssistant` ai tipi delle prop condivise. Il client usa un meta tag CSRF nel documento. Nel layout persistente, affiancare il pannello al corpo della pagina negli spazi abilitati.

```tsx
const { sidebarAssistant } = usePage().props;

<div className="flex min-w-0 flex-1 items-start">
    <div className="min-w-0 flex-1">
        <AppSidebarHeader breadcrumbs={breadcrumbs} />
        {children}
    </div>
    <TixoraSidebar assistant={sidebarAssistant} />
</div>
```

Il pannello React riusa `Button`, `Dialog`, colori e tipografia di Tixora. Puoi anche mantenere il markup di `AgentChat` e sostituire lo stato locale dei turni con `SidebarClient`. Le richieste JSON non causano visite Inertia. Dopo una navigazione, il prossimo invio usa il nuovo token e rimane nella stessa conversazione. Dopo un cambio di tenant o utente, la chiave del componente carica la conversazione di quello spazio e scarta eventuali risposte in arrivo per il precedente.

Ogni risultato della ricerca include i riferimenti ai riepiloghi autorizzati. `TixoraHost` li risolve di nuovo tramite `KnowledgeAgentSources::currentTicketSummaries()` prima di mostrarli o inviarli come cronologia. L'hash del riepilogo funge da revisione.
