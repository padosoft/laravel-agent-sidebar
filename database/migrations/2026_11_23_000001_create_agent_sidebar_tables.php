<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_sidebar_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('actor_key', 64);
            $table->char('scope_key', 64)->index();
            $table->unsignedBigInteger('write_version')->default(0);
            $table->timestamps();
            $table->unique(['actor_key', 'scope_key']);
        });

        Schema::create('agent_sidebar_turns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('agent_sidebar_sessions')->cascadeOnDelete();
            $table->uuid('client_message_id');
            $table->text('question');
            $table->text('answer')->nullable();
            $table->string('status', 16);
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->timestamp('expires_at');
            $table->text('dependencies')->nullable();
            $table->json('citation_ids')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'client_message_id']);
            $table->unique(['session_id', 'active_slot']);
            $table->index(['session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_sidebar_turns');
        Schema::dropIfExists('agent_sidebar_sessions');
    }
};
