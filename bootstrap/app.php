<?php

use App\Services\MovementConflictException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Story 1.5: a movement request that conflicts with existing state
        // (duplicate open movement, already decided) is a 409 Conflict — the
        // active branch association remains unchanged and the reason is audited.
        // Laravel 13 render() takes one callable; the first parameter type
        // selects which exception it handles.
        $exceptions->render(function (MovementConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        });
    })->create();
