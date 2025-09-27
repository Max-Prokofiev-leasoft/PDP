<?php

namespace App\Http\Controllers;

use App\Models\Pdp;
use App\Models\PdpSkill;
use App\Models\PdpSkillCriterionProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PdpSkillController extends Controller
{
    protected function authorizePdp(Request $request, Pdp $pdp): void
    {
        if ($pdp->user_id === $request->user()->id) return;
        abort_unless($pdp->curators()->where('user_id', $request->user()->id)->exists(), Response::HTTP_FORBIDDEN);
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

    public function updateCriterionComment(Request $request, Pdp $pdp, PdpSkill $skill, int $index)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        $payload = $request->validate([
            'comment' => ['nullable','string'],
        ]);

        $raw = (string)($skill->criteria ?? '');
        $items = [];
        // Try parse JSON new format
        try {
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($parsed)) {
                foreach ($parsed as $it) {
                    if (is_string($it)) {
                        $items[] = ['text' => trim($it), 'done' => false];
                    } elseif (is_array($it)) {
                        $text = isset($it['text']) ? trim((string)$it['text']) : '';
                        if ($text !== '') {
                            $comment = isset($it['comment']) && trim((string)$it['comment']) !== '' ? (string)$it['comment'] : null;
                            $done = isset($it['done']) ? (bool)$it['done'] : false;
                            $items[] = ['text' => $text, 'comment' => $comment, 'done' => $done];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // legacy format or invalid JSON -> fallback below
        }
        if (empty($items)) {
            // Legacy: split by new lines / commas / semicolons
            $parts = array_filter(array_map('trim', preg_split('/[\n,;]+/', $raw) ?: []));
            foreach ($parts as $t) {
                $items[] = ['text' => $t, 'done' => false];
            }
        }

        if ($index < 0 || $index >= count($items)) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $items[$index]['comment'] = isset($payload['comment']) && $payload['comment'] !== '' ? $payload['comment'] : null;

        $skill->criteria = json_encode($items, JSON_UNESCAPED_UNICODE);
        $skill->save();

        return response()->json($skill->refresh());
    }

    public function updateCriterionDone(Request $request, Pdp $pdp, PdpSkill $skill, int $index)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        $payload = $request->validate([
            'done' => ['required','boolean'],
        ]);

        $raw = (string)($skill->criteria ?? '');
        $items = [];
        try {
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($parsed)) {
                foreach ($parsed as $it) {
                    if (is_string($it)) {
                        $items[] = ['text' => trim($it), 'done' => false];
                    } elseif (is_array($it)) {
                        $text = isset($it['text']) ? trim((string)$it['text']) : '';
                        if ($text !== '') {
                            $comment = isset($it['comment']) && trim((string)$it['comment']) !== '' ? (string)$it['comment'] : null;
                            $done = isset($it['done']) ? (bool)$it['done'] : false;
                            $items[] = ['text' => $text, 'comment' => $comment, 'done' => $done];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // legacy format or invalid JSON -> fallback below
        }
        if (empty($items)) {
            $parts = array_filter(array_map('trim', preg_split('/[\n,;]+/', $raw) ?: []));
            foreach ($parts as $t) {
                $items[] = ['text' => $t, 'done' => false];
            }
        }

        if ($index < 0 || $index >= count($items)) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $items[$index]['done'] = (bool)$payload['done'];

        // Auto-update skill status based on criteria completion
        $allDone = !empty($items);
        if ($allDone) {
            foreach ($items as $it) {
                if (empty($it['done'])) { $allDone = false; break; }
            }
            // Only adjust status if there is at least one criterion
            $skill->status = $allDone ? 'Done' : 'In Progress';
        }

        $skill->criteria = json_encode($items, JSON_UNESCAPED_UNICODE);
        $skill->save();

        return response()->json($skill->refresh());
    }

    public function destroy(Request $request, Pdp $pdp, PdpSkill $skill)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);
        $skill->delete();
        return response()->noContent();
    }

    // Progress: list entries for a criterion
    public function listProgress(Request $request, Pdp $pdp, PdpSkill $skill, int $index)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        if ($index < 0) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        if ($index >= count($items)) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $entries = PdpSkillCriterionProgress::query()
            ->where('pdp_skill_id', $skill->id)
            ->where('criterion_index', $index)
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'criterion' => $items[$index] ?? null,
            'entries' => $entries,
        ]);
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

        if ($index < 0) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        if ($index >= count($items)) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $entry = PdpSkillCriterionProgress::create([
            'pdp_skill_id' => $skill->id,
            'criterion_index' => $index,
            'user_id' => $request->user()->id,
            'note' => $data['note'],
            'approved' => false,
        ]);

        return response()->json($entry->load('user:id,name,email'), Response::HTTP_CREATED);
    }

    public function deleteProgress(Request $request, Pdp $pdp, PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry)
    {
        // Only PDP owner can delete progress entries
        abort_unless($pdp->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        // Validate index exists in current criteria set
        if ($index < 0) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        if ($index >= count($items)) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Ensure the progress entry belongs to this skill and criterion
        if ($entry->pdp_skill_id !== $skill->id || $entry->criterion_index !== $index) {
            return response()->json(['message' => 'Entry does not belong to the specified criterion'], Response::HTTP_FORBIDDEN);
        }

        $entry->delete();
        return response()->noContent();
    }

    public function approveProgress(Request $request, Pdp $pdp, PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry)
    {
        // Only curators can approve progress, owner cannot
        abort_if($pdp->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        abort_unless($pdp->curators()->where('user_id', $request->user()->id)->exists(), Response::HTTP_FORBIDDEN);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        // Validate criterion index exists
        if ($index < 0) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        if ($index >= count($items)) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Ensure the entry belongs to this skill and criterion
        if ($entry->pdp_skill_id !== $skill->id || $entry->criterion_index !== $index) {
            return response()->json(['message' => 'Entry does not belong to the specified criterion'], Response::HTTP_FORBIDDEN);
        }

        $entry->approved = true;
        $entry->save();

        return response()->json($entry->fresh()->load('user:id,name,email'));
    }

    // Annex: document-like data with only approved progress entries
    public function annex(Request $request, Pdp $pdp)
    {
        $this->authorizePdp($request, $pdp);

        $skills = $pdp->skills()->orderBy('order_column')->orderBy('id')->get();
        $out = [];
        foreach ($skills as $s) {
            $items = $this->parseCriteriaItems((string)($s->criteria ?? ''));
            $criteria = [];
            foreach ($items as $i => $item) {
                $entries = PdpSkillCriterionProgress::query()
                    ->where('pdp_skill_id', $s->id)
                    ->where('criterion_index', $i)
                    ->where('approved', true)
                    ->with('user:id,name,email')
                    ->orderBy('created_at')
                    ->get();
                $criteria[] = [
                    'index' => $i,
                    'text' => $item['text'] ?? '',
                    'comment' => $item['comment'] ?? null,
                    'entries' => $entries,
                ];
            }
            $out[] = [
                'id' => $s->id,
                'skill' => $s->skill,
                'description' => $s->description,
                'criteria' => $criteria,
                'priority' => $s->priority,
                'eta' => $s->eta,
                'status' => $s->status,
            ];
        }

        return response()->json([
            'pdp' => $pdp->only(['id','title','description','priority','eta','status']),
            'skills' => $out,
        ]);
    }

    public function pendingApprovals(Request $request)
    {
        // List pending progress entries for which the current user is a curator
        $userId = $request->user()->id;

        $query = PdpSkillCriterionProgress::query()
            ->where('approved', false)
            ->join('pdp_skills', 'pdp_skills.id', '=', 'pdp_skill_criterion_progress.pdp_skill_id')
            ->join('pdps', 'pdps.id', '=', 'pdp_skills.pdp_id')
            ->join('pdp_curators', function ($j) use ($userId) {
                $j->on('pdp_curators.pdp_id', '=', 'pdps.id')
                  ->where('pdp_curators.user_id', '=', $userId);
            })
            ->leftJoin('users', 'users.id', '=', 'pdps.user_id') // owner
            ->select([
                'pdp_skill_criterion_progress.id as id',
                'pdp_skill_criterion_progress.pdp_skill_id as skill_id',
                'pdp_skill_criterion_progress.criterion_index as criterion_index',
                'pdp_skill_criterion_progress.user_id as author_id',
                'pdp_skill_criterion_progress.note as note',
                'pdp_skill_criterion_progress.created_at as created_at',
                'pdp_skills.pdp_id as pdp_id',
                'pdp_skills.skill as skill',
                'pdp_skills.criteria as criteria',
                'pdps.title as pdp_title',
                'users.id as owner_id',
                'users.name as owner_name',
                'users.email as owner_email',
            ])
            ->orderBy('pdp_skill_criterion_progress.created_at');

        $rows = $query->limit(100)->get();

        $out = [];
        foreach ($rows as $row) {
            // Parse criterion text
            $criterionText = '';
            $items = $this->parseCriteriaItems((string)($row->criteria ?? ''));
            $idx = (int)$row->criterion_index;
            if ($idx >= 0 && $idx < count($items)) {
                $criterionText = (string)($items[$idx]['text'] ?? '');
            }

            $out[] = [
                'id' => (int)$row->id,
                'pdp' => [
                    'id' => (int)$row->pdp_id,
                    'title' => (string)$row->pdp_title,
                ],
                'skill' => [
                    'id' => (int)$row->skill_id,
                    'name' => (string)$row->skill,
                ],
                'criterion' => [
                    'index' => $idx,
                    'text' => $criterionText,
                ],
                'note' => (string)$row->note,
                'created_at' => (string)$row->created_at,
                'owner' => [
                    'id' => (int)($row->owner_id ?? 0),
                    'name' => $row->owner_name,
                    'email' => $row->owner_email,
                ],
            ];
        }

        return response()->json($out);
    }

    public function summary(Request $request, Pdp $pdp)
    {
        // Only accessible by PDP owner or its curators
        $this->authorizePdp($request, $pdp);

        // Load skills and count total criteria
        $skills = $pdp->skills()->get(['id','skill','criteria']);
        $totalCriteria = 0;
        $doneAware = false;
        $doneCount = 0;
        $skillsOut = [];
        foreach ($skills as $s) {
            $items = $this->parseCriteriaItems((string)($s->criteria ?? ''));
            $skillTotal = count($items);
            $totalCriteria += $skillTotal;

            $skillDoneAware = false;
            $skillDoneCount = 0;
            foreach ($items as $it) {
                if (array_key_exists('done', $it)) {
                    $doneAware = true; // at least one skill has done flags
                    $skillDoneAware = true;
                    if (!empty($it['done'])) { $skillDoneCount++; }
                }
            }

            if ($skillDoneAware) {
                $skillApproved = $skillDoneCount;
            } else {
                // Fallback: count distinct approved criterion indexes for this skill
                $skillApproved = (int)\App\Models\PdpSkillCriterionProgress::query()
                    ->where('pdp_skill_id', $s->id)
                    ->where('approved', true)
                    ->distinct()
                    ->get(['criterion_index'])
                    ->count();
            }

            if ($skillDoneAware) { $doneCount += $skillDoneCount; }

            $skillsOut[] = [
                'id' => (int)$s->id,
                'skill' => (string)$s->skill,
                'totalCriteria' => (int)$skillTotal,
                'approvedCount' => (int)$skillApproved,
                'pendingCount' => max(0, (int)$skillTotal - (int)$skillApproved),
            ];
        }

        // Default: count approved distinct criteria (skill_id + criterion_index)
        $approvedDistinct = \App\Models\PdpSkillCriterionProgress::query()
            ->join('pdp_skills', 'pdp_skills.id', '=', 'pdp_skill_criterion_progress.pdp_skill_id')
            ->where('pdp_skills.pdp_id', $pdp->id)
            ->where('pdp_skill_criterion_progress.approved', true)
            ->distinct()
            ->get(['pdp_skill_criterion_progress.pdp_skill_id', 'pdp_skill_criterion_progress.criterion_index'])
            ->count();

        // If any criteria explicitly track done flags, base counts on them; else use approvals
        if ($doneAware) {
            $approvedCount = (int)$doneCount;
        } else {
            $approvedCount = (int)$approvedDistinct;
        }
        $pendingCount = max(0, (int)$totalCriteria - $approvedCount);

        // Timezone and period for last 30 days
        $tz = new \DateTimeZone(config('app.timezone', 'UTC'));
        $today = new \DateTime('now', $tz);
        $today->setTime(0, 0, 0);
        $start = (clone $today)->modify('-29 days');
        $endExclusive = (clone $today)->modify('+1 day');

        // Compute approval durations (in hours) for entries approved in last 30 days
        $approvedRows = \App\Models\PdpSkillCriterionProgress::query()
            ->join('pdp_skills', 'pdp_skills.id', '=', 'pdp_skill_criterion_progress.pdp_skill_id')
            ->where('pdp_skills.pdp_id', $pdp->id)
            ->where('pdp_skill_criterion_progress.approved', true)
            ->whereBetween('pdp_skill_criterion_progress.updated_at', [
                $start->format('Y-m-d 00:00:00'),
                $endExclusive->format('Y-m-d 00:00:00'),
            ])
            ->get(['pdp_skill_criterion_progress.created_at','pdp_skill_criterion_progress.updated_at']);

        $durations = [];
        foreach ($approvedRows as $row) {
            try {
                $created = new \DateTime((string)$row->created_at, $tz);
                $updated = new \DateTime((string)$row->updated_at, $tz);
                $hours = max(0, ($updated->getTimestamp() - $created->getTimestamp()) / 3600);
                $durations[] = $hours;
            } catch (\Throwable $e) {
                // skip invalid rows
            }
        }

        $avgApproveHours = null;
        $medianApproveHours = null;
        if (!empty($durations)) {
            $sum = array_sum($durations);
            $avgApproveHours = $sum / count($durations);
            sort($durations);
            $n = count($durations);
            if ($n % 2 === 1) {
                $medianApproveHours = $durations[intval($n/2)];
            } else {
                $medianApproveHours = ($durations[$n/2 - 1] + $durations[$n/2]) / 2;
            }
            // Round to 1 decimal for readability
            $avgApproveHours = round($avgApproveHours, 1);
            $medianApproveHours = round($medianApproveHours, 1);
        }

        // Build wins series for last 30 days (by approval date)
        $rows = \App\Models\PdpSkillCriterionProgress::query()
            ->join('pdp_skills', 'pdp_skills.id', '=', 'pdp_skill_criterion_progress.pdp_skill_id')
            ->where('pdp_skills.pdp_id', $pdp->id)
            ->where('pdp_skill_criterion_progress.approved', true)
            ->whereBetween('pdp_skill_criterion_progress.updated_at', [
                $start->format('Y-m-d 00:00:00'),
                $endExclusive->format('Y-m-d 00:00:00'),
            ])
            ->selectRaw('date(pdp_skill_criterion_progress.updated_at) as d, count(*) as c')
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r->d] = (int)$r->c;
        }

        $wins = [];
        $cursor = clone $start;
        for ($i = 0; $i < 30; $i++) {
            $key = $cursor->format('Y-m-d');
            $wins[] = ['date' => $key, 'count' => $map[$key] ?? 0];
            $cursor->modify('+1 day');
        }

        return response()->json([
            'totalCriteria' => (int)$totalCriteria,
            'approvedCount' => $approvedCount,
            'pendingCount' => $pendingCount,
            'avgApproveHours' => $avgApproveHours,
            'medianApproveHours' => $medianApproveHours,
            'wins' => $wins,
            'skills' => $skillsOut,
        ]);
    }


    // Helper to parse criteria consistently
    protected function parseCriteriaItems(string $raw): array
    {
        $items = [];
        try {
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($parsed)) {
                foreach ($parsed as $it) {
                    if (is_string($it)) {
                        $items[] = ['text' => trim($it)];
                    } elseif (is_array($it)) {
                        $text = isset($it['text']) ? trim((string)$it['text']) : '';
                        if ($text !== '') {
                            $comment = isset($it['comment']) && trim((string)$it['comment']) !== '' ? (string)$it['comment'] : null;
                            $done = isset($it['done']) ? (bool)$it['done'] : null; // preserve done if present
                            $row = ['text' => $text, 'comment' => $comment];
                            if ($done !== null) { $row['done'] = $done; }
                            $items[] = $row;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        if (empty($items)) {
            $parts = array_filter(array_map('trim', preg_split('/[\n,;]+/', $raw) ?: []));
            foreach ($parts as $t) {
                $items[] = ['text' => $t];
            }
        }
        return $items;
    }
}
