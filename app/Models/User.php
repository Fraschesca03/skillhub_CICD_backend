<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use Notifiable;

    protected $fillable = [
        'nom',
        'email',
        'password',
        'role',
        'photo_profil',
    ];

    /**
     * Valeurs par defaut au moment du INSERT.
     * La table users (creee aussi par le service Spring Boot) impose
     * failed_attempts NOT NULL sans DEFAULT cote SQL.
     */
    protected $attributes = [
        'failed_attempts' => 0,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    public function formations()
    {
        return $this->hasMany(Formation::class, 'formateur_id');
    }

    public function inscriptions()
    {
        return $this->hasMany(Inscription::class, 'utilisateur_id');
    }

    public function modulesTermines()
    {
        return $this->belongsToMany(Module::class, 'module_user', 'utilisateur_id', 'module_id')
            ->withPivot('termine')
            ->withTimestamps();
    }
    /**
     * Relation : messages envoyés par l'utilisateur.
     */
    public function messagesEnvoyes()
    {
        return $this->hasMany(Message::class, 'expediteur_id');
    }

    /**
     * Relation : messages reçus par l'utilisateur.
     */
    public function messagesRecus()
    {
        return $this->hasMany(Message::class, 'destinataire_id');
    }
}
