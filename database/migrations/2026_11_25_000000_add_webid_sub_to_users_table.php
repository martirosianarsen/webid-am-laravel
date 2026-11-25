<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each user's webid id ("sub"), so the same person is found again at every sign-in.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('webid_sub', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['webid_sub']);
            $table->dropColumn('webid_sub');
        });
    }
};
