<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Annonces rédigées depuis la console, et leur programmation.
 *
 * Les campagnes existantes portaient une classe de courriel figée dans le code :
 * annoncer une mise à jour demandait un déploiement. L'exploitant rédige
 * désormais son annonce, choisit ses destinataires, et l'envoie sur-le-champ ou
 * la programme.
 *
 * Les colonnes sont ajoutées à `campaigns` plutôt que dans une table à part : une
 * annonce est une campagne, et le suivi d'envoi par destinataire
 * (`campaign_sends`) sert aux deux — il évite qu'un même utilisateur soit
 * relancé deux fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // `legacy` : les campagnes dont le contenu vit dans une classe.
            $table->string('type', 30)->default('legacy')->after('name');
            $table->string('subject', 200)->nullable()->after('type');
            $table->text('message')->nullable()->after('subject');

            // Version annoncée et lien de téléchargement, pour une mise à jour.
            $table->string('version', 20)->nullable()->after('message');
            $table->string('store_url')->nullable()->after('version');

            // draft | scheduled | sending | sent
            $table->string('status', 20)->default('draft')->after('store_url');
            $table->timestamp('scheduled_at')->nullable()->after('status');

            // all | selected
            $table->string('audience', 20)->default('all')->after('scheduled_at');

            // La classe de courriel devient facultative : une annonce rédigée n'en
            // a pas besoin.
            $table->string('mailable_class')->nullable()->change();
        });

        // Les campagnes déjà en base gardent leur comportement.
        DB::table('campaigns')->update(['type' => 'legacy', 'status' => 'sent']);

        Schema::create('campaign_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_targets');

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'type', 'subject', 'message', 'version', 'store_url',
                'status', 'scheduled_at', 'audience',
            ]);
        });
    }
};
