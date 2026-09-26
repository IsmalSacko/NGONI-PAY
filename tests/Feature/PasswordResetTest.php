<?php

use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Mail\PasswordResetCodeMail;
use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();

    $this->commercant = User::factory()->create([
        'phone' => '+22376008201',
        'email' => 'boutique@example.com',
        'password' => Hash::make('ancien123'),
        'role' => 'owner',
    ]);
});

function demanderCode(string $phone = '76008201')
{
    return test()->postJson('/api/auth/forgot-password', ['phone' => $phone, 'country' => 'ML']);
}

function codeEnvoye(): string
{
    $code = null;
    Mail::assertSent(PasswordResetCodeMail::class, function ($mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return $code;
}

it('ne change plus le mot de passe sur simple numéro de téléphone', function () {
    // L'attaque d'avant : connaître le numéro suffisait.
    $this->postJson('/api/auth/forgot-password', [
        'phone' => '76008201',
        'new_password' => 'pirate123',
        'new_password_confirmation' => 'pirate123',
    ])->assertStatus(422)->assertJsonPath('code', 'RESET_REQUIRES_CODE');

    expect(Hash::check('ancien123', $this->commercant->fresh()->password))->toBeTrue();
});

it('envoie un code par e-mail, puis change le mot de passe avec ce code', function () {
    $jeton = $this->commercant->createToken('api')->plainTextToken;

    demanderCode()->assertOk()->assertJsonPath('code', 'RESET_CODE_REQUESTED');
    $code = codeEnvoye();

    expect($code)->toMatch('/^\d{6}$/')
        ->and(PasswordResetCode::first()->code_hash)->not->toBe($code);

    $this->postJson('/api/auth/reset-password', [
        'phone' => '76008201',
        'country' => 'ML',
        'code' => $code,
        'new_password' => 'nouveau123',
        'new_password_confirmation' => 'nouveau123',
    ])->assertOk();

    expect(Hash::check('nouveau123', $this->commercant->fresh()->password))->toBeTrue()
        ->and($this->commercant->tokens()->count())->toBe(0)
        ->and(PasswordResetCode::count())->toBe(0);
});

it('refuse un mauvais code, et bloque après cinq essais', function () {
    demanderCode();
    $code = codeEnvoye();
    $faux = $code === '111111' ? '222222' : '111111';

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/auth/reset-password', [
            'phone' => '76008201', 'country' => 'ML', 'code' => $faux,
            'new_password' => 'nouveau123', 'new_password_confirmation' => 'nouveau123',
        ])->assertStatus(422)->assertJsonPath('code', 'RESET_CODE_INVALID');
    }

    // Même le bon code ne passe plus : il faut en redemander un.
    $this->postJson('/api/auth/reset-password', [
        'phone' => '76008201', 'country' => 'ML', 'code' => $code,
        'new_password' => 'nouveau123', 'new_password_confirmation' => 'nouveau123',
    ])->assertStatus(422);

    expect(Hash::check('ancien123', $this->commercant->fresh()->password))->toBeTrue();
});

it('refuse un code expiré', function () {
    demanderCode();
    $code = codeEnvoye();
    $this->travel(16)->minutes();

    $this->postJson('/api/auth/reset-password', [
        'phone' => '76008201', 'country' => 'ML', 'code' => $code,
        'new_password' => 'nouveau123', 'new_password_confirmation' => 'nouveau123',
    ])->assertStatus(422);
});

it('répond pareil que le numéro existe ou non, et renvoie vers le support sans e-mail', function () {
    $inconnu = demanderCode('70000009')->assertOk()->json();
    $sansEmail = tap(User::factory()->create(['phone' => '+22370000010', 'email' => null]));
    $reponseSansEmail = demanderCode('70000010')->assertOk()->json();

    expect($inconnu)->toEqual($reponseSansEmail)
        ->and($inconnu['support_whatsapp'])->toBe('+33605758494');

    Mail::assertNothingSent();
});

it('limite les demandes de code en rafale', function () {
    foreach (range(1, 5) as $i) {
        demanderCode()->assertOk();
    }

    demanderCode()->assertStatus(429);
});

it('laisse l’exploitant générer un mot de passe provisoire depuis la console', function () {
    $admin = User::factory()->create(['phone' => '+22370000002', 'role' => User::ROLE_SYSTEM_ADMIN]);
    $this->commercant->createToken('api');

    $composant = Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->call('resetPassword', $this->commercant->id);

    $provisoire = $composant->get('temporaryPassword');

    expect($provisoire)->toMatch('/^[a-z2-9]{8}$/')
        ->and(Hash::check($provisoire, $this->commercant->fresh()->password))->toBeTrue()
        ->and($this->commercant->tokens()->count())->toBe(0);

    $composant->assertSee($provisoire)->assertSee('wa.me/22376008201');
});
