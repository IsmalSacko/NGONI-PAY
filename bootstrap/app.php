<?php

declare(strict_types=1);

use App\Http\Middleware\SetTenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => SetTenantContext::class,
            'abonnement' => \App\Http\Middleware\ExigeAbonnementActif::class,
            'plateforme' => \App\Http\Middleware\EstAdminPlateforme::class,
            'backoffice' => \App\Http\Middleware\AccesBackOffice::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);

        // Le contexte tenant doit être posé avant que le routeur ne résolve
        // les modèles de l'URL : sans cette priorité, `SubstituteBindings`
        // interrogerait le modèle avec le `TenantContext` encore vide — le
        // `BoutiqueScope`, volontairement inerte hors contexte, laisserait
        // alors `/produits/{produit}` rendre le produit de n'importe quelle
        // boutique à qui en connaît l'identifiant.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetTenantContext::class,
        );

        // Dernière activité, pour la console : l'application comme le back-office.
        $middleware->appendToGroup('api', \App\Http\Middleware\NoterPresence::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\NoterPresence::class);

        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo('connexion');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*') || ! $e->getPrevious() instanceof ModelNotFoundException) {
                return null;
            }

            return response()->json(['message' => 'Élément introuvable.'], 404);
        });
    })->create();
