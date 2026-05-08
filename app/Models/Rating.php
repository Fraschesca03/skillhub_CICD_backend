<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modèle Rating.
 * Représente la note (1 à 5) et le commentaire qu'un apprenant inscrit
 * laisse sur une formation. Un couple (utilisateur, formation) est unique.
 */
class Rating extends Model
{
    /**
     * Champs autorisés pour l'insertion massive.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'utilisateur_id',
        'formation_id',
        'note',
        'commentaire',
    ];

    /**
     * Cast des champs.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'note' => 'integer',
    ];

    /**
     * Relation vers l'apprenant qui a note la formation.
     */
    public function utilisateur()
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    /**
     * Relation vers la formation notee.
     */
    public function formation()
    {
        return $this->belongsTo(Formation::class);
    }
}
