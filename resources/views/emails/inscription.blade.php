<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>{{ $nouveauCompte ? 'Nouvelle inscription' : 'Nouvelle boutique' }}</title></head>
<body style="margin:0; padding:24px 0; background:#eef2f8; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#111b30;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background:#fff; border-radius:12px; overflow:hidden;">
    <tr><td style="background:#0F2A5C; padding:20px 28px; color:#fff; font-size:18px; font-weight:700;">Ngoni <span style="color:#FFCC1F;">Caisse</span> <span style="color:#c9d3e6; font-size:14px; font-weight:400;">· {{ $nouveauCompte ? 'Nouvelle inscription' : 'Nouvelle boutique' }}</span></td></tr>
    <tr><td style="padding:24px 28px 8px 28px; font-size:15px; line-height:1.6;">
      <p style="margin:0 0 16px 0;">
        @if ($nouveauCompte)
          <strong>{{ $user->name }}</strong> vient de s'inscrire avec la boutique <strong>{{ $boutique->nom }}</strong>.
        @else
          <strong>{{ $user->name }}</strong> vient d'ouvrir une nouvelle boutique : <strong>{{ $boutique->nom }}</strong>.
        @endif
      </p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
        <tr><td style="padding:5px 0; color:#5a6478; width:170px;">Téléphone</td><td>{{ $user->phone ?? '—' }}@if ($whatsapp) · <a href="{{ $whatsapp }}" style="color:#128C7E; font-weight:600;">Écrire sur WhatsApp</a>@endif</td></tr>
        <tr><td style="padding:5px 0; color:#5a6478;">E-mail</td><td>{{ $user->email ?: '—' }}</td></tr>
        <tr><td style="padding:5px 0; color:#5a6478;">Pays</td><td>{{ $pays }} · {{ $boutique->devise }}</td></tr>
        <tr><td style="padding:5px 0; color:#5a6478;">Date</td><td>{{ now()->format('d/m/Y à H:i') }}</td></tr>
        @if ($finEssai)<tr><td style="padding:5px 0; color:#5a6478;">Essai gratuit</td><td>jusqu'au {{ $finEssai }}</td></tr>@endif
      </table>
    </td></tr>
    <tr><td style="padding:20px 28px 28px 28px;">
      <a href="{{ $lienConsole }}" style="display:inline-block; background:#0F2A5C; color:#fff; text-decoration:none; font-weight:600; font-size:14px; padding:12px 20px; border-radius:8px;">Voir le compte dans la console</a>
      <p style="margin:14px 0 0 0; color:#8a94a8; font-size:12px;">Un appel dans les premiers jours aide le commerçant à bien démarrer son essai.</p>
    </td></tr>
  </table>
</td></tr></table>
</body>
</html>
