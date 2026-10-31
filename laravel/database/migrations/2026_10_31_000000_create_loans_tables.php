<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money lent to people, and the repayments received against each loan.
        Schema::create('loans', function (Blueprint $t) {
            $t->id();
            $t->date('date')->index();
            $t->string('borrower_name', 150)->index();
            $t->string('phone', 20)->nullable();
            $t->decimal('amount', 14, 2);
            $t->date('due_date')->nullable()->index();
            $t->string('mode', 10)->default('CASH');
            $t->string('address', 2000)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('loan_repayments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $t->date('date')->index();
            $t->decimal('amount', 14, 2);
            $t->string('mode', 10)->default('CASH');
            $t->string('notes', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayments');
        Schema::dropIfExists('loans');
    }
};
