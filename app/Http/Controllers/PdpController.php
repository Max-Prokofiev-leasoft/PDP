<?php

namespace App\Http\Controllers;

use App\Models\Pdp;
use App\Models\PdpSkill;
use App\Models\User;
use App\Models\PdpTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

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

    // Quick users search by name or email for dropdown suggestions
    public function usersSearch(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $limit = (int) $request->query('limit', 10);
        $limit = max(1, min($limit, 20));
        $query = User::query()->select('id','name','email')->orderBy('name');
        if ($q !== '') {
            $query->where(function($w) use ($q) {
                $w->where('email', 'like', '%' . str_replace(['%','_'], ['\%','\_'], $q) . '%')
                  ->orWhere('name', 'like', '%' . str_replace(['%','_'], ['\%','\_'], $q) . '%');
            });
        }
        // Optionally restrict to company domain (uncomment if needed)
        // $query->where('email', 'like', '%@leasoft.org');
        $users = $query->limit($limit)->get();
        return response()->json($users);
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
                'eta' => null, // template: no timeline/progress
                'status' => 'Planned', // template: reset status
                'order_column' => $s->order_column,
            ];
        }
        return response()->json([
            'version' => 1,
            'pdp' => [
                'title' => $pdp->title,
                'description' => $pdp->description,
                'priority' => $pdp->priority,
                'eta' => null, // template: no timeline/progress
                'status' => 'Planned', // template: reset status
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

    // List PDP templates (DB-backed only). No completion indicators.
    public function templates(Request $request)
    {
        $list = [];
        $db = PdpTemplate::query()->where('published', true)->orderByDesc('created_at')->get();
        foreach ($db as $tpl) {
            $data = (array) $tpl->data;
            $p = (array) ($data['pdp'] ?? []);
            $skills = (array) ($data['skills'] ?? []);
            $list[] = [
                'key' => 'db-' . $tpl->id,
                'title' => $p['title'] ?? ($tpl->title ?: 'Template'),
                'description' => $p['description'] ?? $tpl->description,
                'priority' => (string)($p['priority'] ?? 'Medium'),
                'status' => (string)($p['status'] ?? 'Planned'),
                'skills_count' => count($skills),
            ];
        }
        return response()->json($list);
    }

    // Assign a template to current user: creates a new PDP with skills. Only DB-backed templates are supported.
    public function assignTemplate(Request $request, string $key)
    {
        abort_unless(Str::startsWith($key, 'db-'), Response::HTTP_NOT_FOUND);

        $id = (int) Str::after($key, 'db-');
        $record = PdpTemplate::query()->where('id', $id)->where('published', true)->first();
        abort_unless($record, Response::HTTP_NOT_FOUND);
        $tpl = (array) $record->data;
        $p = $tpl['pdp'] ?? [];

        $new = Pdp::create([
            'user_id' => $request->user()->id,
            'title' => (string)($p['title'] ?? 'PDP Template'),
            'description' => $p['description'] ?? null,
            'priority' => (string)($p['priority'] ?? 'Medium'),
            'eta' => $p['eta'] ?? null,
            'status' => (string)($p['status'] ?? 'Planned'),
        ]);

        $skills = $tpl['skills'] ?? [];
        $order = 0;
        foreach ($skills as $s) {
            PdpSkill::create([
                'pdp_id' => $new->id,
                'skill' => (string)$s['skill'],
                'description' => $s['description'] ?? null,
                'criteria' => $s['criteria'] ?? null,
                'priority' => (string)($s['priority'] ?? 'Medium'),
                'eta' => $s['eta'] ?? null,
                'status' => (string)($s['status'] ?? 'Planned'),
                'order_column' => $s['order_column'] ?? $order,
            ]);
            $order++;
        }

        return response()->json($new->fresh()->loadCount('skills'), Response::HTTP_CREATED);
    }

    // Create/store a new template in DB.
    public function createTemplate(Request $request)
    {
        // Accept the same shape as import/export JSON
        $data = $request->validate([
            'version' => ['nullable','integer'],
            'pdp' => ['required','array'],
            'pdp.title' => ['required','string','max:255'],
            'pdp.description' => ['nullable','string'],
            'pdp.priority' => ['nullable','in:Low,Medium,High'],
            'pdp.eta' => ['nullable','string','max:255'],
            'pdp.status' => ['nullable','in:Planned,In Progress,Done,Blocked'],
            'skills' => ['nullable','array'],
            'skills.*.skill' => ['required','string','max:255'],
            'skills.*.description' => ['nullable','string'],
            'skills.*.criteria' => ['nullable','string'],
            'skills.*.priority' => ['nullable','in:Low,Medium,High'],
            'skills.*.eta' => ['nullable','string','max:255'],
            'skills.*.status' => ['nullable','in:Planned,In Progress,Done,Blocked'],
            'skills.*.order_column' => ['nullable','integer','min:0'],
        ]);

        // Normalize missing defaults for template (no timeline/progress by default)
        $p = $data['pdp'];
        $p['priority'] = $p['priority'] ?? 'Medium';
        $p['eta'] = $p['eta'] ?? null;
        $p['status'] = $p['status'] ?? 'Planned';
        $skills = [];
        $order = 0;
        foreach (($data['skills'] ?? []) as $s) {
            $skills[] = [
                'skill' => $s['skill'],
                'description' => $s['description'] ?? null,
                'criteria' => $s['criteria'] ?? null,
                'priority' => $s['priority'] ?? 'Medium',
                'eta' => null,
                'status' => 'Planned',
                'order_column' => $s['order_column'] ?? $order,
            ];
            $order++;
        }
        $payload = [
            'version' => (int)($data['version'] ?? 1),
            'pdp' => $p,
            'skills' => $skills,
        ];

        $tpl = PdpTemplate::create([
            'user_id' => $request->user()->id,
            'title' => (string)($p['title'] ?? 'Template'),
            'description' => $p['description'] ?? null,
            'data' => $payload,
            'published' => true,
        ]);

        return response()->json([
            'key' => 'db-' . $tpl->id,
            'id' => (int)$tpl->id,
            'title' => (string)($p['title'] ?? 'Template'),
            'skills_count' => count($skills),
        ], Response::HTTP_CREATED);
    }

    // Built-in templates catalog definition (minimal, can be extended or moved to DB later)
    private function templatesCatalog(): array
    {
        // Criteria are stored as JSON string in the same format used in the app.
        $json = static fn(array $items) => json_encode($items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        return [
            'junior_backend' => [
                'pdp' => [
                    'title' => 'Junior Backend Developer PDP',
                    'description' => 'Starter plan for a junior backend developer focusing on PHP and Laravel basics.',
                    'priority' => 'Medium',
                    'eta' => null,
                    'status' => 'Planned',
                ],
                'skills' => [
                    [
                        'skill' => 'PHP Fundamentals',
                        'description' => 'Syntax, OOP, Composer, PSR standards',
                        'criteria' => $json([
                            ['text' => 'Understand basic syntax and types'],
                            ['text' => 'OOP pillars: encapsulation, inheritance, polymorphism'],
                            ['text' => 'Use Composer and autoloading'],
                        ]),
                        'priority' => 'High',
                        'eta' => null,
                        'status' => 'Planned',
                        'order_column' => 0,
                    ],
                    [
                        'skill' => 'Laravel Basics',
                        'description' => 'Routing, Controllers, Eloquent, Migrations',
                        'criteria' => $json([
                            ['text' => 'Build a simple CRUD with Eloquent'],
                            ['text' => 'Understand service container and facades'],
                            ['text' => 'Create and run database migrations'],
                        ]),
                        'priority' => 'High',
                        'eta' => null,
                        'status' => 'Planned',
                        'order_column' => 1,
                    ],
                ],
            ],
            'qa_engineer' => [
                'pdp' => [
                    'title' => 'QA Engineer PDP',
                    'description' => 'Template for manual and automated testing skills.',
                    'priority' => 'Medium',
                    'eta' => null,
                    'status' => 'Planned',
                ],
                'skills' => [
                    [
                        'skill' => 'Test Case Design',
                        'description' => 'Equivalence partitioning, boundary values',
                        'criteria' => $json([
                            ['text' => 'Write clear, reproducible test cases'],
                            ['text' => 'Apply boundary value analysis'],
                        ]),
                        'priority' => 'Medium',
                        'eta' => null,
                        'status' => 'Planned',
                        'order_column' => 0,
                    ],
                    [
                        'skill' => 'Automation Basics',
                        'description' => 'Selenium or Playwright basics',
                        'criteria' => $json([
                            ['text' => 'Set up basic UI test project'],
                            ['text' => 'Create smoke test for login flow'],
                        ]),
                        'priority' => 'Low',
                        'eta' => null,
                        'status' => 'Planned',
                        'order_column' => 1,
                    ],
                ],
            ],
        ];
    }
}
