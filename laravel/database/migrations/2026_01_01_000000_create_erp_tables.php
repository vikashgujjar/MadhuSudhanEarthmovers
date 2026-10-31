<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('username', 150)->unique();
            $t->string('first_name', 150)->nullable();
            $t->string('last_name', 150)->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('password');
            $t->string('role', 10)->default('ENTRY');
            $t->boolean('is_active')->default(true);
            $t->boolean('can_add_income')->default(true);
            $t->boolean('can_add_expense')->default(true);
            $t->boolean('can_add_driver_expense')->default(true);
            $t->boolean('can_record_payment')->default(false);
            $t->boolean('can_manage_masters')->default(false);
            $t->boolean('can_view_reports')->default(false);
            $t->boolean('can_export_reports')->default(false);
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('business_settings', function (Blueprint $t) {
            $t->id();
            $t->string('business_name', 150);
            $t->string('erp_title', 150);
            $t->string('tagline', 200)->nullable();
            $t->string('logo')->nullable();
            $t->string('owner_name', 120)->nullable();
            $t->text('address')->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('email')->nullable();
            $t->string('gstin', 20)->nullable();
            $t->string('pan', 12)->nullable();
            $t->string('currency_symbol', 5)->default('₹');
            $t->unsignedTinyInteger('financial_year_start_month')->default(4);
            $t->string('invoice_prefix', 12)->default('ME-INV');
            $t->string('receipt_prefix', 12)->default('ME-RCT');
            $t->string('expense_prefix', 12)->default('ME-EXP');
            $t->decimal('opening_cash_balance', 14, 2)->default(0);
            $t->boolean('require_approval')->default(true);
            $t->string('report_footer', 200)->nullable();
            $t->timestamps();
        });

        DB::table('business_settings')->insert([
            'id' => 1,
            'business_name' => 'Madhusudhan Earthmover',
            'erp_title' => 'Madhusudhan Earthmover ERP',
            'tagline' => 'JCB, Tractor, Earthmoving & Equipment Services',
            'report_footer' => 'This is a system-generated report.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('vehicles', function (Blueprint $t) {
            $t->id();
            $t->string('category', 10)->index();
            $t->string('registration_number', 20)->unique();
            $t->string('name', 80)->nullable();
            $t->string('make', 60)->nullable();
            $t->string('model', 60)->nullable();
            $t->unsignedSmallInteger('year')->nullable();
            $t->string('chassis_number', 40)->nullable();
            $t->string('engine_number', 40)->nullable();
            $t->date('purchase_date')->nullable();
            $t->decimal('purchase_cost', 14, 2)->nullable();
            $t->decimal('hourly_rate', 14, 2)->default(0);
            $t->decimal('daily_rate', 14, 2)->default(0);
            $t->date('insurance_expiry')->nullable();
            $t->string('status', 12)->default('ACTIVE');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('drivers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('phone', 20)->nullable();
            $t->string('alternate_phone', 20)->nullable();
            $t->string('license_number', 40)->nullable();
            $t->date('license_expiry')->nullable();
            $t->text('address')->nullable();
            $t->foreignId('assigned_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $t->decimal('monthly_salary', 14, 2)->default(0);
            $t->decimal('daily_allowance', 14, 2)->default(0);
            $t->date('joining_date')->nullable();
            $t->string('status', 10)->default('ACTIVE');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('contact_person', 120)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->string('gstin', 20)->nullable();
            $t->decimal('opening_balance', 14, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // Approvable financial records share these columns.
        $transaction = function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->nullable()->index();
            $t->date('date')->index();
            $t->decimal('amount', 14, 2);
            $t->string('status', 10)->default('PENDING')->index();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->string('rejection_reason', 255)->nullable();
        };

        Schema::create('incomes', function (Blueprint $t) use ($transaction) {
            $transaction($t);
            $t->string('vehicle_category', 10);
            $t->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $t->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $t->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $t->string('billing_type', 10)->default('HOURLY');
            $t->decimal('quantity', 10, 2)->nullable();
            $t->decimal('rate', 14, 2)->nullable();
            $t->string('work_location', 200)->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
        });

        Schema::create('expenses', function (Blueprint $t) use ($transaction) {
            $transaction($t);
            $t->string('category', 15)->index();
            $t->string('vehicle_category', 10)->nullable();
            $t->foreignId('vehicle_id')->nullable()->constrained('vehicles')->restrictOnDelete();
            $t->foreignId('driver_id')->nullable()->constrained('drivers')->restrictOnDelete();
            $t->string('driver_expense_type', 10)->nullable();
            $t->decimal('litres', 9, 2)->nullable();
            $t->string('vendor', 150)->nullable();
            $t->string('bill_number', 60)->nullable();
            $t->string('payment_mode', 10)->default('CASH');
            $t->text('description')->nullable();
            $t->string('attachment')->nullable();
            $t->timestamps();
        });

        Schema::create('payments', function (Blueprint $t) use ($transaction) {
            $transaction($t);
            $t->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $t->foreignId('income_id')->nullable()->constrained('incomes')->restrictOnDelete();
            $t->string('mode', 10)->default('CASH');
            $t->string('reference', 80)->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_initial')->default(false);
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->timestamp('timestamp')->useCurrent()->index();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('username', 150)->nullable();
            $t->string('action', 20)->index();
            $t->string('model_name', 60)->nullable()->index();
            $t->string('object_id', 40)->nullable();
            $t->string('object_repr', 255)->nullable();
            $t->json('changes')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->index(['model_name', 'object_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'payments', 'expenses', 'incomes', 'customers', 'drivers', 'vehicles',
                  'business_settings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
