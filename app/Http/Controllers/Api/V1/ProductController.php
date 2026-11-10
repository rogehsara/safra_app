<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Services\InventoryGrpcClient;
use Throwable;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = MenuItem::query()
            ->with('menuCategory');

        if ($request->filled('search')) {
            $search = $request->input('search');

            $query->where('name', 'like', '%' . $search . '%');
        }

        if ($request->filled('category_id')) {
            $query->where(
                'menu_category_id',
                $request->integer('category_id')
            );
        }

        if ($request->filled('min_price')) {
            $query->where(
                'price',
                '>=',
                $request->input('min_price')
            );
        }

        if ($request->filled('max_price')) {
            $query->where(
                'price',
                '<=',
                $request->input('max_price')
            );
        }

        if ($request->filled('is_available')) {
            $query->where(
                'is_available',
                $request->boolean('is_available')
            );
        }

        $products = $query
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'message' => 'Products retrieved successfully',
            'data' => $products,
        ], 200);
    }

public function show(
    MenuItem $product,
    InventoryGrpcClient $inventory
) {
    $product->load('menuCategory');

    try {
        $quantity = $inventory->getAvailableQuantity(
            (int) $product->id
        );

        return response()->json([
            'success' => true,
            'data' => [
                'product' => $product,
                'inventory' => $quantity,
            ],
        ], 200);

    } catch (Throwable $e) {

        Log::error('Failed to retrieve product inventory', [
            'product_id' => $product->id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Unable to retrieve inventory information',
            'code' => 'INVENTORY_SERVICE_UNAVAILABLE',
        ], 503);
    }
}

public function store(
    Request $request,
    InventoryGrpcClient $inventory
) {
    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'price' => ['required', 'numeric', 'min:0'],
        'description' => ['nullable', 'string'],
        'preparation_time' => ['nullable', 'integer', 'min:0'],

        'menu_category_id' => [
            'required',
            'integer',
            'exists:menu_categories,id',
        ],

        'gifted_loyalty_points' => [
            'nullable',
            'numeric',
            'min:1',
        ],

        'meal_price_in_points' => [
            'nullable',
            'numeric',
            'min:3',
        ],

        'is_available' => ['required', 'boolean'],

        // Initial quantity belongs to Spring Boot, not MenuItem.
        'initial_quantity' => [
            'required',
            'integer',
            'min:1',
            'max:2147483647',
        ],

        'image' => [
            'required',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:2048',
        ],
    ]);

    $imagePath = null;
    $product = null;

    try {
        // 1. Store image.
        $imagePath = $request
            ->file('image')
            ->store('products', 'public');

        // 2. Create product in Laravel.
        // No quantity is saved in menu_items.
        $product = MenuItem::create([
            'name' => $validated['name'],
            'price' => $validated['price'],
            'description' => $validated['description'] ?? null,
            'preparation_time' => $validated['preparation_time'] ?? null,
            'menu_category_id' => $validated['menu_category_id'],
            'gifted_loyalty_points' =>
                $validated['gifted_loyalty_points'] ?? null,
            'meal_price_in_points' =>
                $validated['meal_price_in_points'] ?? null,
            'is_available' => $validated['is_available'],
            'image' => $imagePath,
        ]);

        // 3. Send the Laravel product ID and initial quantity
        // directly to Spring Boot.
        $inventoryResult = $inventory->addStock(
            (int) $product->id,
            (int) $validated['initial_quantity']
        );

        if (! $inventoryResult['success']) {
            // The inventory service explicitly rejected the operation.
            $product->delete();

            Storage::disk('public')->delete($imagePath);

            return response()->json([
                'success' => false,
                'message' => $inventoryResult['message']
                    ?: 'Inventory initialization failed',
                'code' => 'INVENTORY_INITIALIZATION_FAILED',
            ], 502);
        }

        // 4. Retrieve the actual inventory data from Spring Boot.
        $quantity = $inventory->getAvailableQuantity(
            (int) $product->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => [
                'product' => $product->load('menuCategory'),
                'inventory' => $quantity,
            ],
        ], 201);

    } catch (Throwable $e) {

        Log::error('Product creation or inventory operation failed', [
            'product_id' => $product?->id,
            'error' => $e->getMessage(),
        ]);

        /*
         * Important:
         * A gRPC timeout does not prove that Spring Boot
         * failed to execute AddStock.
         *
         * Therefore, do not automatically delete the product
         * here: the inventory operation may have succeeded.
         */

        return response()->json([
            'success' => false,
            'message' => 'Product creation or inventory initialization could not be confirmed.',
            'code' => 'PRODUCT_INVENTORY_OPERATION_UNCONFIRMED',
            'product_id' => $product?->id,
        ], 502);
    }
}

    public function update(Request $request, MenuItem $product)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'description' => ['sometimes', 'nullable', 'string'],
            'preparation_time' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],
            'menu_category_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:menu_categories,id',
            ],
            'is_available' => ['sometimes', 'boolean'],
            'image' => [
                'sometimes',
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ]);

        if ($request->hasFile('image')) {
            $validated['image'] = $request
                ->file('image')
                ->store('products', 'public');
                if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
        }

        $product->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $product->fresh()->load('menuCategory'),
        ], 200);
    }

    public function destroy(MenuItem $product)
    {
        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully',
        ], 200);
    }
    public function addInventoryStock(
    Request $request,
    MenuItem $product,
    InventoryGrpcClient $inventory
) {
    $validated = $request->validate([
        'quantity' => [
            'required',
            'integer',
            'min:1',
            'max:2147483647',
        ],
    ]);

    try {
        // Send the quantity directly to Spring Boot.
        $result = $inventory->addStock(
            (int) $product->id,
            (int) $validated['quantity']
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        // Read the updated quantity from Spring Boot.
        $inventoryData = $inventory->getAvailableQuantity(
            (int) $product->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Stock added successfully',
            'data' => [
                'product_id' => (int) $product->id,
                'inventory' => $inventoryData,
            ],
        ], 200);

    } catch (Throwable $e) {

        Log::error('Adding product stock failed', [
            'product_id' => $product->id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Inventory service is currently unavailable',
            'code' => 'INVENTORY_SERVICE_UNAVAILABLE',
        ], 503);
    }
}
}