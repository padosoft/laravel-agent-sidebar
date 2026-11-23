# Laravel Agent Sidebar

Backend riusabile per una sidebar agentica. Laravel AI esegue i tool del progetto. Il pacchetto conserva una conversazione per utente e spazio, con risposte, fonti e stati dei turni. Il sito monta la sidebar nel proprio layout e usa i propri componenti grafici.

## Installazione

Registrare il repository Git nel progetto Laravel e installare la prima versione stabile del pacchetto.

```sh
composer config repositories.agent-sidebar vcs https://github.com/padosoft/laravel-agent-sidebar
composer require padosoft/laravel-agent-sidebar:^0.1
php artisan vendor:publish --tag=agent-sidebar-config
php artisan vendor:publish --tag=agent-sidebar-migrations
php artisan migrate
```

Configurare un provider di [Laravel AI](https://laravel.com/docs/13.x/ai-sdk#configuration) e scegliere provider e modello con `AGENT_SIDEBAR_PROVIDER` e `AGENT_SIDEBAR_MODEL`. Il modello deve supportare la chiamata ai tool e saper rispondere in JSON. Il pacchetto valida il JSON finale. Per impostare i provider in `config/ai.php` si può pubblicare il solo tag `ai-config`. Le due migrazioni conversazionali del SDK servono alle app che usano la memoria nativa di Laravel AI. La sidebar usa la propria memoria.

Le versioni supportate sono PHP 8.3–8.5 e Laravel 12.62+ o 13.15+. Il pacchetto si installa via Composer discovery. Nell'app ospite serve la normale chiave Laravel `APP_KEY`, utilizzata per cifrare i contenuti dei turni e il token di pagina.

## Attivare la sidebar

1. Registrare `AgentSidebar::routes()` in `routes/web.php` o dentro il gruppo che risolve lo spazio e applica le policy del sito.
2. Configurare le route nominative che possono mostrare la sidebar, come `'pages' => ['orders.index' => 'Ordini']` in `config/agent-sidebar.php`.
3. Dal controller o dal middleware della pagina passare `app(PageContext::class)->share($request)` al layout. Il valore nullo indica che la pagina non espone l'assistente. Il valore presente contiene la label e il token per ogni nuovo invio.
4. Montare l'interfaccia nel layout e usare l'URL della rotta `agent-sidebar.session.show` per la cronologia e gli invii. Il layout deve esporre `<meta name="csrf-token" content="{{ csrf_token() }}">` se usa il client di esempio.

### Blade

Copiare [la vista](examples/sidebar.blade.php) in `resources/views/partials/sidebar.blade.php`. Copiare [il client](examples/sidebar-client.ts) e [il collegamento Blade](examples/blade-sidebar.ts) in `resources/js` e importare `./blade-sidebar` dall'entrypoint Vite del sito. Includere la vista nel layout quando `$assistant` è presente. La vista usa `details`, pulsanti e controlli nativi che il progetto può sostituire con i suoi componenti. Al cambio pagina il layout genera un nuovo token e l'apertura ripristina la cronologia.

```php
use Padosoft\AgentSidebar\PageContext;

return view('orders.index', [
    'assistant' => app(PageContext::class)->share($request),
]);
```

```blade
@if ($assistant ?? null)
    @include('partials.sidebar')
@endif
```

### Inertia e Tixora

L'[esempio di integrazione Tixora](examples/tixora.md) collega tenant, pannello e ricerca ticket del progetto. Conserva il layout e i componenti grafici del sito. Il browser aggiorna il token alla navigazione e rimonta il pannello quando cambia utente o spazio.

## Registrare tool e fonti

Impostare in `tools` le classi PHP che implementano `Contracts\ReadTool`. Ogni classe ha un nome univoco, una descrizione per l'agente, uno schema, regole Laravel e un metodo `read(Subject $subject, array $arguments): ToolResult`. Usare lo stesso servizio e le stesse policy delle letture manuali. `$subject->actor` è l'utente autenticato, `$subject->scope` è lo spazio risolto dal server. Impostare `require_tool` a `true` quando il sito vuole chiedere al provider una chiamata a un tool nel primo passaggio.

`ToolResult` contiene il testo da fornire al modello e gli ID di **tutte** le risorse riservate che hanno contribuito al testo, inclusi i record di un elenco o riepilogo. Il contratto `Host::sources()` restituisce gli oggetti `Source` ancora visibili con lo stesso ID, un titolo e un percorso locale. Una revisione diversa invalida i turni che dipendono da quella versione della fonte. Il riferimento alle risorse si propaga quando il modello riceve la risposta come cronologia. [Esempio di tool](examples/SearchTixoraTickets.php) · [Esempio di risoluzione fonti](examples/TixoraHost.php)

Con `ApplicationHost` lo spazio è l'intera applicazione e le pagine espongono solo la label configurata. Usare un proprio `Host` per multitenancy o dati riservati. `Host::scope()` deve negare l'accesso con `null` e rileggere i permessi correnti quando viene chiamato durante la generazione. `Host::page()` rivalida la route e le risorse del contesto. Qualunque dato riservato incluso nel riepilogo della pagina va indicato in `Page::sourceIds`.

L'applicazione decide quali dati possono raggiungere il provider configurato. Applicare le policy del progetto nel proprio `Host` e ridurre testo e risultati restituiti dai tool ai campi necessari.

## API

Percorso base predefinito `/agent-sidebar/v1/session`. Tutti gli endpoint usano la sessione web e risposte JSON private. Per invii e azzeramenti inviare il token CSRF.

| Metodo e percorso | Risposta |
| --- | --- |
| `GET /session?before={next_cursor}` | `session_id`, `turns` in ordine cronologico e `next_cursor`. La prima pagina contiene i 20 turni più recenti. |
| `POST /session/turns` | Inviare `session_id` (nullo inizialmente), `message`, `client_message_id` UUID e `context_token`. Restituisce `session_id` e `turn`. |
| `GET /session/turns/{client_message_id}` | Restituisce `session_id` e `turn` per recuperare un invio conosciuto. |
| `DELETE /session/{session_id}` | Risponde `204`, elimina i turni e ruota l'ID pubblico. Rileggere `GET /session` per inviare la prima domanda della chat vuota. |

Una nuova richiesta registrata restituisce `201`. Il campo `turn.status` indica `processing`, `completed` o `failed`. Il replay restituisce `200` se il turno è concluso e `202` se è ancora in corso. Riutilizzare lo stesso UUID quando l'esito del POST è incerto. Dopo un errore di generazione, un nuovo tentativo esplicito usa un nuovo UUID. `409` indica conflitto di sessione, nuovo invio mentre c'è un turno in corso o UUID riusato con un altro messaggio. Su `422` per il token, ricaricare i dati della pagina. Il limite di richieste restituisce `429`.

Ogni turno contiene anche `id`, `message`, `answer`, `available`, `sources`, `error_code` e `created_at`. Se `available` è falso, mostrare la domanda con uno stato di indisponibilità. Mostrare testo e titoli con l'escaping del framework. Configurare i timeout del server e del proxy in coerenza con la scadenza dei turni, 90 secondi in configurazione iniziale.

Per eliminare tutte le chat di un account chiamare `app(Conversations::class)->forgetActor($user)` nel flusso di cancellazione. Per uno spazio chiamare `forgetScope($scope)` con la stessa chiave restituita da `Host::scope()`. La cancellazione propaga ai turni tramite chiave esterna.

## Verifica

```sh
composer install
composer test
AGENT_SIDEBAR_SMOKE_MODEL=qwen3.5:9b vendor/bin/phpunit --group live
```

La prova `live` usa un ordine fittizio e un modello già installato in Ollama locale. La suite ordinaria usa un database SQLite reale e simula le risposte del provider.
