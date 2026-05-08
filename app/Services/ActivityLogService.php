<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service d'historisation des actions dans MongoDB.
 *
 * Si MongoDB est indisponible, l'echec est seulement logge :
 * l'API metier (CRUD formations, inscriptions, etc.) reste fonctionnelle.
 */
class ActivityLogService
{
    /**
     * Ecriture defensive : si MongoDB est down, on logge et on continue.
     */
    private static function safeCreate(array $data): void
    {
        try {
            ActivityLog::create($data);
        } catch (Throwable $e) {
            Log::warning('ActivityLog : ecriture MongoDB ignoree', [
                'event' => $data['event'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Enregistrer la consultation d'une formation.
     */
    public static function consultationFormation(int $formationId, ?int $userId): void
    {
        self::safeCreate([
            'event'        => 'course_view',
            'user_id'      => $userId,
            'formation_id' => $formationId,
            'timestamp'    => now()->toISOString(),
        ]);
    }

    /**
     * Enregistrer l'inscription à une formation.
     */
    public static function inscriptionFormation(int $formationId, int $userId): void
    {
        self::safeCreate([
            'event'        => 'course_enrollment',
            'user_id'      => $userId,
            'formation_id' => $formationId,
            'timestamp'    => now()->toISOString(),
        ]);
    }

    /**
     * Enregistrer la création d'une formation.
     */
    public static function creationFormation(int $formationId, int $userId): void
    {
        self::safeCreate([
            'event'        => 'course_creation',
            'user_id'      => $userId,
            'formation_id' => $formationId,
            'timestamp'    => now()->toISOString(),
        ]);
    }

    /**
     * Enregistrer la modification d'une formation.
     * Stocke les valeurs avant et après modification.
     */
    public static function modificationFormation(int $formationId, int $userId, array $oldValues, array $newValues): void
    {
        self::safeCreate([
            'event'        => 'course_update',
            'user_id'      => $userId,
            'formation_id' => $formationId,
            'old_values'   => $oldValues,
            'new_values'   => $newValues,
            'timestamp'    => now()->toISOString(),
        ]);
    }

    /**
     * Enregistrer la suppression d'une formation.
     */
    public static function suppressionFormation(int $formationId, int $userId): void
    {
        self::safeCreate([
            'event'        => 'course_deletion',
            'user_id'      => $userId,
            'formation_id' => $formationId,
            'timestamp'    => now()->toISOString(),
        ]);
    }
}
