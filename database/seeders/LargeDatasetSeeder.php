<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class LargeDatasetSeeder extends Seeder
{
    private int $customerTarget = 16000;
    private int $internalTarget = 77500;
    private int $deliveryTarget = 70000;

    private int $customerBatch = 1000;
    private int $orderBatch = 500;
    private int $itemBatch = 2000;

    private string $emailPrefix = 'loadtest_20261109_';

    public function run(): void
    {
        DB::disableQueryLog();

        $this->command->info('Starting large dataset generation...');

        // منع التكرار عند إعادة التشغيل
        /*   if (DB::table('users')
               ->where('email', 'like', $this->emailPrefix . '%')
               ->exists()) {
               throw new RuntimeException(
                   'Load-test data already exists. Do not run this seeder again.'
               );
           }*/

        $this->validateReferences();

        $this->createCustomers();

        $customerIds = DB::table('customers')
            ->join('users', 'customers.user_id', '=', 'users.id')
            ->where('users.email', 'like', $this->emailPrefix . '%')
            ->pluck('customers.id')
            ->all();

        $this->command->info(
            'Generated customers: ' . count($customerIds)
        );

        // حساب عدد الطلبات الموجودة حالياً
        $existingInternalOrders = DB::table('in_orders')->count();

        $existingDeliveryOrders = DB::table('delivery_orders')->count();

        // حساب العدد المتبقي فقط
        $remainingInternalOrders = max(
            0,
            $this->internalTarget - $existingInternalOrders
        );

        $remainingDeliveryOrders = max(
            0,
            $this->deliveryTarget - $existingDeliveryOrders
        );

        $this->command->info(
            "Internal orders remaining: {$remainingInternalOrders}"
        );

        $this->command->info(
            "Delivery orders remaining: {$remainingDeliveryOrders}"
        );

        // استكمال الطلبات الداخلية
        if ($remainingInternalOrders > 0) {
            $this->generateOrders(
                'in',
                $remainingInternalOrders,
                $customerIds
            );
        }

        // استكمال طلبات التوصيل
        if ($remainingDeliveryOrders > 0) {
            $this->generateOrders(
                'delivery',
                $remainingDeliveryOrders,
                $customerIds
            );
        }

        $this->command->info('Dataset generation completed!');
    }

    private function validateReferences(): void
    {
        $checks = [
            'menu_items' => DB::table('menu_items')
                ->where('is_available', 1)->count(),

            'waiters' => DB::table('waiters')->count(),

            'table_layouts' => DB::table('table_layouts')->count(),

            'chefs' => DB::table('chefs')->count(),
        ];

        foreach ($checks as $table => $count) {
            if ($count === 0) {
                throw new RuntimeException(
                    "Required reference data missing: {$table}"
                );
            }
        }
    }


    private function createCustomers(): void
    {
        $existingUsers = DB::table('users')
            ->where('email', 'like', $this->emailPrefix . '%')
            ->count();

        $existingCustomers = DB::table('customers')
            ->join('users', 'customers.user_id', '=', 'users.id')
            ->where('users.email', 'like', $this->emailPrefix . '%')
            ->count();

        if ($existingUsers !== $existingCustomers) {
            throw new RuntimeException(
                'Customer and user counts do not match. Manual inspection required.'
            );
        }

        if ($existingCustomers > $this->customerTarget) {
            throw new RuntimeException(
                'Existing load-test customers exceed target.'
            );
        }

        $needed = $this->customerTarget - $existingCustomers;

        $this->command->info(
            "Resuming customers: {$existingCustomers} already exist. {$needed} remaining."
        );

        $password = Hash::make('LoadTest@12345');

        $counter = $existingUsers + 1;

        while ($needed > 0) {
            $size = min($this->customerBatch, $needed);
            DB::transaction(function () use ($size, $counter, $password) {
                $users = [];
                $customers = [];

                for ($i = 0; $i < $size; $i++) {
                    $number = $counter + $i;

                    $users[] = [
                        'role' => 'customer',
                        'email' => $this->emailPrefix .
                            str_pad($number, 6, '0', STR_PAD_LEFT) .
                            '@example.test',
                        'password' => $password,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DB::table('users')->insert($users);

                $firstUserId = (int) DB::getPdo()->lastInsertId();

                for ($i = 0; $i < $size; $i++) {
                    $number = $counter + $i;

                    $customers[] = [
                        'user_id' => $firstUserId + $i,
                        'first_name' => 'TestCustomer' . $number,
                        'last_name' => 'LoadTest',
                        'phone_number' => '0998' .
                            str_pad($number, 7, '0', STR_PAD_LEFT),
                        'location' => 'Test Location ' . ($number % 100),
                        'points_payment' => 0,
                        'loyalty_points' => mt_rand(0, 500),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DB::table('customers')->insert($customers);
            });

            $counter += $size;
            $needed -= $size;

            unset($users, $customers);
            gc_collect_cycles();

            $this->command->info(
                "Customers batch completed. Remaining: {$needed}"
            );
        }
    }

    private function generateOrders(
        string $type,
        int $target,
        array $customerIds
    ): void {
        if (empty($customerIds)) {
            throw new RuntimeException('No customers available.');
        }

        $menuItems = DB::table('menu_items')
            ->where('is_available', 1)
            ->get();

        $waiterIds = DB::table('waiters')->pluck('id')->all();

        $tableIds = DB::table('table_layouts')->pluck('id')->all();

        $offers = DB::table('offers')->pluck('id')->all();

        $chefs = DB::table('chefs')
            ->get()
            ->groupBy('menu_category_id');

        $generated = 0;

        $this->command->info(
            "Generating {$target} {$type} orders..."
        );

        while ($generated < $target) {
            $size = min(
                $this->orderBatch,
                $target - $generated
            );

            $orders = [];
            $pendingItems = [];

            for ($i = 0; $i < $size; $i++) {
                $orderItems = [];
                $totalPrice = 0;

                $itemsCount = mt_rand(2, 4);

                for ($j = 0; $j < $itemsCount; $j++) {
                    $menuItem = $menuItems->random();

                    $quantity = mt_rand(1, 3);

                    $totalPrice +=
                        $menuItem->price * $quantity;

                    $categoryChefs = $chefs->get(
                        $menuItem->menu_category_id,
                        collect()
                    );

                    $chefId = $categoryChefs->isNotEmpty()
                        ? $categoryChefs->random()->id
                        : null;

                    $orderItems[] = [
                        'menu_item_id' => $menuItem->id,
                        'chef_id' => $chefId,
                        'quantity' => $quantity,
                    ];
                }

                $status = mt_rand(0, 1)
                    ? 'done'
                    : 'pending';

                $createdAt = now()
                    ->subSeconds(mt_rand(0, 31536000))
                    ->format('Y-m-d H:i:s');
                if ($type === 'in') {
                    $orders[] = [
                        'table_layout_id' => $tableIds[array_rand($tableIds)],
                        'waiter_id' => $waiterIds[array_rand($waiterIds)],
                        'status' => $status,
                        'total_price' => $totalPrice,
                        'payment_completed' => $status === 'done' ? 1 : 0,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ];
                } else {
                    $orders[] = [
                        'customer_id' => $customerIds[array_rand($customerIds)],
                        'offer_id' => !empty($offers) && mt_rand(0, 3) === 0
                            ? $offers[array_rand($offers)]
                            : null,
                        'status' => $status,
                        'total_price' => $totalPrice,
                        'delivery_address' => 'Test Address ' . mt_rand(1, 10000),
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ];
                }

                $pendingItems[] = [
                    'items' => $orderItems,
                    'status' => $status,
                    'created_at' => $createdAt,
                ];
            }

            $table = $type === 'in'
                ? 'in_orders'
                : 'delivery_orders';

            DB::table($table)->insert($orders);

            $firstOrderId = (int) DB::getPdo()->lastInsertId();

            $itemRows = [];

            foreach ($pendingItems as $index => $pending) {
                $orderId = $firstOrderId + $index;

                foreach ($pending['items'] as $item) {
                    $itemRows[] = [
                        'in_order_id' => $type === 'in' ? $orderId : null,
                        'delivery_order_id' => $type === 'delivery'
                            ? $orderId
                            : null,
                        'favourite_id' => null,
                        'menu_item_id' => $item['menu_item_id'],
                        'chef_id' => $item['chef_id'],
                        'status' => $pending['status'] === 'done'
                            ? 'done'
                            : (mt_rand(0, 1) ? 'pending' : 'preparing'),
                        'notes' => null,
                        'quantity' => $item['quantity'],
                        'order_type' => $type,
                        'created_at' => $pending['created_at'],
                        'updated_at' => $pending['created_at'],
                    ];
                }
            }

            foreach (array_chunk($itemRows, $this->itemBatch) as $chunk) {
                DB::table('order_items')->insert($chunk);
            }

            $generated += $size;
            unset($orders, $pendingItems, $itemRows);

            gc_collect_cycles();
            $this->command->info(
                ucfirst($type) .
                " orders: {$generated} / {$target}"
            );
        }
    }
}