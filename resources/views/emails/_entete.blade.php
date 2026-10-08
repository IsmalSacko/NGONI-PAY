{{-- En-tête commun des e-mails : logo, « Ngoni Caisse », et ce dont il s'agit. --}}
<tr><td style="background:#0F2A5C; padding:16px 28px;">
  <table role="presentation" cellpadding="0" cellspacing="0"><tr>
    <td style="padding-right:12px; vertical-align:middle;"><img src="{{ asset('icone-192.png') }}" width="40" height="40" alt="Ngoni Caisse" style="display:block; border:0; border-radius:9px;"></td>
    <td style="vertical-align:middle; color:#fff; font-size:18px; font-weight:700; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
      Ngoni <span style="color:#FFCC1F;">Caisse</span>@if (filled($sousTitre ?? null)) <span style="color:#c9d3e6; font-size:14px; font-weight:400;">· {{ $sousTitre }}</span>@endif
    </td>
  </tr></table>
</td></tr>
