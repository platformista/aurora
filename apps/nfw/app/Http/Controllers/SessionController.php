<?php

namespace App\Http\Controllers;

use App\Models\Incaricata;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SessionController extends Controller
{
    public function show(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $sellers = Incaricata::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('code', 'like', "%{$q}%")
                ->orWhere('last_name', 'like', "%{$q}%")
                ->orWhere('city', 'like', "%{$q}%")))
            ->withCount('downline')
            ->orderBy('depth')->orderBy('id')
            ->limit(24)
            ->get()
            ->map(fn (Incaricata $s) => $s->summary() + ['depth' => $s->depth, 'downline' => $s->downline_count]);

        return Inertia::render('Accedi', ['sellers' => $sellers, 'q' => $q]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['seller_id' => ['required', 'integer', 'exists:incaricate,id']]);
        $request->session()->put('seller_id', (int) $data['seller_id']);

        return redirect()->route('orders.create');
    }

    public function destroy(Request $request)
    {
        $request->session()->forget('seller_id');

        return redirect()->route('login');
    }
}
