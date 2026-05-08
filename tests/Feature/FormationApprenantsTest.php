<?php

namespace Tests\Feature;

use App\Models\Formation;
use App\Models\Inscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Tests fonctionnels du endpoint GET /api/formations/{id}/apprenants
 * (vue formateur : lister les apprenants inscrits a sa formation).
 *
 * Couvre :
 *  - 200 cas nominal : formateur proprietaire, plusieurs apprenants inscrits
 *  - 200 cas vide    : formateur proprietaire, aucun inscrit
 *  - 403             : formateur non proprietaire de la formation
 *  - 403             : utilisateur de role apprenant (n'est pas formateur)
 *  - 401             : aucun JWT fourni
 *  - 404             : formation inexistante
 */
class FormationApprenantsTest extends TestCase
{
    use RefreshDatabase;

    // Helpers de fabrication des objets de test (memes patterns que les autres tests).

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
            'titre'          => 'Formation Test',
            'description'    => 'Description de la formation',
            'categorie'      => 'developpement_web',
            'niveau'         => 'debutant',
            'nombre_de_vues' => 0,
            'formateur_id'   => $formateur->id,
        ], $valeursPersonnalisees));
    }

    /**
     * Inscrit un apprenant a une formation avec une progression donnee.
     */
    private function inscrireApprenant(User $apprenant, Formation $formation, int $progression = 0): Inscription
    {
        return Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'progression'    => $progression,
        ]);
    }

    /**
     * URL du endpoint pour une formation donnee.
     */
    private function urlApprenants(Formation $formation): string
    {
        return '/api/formations/' . $formation->id . '/apprenants';
    }


    // CAS NOMINAL : 200 avec liste d'apprenants


    /**
     * Le formateur proprietaire recoit la liste des apprenants inscrits
     * a sa formation, avec id, nom, email, progression et date_inscription.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function liste_apprenants_retourne_200_avec_les_inscrits_pour_le_proprietaire(): void
    {
        // Arrange : 1 formateur proprietaire, 2 apprenants inscrits avec progressions differentes
        ['user' => $formateur, 'token' => $jwtFormateur] = $this->creerUtilisateur('formateur');
        ['user' => $apprenant1] = $this->creerUtilisateur('apprenant');
        ['user' => $apprenant2] = $this->creerUtilisateur('apprenant');

        $formation = $this->creerFormation($formateur);
        $this->inscrireApprenant($apprenant1, $formation, 25);
        $this->inscrireApprenant($apprenant2, $formation, 75);

        // Act
        $reponse = $this->getJson(
            $this->urlApprenants($formation),
            $this->headersAuthentifies($jwtFormateur)
        );

        // Assert : 200 + tableau de 2 elements avec les bons champs
        $reponse->assertStatus(200);
        $this->assertCount(2, $reponse->json());

        $reponse->assertJsonStructure([
            '*' => ['id', 'nom', 'email', 'progression', 'date_inscription'],
        ]);

        // Verifie qu'apprenant1 figure bien avec sa progression
        $reponse->assertJsonFragment([
            'id'          => $apprenant1->id,
            'nom'         => $apprenant1->nom,
            'email'       => $apprenant1->email,
            'progression' => 25,
        ]);

        // Verifie qu'apprenant2 figure aussi avec la sienne
        $reponse->assertJsonFragment([
            'id'          => $apprenant2->id,
            'nom'         => $apprenant2->nom,
            'email'       => $apprenant2->email,
            'progression' => 75,
        ]);
    }


    // CAS VIDE : 200 avec un tableau vide


    /**
     * Quand la formation n'a aucun inscrit, le proprietaire recoit
     * un tableau JSON vide (pas une 404 ni une erreur).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function liste_apprenants_retourne_200_avec_tableau_vide_si_aucun_inscrit(): void
    {
        ['user' => $formateur, 'token' => $jwtFormateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $reponse = $this->getJson(
            $this->urlApprenants($formation),
            $this->headersAuthentifies($jwtFormateur)
        );

        $reponse->assertStatus(200);
        $this->assertEquals([], $reponse->json());
    }


    // CAS 403 : formateur non proprietaire


    /**
     * Un autre formateur (qui n'est pas proprietaire de la formation)
     * doit recevoir 403 et aucune donnee.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function liste_apprenants_echoue_403_si_formateur_non_proprietaire(): void
    {
        // formateur1 cree la formation
        ['user' => $formateurProprietaire] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateurProprietaire);

        // formateur2 essaie d'acceder a la liste des apprenants
        ['token' => $jwtAutreFormateur] = $this->creerUtilisateur('formateur');

        $reponse = $this->getJson(
            $this->urlApprenants($formation),
            $this->headersAuthentifies($jwtAutreFormateur)
        );

        $reponse->assertStatus(403);
    }


    // CAS 403 : role apprenant


    /**
     * Un utilisateur de role apprenant (meme s'il est inscrit a la formation)
     * n'a pas le droit de consulter la liste des autres apprenants : 403.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function liste_apprenants_echoue_403_si_role_apprenant(): void
    {
        ['user' => $formateur]                            = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $jwtApprenant]  = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $this->inscrireApprenant($apprenant, $formation);

        $reponse = $this->getJson(
            $this->urlApprenants($formation),
            $this->headersAuthentifies($jwtApprenant)
        );

        $reponse->assertStatus(403);
    }


    // CAS 401 : aucun JWT


    /**
     * Sans header Authorization, l'endpoint renvoie 401.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function liste_apprenants_echoue_401_si_aucun_jwt_fourni(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        // Pas de header Authorization
        $reponse = $this->getJson($this->urlApprenants($formation));

        $reponse->assertStatus(401);
    }


    // CAS 404 : formation inexistante


    /**
     * Quand l'id de formation n'existe pas en base, on attend 404
     * (et pas 403, meme si l'utilisateur n'aurait de toute facon pas le droit).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function liste_apprenants_echoue_404_si_formation_inexistante(): void
    {
        ['token' => $jwtFormateur] = $this->creerUtilisateur('formateur');

        $reponse = $this->getJson(
            '/api/formations/99999/apprenants',
            $this->headersAuthentifies($jwtFormateur)
        );

        $reponse->assertStatus(404);
    }
}
