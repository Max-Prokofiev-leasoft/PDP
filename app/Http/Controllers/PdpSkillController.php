<?php

namespace App\Http\Controllers;

use App\Models\Pdp;
use App\Models\PdpSkill;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PdpSkillController extends Controller
{
    protected function authorizePdp(Request $request, Pdp $pdp): void
    {
        abort_unless($pdp->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
    }

    public function index(Request $request, Pdp $pdp)
    {
        $this->authorizePdp($request, $pdp);
        return response()->json($pdp->skills()->get());
    }

    public function store(Request $request, Pdp $pdp)
    {
        $this->authorizePdp($request, $pdp);
        $data = $request->validate([
            'skill' => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'criteria' => ['nullable','string'],
            'priority' => ['required','in:Low,Medium,High'],
            'eta' => ['nullable','string','max:255'],
            'status' => ['required','in:Planned,In Progress,Done,Blocked'],
            'order_column' => ['nullable','integer','min:0'],
        ]);
        $skill = $pdp->skills()->create($data + [
            'order_column' => $data['order_column'] ?? ($pdp->skills()->max('order_column') + 1),
        ]);
        return response()->json($skill, Response::HTTP_CREATED);
    }

    public function update(Request $request, Pdp $pdp, PdpSkill $skill)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'skill' => ['sometimes','required','string','max:255'],
            'description' => ['nullable','string'],
            'criteria' => ['nullable','string'],
            'priority' => ['sometimes','required','in:Low,Medium,High'],
            'eta' => ['nullable','string','max:255'],
            'status' => ['sometimes','required','in:Planned,In Progress,Done,Blocked'],
            'order_column' => ['nullable','integer','min:0'],
        ]);
        $skill->update($data);
        return response()->json($skill);
    }

    public function destroy(Request $request, Pdp $pdp, PdpSkill $skill)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);
        $skill->delete();
        return response()->noContent();
    }
}
