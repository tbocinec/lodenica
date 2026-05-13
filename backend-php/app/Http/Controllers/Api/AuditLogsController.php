<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Http\Support\Paginated;
use App\Services\AuditQuery;

class AuditLogsController extends Controller
{
    public function __construct(private readonly AuditQuery $audit) {}

    public function index(ListAuditLogsRequest $request): array
    {
        $page = (int) ($request->validated('page') ?? 1);
        $pageSize = (int) ($request->validated('pageSize') ?? 50);

        $result = $this->audit->list([
            'entityType' => $request->validated('entityType'),
            'entityId' => $request->validated('entityId'),
            'action' => $request->validated('action'),
            'from' => $request->validated('from'),
            'to' => $request->validated('to'),
            'skip' => ($page - 1) * $pageSize,
            'take' => $pageSize,
        ]);

        return Paginated::from(
            $result['items'],
            $result['total'],
            $page,
            $pageSize,
            AuditLogResource::class,
        );
    }
}
