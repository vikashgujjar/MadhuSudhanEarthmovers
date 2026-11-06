<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A private link per driver for marking their own attendance, and a flag on records made through it.
        Schema::table('drivers', function (Blueprint $t) {
            $t->string('attendance_token', 64)->nullable()->unique();
        });
        Schema::table('driver_attendances', function (Blueprint $t) {
            $t->boolean('self_marked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('driver_attendances', fn (Blueprint $t) => $t->dropColumn('self_marked'));
        Schema::table('drivers', function (Blueprint $t) {
            $t->dropUnique(['attendance_token']);
            $t->dropColumn('attendance_token');
        });
    }
};
