<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aligne le schema users avec ce que Spring Boot (Hibernate) cree automatiquement :
 *  - failed_attempts : compteur de tentatives de connexion echouees
 *  - lock_until      : date jusqu'a laquelle le compte est verrouille
 *
 * Migration idempotente : sur SQLite (tests) elle cree les colonnes manquantes,
 * sur MySQL ou Hibernate les a deja creees elle ne fait rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'failed_attempts')) {
                $table->unsignedInteger('failed_attempts')->default(0)->after('role');
            }
            if (! Schema::hasColumn('users', 'lock_until')) {
                $table->timestamp('lock_until')->nullable()->after('failed_attempts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'lock_until')) {
                $table->dropColumn('lock_until');
            }
            if (Schema::hasColumn('users', 'failed_attempts')) {
                $table->dropColumn('failed_attempts');
            }
        });
    }
};
