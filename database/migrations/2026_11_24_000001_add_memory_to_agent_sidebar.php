<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_sidebar_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('generation')->default(0);
            $table->unsignedBigInteger('memory_version')->default(0);
            $table->unsignedBigInteger('memory_pending')->default(0);
            $table->unsignedBigInteger('memory_processed')->default(0);
            $table->unsignedBigInteger('short_through')->default(0);
            $table->boolean('short_rebuild')->default(false);
            $table->text('memory_permit')->nullable();
        });

        Schema::create('agent_sidebar_memory', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('agent_sidebar_sessions')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('key', 64);
            $table->uuid('revision');
            $table->text('content')->nullable();
            $table->text('dependencies');
            $table->foreignId('file_id')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'kind', 'key']);
        });

        Schema::create('agent_sidebar_wiki_files', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 100);
            $table->string('path', 512);
            $table->string('status', 12)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_sidebar_memory');
        Schema::dropIfExists('agent_sidebar_wiki_files');

        Schema::table('agent_sidebar_sessions', fn (Blueprint $table) => $table->dropColumn([
            'generation', 'memory_version', 'memory_pending', 'memory_processed', 'short_through', 'short_rebuild', 'memory_permit',
        ]));
    }
};
