<?php

namespace App\Http\Middleware;

use App\Models\Incaricata;
use Closure;
use Illuminate\Http\Request;

/** Demo "login": the visitor picks which seller they are. No passwords. */
class RequireSeller
{
    public function handle(Request $request, Closure $next)
    {
        $seller = Incaricata::find($request->session()->get('seller_id'));
        if (! $seller) {
            return redirect()->route('login');
        }
        $request->attributes->set('seller', $seller);

        return $next($request);
    }
}
