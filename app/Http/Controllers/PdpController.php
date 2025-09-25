<?php

namespace App\Http\Controllers;

use App\Models\Pdp;
use App\Models\PdpSkill;
use App\Models\User;
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

    public function shared(Request $request)
    {
        $pdps = Pdp::query()
            ->whereHas('curators', fn($q) => $q->where('users.id', $request->user()->id))
            ->with(['user:id,name,email'])
            ->withCount('skills')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($pdps);
    }

    public function overview(Request $request)
    {
        $uid = $request->user()->id;

        $pdps = Pdp::query()
            ->where(function($q) use ($uid) {
                $q->where('user_id', $uid)
                  ->orWhereExists(function($sq) use ($uid) {
                      $sq->selectRaw('1')
                         ->from('pdp_curators')
                         ->whereColumn('pdp_curators.pdp_id', 'pdps.id')
                         ->where('pdp_curators.user_id', $uid);
                  });
            })
            ->with(['user:id,name,email'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        $out = [];
        foreach ($pdps as $pdp) {
            $skills = $pdp->skills()->get(['id','criteria']);
            $total = 0;
            $doneAwareAny = false;
            $closed = 0;

            foreach ($skills as $s) {
                $items = $this->parseCriteriaItemsForOverview((string)($s->criteria ?? ''));
                $total += count($items);

                $skillDoneAware = false;
                $skillDoneCount = 0;
                foreach ($items as $it) {
                    if (array_key_exists('done', $it)) {
                        $doneAwareAny = true;
                        $skillDoneAware = true;
                        if (!empty($it['done'])) { $skillDoneCount++; }
                    }
                }

                if ($skillDoneAware) {
                    $closed += $skillDoneCount;
                } else {
                    $skillApproved = (int)\App\Models\PdpSkillCriterionProgress::query()
                        ->where('pdp_skill_id', $s->id)
                        ->where('approved', true)
                        ->distinct()
                        ->get(['criterion_index'])
                        ->count();
                    $closed += $skillApproved;
                }
            }

            $out[] = [
                'id' => (int)$pdp->id,
                'title' => (string)$pdp->title,
                'role' => $pdp->user_id === $uid ? 'owner' : 'curator',
                'status' => (string)$pdp->status,
                'eta' => $pdp->eta,
                'totalCriteria' => (int)$total,
                'closed' => (int)$closed,
                'remaining' => max(0, (int)$total - (int)$closed),
                'updated_at' => (string)$pdp->updated_at,
                'owner' => [
                    'id' => (int)($pdp->user->id ?? 0),
                    'name' => $pdp->user->name ?? null,
                    'email' => $pdp->user->email ?? null,
                ],
            ];
        }

        return response()->json($out);
    }

    private function parseCriteriaItemsForOverview(string $raw): array
    {
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
                            $row = ['text' => $text, 'comment' => $comment, 'done' => $done];
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
                $items[] = ['text' => $t, 'done' => false];
            }
        }
        return $items;
    }

    public function assignCurator(Request $request, Pdp $pdp)
    {
        $this->authorizeAccess($request, $pdp);
        $data = $request->validate([
            'email' => ['required','email','exists:users,email'],
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();

        // Do not allow duplicate or assigning the owner redundantly
        if ($user->id !== $pdp->user_id) {
            $pdp->curators()->syncWithoutDetaching([$user->id]);
        }

        return response()->json(['status' => 'ok', 'curator' => $user->only(['id','name','email'])]);
    }

    public function curators(Request $request, Pdp $pdp)
    {
        // Only owner can list curators
        abort_unless($pdp->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        $list = $pdp->curators()->select('users.id','users.name','users.email')->orderBy('users.name')->get();
        return response()->json($list);
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
        if ($pdp->user_id === $request->user()->id) return;
        abort_unless($pdp->curators()->where('user_id', $request->user()->id)->exists(), Response::HTTP_FORBIDDEN);
    }

    public function removeCurator(Request $request, Pdp $pdp, User $user)
    {
        // Only owner can detach curators
        abort_unless($pdp->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        // Prevent removing the owner even if somehow present
        if ($user->id === $pdp->user_id) {
            return response()->json(['message' => 'Cannot remove the owner from curators'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $pdp->curators()->detach($user->id);
        return response()->noContent();
    }

    // Export PDP (template without progress) for sharing
    public function export(Request $request, Pdp $pdp)
    {
        $this->authorizeAccess($request, $pdp);
        $skills = $pdp->skills()->orderBy('order_column')->orderBy('id')->get();
        $outSkills = [];
        foreach ($skills as $s) {
            $outSkills[] = [
                'skill' => $s->skill,
                'description' => $s->description,
                'criteria' => $s->criteria,
                'priority' => $s->priority,
                'eta' => $s->eta,
                'status' => $s->status,
                'order_column' => $s->order_column,
            ];
        }
        return response()->json([
            'version' => 1,
            'pdp' => [
                'title' => $pdp->title,
                'description' => $pdp->description,
                'priority' => $pdp->priority,
                'eta' => $pdp->eta,
                'status' => $pdp->status,
            ],
            'skills' => $outSkills,
        ]);
    }

    // Import PDP (creates a copy for current user) from exported JSON
    public function import(Request $request)
    {
        $data = $request->validate([
            'version' => ['nullable','integer'],
            'pdp' => ['required','array'],
            'pdp.title' => ['required','string','max:255'],
            'pdp.description' => ['nullable','string'],
            'pdp.priority' => ['required','in:Low,Medium,High'],
            'pdp.eta' => ['nullable','string','max:255'],
            'pdp.status' => ['required','in:Planned,In Progress,Done,Blocked'],
            'skills' => ['nullable','array'],
            'skills.*.skill' => ['required','string','max:255'],
            'skills.*.description' => ['nullable','string'],
            'skills.*.criteria' => ['nullable','string'],
            'skills.*.priority' => ['required','in:Low,Medium,High'],
            'skills.*.eta' => ['nullable','string','max:255'],
            'skills.*.status' => ['required','in:Planned,In Progress,Done,Blocked'],
            'skills.*.order_column' => ['nullable','integer','min:0'],
        ]);

        $pdpPayload = $data['pdp'];
        $new = Pdp::create([
            'user_id' => $request->user()->id,
            'title' => $pdpPayload['title'],
            'description' => $pdpPayload['description'] ?? null,
            'priority' => $pdpPayload['priority'],
            'eta' => $pdpPayload['eta'] ?? null,
            'status' => $pdpPayload['status'],
        ]);

        $skills = $data['skills'] ?? [];
        $order = 0;
        foreach ($skills as $s) {
            PdpSkill::create([
                'pdp_id' => $new->id,
                'skill' => $s['skill'],
                'description' => $s['description'] ?? null,
                'criteria' => $s['criteria'] ?? null,
                'priority' => $s['priority'],
                'eta' => $s['eta'] ?? null,
                'status' => $s['status'],
                'order_column' => $s['order_column'] ?? $order,
            ]);
            $order++;
        }

        return response()->json($new->fresh()->loadCount('skills'), Response::HTTP_CREATED);
    }
}
