<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('utilisateur_id');
            $table->unsignedBigInteger('formation_id');
            $table->unsignedTinyInteger('note');
            $table->text('commentaire')->nullable();

            $table->timestamps();

            $table->foreign('utilisateur_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('formation_id')
                ->references('id')
                ->on('formations')
                ->onDelete('cascade');

            // Un apprenant ne peut noter une formation qu'une seule fois
            $table->unique(['utilisateur_id', 'formation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
