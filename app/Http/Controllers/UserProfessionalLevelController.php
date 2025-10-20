<?php

namespace App\Http\Controllers;

use App\Services\ProfessionalLevelService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserProfessionalLevelController extends Controller
{
    public function __construct(private ProfessionalLevelService $service)
    {
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $data = $this->service->current($user);
        // Also return the levels list so the client can render ladder/labels
        $data['levels'] = $this->service->levels();
        return response()->json($data);
    }

    /**
     * Set starting professional level by mapping a selected level key to an offset.
     * This is intended to be called right after registration.
     */
    public function start(Request $request)
    {
        $user = $request->user();

        // If user already has any closed skills, prevent backdating start level to avoid inconsistencies
        $closed = $this->service->countClosedSkills($user);
        if ($closed > 0) {
            return response()->json([
                'message' => 'Cannot set starting level after progress has been made.',
            ], 422);
        }

        $levels = $this->service->levels();
        $allowed = array_column($levels, 'key');

        $validated = $request->validate([
            'level' => ['required', 'string', Rule::in($allowed)],
        ]);

        // Find selected level threshold
        $selected = null;
        foreach ($levels as $lvl) {
            if ($lvl['key'] === $validated['level']) {
                $selected = $lvl;
                break;
            }
        }

        if (!$selected) {
            return response()->json(['message' => 'Invalid level'], 422);
        }

        // Compute offset so that current total equals the selected threshold.
        $threshold = (int) ($selected['threshold'] ?? 0);
        $offset = max(0, $threshold - $closed);

        $user->pro_level_offset = $offset;
        $user->save();

        $data = $this->service->current($user);
        $data['levels'] = $levels;

        return response()->json($data);
    }
}
