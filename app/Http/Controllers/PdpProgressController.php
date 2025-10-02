<?php

namespace App\Http\Controllers;

use App\Models\Pdp;
use App\Services\PdpProgressService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PdpProgressController extends Controller
{
    public function __construct(private PdpProgressService $service)
    {
    }

    public function show(Request $request, Pdp $pdp)
    {
        // Authorize: owner or curator
        if (!$pdp->isAccessibleBy($request->user())) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return response()->json($this->service->forPdp($pdp));
    }
}
