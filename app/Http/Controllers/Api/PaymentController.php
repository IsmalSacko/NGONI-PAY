<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Business;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Requests\Payment\StorePaymentRequest;

class PaymentController extends Controller
{
    public function store(StorePaymentRequest $request, Business $business)
    {
        // Client existant
        if ($request->filled('client_id')) {
            $client = Client::where('business_id', $business->id)
                ->findOrFail($request->client_id);
        }
        // Client spontané
        else {
            $client = Client::firstOrCreate(
                ['phone' => $this->normalizePhone($request->phone), 'business_id' => $business->id,],
                ['name' => $request->name ?? 'Client spontané',]
            );
        }

        // Met à jour le nom du client si fourni et différent
        if ($request->filled('name') && $client->name !== $request->name) {
            $client->update(['name' => $request->name]);
        }

        // Defensive check: ensure 'method' exists in input. If missing, return a validation error
        $method = $request->input('method');
        if (empty($method)) {
            throw ValidationException::withMessages([
                'method' => ["Le champ 'method' est requis."],
            ]);
        }

        $payment = Payment::create([
            'business_id' => $business->id,
            'client_id' => $client->id,
            'user_id' => $request->user()->id,
            'amount' => $request->amount,
            'currency' => $request->currency ?? 'XOF',
            'method' => $method,
            'transaction_ref' => (string) Str::uuid(),
            'status' => 'pending',
        ]);

        return new PaymentResource($payment->load('client'));
    }

    public function index(Request $request, Business $business)
    {
        // 🔐 Vérifie que l'utilisateur est propriétaire / autorisé
        $this->authorizeOwner($business, $request);

        $payments = Payment::with('client')
            ->where('business_id', $business->id)
            ->latest()
            ->get();

        return PaymentResource::collection($payments);
    }


    public function show(Request $request, Business $business, Payment $payment)
    {
        // Ensure the payment belongs to the requested business
        if ($payment->business_id !== $business->id) {
            abort(404);
        }

        $payment->load(['client:id,name,phone']);

        return new PaymentResource($payment);
    }

    private function authorizeOwner(Business $business, Request $request): void
    {
        if ($business->owner_id !== $request->user()->id) {
            abort(403, 'Accès interdit');
        }
    }


    private function normalizePhone(string $phone): string
    {
        // Supprimer espaces
        $phone = str_replace(' ', '', $phone);

        // Si déjà au format +223XXXXXXXX
        if (str_starts_with($phone, '+223')) {
            return $phone;
        }

        // Si envoyé sans indicatif (8 chiffres)
        if (preg_match('/^\d{8}$/', $phone)) {
            return '+223' . $phone;
        }

        // Si envoyé comme 223XXXXXXXX
        if (preg_match('/^223\d{8}$/', $phone)) {
            return '+' . $phone;
        }

        // Sinon, on retourne tel quel (ou on peut lever une erreur)
        return $phone;
    }
}
