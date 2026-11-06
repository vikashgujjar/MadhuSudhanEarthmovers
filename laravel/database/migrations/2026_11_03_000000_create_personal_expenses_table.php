<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The owner's personal spending: kept on record, never mixed into business figures.
        Schema::create('personal_expenses', function (Blueprint $t) {
            $t->id();
            $t->date('date')->index();
            $t->string('category', 20)->index();
            $t->decimal('amount', 14, 2);
            $t->string('mode', 10)->default('CASH');
            $t->string('paid_to', 150)->nullable();
            $t->string('description', 255)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->boolean('can_manage_personal_expenses')->default(false)->after('can_export_reports');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('can_manage_personal_expenses'));
        Schema::dropIfExists('personal_expenses');
    }
};
