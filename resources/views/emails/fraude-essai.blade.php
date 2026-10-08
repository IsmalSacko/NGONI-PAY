<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Alerte fraude</title></head>
<body style="margin:0; padding:24px 0; background:#eef2f8; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#111b30;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background:#fff; border-radius:12px; overflow:hidden;">
    @include('emails._entete', ['sousTitre' => 'Alerte fraude'])
    <tr><td style="background:#C62828; padding:14px 28px; color:#fff; font-size:16px; font-weight:700;">
      ⚠️ Tentative de fraude à l'essai gratuit
      <div style="font-size:13px; font-weight:400; color:#ffe1e1; margin-top:4px;">Un nouveau compte a été créé depuis un téléphone déjà utilisé : l'essai gratuit a été bloqué automatiquement.</div>
    </td></tr>
    <tr><td style="padding:24px 28px 8px 28px; font-size:15px; line-height:1.6;">
      <p style="margin:0 0 16px 0;">
        <strong>{{ $user->name }}</strong> vient de s'inscrire depuis un <strong>téléphone déjà utilisé</strong> pour un autre compte,
        sans doute pour obtenir un nouvel essai gratuit. Le compte est créé, <strong>sans essai</strong> : il devra s'abonner pour tout utiliser.
      </p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
        <tr><td style="padding:5px 0; color:#5a6478; width:170px;">Nom</td><td>{{ $user->name }}</td></tr>
        <tr><td style="padding:5px 0; color:#5a6478;">Numéro</td><td>{{ $user->phone ?? '—' }}</td></tr>
        <tr><td style="padding:5px 0; color:#5a6478;">E-mail</td><td>{{ $user->email ?: '—' }}</td></tr>
        <tr><td style="padding:5px 0; color:#5a6478;">Boutique</td><td>{{ $boutique->nom }} · {{ $pays }}</td></tr>
        <tr><td style="padding:5px 0; color:#5a6478;">Téléphone utilisé</td><td>{{ $appareil }}</td></tr>
        <tr><td style="padding:5px 0; color:#5a6478;">Date</td><td>{{ now()->timezone('Africa/Bamako')->format('d/m/Y à H:i') }}</td></tr>
      </table>

      <p style="margin:20px 0 8px 0; font-weight:700;">Comptes déjà vus sur ce téléphone</p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
        @foreach ($comptes as $compte)
          <tr><td style="padding:5px 0;">
            <strong>{{ $compte['nom'] }}</strong> · {{ $compte['telephone'] ?? '—' }}@if ($compte['inscrit_le']) · inscrit le {{ $compte['inscrit_le'] }}@endif
            @if ($compte['whatsapp']) · <a href="{{ $compte['whatsapp'] }}" style="color:#128C7E; font-weight:600;">WhatsApp</a>@endif
          </td></tr>
        @endforeach
      </table>
    </td></tr>
    <tr><td style="padding:20px 28px 8px 28px;">
      @if ($whatsappAvertissement)
        <a href="{{ $whatsappAvertissement }}" style="display:inline-block; background:#128C7E; color:#fff; text-decoration:none; font-weight:600; font-size:14px; padding:12px 20px; border-radius:8px;">Envoyer l'avertissement sur WhatsApp</a>
        <p style="margin:10px 0 0 0; color:#8a94a8; font-size:12px;">Ouvre la conversation avec {{ $user->phone }}, le message ci-dessous déjà écrit : il ne reste qu'à l'envoyer.</p>
      @endif
      <div style="margin:14px 0 0 0; padding:12px 14px; background:#f5f7fb; border-radius:8px; font-size:13px; line-height:1.5; color:#333c4f;">{{ $avertissement }}</div>
    </td></tr>
    <tr><td style="padding:16px 28px 28px 28px;">
      <a href="{{ $lienConsole }}" style="display:inline-block; background:#0F2A5C; color:#fff; text-decoration:none; font-weight:600; font-size:14px; padding:12px 20px; border-radius:8px;">Voir les comptes dans la console</a>
      <p style="margin:14px 0 0 0; color:#8a94a8; font-size:12px;">Si c'est légitime (un commerçant qui ouvre une vraie deuxième boutique, un téléphone revendu), accordez un essai depuis la console.</p>
    </td></tr>
  </table>
</td></tr></table>
</body>
</html>
