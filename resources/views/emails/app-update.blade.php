<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NGONI PAY</title>
</head>
{{-- Styles en ligne : les clients de messagerie ignorent les feuilles liées. --}}
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="background:#4f46e5;padding:28px 32px;">
                            <div style="color:#ffffff;font-size:20px;font-weight:700;letter-spacing:0.5px;">NGONI PAY</div>
                            @if (!empty($version))
                                <div style="color:#c7d2fe;font-size:13px;margin-top:4px;">
                                    Version {{ $version }} disponible
                                </div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 16px;font-size:16px;color:#0f172a;">
                                Bonjour{{ $prenom ? ' ' . $prenom : '' }},
                            </p>

                            <div style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#334155;">
                                {!! nl2br(e($annonce)) !!}
                            </div>

                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:#4f46e5;border-radius:10px;">
                                        <a href="{{ $lien }}"
                                           style="display:inline-block;padding:14px 28px;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                                            Mettre à jour l'application
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:24px 0 0;font-size:13px;line-height:1.6;color:#64748b;">
                                Si le bouton ne fonctionne pas, ouvrez ce lien :<br>
                                <a href="{{ $lien }}" style="color:#4f46e5;word-break:break-all;">{{ $lien }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px;background:#f8fafc;font-size:12px;color:#94a3b8;">
                            Vous recevez ce message parce que vous avez un compte NGONI PAY.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
