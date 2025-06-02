<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderReturnRequest;
use App\Http\Resources\OrderReturnResource;
use App\Http\Resources\OrderReturnShowResource;
use App\Services\OrderReturn\OrderReturnService;
use Illuminate\Http\JsonResponse;

class OrderReturnController extends Controller
{
    public function __construct(
        protected OrderReturnService $orderReturnService
    ) {}

    public function index()
    {
        $data = $this->orderReturnService->findAll();

        return response()->json([
            "data" => OrderReturnResource::collection($data['data']),
            "total_sale_price" => $data['total_sale_price'],
            "total_cost_price" => $data['total_cost_price'],
        ]);
    }


    public function store(StoreOrderReturnRequest $request)
    {
        $result = $this->orderReturnService->create($request->validated());

        $formatVal = number_format($result['total_price'], 2, '.', ',');

        return response()->json([
            "message" => "Qaytarilgan yuk muvaffaqiyatli qabul qilindi. Jami summa {$formatVal} uzs"
        ]);
    }


    public function show(string $id): JsonResponse
    {
        $data = $this->orderReturnService->findOne((int) $id);

        return response()->json([
            "data" => OrderReturnShowResource::make($data),
        ]);
    }
}
