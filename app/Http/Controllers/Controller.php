<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

abstract class Controller
{
    protected const MSG_TOKEN_INVALIDE  = 'Token invalide ou absent';
    protected const MSG_USER_NON_TROUVE = 'Utilisateur non trouvé';

    /**
     * Retourne [user, null] ou [null, JsonResponse d erreur].
     */
    protected function authentifierUtilisateur(): array
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return [null, response()->json(['message' => self::MSG_USER_NON_TROUVE], 404)];
            }

            return [$user, null];
        } catch (JWTException $e) {
            return [null, response()->json(['message' => self::MSG_TOKEN_INVALIDE], 401)];
        }
    }
}
