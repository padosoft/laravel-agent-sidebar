<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Padosoft\AgentSidebar\Contracts\ReadTool;
use Throwable;

final readonly class SdkTool implements Tool
{
    public function __construct(private ReadTool $tool, private TurnContext $context, private ?Closure $allowed = null) {}

    public function name(): string
    {
        return $this->tool->name();
    }

    public function description(): string
    {
        return $this->tool->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    public function handle(Request $request): string
    {
        try {
            return $this->read($request);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (TurnStopped $exception) {
            $this->context->stop($exception->getMessage());
        } catch (Throwable) {
            $this->context->stop('tool_failed');
        }
    }

    private function read(Request $request): string
    {
        $this->context->startTool();
        if ($this->allowed !== null && ! ($this->allowed)()) {
            throw new TurnStopped('access_changed');
        }

        $rules = $this->tool->rules();
        $permitted = array_map(fn (string $key): string => explode('.', $key, 2)[0], array_keys($rules));

        if (array_diff(array_keys($request->all()), $permitted) !== []
            || strlen(json_encode($request->all(), JSON_THROW_ON_ERROR)) > 4000) {
            throw ValidationException::withMessages(['arguments' => 'Invalid tool arguments.']);
        }

        $arguments = $request->validate($rules);
        try {
            $result = $this->tool->read($this->context->subject, $arguments);
        } catch (TurnStopped $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->context->stop('tool_failed');
        }
        if (strlen($result->text) > 12000) {
            throw new TurnStopped('tool_result_limit');
        }

        if ($this->allowed !== null && ! ($this->allowed)()) {
            throw new TurnStopped('access_changed');
        }
        $this->context->collect($result->sourceIds, $result->citeable);
        if (! $this->context->includeDependencies($result->revisions)) {
            throw new TurnStopped('sources_changed');
        }
        $this->context->check();

        return json_encode(['text' => $result->text, 'source_ids' => $result->citeable ? $result->sourceIds : []], JSON_THROW_ON_ERROR);
    }
}
