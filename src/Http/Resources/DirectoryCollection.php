<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class DirectoryCollection extends ResourceCollection
{
    public $collects = JsonResource::class;

    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'meta' => [
                'current_page' => $paginated['current_page'], 'per_page' => $paginated['per_page'],
                'total' => $paginated['total'], 'last_page' => $paginated['last_page'],
            ],
            'links' => [
                'first' => $paginated['first_page_url'], 'last' => $paginated['last_page_url'],
                'prev' => $paginated['prev_page_url'], 'next' => $paginated['next_page_url'],
            ],
        ];
    }
}
