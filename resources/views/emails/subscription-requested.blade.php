<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demande d'abonnement</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f5f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f7; padding:24px 0;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background-color:#ffffff; border-radius:12px; overflow:hidden;">
        <tr>
          <td style="background-color:#0f172a; padding:24px 32px;">
            <span style="color:#ffffff; font-size:20px; font-weight:700;">Ngoni Pay</span>
            <span style="color:#94a3b8; font-size:14px;"> · Demande d'abonnement</span>
          </td>
        </tr>
        <tr>
          <td style="padding:28px 32px 8px 32px; color:#1f2937; font-size:15px; line-height:1.6;">
            <p style="margin:0 0 16px 0;">
              <strong>{{ $business->name }}</strong> demande le plan
              <strong>{{ ucfirst($demande->plan) }}</strong>
              @if ($cycle) ({{ strtolower($cycle) }}) @endif
              pour <strong>{{ $amount }}</strong>.
            </p>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; border-collapse:collapse;">
              <tr>
                <td style="padding:6px 0; color:#64748b; width:170px;">Propriétaire</td>
                <td style="padding:6px 0;">{{ $owner?->name ?? '—' }}</td>
              </tr>
              <tr>
                <td style="padding:6px 0; color:#64748b;">Téléphone</td>
                <td style="padding:6px 0;">
                  {{ $owner?->phone ?? '—' }}
                  @if ($ownerWhatsApp)
                    · <a href="{{ $ownerWhatsApp }}" style="color:#059669; font-weight:600;">Écrire sur WhatsApp</a>
                  @elseif ($owner?->phone)
                    <span style="color:#94a3b8;">(sans indicatif pays)</span>
                  @endif
                </td>
              </tr>
              <tr>
                <td style="padding:6px 0; color:#64748b;">E-mail</td>
                <td style="padding:6px 0;">{{ $owner?->email ?? '—' }}</td>
              </tr>
              @if ($demande->contact_phone && $demande->contact_phone !== $owner?->phone)
              <tr>
                <td style="padding:6px 0; color:#64748b;">Contact indiqué</td>
                <td style="padding:6px 0;">
                  {{ $demande->contact_phone }}
                  @if ($contactWhatsApp)
                    · <a href="{{ $contactWhatsApp }}" style="color:#059669; font-weight:600;">WhatsApp</a>
                  @endif
                </td>
              </tr>
              @endif
              @if ($requester && $requester->id !== $owner?->id)
              <tr>
                <td style="padding:6px 0; color:#64748b;">Demandé par</td>
                <td style="padding:6px 0;">{{ $requester->name }} · {{ $requester->phone ?? '—' }}</td>
              </tr>
              @endif
              @if ($method)
              <tr>
                <td style="padding:6px 0; color:#64748b;">Moyen annoncé</td>
                <td style="padding:6px 0;">{{ $method }}</td>
              </tr>
              @endif
              @if ($demande->note)
              <tr>
                <td style="padding:6px 0; color:#64748b;">Note</td>
                <td style="padding:6px 0;">{{ $demande->note }}</td>
              </tr>
              @endif
              @if ($demande->proof_path)
              <tr>
                <td style="padding:6px 0; color:#64748b;">Preuve de paiement</td>
                <td style="padding:6px 0;">jointe à la demande (visible dans la console)</td>
              </tr>
              @endif
              <tr>
                <td style="padding:6px 0; color:#64748b;">Abonnement actuel</td>
                <td style="padding:6px 0;">
                  @if (! $subscription)
                    aucun
                  @else
                    {{ $subscription->isTrial() ? 'essai gratuit' : $subscription->plan }} —
                    @if (! $subscription->isCurrentlyActive())
                      <span style="color:#dc2626;">expiré le {{ \Illuminate\Support\Carbon::parse($subscription->ends_at)->format('d/m/Y') }}</span>
                    @elseif ($subscription->ends_at)
                      actif jusqu'au {{ \Illuminate\Support\Carbon::parse($subscription->ends_at)->format('d/m/Y') }}
                    @else
                      actif à vie
                    @endif
                  @endif
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 32px 32px 32px;">
            <a href="{{ $requestsUrl }}" style="display:inline-block; background-color:#4f46e5; color:#ffffff; text-decoration:none; font-weight:600; font-size:14px; padding:12px 20px; border-radius:8px;">
              Valider ou refuser la demande
            </a>
            <a href="{{ $businessUrl }}" style="display:inline-block; margin-left:8px; color:#4f46e5; text-decoration:none; font-weight:600; font-size:14px; padding:12px 8px;">
              Fiche entreprise
            </a>
            <p style="margin:16px 0 0 0; color:#94a3b8; font-size:12px;">
              Le plan n'est activé qu'après votre validation, une fois le paiement constaté.
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
