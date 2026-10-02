@php($wa = \App\Support\WhatsApp::link((string) config('ecaisse.support_whatsapp')))
<x-juridique.page titre="Politique de confidentialité" actif="confidentialite" :canonique="route('confidentialite')">

<p>Cette politique explique quelles données Ngoni Caisse traite, pourquoi, avec qui, combien de temps, et quels sont vos droits. Elle s’applique à l’application mobile, à l’application web, au back-office et au site.</p>

<h2 id="responsable">1. Qui traite vos données</h2>
<p>Le responsable du traitement est <strong>Ismaila SACKO</strong>, entrepreneur individuel (IsmaelDev), 18 avenue Maurice Thorez, 69200 Vénissieux, France — RCS Lyon 108 559 998. Contact : <a href="mailto:contact@ismael-dev.com">contact@ismael-dev.com</a>. L’Éditeur étant établi en France, le Règlement général sur la protection des données (RGPD) et la loi Informatique et Libertés s’appliquent.</p>
<p><strong>Les données de vos propres clients</strong> (nom, téléphone, achats, crédit) sont enregistrées par vous, pour votre commerce : pour elles, c’est vous, commerçant, qui en êtes responsable, et l’Éditeur agit en sous-traitant, uniquement pour faire fonctionner le Service. Il appartient au commerçant d’informer ses clients et de respecter la loi de son pays.</p>

<h2 id="donnees">2. Données traitées</h2>
<table>
    <tr><th>Catégorie</th><th>Exemples</th></tr>
    <tr><td>Compte</td><td>nom, numéro de téléphone, e-mail (facultatif), mot de passe (enregistré chiffré, jamais lisible), rôle dans l’équipe</td></tr>
    <tr><td>Boutique</td><td>nom, pays, devise, adresse, téléphone, logo, identifiants légaux (NIF, RCCM) s’ils sont saisis</td></tr>
    <tr><td>Activité</td><td>articles, prix, stocks, ventes, tickets, factures, achats, fournisseurs, séances de caisse</td></tr>
    <tr><td>Clients du commerçant</td><td>nom, téléphone, achats, fidélité, crédit</td></tr>
    <tr><td>Abonnement</td><td>plan, échéances, preuve de paiement envoyée (capture, référence)</td></tr>
    <tr><td>Technique</td><td>modèle et système de l’appareil, jeton de notification, adresse IP, journaux de connexion</td></tr>
    <tr><td>Preuve d’acceptation</td><td>version des conditions acceptée, date, heure, adresse IP, appareil</td></tr>
</table>
<p>L’application demande l’accès à l’appareil photo (scanner un code-barres, photographier un article ou une preuve) et au Bluetooth (imprimante), seulement au moment où vous utilisez ces fonctions. Vous pouvez les refuser ou les retirer dans les réglages du téléphone.</p>

<h2 id="finalites">3. Pourquoi, et sur quelle base</h2>
<ul>
    <li><strong>Fournir le Service</strong> (compte, caisse, synchronisation, tickets, rapports, support) : exécution du contrat.</li>
    <li><strong>Gérer les abonnements et le parrainage</strong> : exécution du contrat.</li>
    <li><strong>Sécurité et prévention de la fraude</strong> (journaux, contrôle des paiements) : intérêt légitime de l’Éditeur.</li>
    <li><strong>Prouver l’acceptation des conditions</strong> et répondre aux obligations légales : obligation légale et intérêt légitime.</li>
    <li><strong>Annonces et nouveautés</strong> (notifications dans l’application, e-mail si vous l’avez permis) : intérêt légitime ; vous pouvez couper les e-mails dans votre profil.</li>
    <li><strong>Mesure des campagnes publicitaires</strong> (voir Meta ci-dessous) : intérêt légitime ; vous pouvez réinitialiser l’identifiant publicitaire de votre téléphone.</li>
</ul>

<h2 id="partage">4. Avec qui</h2>
<p>Nous ne vendons pas vos données. Elles ne sont transmises qu’aux prestataires nécessaires au Service, tenus à la confidentialité :</p>
<table>
    <tr><th>Prestataire</th><th>Rôle</th><th>Lieu</th></tr>
    <tr><td>OVHcloud (OVH SAS)</td><td>hébergement du Service et des données</td><td>serveurs OVHcloud</td></tr>
    <tr><td>Google Drive (Google)</td><td>copie de sauvegarde de la base</td><td>Union européenne / États-Unis</td></tr>
    <tr><td>Firebase Cloud Messaging (Google)</td><td>notifications sur le téléphone</td><td>États-Unis</td></tr>
    <tr><td>Mailjet, Brevo</td><td>envoi des e-mails (codes, bilans, rappels)</td><td>Union européenne</td></tr>
    <tr><td>Meta (Facebook)</td><td>mesure des campagnes publicitaires</td><td>Union européenne / États-Unis</td></tr>
</table>
<p><strong>Meta.</strong> L’application intègre le kit officiel de Meta pour mesurer nos campagnes sur Facebook et Instagram. Il transmet l’installation, l’ouverture de l’application et la création d’un compte, avec l’identifiant publicitaire et le modèle du téléphone. Aucune donnée de votre boutique n’y circule : ni nom, ni téléphone, ni e-mail, ni vente, ni client.</p>
<p>Les transferts hors de l’Union européenne se font avec les garanties prévues par le RGPD (décisions d’adéquation, dont le cadre UE–États-Unis, ou clauses contractuelles types de la Commission européenne).</p>
<p>Au sein d’une boutique, les membres de l’équipe voient les données selon les droits que le titulaire leur a donnés.</p>

<h2 id="conservation">5. Combien de temps</h2>
<ul>
    <li><strong>Compte et données de la boutique</strong> : tant que le compte existe ; supprimés sous 30 jours après une demande de suppression.</li>
    <li><strong>Sauvegardes</strong> : 60 jours, puis effacées. Une remise à zéro de boutique est gardée 30 jours pour pouvoir la restaurer.</li>
    <li><strong>Journaux techniques</strong> : 12 mois au plus.</li>
    <li><strong>Preuve d’acceptation des conditions</strong> : pendant la relation, puis 5 ans (délai de prescription), pour pouvoir la produire en cas de litige.</li>
</ul>

<h2 id="securite">6. Sécurité</h2>
<p>Connexions chiffrées (HTTPS), mots de passe chiffrés, accès séparés par boutique et par rôle, sauvegardes régulières. Aucun système n’est infaillible : en cas de violation de données présentant un risque, nous vous prévenons et informons l’autorité compétente comme la loi l’exige.</p>

<h2 id="droits">7. Vos droits</h2>
<p>Vous pouvez demander l’accès à vos données, leur rectification, leur effacement, leur portabilité, la limitation du traitement, et vous opposer aux traitements fondés sur l’intérêt légitime. Écrivez à <a href="mailto:contact@ismael-dev.com">contact@ismael-dev.com</a>@if ($wa) ou <a href="{{ $wa }}" target="_blank" rel="noopener">par WhatsApp</a>@endif, depuis le numéro ou l’adresse de votre compte. Réponse sous un mois.</p>
<p>Vous pouvez aussi saisir l’autorité de protection des données : en France, la <a href="https://www.cnil.fr" rel="noopener">CNIL</a> ; ou celle de votre pays (au Mali, l’APDP).</p>

<h2 id="suppression-compte">8. Supprimer votre compte Ngoni Caisse</h2>
<p>Pour supprimer votre compte et ses données, envoyez-nous une demande depuis le numéro de téléphone de votre compte @if ($wa)<a href="{{ $wa }}" target="_blank" rel="noopener">par WhatsApp</a>@endif ou par e-mail à <a href="mailto:contact@ismael-dev.com">contact@ismael-dev.com</a>, en indiquant ce numéro.</p>
<p>Nous supprimons alors, sous 30 jours : votre compte, vos boutiques et leurs données (articles, ventes, clients, stocks, équipe), vos notifications et vos appareils enregistrés. Une sauvegarde technique de la suppression est conservée par l’Éditeur, pour pouvoir corriger une erreur ; elle n’est accessible qu’à lui. La preuve d’acceptation des conditions est gardée comme indiqué à l’article 5.</p>
<p>La suppression est définitive : exportez ce que vous souhaitez garder avant de la demander.</p>

<h2 id="mineurs">9. Mineurs</h2>
<p>Le Service est destiné aux professionnels. Il ne s’adresse pas aux enfants et ne collecte pas sciemment leurs données.</p>

<h2 id="modifications">10. Modifications</h2>
<p>Cette politique peut évoluer. Chaque version est datée sur cette page ; un changement important est présenté dans l’application et doit être accepté, comme les <a href="{{ route('conditions') }}">conditions d’utilisation</a>.</p>

</x-juridique.page>
