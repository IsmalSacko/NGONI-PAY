<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Demande d'abonnement</title></head>
<body style="margin:0; padding:24px 0; background:#f4f5f7; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background:#fff; border-radius:12px; overflow:hidden;">
    <tr><td style="background:#0F2A5C; padding:20px 28px; color:#fff; font-size:18px; font-weight:700;">Ngoni Caisse <span style="color:#94a3b8; font-size:14px; font-weight:400;">· Demande d'abonnement</span></td></tr>
    <tr><td style="padding:24px 28px 8px 28px; font-size:15px; line-height:1.6;">
      <p style="margin:0 0 16px 0;"><strong>{{ $demande->boutique?->nom ?? '—' }}</strong> demande le plan <strong>{{ ucfirst($demande->plan) }}</strong> ({{ strtolower($demande->cycle->libelle()) }}) pour <strong>{{ $montant }}</strong>.</p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
        <tr><td style="padding:5px 0; color:#64748b; width:170px;">Propriétaire</td><td>{{ $proprietaire?->name ?? '—' }}</td></tr>
        <tr><td style="padding:5px 0; color:#64748b;">Téléphone</td><td>{{ $demande->telephone_contact ?: ($proprietaire?->phone ?? '—') }}@if ($whatsapp) · <a href="{{ $whatsapp }}" style="color:#059669; font-weight:600;">Écrire sur WhatsApp</a>@endif</td></tr>
        <tr><td style="padding:5px 0; color:#64748b;">E-mail</td><td>{{ $proprietaire?->email ?? '—' }}</td></tr>
        @if ($demande->demandeur && $demande->demandeur->id !== $proprietaire?->id)
        <tr><td style="padding:5px 0; color:#64748b;">Demandé par</td><td>{{ $demande->demandeur->name }} · {{ $demande->demandeur->phone }}</td></tr>
        @endif
        @if ($moyen)<tr><td style="padding:5px 0; color:#64748b;">Moyen annoncé</td><td>{{ $moyen }}</td></tr>@endif
        @if ($demande->note)<tr><td style="padding:5px 0; color:#64748b;">Note</td><td>{{ $demande->note }}</td></tr>@endif
        <tr><td style="padding:5px 0; color:#64748b;">Preuve de paiement</td><td>{{ $demande->preuve_chemin || $demande->preuve_note ? 'jointe (voir la console)' : 'aucune pour le moment' }}</td></tr>
        <tr><td style="padding:5px 0; color:#64748b;">Abonnement actuel</td><td>
          @if (! $abonnement) aucun
          @else
            {{ $abonnement->estEssai() ? 'essai gratuit' : $abonnement->plan }} —
            @if (! $abonnement->estEnCours())<span style="color:#dc2626;">expiré le {{ $abonnement->fin?->format('d/m/Y') }}</span>
            @elseif ($abonnement->fin) actif jusqu'au {{ $abonnement->fin->format('d/m/Y') }}
            @else actif sans échéance @endif
          @endif
        </td></tr>
      </table>
    </td></tr>
    <tr><td style="padding:20px 28px 28px 28px;">
      <a href="{{ $lienConsole }}" style="display:inline-block; background:#0F2A5C; color:#fff; text-decoration:none; font-weight:600; font-size:14px; padding:12px 20px; border-radius:8px;">Valider ou refuser la demande</a>
      <p style="margin:14px 0 0 0; color:#94a3b8; font-size:12px;">Le plan n'est activé qu'après votre validation, une fois le paiement constaté.</p>
    </td></tr>
  </table>
</td></tr></table>
</body>
</html>
