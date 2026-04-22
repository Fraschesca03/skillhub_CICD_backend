<?php

namespace App\Http\Controllers;

use App\Models\Formation;
use App\Models\Inscription;
use App\Models\Module;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controleur de gestion des modules.
 */
class ModuleController extends Controller
{
    private const MSG_MODULE_INTRO = 'Module introuvable';

    /**
     * Lister les modules d une formation (acces public).
     * Route : GET /formations/{id}/modules
     */
    public function index($formationId): JsonResponse
    {
        $modules = Module::where('formation_id', $formationId)
            ->orderBy('ordre')
            ->get();

        return response()->json($modules);
    }

    /**
     * Creer un module - reserve au formateur proprietaire.
     * Route : POST /formations/{id}/modules
     */
    public function store(Request $request, $formationId): JsonResponse
    {
        [$user, $erreur] = $this->authentifierUtilisateur();
        if ($erreur) {
            return $erreur;
        }

        if ($user->role !== 'formateur') {
            return response()->json(['message' => 'Seul un formateur peut créer un module'], 403);
        }

        $formation = Formation::find($formationId);

        if (! $formation) {
            return response()->json(['message' => 'Formation introuvable'], 404);
        }

        if ($formation->formateur_id !== $user->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas modifier une formation qui ne vous appartient pas',
            ], 403);
        }

        $data = $request->validate($this->moduleRules());

        $module = Module::create([
            'titre'        => $data['titre'],
            'contenu'      => $data['contenu'],
            'ordre'        => $data['ordre'],
            'formation_id' => $formationId,
        ]);

        return response()->json([
            'message' => 'Module créé avec succès',
            'module'  => $module,
        ], 201);
    }

    /**
     * Mettre a jour un module - reserve au formateur proprietaire.
     * Route : PUT /modules/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        [$user, $erreur] = $this->authentifierUtilisateur();
        if ($erreur) {
            return $erreur;
        }

        if ($user->role !== 'formateur') {
            return response()->json(['message' => 'Seul un formateur peut modifier un module'], 403);
        }

        $module = Module::find($id);

        if (! $module) {
            return response()->json(['message' => self::MSG_MODULE_INTRO], 404);
        }

        $formation = Formation::find($module->formation_id);

        if (! $formation || $formation->formateur_id !== $user->id) {
            return response()->json(['message' => 'Action non autorisée'], 403);
        }

        $data = $request->validate($this->moduleRules());

        $module->update([
            'titre'   => $data['titre'],
            'contenu' => $data['contenu'],
            'ordre'   => $data['ordre'],
        ]);

        return response()->json([
            'message' => 'Module mis à jour avec succès',
            'module'  => $module,
        ]);
    }

    /**
     * Supprimer un module - reserve au formateur proprietaire.
     * Route : DELETE /modules/{id}
     */
    public function destroy($id): JsonResponse
    {
        [$user, $erreur] = $this->authentifierUtilisateur();
        if ($erreur) {
            return $erreur;
        }

        if ($user->role !== 'formateur') {
            return response()->json(['message' => 'Seul un formateur peut supprimer un module'], 403);
        }

        $module = Module::find($id);

        if (! $module) {
            return response()->json(['message' => self::MSG_MODULE_INTRO], 404);
        }

        $formation = Formation::find($module->formation_id);

        if (! $formation || $formation->formateur_id !== $user->id) {
            return response()->json(['message' => 'Action non autorisée'], 403);
        }

        $module->delete();

        return response()->json(['message' => 'Module supprimé avec succès']);
    }

    /**
     * Marquer un module comme termine - reserve a l apprenant inscrit.
     * Route : POST /modules/{id}/terminer
     */
    public function terminer($id): JsonResponse
    {
        [$user, $erreur] = $this->authentifierUtilisateur();
        if ($erreur) {
            return $erreur;
        }

        if ($user->role !== 'apprenant') {
            return response()->json(['message' => 'Seul un apprenant peut terminer un module'], 403);
        }

        $module = Module::find($id);

        if (! $module) {
            return response()->json(['message' => self::MSG_MODULE_INTRO], 404);
        }

        $inscription = Inscription::where('utilisateur_id', $user->id)
            ->where('formation_id', $module->formation_id)
            ->first();

        if (! $inscription) {
            return response()->json([
                'message' => "Vous n'êtes pas inscrit à cette formation",
            ], 403);
        }

        $dejaTermine = $user->modulesTermines()
            ->where('modules.id', $module->id)
            ->exists();

        if ($dejaTermine) {
            return response()->json([
                'message'     => 'Ce module est déjà terminé',
                'progression' => $inscription->progression,
            ]);
        }

        $user->modulesTermines()->attach($module->id, ['termine' => true]);

        $totalModules    = Module::where('formation_id', $module->formation_id)->count();
        $modulesTermines = $user->modulesTermines()
            ->where('formation_id', $module->formation_id)
            ->count();

        $progression = $totalModules > 0
            ? round(($modulesTermines / $totalModules) * 100)
            : 0;

        $inscription->progression = $progression;
        $inscription->save();

        return response()->json([
            'message'     => 'Module terminé avec succès',
            'progression' => $inscription->progression,
        ]);
    }

    // ─── Helpers prives ──────────────────────────────────────────

    private function moduleRules(): array
    {
        return [
            'titre'   => 'required|string|max:255',
            'contenu' => 'required|string',
            'ordre'   => 'required|integer|min:1',
        ];
    }
}
