<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>{{ $annonce->titre }}</title></head>
<body style="margin:0; padding:24px 0; background:#f4f5f7; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#1f2937;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background:#fff; border-radius:12px; overflow:hidden;">
        <tr><td style="background:#0B6E4F; padding:20px 28px; color:#fff; font-size:18px; font-weight:700;">Ngoni Caisse</td></tr>
        <tr><td style="padding:28px; font-size:15px; line-height:1.6;">
          <p style="margin:0 0 12px 0;">Bonjour {{ $nom ?: '' }},</p>
          <p style="margin:0 0 12px 0; font-size:18px; font-weight:700;">{{ $annonce->titre }}</p>
          @if ($annonce->version)
            <p style="margin:0 0 12px 0;">La version <strong>{{ $annonce->version }}</strong> est disponible.</p>
          @endif
          <p style="margin:0 0 20px 0; white-space:pre-line;">{{ $annonce->message }}</p>
          @if ($annonce->lien)
            <p style="margin:0 0 8px 0; text-align:center;">
              <a href="{{ $annonce->lien }}" style="display:inline-block; background:#0B6E4F; color:#fff; text-decoration:none; font-weight:700; padding:12px 24px; border-radius:10px;">
                {{ $annonce->type === 'mise_a_jour' ? 'Mettre à jour' : 'En savoir plus' }}
              </a>
            </p>
          @endif
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
