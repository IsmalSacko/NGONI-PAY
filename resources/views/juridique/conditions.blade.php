@php
    // Abonnement à vie : tenu dans la console (Plans et tarifs), comme sur la vitrine.
    $aVie = \App\Models\Plan::offreAVie();
    // « 1er septembre 2026 », « 28 février 2027 ».
    $date = function (string $d): string {
        $c = \Illuminate\Support\Carbon::parse($d)->locale('fr');

        return ($c->day === 1 ? '1er' : $c->day).' '.$c->isoFormat('MMMM YYYY');
    };
    $montant = fn (int $m) => number_format($m, 0, ',', ' ').' F CFA';
    $essai = (int) (\App\Models\Plan::parCode('essai')?->jours_essai ?? 7);
@endphp
<x-juridique.page titre="Conditions générales d’utilisation et de vente" actif="conditions" :canonique="route('conditions')">

<div class="encadre">
    <p><strong>En bref.</strong> Ngoni Caisse est un logiciel de caisse et de gestion pour les commerçants, édité par IsmaelDev. Vous pouvez l’essayer gratuitement {{ $essai }} jours, puis vous abonner. Vous restez responsable de votre activité, de vos prix et de vos obligations fiscales ; nous sommes responsables du bon fonctionnement du logiciel et de la protection de vos données. Ce résumé ne remplace pas le texte ci-dessous.</p>
</div>

<h2 id="objet">1. Objet et acceptation</h2>
<p>Les présentes conditions générales d’utilisation et de vente (les « Conditions ») régissent l’accès et l’utilisation de l’application mobile, de l’application web et du back-office Ngoni Caisse (ensemble, le « Service »), ainsi que la souscription des abonnements.</p>
<p>Le Service est édité par <strong>Ismaila SACKO</strong>, entrepreneur individuel exerçant sous le nom commercial <strong>IsmaelDev</strong>, immatriculé au RCS de Lyon sous le numéro 108 559 998, dont l’établissement est situé 18 avenue Maurice Thorez, 69200 Vénissieux (France) (l’« Éditeur »). Voir les <a href="{{ route('mentions-legales') }}">mentions légales</a>.</p>
<p>La création d’un compte suppose l’acceptation expresse des Conditions et de la <a href="{{ route('confidentialite') }}">politique de confidentialité</a>, par une case à cocher. Chaque acceptation est enregistrée (version, date, heure, appareil). Sans cette acceptation, le Service ne peut pas être utilisé.</p>

<h2 id="utilisateurs">2. À qui s’adresse le Service</h2>
<p>Le Service est destiné aux <strong>professionnels</strong> : commerçants, artisans, prestataires, et les membres de leur équipe. L’utilisateur qui crée une boutique (le « Titulaire ») déclare agir pour les besoins de son activité professionnelle, être majeur et avoir la capacité de s’engager.</p>
<p>Le Titulaire peut ajouter des membres à son équipe (administrateurs, gérants, caissiers). Il est responsable des accès qu’il leur donne et de l’usage qu’ils font du Service.</p>

<h2 id="compte">3. Compte et sécurité</h2>
<ul>
    <li>Le compte est lié à un numéro de téléphone. Les informations fournies doivent être exactes et tenues à jour.</li>
    <li>Le mot de passe est personnel et confidentiel. Toute action faite avec un compte est réputée faite par son titulaire, sauf s’il signale sans délai une utilisation frauduleuse.</li>
    <li>Le Titulaire peut retirer un membre de son équipe ou supprimer son compte. Les ventes déjà enregistrées gardent le nom de leur caissier : un ticket ou une facture ne doit jamais perdre ses mentions.</li>
</ul>

<h2 id="service">4. Description du Service</h2>
<p>Le Service permet notamment : l’encaissement des ventes (y compris hors connexion, avec envoi au retour du réseau), l’édition de tickets et de factures, la gestion du catalogue, des stocks, des clients, des achats et de l’équipe, et le suivi de l’activité. Ses fonctions peuvent évoluer, être améliorées ou remplacées.</p>
<p>Les fonctions disponibles dépendent du plan souscrit (voir article 6). La liste à jour figure sur le <a href="{{ route('vitrine') }}#tarifs">site</a> et dans l’application.</p>

<h2 id="responsabilites-utilisateur">5. Vos responsabilités</h2>
<ul>
    <li><strong>Vos prix et vos taxes.</strong> Les prix sont saisis toutes taxes comprises (TTC) par l’utilisateur, ainsi que les taux de TVA ou de taxe de chaque article. Le Service calcule les montants à partir de ces informations ; leur exactitude relève de l’utilisateur.</li>
    <li><strong>Vos obligations fiscales et comptables.</strong> L’utilisateur reste seul responsable du respect des lois de son pays : déclarations, mentions obligatoires sur ses tickets et factures (identifiant fiscal, registre de commerce…), conservation de ses pièces. Le Service est un outil d’aide à la gestion ; il ne remplace ni un comptable ni un conseil fiscal.</li>
    <li><strong>Logiciel non certifié en France.</strong> Le Service n’est pas un logiciel de caisse certifié au sens de la réglementation française (article 286, I-3° bis du Code général des impôts). Il n’est pas destiné aux commerçants qui y sont soumis.</li>
    <li><strong>Usage loyal.</strong> Il est interdit d’utiliser le Service pour une activité illicite, d’enregistrer de fausses ventes ou de faux paiements, de tenter d’accéder aux données d’autrui ou de perturber le Service.</li>
    <li><strong>Vos données.</strong> L’utilisateur est responsable des informations qu’il enregistre, notamment celles de ses propres clients (voir la <a href="{{ route('confidentialite') }}">politique de confidentialité</a>).</li>
</ul>

<h2 id="tarifs">6. Essai, abonnements et tarifs</h2>
<h3>6.1 Essai gratuit</h3>
<p>Toute nouvelle boutique bénéficie d’un essai gratuit de {{ $essai }} jours avec toutes les fonctions, sans engagement et sans moyen de paiement à fournir. Un rappel est envoyé avant la fin de l’essai.</p>
<p>À la fin de l’essai, sans abonnement, la caisse reste ouverte : l’utilisateur peut encore encaisser les articles de son catalogue et imprimer ses tickets. En revanche, plus rien ne se modifie (articles, prix, stocks, clients, équipe) et les ventes hors catalogue s’arrêtent. Les données restent consultables.</p>
<h3>6.2 Abonnements</h3>
<p>Les plans (Basic, Pro…), leurs limites et leurs prix sont ceux affichés sur le site et dans l’application au moment de la souscription. Les prix sont exprimés en francs CFA, nets. Les abonnements sont proposés au mois, au trimestre, au semestre ou à l’année.</p>
<p>Le paiement se fait par Orange Money, Wave, Moov Money, espèces ou virement. L’utilisateur envoie la preuve de paiement depuis l’application ; l’abonnement est activé à réception et vérification du paiement. <strong>Il n’y a pas de renouvellement automatique</strong> : l’abonnement prend fin à son échéance, sauf nouveau paiement.</p>
<h3>6.3 Évolution des tarifs</h3>
<p>L’Éditeur peut faire évoluer ses plans et ses prix. Une hausse est annoncée au moins <strong>30 jours à l’avance</strong> (notification dans l’application ou message). Elle ne s’applique jamais à une période déjà payée : elle vaut à partir du renouvellement suivant. Les demandes d’abonnement déjà déposées gardent leur montant.</p>
<h3 id="a-vie">6.4 Abonnement à vie (offre de lancement)</h3>
@if ($aVie)
<p>Un abonnement « à vie » est proposé pour toute souscription faite @if ($aVie['debut'] && $aVie['fin'])<strong>du {{ $date($aVie['debut']->toDateString()) }} au {{ $date($aVie['fin']->toDateString()) }} inclus</strong>@else pendant la durée de l’offre @endif : {{ collect($aVie['offres'])->map(fn ($o) => $o['nom'].' à vie ('.$montant($o['prix']).')')->implode(' et ') }}, payés une seule fois. Après cette date, l’offre n’est plus proposée ; les abonnements à vie déjà souscrits restent valables.</p>
@else
<p>L’abonnement « à vie » n’est plus proposé à la souscription ; les abonnements à vie déjà souscrits restent valables.</p>
@endif
<ul>
    <li><strong>« À vie »</strong> signifie : pour toute la durée d’existence du Service Ngoni Caisse, sans nouveau paiement. Le prix payé n’est jamais augmenté.</li>
    <li>L’abonnement est attaché à <strong>un seul compte</strong>, n’est ni transférable ni cessible, et s’exerce dans les limites du plan choisi (nombre de boutiques, de membres, fonctions incluses à la souscription).</li>
    <li>Il est <strong>remboursable sous certaines conditions</strong> seulement : en cas d’impossibilité durable d’utiliser le Service du fait de l’Éditeur, non corrigée dans un délai raisonnable après signalement ; ou sur demande motivée, avec l’accord de l’Éditeur. En dehors de ces cas, il n’est pas remboursable.</li>
    <li>En cas d’<strong>arrêt définitif du Service</strong>, l’Éditeur prévient les titulaires au moins <strong>3 mois à l’avance</strong> et leur permet d’exporter leurs données pendant ce délai.</li>
    <li>Il peut être suspendu ou retiré en cas de fraude, d’usage contraire à l’article 5, ou de non-paiement (paiement annulé ou contesté).</li>
</ul>
<h3>6.5 Remboursements</h3>
<p>En dehors de l’abonnement à vie (article 6.4), une période d’abonnement commencée n’est pas remboursable, sauf erreur de l’Éditeur ou impossibilité d’utiliser le Service de son fait.</p>

<h2 id="parrainage">7. Parrainage</h2>
<p>Le titulaire d’une boutique peut recommander le Service. Le commerçant parrainé, qui saisit le code à son inscription (et seulement à ce moment-là, avec un numéro de téléphone différent de celui du parrain), reçoit des jours d’essai supplémentaires. Le parrain gagne un mois offert lorsque le parrainé paie son <strong>premier</strong> abonnement, une fois le paiement validé ; un accès offert par l’Éditeur ne compte pas, et les renouvellements non plus.</p>
<p>Les mois offerts sont plafonnés sur douze mois glissants, ne s’échangent pas contre de l’argent et ne se transfèrent pas. Ils peuvent être annulés en cas d’abus (comptes créés pour l’occasion, faux paiements). Les conditions détaillées figurent sur le <a href="{{ route('vitrine') }}#parrainage">site</a>.</p>

<h2 id="disponibilite">8. Disponibilité, données et sauvegardes</h2>
<p>L’Éditeur met en œuvre des moyens raisonnables pour que le Service soit disponible et fiable, et sauvegarde régulièrement les données. Il ne garantit pas une disponibilité permanente : des interruptions peuvent survenir pour maintenance, mise à jour, panne d’un prestataire (hébergeur, réseau, opérateur de télécommunication) ou cas de force majeure. Le mode hors connexion permet de continuer à encaisser pendant une coupure.</p>
<p>L’utilisateur peut à tout moment consulter et exporter ses ventes et rapports depuis le Service.</p>

<h2 id="responsabilite">9. Responsabilité de l’Éditeur</h2>
<p>L’Éditeur est tenu d’une <strong>obligation de moyens</strong>. Il n’est pas responsable :</p>
<ul>
    <li>des erreurs résultant d’informations saisies par l’utilisateur (prix, taux de taxe, quantités, remises, clients) ;</li>
    <li>de l’usage que l’utilisateur fait des tickets, factures et rapports, ni du respect de ses obligations légales, fiscales ou sociales ;</li>
    <li>des dommages indirects (perte de chiffre d’affaires, de clientèle, d’image) ;</li>
    <li>des interruptions dues aux réseaux, aux opérateurs, au matériel de l’utilisateur (téléphone, imprimante) ou à un cas de force majeure.</li>
</ul>
<p>Dans tous les cas, la responsabilité totale de l’Éditeur envers un utilisateur est limitée aux sommes payées par celui-ci au titre du Service au cours des douze mois précédant le fait générateur.</p>

<h2 id="suspension">10. Suspension et résiliation</h2>
<p>L’utilisateur peut cesser d’utiliser le Service à tout moment et demander la suppression de son compte (voir la <a href="{{ route('confidentialite') }}#suppression-compte">procédure</a>).</p>
<p>L’Éditeur peut suspendre ou fermer un compte, après avertissement sauf urgence, en cas de manquement grave aux Conditions : fraude, fausses ventes, faux paiements, atteinte à la sécurité du Service ou aux droits de tiers.</p>

<h2 id="propriete">11. Propriété intellectuelle</h2>
<p>Le Service, son code, ses textes, logos (dont le ticket « N » et la marque Ngoni Caisse) et visuels appartiennent à l’Éditeur. L’abonnement donne un droit d’utilisation personnel et non exclusif, pour la durée de l’abonnement ; il ne transfère aucun droit de propriété. Les données saisies par l’utilisateur restent les siennes.</p>

<h2 id="modification">12. Modification des Conditions</h2>
<p>Les Conditions peuvent évoluer, notamment avec le Service, les tarifs ou la loi. Chaque nouvelle version est datée et publiée sur cette page. Elle est présentée dans l’application à la connexion suivante et doit être acceptée pour continuer à utiliser le Service. La version acceptée et sa date sont enregistrées.</p>

<h2 id="droit">13. Droit applicable et litiges</h2>
<p>Les Conditions sont soumises au <strong>droit français</strong>. En cas de difficulté, l’utilisateur contacte d’abord l’Éditeur (voir les <a href="{{ route('mentions-legales') }}">mentions légales</a>) pour rechercher une solution amiable. À défaut d’accord dans un délai de 30 jours, tout litige entre professionnels relève de la compétence exclusive des <strong>tribunaux de Lyon</strong>.</p>
<p>Les Conditions sont rédigées en français ; en cas de traduction, la version française fait foi.</p>

</x-juridique.page>
