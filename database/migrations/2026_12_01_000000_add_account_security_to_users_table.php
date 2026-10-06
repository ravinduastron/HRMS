<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
            $table->unsignedTinyInteger('failed_login_attempts')->default(0)->after('password_changed_at');
            $table->timestamp('last_failed_login_at')->nullable()->after('failed_login_attempts');
            $table->timestamp('locked_until')->nullable()->after('last_failed_login_at');
        });

        DB::table('users')->update(['password_changed_at' => now()]);

        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('password'); // bcrypt hash only (no reversible encryption)
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'must_change_password', 'password_changed_at',
                'failed_login_attempts', 'last_failed_login_at', 'locked_until']);
        });
    }
};
