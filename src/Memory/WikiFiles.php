<?php

declare(strict_types=1);

namespace Padosoft\AgentSidebar\Memory;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Padosoft\AgentSidebar\Subject;
use RuntimeException;
use Throwable;

final class WikiFiles
{
    public function stage(Subject $subject, string $text): int
    {
        $disk = config('agent-sidebar.wiki.disk', 'local');
        $prefix = trim((string) config('agent-sidebar.wiki.prefix', 'agent-sidebar/wiki'), '/');
        if (! is_string($disk) || $disk === 'public' || config('filesystems.disks.'.$disk.'.visibility') === 'public'
            || ! preg_match('~\A[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*\z~', $prefix)) {
            throw new RuntimeException('A private Wiki disk and a valid prefix are required.');
        }

        $path = $prefix.'/'.$subject->scopeKey.'/'.$subject->actorKey.'/'.Str::uuid().'.enc';
        $id = DB::table('agent_sidebar_wiki_files')->insertGetId([
            'disk' => $disk, 'path' => $path, 'status' => 'staged', 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            if (! Storage::disk($disk)->put($path, Crypt::encryptString($text), 'private')) {
                throw new RuntimeException('Could not save the Wiki section.');
            }
        } catch (Throwable $exception) {
            $this->retire($id);

            throw $exception;
        }

        return (int) $id;
    }

    public function read(int $id): string
    {
        $file = DB::table('agent_sidebar_wiki_files')->where('status', 'live')->find($id);
        $cipher = $file === null ? null : Storage::disk($file->disk)->get($file->path);
        if (! is_string($cipher)) {
            throw new RuntimeException('The Wiki section is unavailable.');
        }

        return Crypt::decryptString($cipher);
    }

    public function publish(int $id): void
    {
        if (DB::table('agent_sidebar_wiki_files')->where('id', $id)->where('status', 'staged')
            ->update(['status' => 'live', 'updated_at' => now()]) !== 1) {
            throw new RuntimeException('The staged Wiki section is unavailable.');
        }
    }

    public function retire(?int $id): void
    {
        if ($id !== null) {
            DB::table('agent_sidebar_wiki_files')->where('id', $id)->update(['status' => 'delete', 'updated_at' => now()]);
        }
    }

    public function cleanup(): void
    {
        $files = DB::table('agent_sidebar_wiki_files')->where(function ($query): void {
            $query->where('status', 'delete')->orWhere(function ($query): void {
                $query->where('status', 'staged')->where('updated_at', '<', now()->subHour());
            });
        })->orderBy('id')->limit(100)->get();

        foreach ($files as $file) {
            // Claim stale staging entries before deleting so an in-flight pointer cannot publish afterwards.
            if ($file->status === 'staged' && DB::table('agent_sidebar_wiki_files')->where('id', $file->id)
                ->where('status', 'staged')->update(['status' => 'delete', 'updated_at' => now()]) !== 1) {
                continue;
            }

            try {
                $disk = Storage::disk($file->disk);
                if ($disk->delete($file->path) || ! $disk->exists($file->path)) {
                    DB::table('agent_sidebar_wiki_files')->where('id', $file->id)->where('status', 'delete')->delete();
                }
            } catch (Throwable $exception) {
                logger()->warning('Sidebar Wiki cleanup will retry.', ['file' => $file->id, 'type' => $exception::class]);
            }
        }
    }
}
