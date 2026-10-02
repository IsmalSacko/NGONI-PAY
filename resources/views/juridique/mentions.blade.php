@php($wa = \App\Support\WhatsApp::link((string) config('ecaisse.support_whatsapp')))
<x-juridique.page titre="Mentions légales" actif="mentions" :canonique="route('mentions-legales')">

<h2>Éditeur</h2>
<p>
    <strong>Ismaila SACKO</strong>, entrepreneur individuel<br>
    Nom commercial : <strong>IsmaelDev</strong><br>
    RCS Lyon 108 559 998 — SIRET 108 559 998 00019<br>
    18 avenue Maurice Thorez, 69200 Vénissieux, France<br>
    TVA non applicable, article 293 B du Code général des impôts.
</p>
<p>Directeur de la publication : Ismaila SACKO.</p>

<h2>Contact</h2>
<ul>
    <li>E-mail : <a href="mailto:contact@ismael-dev.com">contact@ismael-dev.com</a></li>
    @if ($wa)<li>WhatsApp : <a href="{{ $wa }}" target="_blank" rel="noopener">écrire au support</a></li>@endif
</ul>

<h2>Hébergement</h2>
<p>
    Le Service et ses données sont hébergés par <strong>OVH SAS</strong> (OVHcloud),<br>
    2 rue Kellermann, 59100 Roubaix, France — <a href="https://www.ovhcloud.com" rel="noopener">ovhcloud.com</a>.
</p>

<h2>Propriété intellectuelle</h2>
<p>La marque Ngoni Caisse, son logo (le ticket jaune marqué « N »), le site, l’application et leurs contenus sont la propriété de l’Éditeur. Toute reproduction sans autorisation est interdite.</p>

<h2>Documents</h2>
<ul>
    <li><a href="{{ route('conditions') }}">Conditions générales d’utilisation et de vente</a></li>
    <li><a href="{{ route('confidentialite') }}">Politique de confidentialité</a></li>
</ul>

</x-juridique.page>
