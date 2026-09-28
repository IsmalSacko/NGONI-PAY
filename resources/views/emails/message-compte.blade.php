<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>{{ $titre }}</title></head>
<body style="margin:0; padding:24px 0; background:#f4f5f7; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#1f2937;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background:#fff; border-radius:12px; overflow:hidden;">
        <tr><td style="background:#0F2A5C; padding:20px 28px; color:#fff; font-size:18px; font-weight:700;">Ngoni Caisse</td></tr>
        <tr><td style="padding:28px; font-size:15px; line-height:1.6;">
          <p style="margin:0 0 12px 0;">Bonjour {{ $nom ?: '' }},</p>
          <p style="margin:0 0 12px 0; font-size:18px; font-weight:700;">{{ $titre }}</p>
          <p style="margin:0; white-space:pre-line;">{{ $texte }}</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
