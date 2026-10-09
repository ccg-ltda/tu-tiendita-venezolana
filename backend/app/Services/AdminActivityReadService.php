<?php

namespace App\Services;

use App\Repositories\MySqlAdminAuditRepository;

final class AdminActivityReadService
{
    public function __construct(private readonly MySqlAdminAuditRepository $audit) {}

    /** @param array{admin_id?:int,resource_type?:string,action?:string,date_from?:string,date_to?:string,include_auth:bool} $filters */
    public function page(int $limit, ?int $beforeId, array $filters): array
    {
        return $this->audit->page($limit, $beforeId, $filters);
    }

}
