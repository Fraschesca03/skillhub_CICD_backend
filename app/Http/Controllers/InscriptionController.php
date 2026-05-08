<?php

namespace App\Http\Controllers;

use App\Models\Formation;
use App\Models\Inscription;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;

/**
 * Controleur de gestion des inscriptions.
 */
class InscriptionController extends Controller
{
    /**
     * Inscrire un apprenant a une formation.
     * Limite de 5 formations maximum suivies par apprenant.
     * Route : POST /formations/{id}/inscription
     */
    public function store($formationId): JsonResponse
    {
        [$user, $erreur] = $this->authentifierUtilisateur();
        if ($erreur) {
            return $erreur;
        }

        if ($user->role !== 'apprenant') {
            return response()->json([
                'message' => "Seul un apprenant peut s'inscrire à une formation",
            ], 403);
        }

        $formation = Formation::find($formationId);

        if (! $formation) {
            return response()->json(['message' => 'Formation introuvable'], 404);
        }

        // Vérifier que l'apprenant n'est pas déjà inscrit
        $dejaInscrit = Inscription::where('utilisateur_id', $user->id)
            ->where('formation_id', $formation->id)
            ->first();

        if ($dejaInscrit) {
            return response()->json([
                'message' => 'Vous êtes déjà inscrit à cette formation',
            ], 409);
        }

        // REGLE METIER : un apprenant ne peut suivre que 5 formations au maximum
        $maxFormations = 5;
        $nombreFormationsSuivies = Inscription::where('utilisateur_id', $user->id)->count();

        if ($nombreFormationsSuivies >= $maxFormations) {
            return response()->json([
                'message'             => 'Vous ne pouvez pas suivre plus de 5 formations',
                'max_formations'      => $maxFormations,
                'formations_suivies'  => $nombreFormationsSuivies,
            ], 400);
        }

        $inscription = Inscription::create([
            'utilisateur_id' => $user->id,
            'formation_id'   => $formation->id,
            'progression'    => 0,
        ]);

        ActivityLogService::inscriptionFormation($formation->id, $user->id);

        return response()->json([
            'message'             => 'Inscription réussie',
            'inscription'         => $inscription,
            'formations_restantes' => $maxFormations - ($nombreFormationsSuivies + 1),
        ], 201);
    }

    /**
     * Desinscrire un apprenant d une formation.
     * Route : DELETE /formations/{id}/inscription
     */
    public function destroy($formationId): JsonResponse
    {
        [$user, $erreur] = $this->authentifierUtilisateur();
        if ($erreur) {
            return $erreur;
        }

        if ($user->role !== 'apprenant') {
            return response()->json(['message' => 'Seul un apprenant peut se désinscrire'], 403);
        }

        $inscription = Inscription::where('utilisateur_id', $user->id)
            ->where('formation_id', $formationId)
            ->first();

        if (! $inscription) {
            return response()->json(['message' => 'Inscription introuvable'], 404);
        }

        $inscription->delete();

        return response()->json(['message' => 'Désinscription réussie']);
    }

    /**
     * Liste des formations suivies par l apprenant connecte.
     * Route : GET /apprenant/formations
     */
    public function mesFormations(): JsonResponse
    {
        [$user, $erreur] = $this->authentifierUtilisateur();
        if ($erreur) {
            return $erreur;
        }

        if ($user->role !== 'apprenant') {
            return response()->json([
                'message' => 'Seul un apprenant peut voir ses formations',
            ], 403);
        }

        $inscriptions = Inscription::with('formation.formateur:id,nom,email')
            ->where('utilisateur_id', $user->id)
            ->get();

        return response()->json($inscriptions);
    }
}
