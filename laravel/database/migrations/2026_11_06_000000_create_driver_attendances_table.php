<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One attendance record per driver per day, with optional punch in/out times.
        Schema::create('driver_attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('driver_id')->constrained('drivers')->restrictOnDelete();
            $t->date('date')->index();
            $t->string('status', 10);
            $t->time('punch_in')->nullable();
            $t->time('punch_out')->nullable();
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['driver_id', 'date']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->boolean('can_mark_attendance')->default(false)->after('can_manage_personal_expenses');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('can_mark_attendance'));
        Schema::dropIfExists('driver_attendances');
    }
};
