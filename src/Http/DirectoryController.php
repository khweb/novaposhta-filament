<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NovaPoshta\Filament\Exceptions\DirectoryException;
use NovaPoshta\Filament\Http\Resources\DirectoryCollection;
use NovaPoshta\Filament\Services\DirectorySearch;

final class DirectoryController
{
    public function __invoke(Request $request, string $directory, DirectorySearch $search): JsonResponse
    {
        try {
            return (new DirectoryCollection($search->search($directory, $request->query())))->response();
        } catch (DirectoryException $exception) {
            return response()->json(['error' => $exception->reason], 503);
        }
    }
}
