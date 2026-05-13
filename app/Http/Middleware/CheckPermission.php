<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handles permission-based authorization for API routes.
     *
     * Super admins and employees with 'acceso_total' bypass all checks.
     * The $slug parameter is the permission slug required for the route.
     */
    public function handle(Request $request, Closure $next, string $slug): Response
    {
        $employee = $request->user();

        if (!$employee) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // Load role + permissions if not already loaded
        if (!$employee->relationLoaded('role')) {
            $employee->load('role.permissions');
        } elseif ($employee->role && !$employee->role->relationLoaded('permissions')) {
            $employee->role->load('permissions');
        }

        // Super admin role bypasses all permission checks
        if ($employee->hasRole('super_admin')) {
            return $next($request);
        }

        // 'acceso_total' permission bypasses all specific permission checks
        if ($employee->hasPermission('acceso_total')) {
            return $next($request);
        }

        // Check the specific required permission
        if (!$employee->hasPermission($slug)) {
            return response()->json([
                'message' => 'No tienes permiso para realizar esta acción.',
                'required' => $slug,
            ], 403);
        }

        return $next($request);
    }
}
