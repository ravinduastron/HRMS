<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Creates the table on the audit connection (null = default connection).
    public function getConnection(): ?string
    {
        return config('audit.connection');
    }

    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at', 6)->index();
            $table->uuid('batch_id')->nullable()->index();       // groups everything done in one request / command
            $table->unsignedBigInteger('user_id')->nullable()->index(); // no FK: may live in another database
            $table->string('user_name')->nullable();              // snapshot, survives user rename/delete
            $table->string('event', 100)->index();
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id', 64)->nullable();
            $table->string('auditable_label')->nullable();        // snapshot of a human-readable name
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('context')->nullable();
            $table->string('method', 10)->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent', 512)->nullable();
            $table->char('checksum', 64);
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
