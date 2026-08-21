<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'client_id',
        'user_id',
        'amount',
        'currency',
        'method',
        'provider',
        'provider_reference',
        'provider_checkout_url',
        'transaction_ref',
        'idempotency_key',
        'status',
        'purpose',
        'paid_at',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Objet d'un paiement : une vente encaissée pour un client.
     */
    public const PURPOSE_SALE = 'sale';

    /**
     * Objet d'un paiement : l'abonnement du business à NGONI PAY.
     *
     * Ce n'est pas une recette du commerce, c'est une dépense — encaissée par
     * l'éditeur, pas par le commerçant.
     */
    public const PURPOSE_SUBSCRIPTION = 'subscription';

    /* ================= PORTÉES ================= */

    /**
     * Ventes encaissées pour un client, à l'exclusion des abonnements.
     *
     * Sans cette portée, l'abonnement d'un commerçant gonflait ses propres
     * ventes : un plan Pro à 15 000 apparaissait au tableau de bord comme une
     * recette du jour. Les lignes écrites avant la colonne `purpose` n'ont pas
     * de valeur : elles sont des ventes, aucun abonnement n'existait alors.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Payment>  $query
     */
    public function scopeSales($query)
    {
        return $query->where(function ($query) {
            $query->where('purpose', self::PURPOSE_SALE)
                ->orWhereNull('purpose');
        });
    }

    /**
     * Ventes qui comptent dans le chiffre d'affaires.
     *
     * Encaissées — donc `success` — et libellées dans la devise du business.
     * Additionner des montants de devises différentes ne donne pas un total mais
     * un nombre : 5 000 francs CFA et 22 euros ne font pas 5 022.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Payment>  $query
     */
    public function scopeRevenue($query, Business $business)
    {
        return $query->where('business_id', $business->id)
            ->sales()
            ->where('status', 'success')
            ->where('currency', $business->currency ?: 'XOF');
    }

    /* ================= RELATIONS ================= */

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    public function callbacks()
    {
        return $this->hasMany(PaymentCallback::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}
