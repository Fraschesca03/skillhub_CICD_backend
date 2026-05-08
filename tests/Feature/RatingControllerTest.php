<?php

namespace Tests\Feature;

use App\Models\Formation;
use App\Models\Inscription;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Tests fonctionnels du contrôleur de notation des formations.
 *
 * Couvre l'endpoint POST /api/formations/{id}/noter avec :
 *  - le cas nominal (apprenant inscrit, note valide)             → 201
 *  - la double-notation par le même apprenant                    → 400
 *  - les notes hors intervalle 1-5                               → 400
 *  - l'apprenant non inscrit à la formation                      → 403
 *  - l'absence totale de JWT                                     → 401
 *
 * Les tests utilisent SQLite en memoire via le trait RefreshDatabase :
 * chaque test demarre avec une base propre et toutes les migrations appliquees.
 */
class RatingControllerTest extends TestCase
{
    use RefreshDatabase;

    // Helpers de fabrication d'objets (memes patterns que FormationControllerTest)

    /**
     * Cree un utilisateur du role demande et lui emet un JWT.
     *
     * @param  string  $role  "apprenant" ou "formateur"
     * @return array{user: User, token: string}
     */
    private function creerUtilisateur(string $role): array
    {
        $utilisateur = User::create([
            'nom'      => ucfirst($role) . ' Test',
            'email'    => $role . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => $role,
        ]);
        $jwt = JWTAuth::fromUser($utilisateur);

        return ['user' => $utilisateur, 'token' => $jwt];
    }

    /**
     * Construit le header Authorization Bearer pour les requetes de test.
     */
    private function headersAuthentifies(string $jwt): array
    {
        return ['Authorization' => 'Bearer ' . $jwt];
    }

    /**
     * Cree une formation appartenant au formateur passe en parametre.
     */
    private function creerFormation(User $formateur, array $valeursPersonnalisees = []): Formation
    {
        return Formation::create(array_merge([
            'titre'          => 'Formation a noter',
            'description'    => 'Description de la formation',
            'categorie'      => 'developpement_web',
            'niveau'         => 'debutant',
            'nombre_de_vues' => 0,
            'formateur_id'   => $formateur->id,
        ], $valeursPersonnalisees));
    }

    /**
     * Inscrit l'apprenant a la formation (passe par le modele, pas l'API).
     */
    private function inscrireApprenant(User $apprenant, Formation $formation): void
    {
        Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'progression'    => 0,
        ]);
    }

    /**
     * Construit l'URL de notation pour une formation donnee.
     */
    private function urlNotation(Formation $formation): string
    {
        return '/api/formations/' . $formation->id . '/noter';
    }


    // CAS NOMINAL : apprenant inscrit + note valide → 201 + ligne en base


    /**
     * Verifie le scenario heureux :
     *  - L'apprenant est inscrit a la formation
     *  - Il envoie un body JSON valide avec note=4 et un commentaire
     *  - On attend un HTTP 201 avec la structure {message, rating}
     *  - On verifie que la ligne est bien persistee dans la table ratings
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function noter_reussit_201_pour_apprenant_inscrit_avec_note_valide(): void
    {
        // Arrange : un formateur cree une formation, un apprenant s'y inscrit
        ['user' => $formateur]                            = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $jwtApprenant]  = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $this->inscrireApprenant($apprenant, $formation);

        // Act : l'apprenant envoie sa note via POST /api/formations/{id}/noter
        $reponse = $this->postJson(
            $this->urlNotation($formation),
            ['note' => 4, 'commentaire' => 'tres bonne formation'],
            $this->headersAuthentifies($jwtApprenant)
        );

        // Assert : code 201, structure JSON conforme, et persistence en BDD
        $reponse->assertStatus(201)
            ->assertJsonStructure(['message', 'rating' => ['id', 'utilisateur_id', 'formation_id', 'note', 'commentaire']])
            ->assertJsonPath('rating.note', 4)
            ->assertJsonPath('rating.commentaire', 'tres bonne formation');

        $this->assertDatabaseHas('ratings', [
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'note'           => 4,
            'commentaire'    => 'tres bonne formation',
        ]);
    }

    /**
     * Verifie que le commentaire est optionnel : noter sans commentaire doit
     * marcher (rating cree avec commentaire=null).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function noter_reussit_201_meme_sans_commentaire(): void
    {
        ['user' => $formateur]                            = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $jwtApprenant]  = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $this->inscrireApprenant($apprenant, $formation);

        $reponse = $this->postJson(
            $this->urlNotation($formation),
            ['note' => 5],
            $this->headersAuthentifies($jwtApprenant)
        );

        $reponse->assertStatus(201);
        $this->assertDatabaseHas('ratings', [
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'note'           => 5,
            'commentaire'    => null,
        ]);
    }


    // CAS 400 : double notation par le meme apprenant


    /**
     * Verifie qu'un apprenant ne peut noter une formation qu'une seule fois :
     * la 2e tentative doit retourner 400 et ne pas creer de ligne supplementaire.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function noter_echoue_400_si_apprenant_a_deja_note_la_formation(): void
    {
        // Arrange : apprenant deja inscrit ET deja note (1ere note dans le passe)
        ['user' => $formateur]                            = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $jwtApprenant]  = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $this->inscrireApprenant($apprenant, $formation);

        // 1ere notation (succes attendu)
        $this->postJson(
            $this->urlNotation($formation),
            ['note' => 3, 'commentaire' => 'premier avis'],
            $this->headersAuthentifies($jwtApprenant)
        )->assertStatus(201);

        // Act : 2e tentative de notation par le meme apprenant
        $secondeReponse = $this->postJson(
            $this->urlNotation($formation),
            ['note' => 5, 'commentaire' => 'changement d avis'],
            $this->headersAuthentifies($jwtApprenant)
        );

        // Assert : 400 + un seul rating en base (pas de doublon)
        $secondeReponse->assertStatus(400)
            ->assertJsonFragment(['message' => 'Vous avez deja note cette formation']);

        $nombreDeRatings = Rating::where('utilisateur_id', $apprenant->id)
            ->where('formation_id', $formation->id)
            ->count();
        $this->assertEquals(1, $nombreDeRatings, 'Une seule note doit etre persistee');
    }


    // CAS 400 : note hors intervalle 1-5


    /**
     * Note = 0 (en dessous du minimum) → 400.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function noter_echoue_400_si_note_inferieure_a_1(): void
    {
        ['user' => $formateur]                            = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $jwtApprenant]  = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $this->inscrireApprenant($apprenant, $formation);

        $reponse = $this->postJson(
            $this->urlNotation($formation),
            ['note' => 0, 'commentaire' => 'note trop basse'],
            $this->headersAuthentifies($jwtApprenant)
        );

        $reponse->assertStatus(400);
        $this->assertDatabaseMissing('ratings', [
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
        ]);
    }

    /**
     * Note = 6 (au-dessus du maximum) → 400.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function noter_echoue_400_si_note_superieure_a_5(): void
    {
        ['user' => $formateur]                            = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $jwtApprenant]  = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $this->inscrireApprenant($apprenant, $formation);

        $reponse = $this->postJson(
            $this->urlNotation($formation),
            ['note' => 6, 'commentaire' => 'note trop haute'],
            $this->headersAuthentifies($jwtApprenant)
        );

        $reponse->assertStatus(400);
    }

    /**
     * Note absente du body → 400 (champ "note" requis par la validation).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function noter_echoue_400_si_note_manquante(): void
    {
        ['user' => $formateur]                            = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $jwtApprenant]  = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $this->inscrireApprenant($apprenant, $formation);

        $reponse = $this->postJson(
            $this->urlNotation($formation),
            ['commentaire' => 'pas de note'],
            $this->headersAuthentifies($jwtApprenant)
        );

        $reponse->assertStatus(400);
    }


    // CAS 403 : apprenant non inscrit a la formation


    /**
     * Un apprenant qui n'est pas inscrit a la formation doit recevoir 403
     * (meme s'il est correctement authentifie).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function noter_echoue_403_si_apprenant_non_inscrit(): void
    {
        // Arrange : apprenant authentifie mais pas inscrit a la formation cible
        ['user' => $formateur]                            = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $jwtApprenant]  = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        // Volontairement : on n'appelle PAS inscrireApprenant() ici

        // Act
        $reponse = $this->postJson(
            $this->urlNotation($formation),
            ['note' => 4, 'commentaire' => 'tentative non autorisee'],
            $this->headersAuthentifies($jwtApprenant)
        );

        // Assert : 403 + aucun rating en base
        $reponse->assertStatus(403)
            ->assertJsonFragment(['message' => 'Vous devez etre inscrit a cette formation pour la noter']);

        $this->assertDatabaseMissing('ratings', [
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
        ]);
    }

    /**
     * Un formateur (meme proprietaire de la formation) ne peut pas noter :
     * la notation est reservee aux apprenants → 403.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function noter_echoue_403_si_role_formateur(): void
    {
        ['user' => $formateur, 'token' => $jwtFormateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $reponse = $this->postJson(
            $this->urlNotation($formation),
            ['note' => 5, 'commentaire' => 'auto-evaluation'],
            $this->headersAuthentifies($jwtFormateur)
        );

        $reponse->assertStatus(403)
            ->assertJsonFragment(['message' => 'Seul un apprenant peut noter une formation']);
    }


    // CAS 401 : requete sans JWT


    /**
     * Sans header Authorization, la requete doit etre rejetee en 401
     * (par le helper authentifierUtilisateur du Controller de base).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function noter_echoue_401_si_aucun_jwt_fourni(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        // Act : POST sans le header Authorization
        $reponse = $this->postJson(
            $this->urlNotation($formation),
            ['note' => 4, 'commentaire' => 'sans jwt']
        );

        // Assert : 401, et evidemment rien en base
        $reponse->assertStatus(401);
        $this->assertDatabaseCount('ratings', 0);
    }


    // CAS 404 : formation inexistante


    /**
     * Si l'id de formation n'existe pas en base, on attend un 404
     * (avant meme les checks de role et d'inscription).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function noter_echoue_404_si_formation_inexistante(): void
    {
        ['token' => $jwtApprenant] = $this->creerUtilisateur('apprenant');

        $reponse = $this->postJson(
            '/api/formations/99999/noter',
            ['note' => 4, 'commentaire' => 'formation fantome'],
            $this->headersAuthentifies($jwtApprenant)
        );

        $reponse->assertStatus(404);
    }
}
