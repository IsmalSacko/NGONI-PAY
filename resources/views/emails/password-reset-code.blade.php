<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Code de réinitialisation</title></head>
<body style="margin:0; padding:24px 0; background:#f4f5f7; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#1f2937;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background:#fff; border-radius:12px; overflow:hidden;">
        <tr><td style="background:#0f172a; padding:20px 28px; color:#fff; font-size:18px; font-weight:700;">Ngoni Pay</td></tr>
        <tr><td style="padding:28px; font-size:15px; line-height:1.6;">
          <p style="margin:0 0 12px 0;">Bonjour {{ $name ?: '' }},</p>
          <p style="margin:0 0 16px 0;">Voici votre code pour choisir un nouveau mot de passe :</p>
          <p style="margin:0 0 16px 0; font-size:32px; font-weight:800; letter-spacing:8px; text-align:center;">{{ $code }}</p>
          <p style="margin:0 0 8px 0; color:#64748b; font-size:13px;">Il est valable 15 minutes. Ne le communiquez à personne : l'équipe Ngoni Pay ne vous le demandera jamais.</p>
          <p style="margin:0; color:#64748b; font-size:13px;">Vous n'êtes pas à l'origine de cette demande ? Ignorez ce message : votre mot de passe reste inchangé.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
