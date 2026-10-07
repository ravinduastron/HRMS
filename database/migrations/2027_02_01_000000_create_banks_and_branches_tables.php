<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 150)->unique();
            $table->timestamps();
        });

        Schema::create('bank_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained()->cascadeOnDelete(); // only reachable when the bank has no accounts
            $table->string('code', 10);
            $table->string('name', 150);
            $table->timestamps();
            $table->unique(['bank_id', 'code']);
            $table->unique(['bank_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_branches');
        Schema::dropIfExists('banks');
    }
};
