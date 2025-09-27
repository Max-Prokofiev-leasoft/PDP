<?php

namespace App\Http\Controllers;

use App\Services\ProfessionalLevelService;
use Illuminate\Http\Request;

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
}
