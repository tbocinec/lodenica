<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecideReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Http\Support\Paginated;
use App\Services\ReservationApprovalService;
use Illuminate\Http\Request;

/**
 * The approver's side of the workflow. Routes sit behind `auth:sanctum` +
 * `member`; whether THIS member may decide THIS reservation is checked in
 * the service (REZ-054). See docs/AUTH-AND-PERMISSIONS.md.
 */
class ReservationApprovalsController extends Controller
{
    public function __construct(private readonly ReservationApprovalService $approvals) {}

    /** GET /api/v1/reservations/approvals — waiting requests the caller may decide (REZ-060). */
    public function index(Request $request): array
    {
        $page = max(1, (int) $request->query('page', '1'));
        $pageSize = min(200, max(1, (int) $request->query('pageSize', '50')));

        $result = $this->approvals->pendingFor($request->user(), ($page - 1) * $pageSize, $pageSize);

        return Paginated::from($result['items'], $result['total'], $page, $pageSize, ReservationResource::class);
    }

    /** POST /api/v1/reservations/{id}/approve */
    public function approve(DecideReservationRequest $request, string $id): ReservationResource
    {
        return new ReservationResource(
            $this->approvals->approve($id, $request->user(), $request->validated('note')),
        );
    }

    /** POST /api/v1/reservations/{id}/reject */
    public function reject(DecideReservationRequest $request, string $id): ReservationResource
    {
        return new ReservationResource(
            $this->approvals->reject($id, $request->user(), $request->validated('note')),
        );
    }
}
