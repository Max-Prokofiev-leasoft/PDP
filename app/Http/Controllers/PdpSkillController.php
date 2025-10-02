<?php

namespace App\Http\Controllers;

use App\Models\Pdp;
use App\Models\PdpSkill;
use App\Models\PdpSkillCriterionProgress;
use App\Services\PdpSkillService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PdpSkillController extends Controller
{
    public function __construct(private PdpSkillService $service) {}

    protected function authorizePdp(Request $request, Pdp $pdp): void
    {
        if ($pdp->user_id === $request->user()->id) return;
        abort_unless($pdp->curators()->where('user_id', $request->user()->id)->exists(), Response::HTTP_FORBIDDEN);
    }

    public function index(Request $request, Pdp $pdp)
    {
        $this->authorizePdp($request, $pdp);
        return response()->json($this->service->getSkills($pdp));
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
        $skill = $this->service->createSkill($pdp, $data);
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
        $skill = $this->service->updateSkill($skill, $data);
        return response()->json($skill);
    }

    public function updateCriterionComment(Request $request, Pdp $pdp, PdpSkill $skill, int $index)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        $payload = $request->validate([
            'comment' => ['nullable','string'],
        ]);

        $skill = $this->service->updateCriterionComment($skill, $index, $payload['comment'] ?? null);
        return response()->json($skill);
    }

    public function updateCriterionDone(Request $request, Pdp $pdp, PdpSkill $skill, int $index)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        $payload = $request->validate([
            'done' => ['required','boolean'],
        ]);

        $skill = $this->service->updateCriterionDone($skill, $index, (bool)$payload['done']);
        return response()->json($skill);
    }

    public function destroy(Request $request, Pdp $pdp, PdpSkill $skill)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);
        $this->service->deleteSkill($skill);
        return response()->noContent();
    }

    // Progress: list entries for a criterion
    public function listProgress(Request $request, Pdp $pdp, PdpSkill $skill, int $index)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        $data = $this->service->listProgress($skill, $index);
        return response()->json($data);
    }

    // Progress: add entry
    public function addProgress(Request $request, Pdp $pdp, PdpSkill $skill, int $index)
    {
        // Only PDP owner can add progress entries
        abort_unless($pdp->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'note' => ['required','string'],
        ]);

        $entry = $this->service->addProgress($skill, $index, $request->user()->id, $data['note']);
        return response()->json($entry->load('user:id,name,email'), Response::HTTP_CREATED);
    }

    public function deleteProgress(Request $request, Pdp $pdp, PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry)
    {
        // Only PDP owner can delete progress entries
        abort_unless($pdp->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        $this->service->deleteProgress($skill, $index, $entry);
        return response()->noContent();
    }

    public function approveProgress(Request $request, Pdp $pdp, PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry)
    {
        // Only curators can approve progress, owner cannot
        abort_if($pdp->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        abort_unless($pdp->curators()->where('user_id', $request->user()->id)->exists(), Response::HTTP_FORBIDDEN);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        $entry = $this->service->approveProgress($skill, $index, $entry);
        return response()->json($entry);
    }

    public function commentProgress(Request $request, Pdp $pdp, PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry)
    {
        // Only curators can leave comments; owner cannot
        abort_if($pdp->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        abort_unless($pdp->curators()->where('user_id', $request->user()->id)->exists(), Response::HTTP_FORBIDDEN);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'comment' => ['nullable','string'],
        ]);

        $entry = $this->service->setProgressCuratorComment($skill, $index, $entry, $data['comment'] ?? null);
        return response()->json($entry);
    }

    public function updateProgressNote(Request $request, Pdp $pdp, PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry)
    {
        // Only PDP owner can edit their progress entry note
        abort_unless($pdp->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'note' => ['required','string'],
        ]);

        $entry = $this->service->updateProgressNote($skill, $index, $entry, $data['note']);
        return response()->json($entry);
    }

    // Annex: document-like data with only approved progress entries
    public function annex(Request $request, Pdp $pdp)
    {
        $this->authorizePdp($request, $pdp);
        $data = $this->service->buildAnnex($pdp);
        return response()->json($data);
    }

    public function pendingApprovals(Request $request)
    {
        $userId = $request->user()->id;
        $out = $this->service->pendingApprovals($userId);
        return response()->json($out);
    }

    public function summary(Request $request, Pdp $pdp)
    {
        $this->authorizePdp($request, $pdp);
        $data = $this->service->buildSummary($pdp);
        return response()->json($data);
    }

}
