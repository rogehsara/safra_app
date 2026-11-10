<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\InventoryGrpcClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OrderController extends Controller
{
    public function index(Request $request)
{
    $user = auth('jwt')->user();

    if (! $user) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated',
        ], 401);
    }

    if ($user->role === 'admin') {
        $query = DeliveryOrder::query();

    } elseif ($user->role === 'customer') {
        $customer = $user->customer;

        if (! $customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer profile not found',
            ], 404);
        }

        $query = DeliveryOrder::where(
            'customer_id',
            $customer->id
        );

    } else {
        return response()->json([
            'success' => false,
            'message' => 'You are not allowed to view delivery orders',
        ], 403);
    }

    if ($request->filled('status')) {
        $query->where('status', $request->input('status'));
    }

    $orders = $query
        ->with('orderItems.menuItem')
        ->latest()
        ->paginate(10);

    return response()->json([
        'success' => true,
        'data' => $orders,
    ], 200);
}

  public function show(DeliveryOrder $order)
{
    $user = auth('jwt')->user();

    if ($user->hasRole('customer', 'web')) {

        $customer = $user->customer;

        if (
            ! $customer ||
            (int) $order->customer_id !== (int) $customer->id
        ) {

            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);

        }

    } elseif (! $user->hasRole('admin', 'web')) {

        return response()->json([
            'success' => false,
            'message' => 'You are not allowed to view this order',
        ], 403);

    }

    $order->load('orderItems.menuItem');

    return response()->json([
        'success' => true,
        'data' => $order,
    ], 200);
}

   
public function store(
    Request $request,
    InventoryGrpcClient $inventory
) {
    $validated = $request->validate([
        'delivery_address' => [
            'required',
            'string',
            'max:1000'
        ],

        'items' => ['required', 'array', 'min:1'],

        'items.*.menu_item_id' => [
            'required',
            'integer',
            'distinct',
            'exists:menu_items,id',
        ],

        'items.*.quantity' => [

            'integer',
            'min:1',
        ],

        'items.*.notes' => [
            'nullable',
            'string',
            'max:255',
        ],
    ]);

    $user = auth('jwt')->user();

    $customer = $user?->customer;

    if (
        ! $user ||
        $user->role !== 'customer' ||
        ! $customer
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Only customers can create delivery orders',
        ], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. Validate local products and calculate total
    |--------------------------------------------------------------------------
    */

    $items = collect($validated['items']);

    $productIds = $items
        ->pluck('menu_item_id')
        ->map(fn ($id) => (int) $id)
        ->all();

    $products = MenuItem::query()
        ->whereIn('id', $productIds)
        ->where('is_available', true)
        ->get()
        ->keyBy('id');

    if ($products->count() !== count($productIds)) {
        return response()->json([
            'success' => false,
            'message' => 'One or more products are unavailable',
        ], 422);
    }

    $total = 0;

    foreach ($items as $item) {
        $product = $products->get(
            $item['menu_item_id']
        );

        $total += $product->price * $item['quantity'];
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Prepare one inventory reservation request
    |--------------------------------------------------------------------------
    */

    $reservationId = (string) Str::uuid();

    $reservationItems = $items->map(function ($item) {
        return [
            'product_id' => (int) $item['menu_item_id'],
            'quantity' => (int) $item['quantity'],
        ];
    })->all();

    /*
    |--------------------------------------------------------------------------
    | 3. Reserve stock through gRPC
    |--------------------------------------------------------------------------
    */

    try {
        $reservation = $inventory->reserveStock(
            $reservationId,
            $reservationItems
        );
        
    } catch (Throwable $e) {

        Log::error('Inventory reservation failed', [
            'reservation_id' => $reservationId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'code' => 'INVENTORY_SERVICE_UNAVAILABLE',
        ], 503);
    }


    /*
    |--------------------------------------------------------------------------
    | 4. Reject if stock is insufficient
    |--------------------------------------------------------------------------
    */

    if (! $reservation['success']) {
        return response()->json([
            'success' => false,
            'message' => $reservation['message'],
            'code' => 'INSUFFICIENT_STOCK',
        ], 409);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Create order inside MySQL transaction
    |--------------------------------------------------------------------------
    */

    try {

        $order = DB::transaction(function () use (
            $validated,
            $customer,
            $items,
            $total
        ) {

        // TEST ONLY: Force database transaction failure
    //throw new RuntimeException('TEST_RELEASE_RESERVATION');

            $order = DeliveryOrder::create([
                'customer_id' => $customer->id,
                'status' => 'pending',
                'total_price' => $total,
                'delivery_address' => $validated['delivery_address'],
            ]);

            foreach ($items as $item) {

                $order->orderItems()->create([
                    'menu_item_id' => $item['menu_item_id'],
                    'quantity' => $item['quantity'],
                    'notes' => $item['notes'] ?? null,
                    'order_type' => 'delivery',
                    'status' => 'pending',
                ]);
            }

            return $order;
        });

    } catch (Throwable $e) {

        /*
        |--------------------------------------------------------------------------
        | 6. Compensating action: release reserved stock
        |--------------------------------------------------------------------------
        */

        try {

            $released = $inventory->releaseReservation(
                $reservationId
            );

            if (! $released) {
                Log::critical('Inventory reservation release was rejected', [
                    'reservation_id' => $reservationId,
                ]);
            }

        } catch (Throwable $releaseException) {

            Log::critical('Inventory compensation failed', [
                'reservation_id' => $reservationId,
                'error' => $releaseException->getMessage(),
            ]);
        }

        Log::error('Order creation failed after stock reservation', [
            'reservation_id' => $reservationId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Order creation failed. Inventory release was attempted.',
            'code' => 'ORDER_CREATION_FAILED',
            'reservation_id' => $reservationId,
        ], 500);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. Success
    |--------------------------------------------------------------------------
    */

    return response()->json([
        'success' => true,
        'message' => 'Order created and inventory reserved successfully',
        'data' => $order->load('orderItems.menuItem'),
        'reservation_id' => $reservationId,
    ], 201);
}


    public function updateStatus(Request $request, DeliveryOrder $order)
    {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in(['pending', 'done']),
            ],
        ]);

        $order->update([
            'status' => $validated['status'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'data' => $order->fresh(),
        ], 200);
    }
}