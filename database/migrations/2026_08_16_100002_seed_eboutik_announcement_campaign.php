<?php

use App\Mail\EboutikAnnouncementMail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('campaigns')->updateOrInsert(
            ['key' => 'eboutik-announcement'],
            [
                'name' => 'Annonce E-BOUTIK',
                'mailable_class' => EboutikAnnouncementMail::class,
                'is_recurring' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('campaigns')->where('key', 'eboutik-announcement')->delete();
    }
};
