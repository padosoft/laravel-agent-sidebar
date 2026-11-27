<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Orchestra\Testbench\TestCase;
use Padosoft\AgentSidebar\AgentSidebar;
use Padosoft\AgentSidebar\AgentSidebarServiceProvider;
use Padosoft\AgentSidebar\ApplicationHost;
use Padosoft\AgentSidebar\AuthorizedRun;
use Padosoft\AgentSidebar\ContextValue;
use Padosoft\AgentSidebar\Conversations;
use Padosoft\AgentSidebar\Memory\MemoryAgent;
use Padosoft\AgentSidebar\Memory\MemoryWorker;
use Padosoft\AgentSidebar\Memory\UpdateMemory;
use Padosoft\AgentSidebar\Memory\WikiFiles;
use Padosoft\AgentSidebar\MemoryOptions;
use Padosoft\AgentSidebar\PageContext;
use Padosoft\AgentSidebar\SidebarAgent;
use Padosoft\AgentSidebar\Subject;
use Padosoft\AgentSidebar\TurnRunner;
use Padosoft\AgentSidebar\TurnStopped;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

final class SidebarTest extends TestCase
{
    private const API = '/workspaces/acme/agent-sidebar/v1/session';

    private const PROVIDER = 'https://sidebar-provider.test/v1/chat/completions';

    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, AgentSidebarServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.default' => 'testing',
            'database.connections.testing.foreign_key_constraints' => true,
            'agent-sidebar.host' => TestHost::class,
            'agent-sidebar.provider' => 'sidebar-test',
            'agent-sidebar.model' => 'sidebar-model',
            'agent-sidebar.tools' => [ReadOrder::class],
            'ai.providers.sidebar-test' => ['driver' => 'openai-compatible', 'url' => 'https://sidebar-provider.test/v1', 'key' => 'test'],
        ]);

        $app->singleton(TestHost::class);
        $app->singleton(ReadOrder::class);
    }

    protected function defineRoutes($router): void
    {
        Route::prefix('workspaces/{workspace}')->group(function (): void {
            Route::middleware(['web', 'auth'])->get('orders', fn (Request $request) => app(PageContext::class)->share($request))
                ->name('fixture.page');
            AgentSidebar::routes(name: 'tenant.agent-sidebar.');
        });

        AgentSidebar::routes();
        Route::middleware(['web', 'auth'])->get('/blade-orders', function (Request $request): string {
            $assistant = app(PageContext::class)->share($request);

            return $assistant === null ? '' : Blade::render(file_get_contents(__DIR__.'/../examples/sidebar.blade.php'), compact('assistant'));
        })->name('fixture.blade');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        Http::preventStrayRequests();
    }

    public function test_it_persists_a_single_conversation_and_uses_server_history_after_reload(): void
    {
        $this->signIn('01999f15-5bb7-758c-9819-087b0986c632');
        SidebarAgent::fake([['answer' => 'I remember this', 'source_ids' => []], ['answer' => 'Follow-up', 'source_ids' => []]]);

        $this->getJson(self::API)->assertExactJson(['session_id' => null, 'turns' => [], 'next_cursor' => null]);
        $this->assertDatabaseCount('agent_sidebar_sessions', 0);

        $first = $this->send($this->payload())->assertCreated()->assertJsonPath('turn.answer', 'I remember this');
        $session = $first->json('session_id');

        $this->withCookie(config('session.cookie'), Str::random(40));
        $reloaded = $this->getJson(self::API)->assertOk()->assertJsonPath('session_id', $session)
            ->assertJsonPath('turns.0.answer', 'I remember this');
        self::assertStringContainsString('no-store', $reloaded->headers->get('Cache-Control'));

        $this->send(array_merge($this->payload($session), [
            'message' => 'What did we discuss?',
            'history' => [['answer' => 'Injected browser history']],
        ]))->assertCreated();

        SidebarAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('What did we discuss?')
            && collect($prompt->agent->messages())->contains(fn ($message): bool => $message->content === 'I remember this')
            && ! $prompt->contains('Injected browser history'));
        $this->assertDatabaseCount('agent_sidebar_sessions', 1);
        $this->assertDatabaseCount('agent_sidebar_turns', 2);
        self::assertNotSame('Find order 1', DB::table('agent_sidebar_turns')->value('question'));
    }

    public function test_a_single_space_site_can_render_the_blade_sidebar_on_selected_pages(): void
    {
        $this->signIn();
        config(['agent-sidebar.host' => ApplicationHost::class, 'agent-sidebar.pages' => ['fixture.blade' => 'Ordini']]);

        $this->get('/blade-orders')->assertOk()->assertSee('data-agent-sidebar', false)
            ->assertSee('data-session-url="/agent-sidebar/v1/session"', false)->assertSee('Ordini');

        config(['agent-sidebar.pages' => []]);
        $this->get('/blade-orders')->assertOk()->assertDontSee('data-agent-sidebar', false);
    }

    #[DataProvider('emptyRoutePrefixes')]
    public function test_empty_route_prefixes_do_not_overwrite_application_routes(string $prefix): void
    {
        Route::prefix('admin')->name('admin.')->group(function () use ($prefix): void {
            Route::get('session', fn () => response()->json(['from' => 'application']))->name('session.show');
            AgentSidebar::routes(prefix: $prefix, name: '');
        });

        $this->signIn();
        config(['agent-sidebar.host' => ApplicationHost::class]);

        $this->getJson('/admin/session')->assertOk()->assertExactJson(['from' => 'application']);
        self::assertSame('/admin/session', route('admin.session.show', absolute: false));

        $sidebarUrl = route('admin.agent-sidebar.session.show', absolute: false);
        self::assertSame('/admin/agent-sidebar/v1/session', $sidebarUrl);
        $this->getJson($sidebarUrl)->assertOk()->assertJsonPath('turns', []);
    }

    public static function emptyRoutePrefixes(): array
    {
        return [
            'empty' => [''],
            'root slash' => ['/'],
        ];
    }

    public function test_it_replays_an_invocation_without_running_ai_twice_and_rejects_a_changed_message(): void
    {
        $this->signIn();
        config(['agent-sidebar.requests_per_minute' => 1]);
        SidebarAgent::fake([['answer' => 'Found it', 'source_ids' => []]]);
        $payload = $this->payload();
        $first = $this->send($payload)->assertCreated();

        $this->send($payload)->assertOk()->assertExactJson($first->json());
        $this->send(array_merge($payload, ['message' => 'Changed']))->assertConflict()->assertJsonPath('code', 'message_id_reused');
        $this->send($this->payload($first->json('session_id')))->assertTooManyRequests()->assertJsonPath('code', 'rate_limited');
        $this->assertDatabaseCount('agent_sidebar_turns', 1);
        SidebarAgent::assertPromptedTimes(1);
    }

    public function test_a_second_tab_can_observe_but_cannot_start_or_reset_an_active_turn(): void
    {
        $this->signIn();
        $payload = $this->payload();
        $concurrent = [];
        SidebarAgent::fake(function () use ($payload, &$concurrent): array {
            $concurrent['history'] = $this->getJson(self::API);
            $session = $concurrent['history']->json('session_id');
            $concurrent['turn'] = $this->getJson(self::API.'/turns/'.$payload['client_message_id']);
            $concurrent['replay'] = $this->send($payload);
            $concurrent['new'] = $this->send(array_merge($payload, ['client_message_id' => (string) Str::uuid(), 'session_id' => $session]));
            $concurrent['reset'] = $this->deleteJson(self::API.'/'.$session);

            return ['answer' => 'Finished', 'source_ids' => []];
        });

        $response = $this->send($payload);
        $concurrent['history']->assertJsonPath('turns.0.status', 'processing');
        $concurrent['turn']->assertJsonPath('turn.status', 'processing');
        $concurrent['replay']->assertAccepted();
        $concurrent['new']->assertConflict()->assertJsonPath('code', 'turn_in_progress');
        $concurrent['reset']->assertConflict();
        $response->assertCreated()->assertJsonPath('turn.answer', 'Finished');
        $this->assertDatabaseCount('agent_sidebar_sessions', 1);
        $this->assertDatabaseCount('agent_sidebar_turns', 1);
    }

    public function test_concurrent_first_writes_create_exactly_one_session_and_one_turn(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'sidebar-test-');
        $original = DB::getDefaultConnection();
        config(['database.connections.contention' => [
            'driver' => 'sqlite',
            'database' => $database,
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
        ]]);
        DB::setDefaultConnection('contention');
        $migration = require __DIR__.'/../database/migrations/2026_11_23_000001_create_agent_sidebar_tables.php';
        $migration->up();
        DB::disconnect('contention');
        $workers = [];

        try {
            for ($index = 0; $index < 2; $index++) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $pid = pcntl_fork();
                self::assertGreaterThanOrEqual(0, $pid);

                if ($pid === 0) {
                    fclose($pair[0]);
                    fread($pair[1], 1);

                    try {
                        $subject = new Subject(new GenericUser(['id' => 314]), 'application');
                        app(Conversations::class)->reserve($subject, null, (string) Str::uuid(), 'Concurrent question');
                        exit(0);
                    } catch (HttpResponseException $exception) {
                        exit($exception->getResponse()->getStatusCode() === 409 ? 9 : 1);
                    } catch (Throwable) {
                        exit(1);
                    }
                }

                fclose($pair[1]);
                $workers[$pid] = $pair[0];
            }

            foreach ($workers as $pipe) {
                fwrite($pipe, 'x');
                fclose($pipe);
            }

            $results = [];
            foreach (array_keys($workers) as $pid) {
                pcntl_waitpid($pid, $status);
                $results[] = pcntl_wexitstatus($status);
            }
            sort($results);

            self::assertSame([0, 9], $results);
            $this->assertDatabaseCount('agent_sidebar_sessions', 1);
            $this->assertDatabaseCount('agent_sidebar_turns', 1);
        } finally {
            DB::purge('contention');
            DB::setDefaultConnection($original);
            unlink($database);
        }
    }

    public function test_expired_turns_can_be_recovered_and_late_answers_are_discarded(): void
    {
        $this->signIn();
        $this->freezeTime();
        config(['agent-sidebar.turn_ttl' => 5]);
        $payload = $this->payload();

        SidebarAgent::fake(function () use ($payload): array {
            $this->travel(6)->seconds();
            $this->getJson(self::API.'/turns/'.$payload['client_message_id'])
                ->assertJsonPath('turn.error_code', 'turn_expired');

            return ['answer' => 'Too late', 'source_ids' => []];
        });

        $session = $this->send($payload)->assertCreated()->assertJsonPath('turn.status', 'failed')
            ->assertJsonPath('turn.answer', null)->json('session_id');

        SidebarAgent::fake([['answer' => 'New response', 'source_ids' => []]]);
        $this->send($this->payload($session))->assertCreated()->assertJsonPath('turn.answer', 'New response');
    }

    public function test_reset_rejects_stale_clients_and_removes_the_old_turns(): void
    {
        $this->signIn();
        SidebarAgent::fake([['answer' => 'Old response', 'source_ids' => []], ['answer' => 'New response', 'source_ids' => []]]);
        $payload = $this->payload();
        $session = $this->send($payload)->json('session_id');

        $this->deleteJson(self::API.'/'.$session)->assertNoContent();
        $new = $this->getJson(self::API)->assertJsonCount(0, 'turns')->json('session_id');
        self::assertNotSame($session, $new);
        $this->getJson(self::API.'/turns/'.$payload['client_message_id'])->assertNotFound();
        $this->send($payload)->assertConflict()->assertJsonPath('code', 'session_changed');
        $this->send($this->payload($session))->assertConflict()->assertJsonPath('code', 'session_changed');
        $this->send($this->payload($new))->assertCreated();

        $this->assertDatabaseCount('agent_sidebar_sessions', 1);
        $this->assertDatabaseCount('agent_sidebar_turns', 1);
    }

    public function test_it_isolates_workspaces_and_users(): void
    {
        $guest = $this->get(self::API)->assertUnauthorized();
        self::assertStringContainsString('no-store', $guest->headers->get('Cache-Control'));
        $this->signIn(17);
        SidebarAgent::fake([['answer' => 'Acme only', 'source_ids' => []]]);
        $payload = $this->payload();
        $session = $this->send($payload)->assertCreated()->json('session_id');

        $other = str_replace('/acme/', '/other/', self::API);
        $this->getJson($other)->assertJsonPath('session_id', null);
        $this->postJson($other.'/turns', $payload)->assertUnprocessable();
        $this->deleteJson($other.'/'.$session)->assertNotFound();

        $this->signIn(18);
        $this->getJson(self::API)->assertJsonPath('session_id', null);
        $this->getJson(self::API.'/turns/'.$payload['client_message_id'])->assertNotFound();

        $this->signIn(17);
        $this->getJson(self::API)->assertJsonPath('turns.0.answer', 'Acme only');
        app(Conversations::class)->forgetActor(auth()->user());
        $this->assertDatabaseCount('agent_sidebar_turns', 0);
    }

    public function test_invalid_contexts_and_revoked_pages_are_rejected_before_contacting_ai(): void
    {
        $this->signIn();
        SidebarAgent::fake();
        $this->freezeTime();
        $payload = $this->payload();

        $this->send(array_merge($payload, ['context_token' => 'forged']))->assertUnprocessable()->assertJsonValidationErrors('context_token');
        $this->withCookie(config('session.cookie'), Str::random(40));
        $this->send($payload)->assertUnprocessable();

        $payload = $this->payload();
        app(TestHost::class)->allowPage = false;
        $this->send($payload)->assertForbidden();
        app(TestHost::class)->allowPage = true;

        $this->travel(31)->minutes();
        $this->send($payload)->assertUnprocessable();
        SidebarAgent::assertNeverPrompted();
        $this->assertDatabaseCount('agent_sidebar_turns', 0);
    }

    public function test_it_calls_only_registered_tools_and_carries_source_permissions_into_followups(): void
    {
        $this->signIn();
        Http::fake([self::PROVIDER => Http::sequence()
            ->push($this->providerToolCall())
            ->push($this->providerAnswer('The order is turquoise-marigold.', ['order:1']))
            ->push($this->providerAnswer('That status still applies.'))]);

        $first = $this->send($this->payload())->assertCreated()
            ->assertJsonPath('turn.sources.0.url', '/orders/1');
        $session = $first->json('session_id');

        $this->send($this->payload($session))->assertCreated()->assertJsonPath('turn.answer', 'That status still applies.');
        self::assertSame(1, app(ReadOrder::class)->calls);
        Http::assertSent(fn (ProviderRequest $request): bool => collect($request['messages'])
            ->contains(fn (array $message): bool => ($message['role'] ?? null) === 'tool' && str_contains($message['content'], 'turquoise-marigold')));

        app(TestHost::class)->allowSource = false;

        $this->getJson(self::API)
            ->assertJsonPath('turns.0.available', false)->assertJsonPath('turns.0.answer', null)
            ->assertJsonPath('turns.1.available', false)->assertJsonPath('turns.1.answer', null);

        SidebarAgent::fake(function (AgentPrompt $prompt): array {
            self::assertSame([], $prompt->agent->messages());

            return ['answer' => 'The order status needs checking.', 'source_ids' => []];
        });
        $this->send($this->payload($session))->assertCreated()->assertJsonPath('turn.available', true);
    }

    public function test_it_does_not_complete_a_response_when_a_source_is_revoked_during_generation(): void
    {
        $this->signIn();
        Http::fake([self::PROVIDER => function (ProviderRequest $request) {
            if (collect($request['messages'])->contains(fn (array $message): bool => $message['role'] === 'tool')) {
                app(TestHost::class)->allowSource = false;

                return Http::response($this->providerAnswer('Sensitive answer', ['order:1']));
            }

            return Http::response($this->providerToolCall());
        }]);

        $this->send($this->payload())->assertCreated()->assertJsonPath('turn.error_code', 'sources_changed')
            ->assertJsonPath('turn.answer', null);
    }

    public function test_a_disallowed_tool_never_reaches_the_host_action(): void
    {
        $this->signIn();
        Http::fake([self::PROVIDER => Http::response($this->providerToolCall('unknown_tool'))]);

        $this->send($this->payload())->assertCreated()->assertJsonPath('turn.error_code', 'unknown_tool');
        self::assertSame(0, app(ReadOrder::class)->calls);
        Http::assertSentCount(1);
    }

    public function test_invalid_tool_arguments_never_reach_the_host_action(): void
    {
        $this->signIn();
        Http::fake([self::PROVIDER => Http::sequence()
            ->push($this->providerToolCall('read_order', ['order_id' => 2, 'scope' => 'foreign']))
            ->push($this->providerAnswer('I could not retrieve the order.'))]);

        $this->send($this->payload())->assertCreated()->assertJsonPath('turn.answer', 'I could not retrieve the order.');
        self::assertSame(0, app(ReadOrder::class)->calls);
        Http::assertSentCount(2);
    }

    public function test_citations_outside_the_current_turn_are_rejected(): void
    {
        $this->signIn();
        SidebarAgent::fake([['answer' => 'Unverified', 'source_ids' => ['order:1']]]);

        $this->send($this->payload())->assertCreated()->assertJsonPath('turn.error_code', 'invalid_response')
            ->assertJsonPath('turn.sources', []);
    }

    #[Group('live')]
    public function test_a_live_local_provider_calls_the_registered_tool(): void
    {
        $model = getenv('AGENT_SIDEBAR_SMOKE_MODEL');
        if (! $model) {
            self::markTestSkipped('Set AGENT_SIDEBAR_SMOKE_MODEL to run separately against local Ollama.');
        }

        $this->signIn();
        config([
            'ai.providers.sidebar-local' => ['driver' => 'openai-compatible', 'url' => 'http://127.0.0.1:11434/v1'],
            'agent-sidebar.provider' => 'sidebar-local',
            'agent-sidebar.model' => $model,
            'agent-sidebar.turn_ttl' => 180,
            'agent-sidebar.inference_timeout' => 80,
            'agent-sidebar.require_tool' => true,
            'agent-sidebar.instructions' => 'For every order question you must call read_order using the given integer order ID. After the tool returns, output exactly one JSON object with keys answer and source_ids. Use the exact returned status as answer and ["order:1"] as source_ids. Do not output commentary.',
        ]);
        Http::allowStrayRequests();

        $response = $this->send($this->payload())->assertCreated();
        self::assertGreaterThanOrEqual(1, app(ReadOrder::class)->calls, (string) $response->json('turn.error_code'));
        self::assertSame('completed', $response->json('turn.status'), (string) $response->json('turn.error_code'));
        $response->assertJsonPath('turn.sources.0.id', 'order:1');
        self::assertStringContainsString('turquoise-marigold', $response->json('turn.answer'));
    }

    public function test_fluent_context_and_model_have_explicit_precedence(): void
    {
        $this->signIn();
        $input = $this->payload();
        $access = AgentSidebar::forRequest(app('request'));
        $access->context()->put('workflow', new ContextValue('Server context'));
        self::assertSame('Server context', $access->context()->get('workflow')->text);

        AgentSidebar::configure()->usingModel('sidebar-test', 'fluent-default')->resolveModelUsing(TestModelResolver::class)->register();
        Http::fake([self::PROVIDER => Http::sequence()->push($this->providerAnswer('First'))->push($this->providerAnswer('Second'))]);
        $receipt = $access->turn()->usingModel('sidebar-test', 'turn-override')
            ->withContext('temporary', new ContextValue('Single-turn context'))
            ->submit($input['message'], $input['client_message_id'], $input['context_token']);
        self::assertSame(201, $receipt['status']);
        self::assertSame('First', $receipt['data']['turn']['answer']);
        $this->send($this->payload($receipt['data']['session_id']))->assertCreated()->assertJsonPath('turn.answer', 'Second');
        Http::assertSent(fn (ProviderRequest $request): bool => $request['model'] === 'turn-override'
            && str_contains($request->body(), 'Single-turn context') && str_contains($request->body(), 'Server context'));
        Http::assertSent(fn (ProviderRequest $request): bool => $request['model'] === 'resolved-acme'
            && ! str_contains($request->body(), 'Single-turn context') && str_contains($request->body(), 'Server context'));
        self::assertNull($access->context()->forget('workflow')->get('workflow'));
    }

    public function test_memory_is_encrypted_and_survives_reset_but_a_stale_job_cannot_recreate_short_memory(): void
    {
        $this->signIn();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('object-storage');
        AgentSidebar::configure()->withMemory(MemoryOptions::make()->recentTokens(30)
            ->wikiSections(['preferences'])->automatic(TestMemoryAuthorization::class))->register();
        $input = $this->payload();
        $access = AgentSidebar::forRequest(app('request'));
        $access->context()->put('workflow', new ContextValue('Page context'));
        $access->shortTerm()->replace(new ContextValue('Old summary', ['order:1']));
        $wiki = $access->longTerm()->section('preferences');
        $wiki->put(new ContextValue('Question language is Italian', ['order:1']));
        $blob = DB::table('agent_sidebar_wiki_files')->sole();
        self::assertStringNotContainsString('Italian', Storage::disk('local')->get($blob->path));
        config(['agent-sidebar.wiki.disk' => 'object-storage']);
        self::assertSame('Question language is Italian', $wiki->get()->text);
        self::assertSame(['preferences'], $access->longTerm()->sections());

        SidebarAgent::fake([['answer' => 'Saved transcript answer', 'source_ids' => []]]);
        $first = $this->send($input)->assertCreated();
        $job = Queue::pushed(UpdateMemory::class)->sole();
        MemoryAgent::fake([json_encode(['short_summary' => 'Unused summary', 'sections' => [
            'preferences' => ['text' => 'Italian and formal', 'summary' => 'Italian, formal'],
        ]])]);
        $job->handle(app(MemoryWorker::class));
        self::assertSame('Italian and formal', $wiki->get()->text);
        self::assertStringContainsString('Italian, formal', $access->longTerm()->summary());
        $this->deleteJson(self::API.'/'.$first->json('session_id'))->assertNoContent();
        $job->handle(app(MemoryWorker::class));
        self::assertNull($access->shortTerm()->get());
        self::assertNull($access->context()->get('workflow'));
        self::assertSame('Italian and formal', $wiki->get()->text);
        $this->getJson(self::API)->assertJsonPath('turns', []);
        app(TestHost::class)->allowSource = false;
        self::assertNull($wiki->get());
        self::assertSame('', $access->longTerm()->summary());
        app(Conversations::class)->forgetScope('workspace:acme');
        app(WikiFiles::class)->cleanup();
        Storage::disk('local')->assertMissing($blob->path);
        self::assertSame([], Storage::disk('object-storage')->allFiles());
    }

    public function test_headless_execution_never_reads_web_memory_and_reserves_every_step(): void
    {
        $this->signIn();
        Storage::fake('local');
        config(['agent-sidebar.memory.enabled' => true, 'agent-sidebar.memory.sections' => ['preferences']]);
        $this->payload();
        $access = AgentSidebar::forRequest(app('request'));
        $access->context()->put('private', new ContextValue('Browser-only context'));
        $access->longTerm()->section('preferences')->put(new ContextValue('Browser-only wiki'));
        $subject = app(PageContext::class)->subject(app('request'));
        $reserved = [];
        Http::fake([self::PROVIDER => function () use (&$reserved) {
            if (count($reserved) === 1) {
                return Http::response($this->providerToolCall());
            }

            return Http::response($this->providerAnswer('Found', ['order:1']));
        }]);
        $run = new AuthorizedRun($subject, static function (): void {}, function (int $step) use (&$reserved): void {
            $reserved[] = $step;
        });
        $result = app(TurnRunner::class)->runTask($run, 'Find order 1');
        self::assertSame('Found', $result['text']);
        self::assertCount(2, array_unique($reserved));
        $this->assertDatabaseCount('agent_sidebar_turns', 0);
        Http::assertNotSent(fn (ProviderRequest $request): bool => str_contains($request->body(), 'Browser-only') || str_contains($request->body(), 'read_wiki_section'));

        $blocked = new AuthorizedRun($subject, static function (): void {}, static function (): never {
            throw new TurnStopped('budget_exhausted');
        });
        try {
            app(TurnRunner::class)->runTask($blocked, 'Budget test');
            self::fail('An exhausted budget must block inference.');
        } catch (TurnStopped $error) {
            self::assertSame('budget_exhausted', $error->getMessage());
        }
        Http::assertSentCount(2);
    }

    public function test_an_existing_v01_conversation_is_readable_after_the_additive_migration(): void
    {
        $migration = require __DIR__.'/../database/migrations/2026_11_24_000001_add_memory_to_agent_sidebar.php';
        $migration->down();
        $this->signIn();
        $this->payload();
        $subject = app(PageContext::class)->subject(app('request'));
        $conversations = app(Conversations::class);
        [$session, $turn] = $conversations->reserve($subject, null, (string) Str::uuid(), 'Existing question');
        $conversations->complete($turn, 'Existing answer', [], []);

        $migration->up();
        $this->getJson(self::API)->assertOk()->assertJsonPath('session_id', $session->public_id)
            ->assertJsonPath('turns.0.answer', 'Existing answer');
    }

    public function test_failed_wiki_write_preserves_the_previous_revision(): void
    {
        $this->signIn();
        $this->payload();
        Storage::fake('local');
        $wiki = AgentSidebar::forRequest(app('request'))->longTerm()->section('preferences');
        $wiki->put(new ContextValue('Previous revision'));
        config(['agent-sidebar.wiki.disk' => 'unconfigured-private-disk']);
        try {
            $wiki->put(new ContextValue('Next revision'));
            self::fail('A missing disk must fail the new write.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('unconfigured-private-disk', $error->getMessage());
        }
        self::assertSame('Previous revision', $wiki->get()->text);
        $this->assertDatabaseHas('agent_sidebar_wiki_files', ['status' => 'delete', 'disk' => 'unconfigured-private-disk']);
    }

    private function signIn(int|string $id = 1): void
    {
        $this->actingAs(new GenericUser(['id' => $id, 'name' => 'Test User']));
    }

    /** @return array<string, mixed> */
    private function payload(?string $session = null): array
    {
        $page = $this->getJson('/workspaces/acme/orders')->assertOk();
        $cookie = config('session.cookie');
        $this->withCredentials()->withCookie($cookie, $page->getCookie($cookie)->getValue());

        return [
            'message' => 'Find order 1',
            'client_message_id' => (string) Str::uuid(),
            'session_id' => $session,
            'context_token' => $page->json('token'),
        ];
    }

    private function send(array $payload): TestResponse
    {
        return $this->postJson(self::API.'/turns', $payload);
    }

    private function providerToolCall(string $tool = 'read_order', array $arguments = ['order_id' => 1]): array
    {
        return [
            'id' => 'completion-1', 'model' => 'sidebar-model',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'call-'.Str::random(10), 'type' => 'function',
                    'function' => ['name' => $tool, 'arguments' => json_encode($arguments)],
                ]]],
                'finish_reason' => 'tool_calls',
            ]],
        ];
    }

    private function providerAnswer(string $answer, array $sources = []): array
    {
        return [
            'id' => 'completion-2', 'model' => 'sidebar-model',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => json_encode(['answer' => $answer, 'source_ids' => $sources])],
                'finish_reason' => 'stop',
            ]],
        ];
    }
}
