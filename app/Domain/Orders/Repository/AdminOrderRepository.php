<?php

namespace App\Domain\Orders\Repository;

use App\Domain\Orders\ValueObjects\OrderStatus;

interface AdminOrderRepository
{
    /** @return array{data:list<array<string,mixed>>,meta:array{current_page:int,last_page:int,per_page:int,total:int}} */
    public function paginate(?string $search, ?OrderStatus $status, int $page, int $perPage): array;

    /** @return array<string,mixed>|null */
    public function findDetails(int $id): ?array;

    public function statusOf(int $id): ?OrderStatus;

    public function updateStatus(int $id, OrderStatus $status): void;
}
