<?php

namespace Modules\Arca\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Arca\ArcaManager;
use Modules\Arca\Http\Requests\StorePointOfSaleRequest;
use Modules\Arca\Models\PointOfSale;

class ArcaController
{
    public function pointOfSales(ArcaManager $arca): JsonResponse
    {
        return response()->json($arca->pointOfSales()->active()->map(fn (PointOfSale $pointOfSale): array => [
            'number' => $pointOfSale->number,
            'name' => $pointOfSale->name,
            'active' => $pointOfSale->active,
        ])->values());
    }

    public function pointOfSalesFromArca(ArcaManager $arca): JsonResponse
    {
        return response()->json($arca->pointOfSales()->fromArca());
    }

    public function syncPointOfSales(ArcaManager $arca): JsonResponse
    {
        return response()->json($arca->pointOfSales()->syncFromArca());
    }

    public function storePointOfSale(StorePointOfSaleRequest $request, ArcaManager $arca): JsonResponse
    {
        $pointOfSale = $arca->pointOfSales()->create($request->validated());

        return response()->json([
            'number' => $pointOfSale->number,
            'name' => $pointOfSale->name,
            'active' => $pointOfSale->active,
        ], 201);
    }

    public function authorizeVoucher(Request $request, ArcaManager $arca): JsonResponse
    {
        return response()->json($arca->authorize($request->all())->toArray());
    }
}
