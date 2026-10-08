<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schemes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();               // our display name (editable)
            $table->string('mihcm_name', 100)->nullable()->unique(); // MiHCM schemeName used by the sync to match; null = local scheme
            $table->string('source', 10)->default('mihcm');      // mihcm | local
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('scheme_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['scheme_id', 'user_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('scheme_id')->nullable()->after('scheme_name')->constrained('schemes')->nullOnDelete();
        });

        // Backfill from the scheme names already synced from MiHCM
        $now = now();
        DB::table('employees')->whereNotNull('scheme_name')->where('scheme_name', '!=', '')->distinct()->pluck('scheme_name')->each(function ($name) use ($now) {
            $id = DB::table('schemes')->insertGetId(['name' => $name, 'mihcm_name' => $name, 'source' => 'mihcm', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('employees')->where('scheme_name', $name)->update(['scheme_id' => $id]);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scheme_id');
        });
        Schema::dropIfExists('scheme_user');
        Schema::dropIfExists('schemes');
    }
};
