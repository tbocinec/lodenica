<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberRosterResource;
use App\Http\Support\Paginated;
use App\Services\MemberRosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only member roster ("číselník") CRUD + CSV import. The roster drives
 * self-registration auto-approval — see MemberRosterService. Routes live in
 * the admin group. See docs/AUTH-AND-PERMISSIONS.md.
 */
class MemberRosterController extends Controller
{
    public function __construct(private readonly MemberRosterService $roster) {}

    public function index(Request $request): array
    {
        $page = max(1, (int) $request->query('page', '1'));
        $pageSize = (int) $request->query('pageSize', '50');

        $registered = $request->query('registered');
        $result = $this->roster->list([
            'search' => $request->query('search'),
            'registered' => $registered === null ? null : filter_var($registered, FILTER_VALIDATE_BOOLEAN),
            'skip' => ($page - 1) * $pageSize,
            'take' => $pageSize,
        ]);

        return Paginated::from(
            $result['items'],
            $result['total'],
            $page,
            $pageSize,
            MemberRosterResource::class,
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:200'],
            'memberId' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:200'],
        ]);

        $entry = $this->roster->create($data['email'], $data['memberId'] ?? null, $data['name'] ?? null);

        return (new MemberRosterResource($entry))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, string $id): MemberRosterResource
    {
        $data = $request->validate([
            'email' => ['sometimes', 'email', 'max:200'],
            'memberId' => ['sometimes', 'nullable', 'string', 'max:100'],
            'name' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        return new MemberRosterResource($this->roster->update($id, $data));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->roster->delete($id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate(['csv' => ['required', 'string']]);
        $summary = $this->roster->import($data['csv']);

        return new JsonResponse([
            'createdCount' => $summary['created'],
            'skippedCount' => count($summary['skipped']),
            'invalidCount' => count($summary['invalid']),
            'skipped' => $summary['skipped'],
            'invalid' => $summary['invalid'],
        ], Response::HTTP_CREATED);
    }
}
