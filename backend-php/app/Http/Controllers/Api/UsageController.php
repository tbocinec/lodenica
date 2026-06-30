<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UsageTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, anonymous usage beacon. The SPA pings this once per page load
 * (see main.ts) with `firstInSession` set on the first load of a browser
 * session. Records a pageview (+ a visit when first-in-session). Throttled
 * at the route. No PII is collected.
 */
class UsageController extends Controller
{
    public function visit(Request $request, UsageTracker $tracker): JsonResponse
    {
        $tracker->recordVisit($request->boolean('firstInSession'));

        return new JsonResponse(null, 204);
    }
}
