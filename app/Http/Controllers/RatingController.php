<?php

namespace App\Http\Controllers;

use App\Models\Formation;
use App\Models\Inscription;
use App\Models\Rating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Controleur de notation des formations.
 *
 * Permet aux apprenants inscrits et authentifies de noter
 * une formation (note 1 a 5 + commentaire optionnel),
 * une seule fois par formation.
 */
class RatingController extends Controller
{
    private const MSG_FORMATION_INTRO = 'Formation introuvable';

    /**
     * Noter une formation.
     * Route : POST /formations/{id}/noter
     *
     * Reponses :
     *  - 201 : rating cree (JSON)
     *  - 400 : note hors intervalle 1-5 OU formation deja notee par cet apprenant
     *  - 401 : token invalide ou absent
     *  - 403 : apprenant non inscrit a la formation
     *  - 404 : formation introuvable
     */
    public function noter(Request $request, $formationId): JsonResponse
    {
        // 1. Authentification
        [$user, $erreur] = $this->authentifierUtilisateur();
        if ($erreur) {
            return $erreur;
        }

        // 2. Seul un apprenant peut noter
        if ($user->role !== 'apprenant') {
            return response()->json([
                'message' => 'Seul un apprenant peut noter une formation',
            ], 403);
        }

        // 3. Verifier que la formation existe
        $formation = Formation::find($formationId);
        if (! $formation) {
            return response()->json(['message' => self::MSG_FORMATION_INTRO], 404);
        }

        // 4. Verifier que l'apprenant est inscrit a cette formation
        $estInscrit = Inscription::where('utilisateur_id', $user->id)
            ->where('formation_id', $formation->id)
            ->exists();

        if (! $estInscrit) {
            return response()->json([
                'message' => "Vous devez etre inscrit a cette formation pour la noter",
            ], 403);
        }

        // 5. Validation du body : note 1-5, commentaire optionnel
        try {
            $validated = $request->validate([
                'note'        => 'required|integer|min:1|max:5',
                'commentaire' => 'nullable|string|max:2000',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Note hors intervalle 1-5 ou champs invalides',
                'errors'  => $e->errors(),
            ], 400);
        }

        // 6. Une seule note par apprenant et par formation
        $dejaNote = Rating::where('utilisateur_id', $user->id)
            ->where('formation_id', $formation->id)
            ->exists();

        if ($dejaNote) {
            return response()->json([
                'message' => 'Vous avez deja note cette formation',
            ], 400);
        }

        // 7. Creation
        $rating = Rating::create([
            'utilisateur_id' => $user->id,
            'formation_id'   => $formation->id,
            'note'           => $validated['note'],
            'commentaire'    => $validated['commentaire'] ?? null,
        ]);

        return response()->json([
            'message' => 'Note enregistree avec succes',
            'rating'  => $rating,
        ], 201);
    }
}
