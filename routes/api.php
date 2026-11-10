<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\hireController;
use App\Http\Controllers\Admin\MainInfoController;
use App\Http\Controllers\Admin\MenuCategoryController;
use App\Http\Controllers\Chef\ChefController;
use App\Http\Controllers\Customer\AuthCustomerController;
use App\Http\Controllers\Customer\DeliveryBillsController;
use App\Http\Controllers\Customer\FavouriteOrderController;
use App\Http\Controllers\Customer\MealClassificationController;
use App\Http\Controllers\Customer\ProfileCustomerController;
use App\Http\Controllers\Customer\ReservationsCustomerController;
use App\Http\Controllers\Customer\ReviewsExperienceController;
use App\Http\Controllers\Customer\TablesInLayoutController;
use App\Http\Controllers\Customer\TypesAndDetailsOfMeals;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ParallelRequirementsController;
use App\Http\Controllers\Waiter\AuthWaiterController;
use App\Http\Controllers\Waiter\BillController;
use App\Http\Controllers\Waiter\MenuForTableController;
use App\Http\Controllers\Waiter\ReportWaiterController;
use App\Http\Controllers\Waiter\StatusOfTablesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\V1\AuthController as V1AuthController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\OrderController;


/*
|--------------------------------------------------------------------------
| API V1 - Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    Route::post('/auth/login', [V1AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    /*
    |--------------------------------------------------------------------------
    | Protected Routes - JWT
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth:jwt')->group(function () {

        Route::post('/auth/logout', [V1AuthController::class, 'logout']);

   

        /*
        |--------------------------------------------------------------------------
        | Products
        |--------------------------------------------------------------------------
        */

        Route::get('/products', [ProductController::class, 'index']);

        Route::get('/products/{product}', [ProductController::class, 'show']);

       
       
        Route::middleware('role:admin')->group(function () {

            Route::post('/products', [ProductController::class, 'store']);
            Route::patch('/products/{product}', [ProductController::class, 'update']);
            Route::post(  '/products/{product}/inventory/add',[ProductController::class, 'addInventoryStock']
            
);
            Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        });

        /*
        |--------------------------------------------------------------------------
        | Orders
        |--------------------------------------------------------------------------
        */

        Route::get('/orders', [OrderController::class, 'index']); //

        Route::get('/orders/{order}', [OrderController::class, 'show']);

        Route::post('/orders', [OrderController::class, 'store']);

        Route::patch(
            '/orders/{order}/status',
            [OrderController::class, 'updateStatus']
        )->middleware('role:admin');

    });
});













Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


/////Customer:
Route::post('/register-customer', [AuthCustomerController::class, 'registerCustomer']);
Route::post('/login-customer', [AuthCustomerController::class, 'loginCustomer']);
Route::prefix('customer')->group(function () {

    Route::post('/add-item/{categoryId}', [DeliveryBillsController::class, 'addItemToBill'])
        ->middleware('monitor');
    Route::post('/add-item-2/{categoryId}', [DeliveryBillsController::class, 'addItemToBill2'])
        ->middleware('throttle:5,1')
        ->middleware('monitor');
    Route::post('/add-item-3/{categoryId}', [DeliveryBillsController::class, 'addItemToBill3'])
        ->middleware('throttle:10,1')
        ->middleware('monitor');

    Route::get('/report-before', [ReportController::class, 'before']);
    Route::get('/report-after', [ReportController::class, 'after']);
});



// Parallel Programming Requirements 5 & 6
// Requirement 5: Load Distribution simulation
// Requirement 6: Redis Distributed Caching for popular products
Route::prefix('parallel')->middleware(['aop.monitor', 'monitor'])->group(function () {

    // Node endpoint: each Laravel instance on ports 8000, 8001, 8002 exposes this endpoint.
    Route::get('/node/process', [ParallelRequirementsController::class, 'processNode'])->name('parallel.node.process');
    Route::get('/load-balancer/next', [ParallelRequirementsController::class, 'nextServer'])->name('parallel.load.next');
    Route::get('/load-balancer/simulate', [ParallelRequirementsController::class, 'simulateLoadDistribution'])->name('parallel.load.simulate');
    Route::get('/cache/popular-products', [ParallelRequirementsController::class, 'popularProducts'])->name('parallel.cache.popular');
    Route::delete('/cache/popular-products', [ParallelRequirementsController::class, 'clearPopularProductsCache'])->name('parallel.cache.clear');

    // Requirement 7: Concurrency Control / shared lock between multiple instances
    Route::get('/inventory/state', [ParallelRequirementsController::class, 'inventoryState'])->name('parallel.inventory.state');
    Route::post('/inventory/reset', [ParallelRequirementsController::class, 'resetInventory'])->name('parallel.inventory.reset');
    Route::post('/inventory/unsafe-purchase', [ParallelRequirementsController::class, 'unsafePurchase'])->name('parallel.inventory.unsafe');
    Route::post('/inventory/locked-purchase', [ParallelRequirementsController::class, 'lockedPurchase'])->name('parallel.inventory.locked');

    // Requirement 8: ACID / transaction integrity demo
    Route::post('/transaction/purchase', [ParallelRequirementsController::class, 'transactionPurchase'])->name('parallel.transaction.purchase');

    // Requirement 9: Concurrent stress test
    Route::get('/stress/run', [ParallelRequirementsController::class, 'runStressTest'])->name('parallel.stress.run');

    // Requirement 10: Benchmarking before/after optimization
    Route::get('/benchmark/run', [ParallelRequirementsController::class, 'runBenchmark'])->name('parallel.benchmark.run');
});

Route::get('/debug-db-host', function () {
    return response()->json([
        'host' => (new \App\Models\User)->getConnection()->getConfig('host'),
        'database' => (new \App\Models\User)->getConnection()->getDatabaseName(),
    ]);
});