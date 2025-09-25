<?php

namespace App\Http\Controllers;

use App\Models\Pdp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PdpController extends Controller
{
    public function index(Request $request)
    {
        $pdps = Pdp::query()
            ->where('user_id', $request->user()->id)
            ->withCount('skills')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($pdps);
    }

    public function show(Request $request, Pdp $pdp)
    {
        $this->authorizeAccess($request, $pdp);
        $pdp->load('skills');
        return response()->json($pdp);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'priority' => ['required','in:Low,Medium,High'],
            'eta' => ['nullable','string','max:255'],
            'status' => ['required','in:Planned,In Progress,Done,Blocked'],
        ]);

        $pdp = Pdp::create($data + ['user_id' => $request->user()->id]);
        return response()->json($pdp, Response::HTTP_CREATED);
    }

    public function update(Request $request, Pdp $pdp)
    {
        $this->authorizeAccess($request, $pdp);
        $data = $request->validate([
            'title' => ['sometimes','required','string','max:255'],
            'description' => ['nullable','string'],
            'priority' => ['sometimes','required','in:Low,Medium,High'],
            'eta' => ['nullable','string','max:255'],
            'status' => ['sometimes','required','in:Planned,In Progress,Done,Blocked'],
        ]);
        $pdp->update($data);
        return response()->json($pdp);
    }

    public function destroy(Request $request, Pdp $pdp)
    {
        $this->authorizeAccess($request, $pdp);
        $pdp->delete();
        return response()->noContent();
    }

    protected function authorizeAccess(Request $request, Pdp $pdp): void
    {
        abort_unless($pdp->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
    }
}
