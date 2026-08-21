<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;

/**
 * Notifications du commerçant connecté.
 *
 * L'application tient une liste locale de ce qu'elle fait elle-même — un
 * paiement enregistré, une facture émise. Elle relève ici ce qu'elle ne peut pas
 * savoir : les décisions prises par l'exploitant.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $notifications->map(fn (AppNotification $notification) => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'body' => $notification->body,
                'route' => $notification->route,
                'is_read' => $notification->read_at !== null,
                'created_at' => $notification->created_at?->toIso8601String(),
            ]),
            'unread_count' => $notifications->whereNull('read_at')->count(),
        ]);
    }

    public function markAsRead(Request $request, AppNotification $notification)
    {
        // Une notification ne se lit que par son destinataire.
        abort_unless($notification->user_id === $request->user()->id, 404);

        $notification->update(['read_at' => now()]);

        return response()->json(['message' => 'Notification lue.']);
    }

    public function markAllAsRead(Request $request)
    {
        AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifications lues.']);
    }
}
