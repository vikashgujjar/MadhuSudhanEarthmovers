<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Driver cash book: where each driver's balance starts and when salary stops.
        Schema::table('drivers', function (Blueprint $t) {
            $t->date('ledger_start_month')->nullable();
            $t->date('ledger_end_month')->nullable();
            $t->decimal('opening_balance', 14, 2)->default(0);
        });

        // Salary by month, so a raise does not rewrite months already passed.
        Schema::create('driver_salary_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $t->date('effective_month');
            $t->decimal('monthly_salary', 14, 2);
            $t->unique(['driver_id', 'effective_month']);
        });

        // Bonuses and deductions that change what is owed without any money moving.
        Schema::create('driver_salary_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('driver_id')->constrained('drivers')->restrictOnDelete();
            $t->date('date')->index();
            $t->string('kind', 10);
            $t->decimal('amount', 14, 2);
            $t->string('reason', 255);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_salary_adjustments');
        Schema::dropIfExists('driver_salary_changes');
        Schema::table('drivers', fn (Blueprint $t) => $t->dropColumn(['ledger_start_month', 'ledger_end_month', 'opening_balance']));
    }
};
