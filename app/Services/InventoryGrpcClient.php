<?php


namespace App\Services;

use Grpc\ChannelCredentials;
use Inventory\InventoryServiceClient;
use Inventory\GetAvailableQuantityRequest;
use Inventory\ReserveStockRequest;
use Inventory\StockItem;
use Inventory\ReleaseReservationRequest;
use RuntimeException;
use Inventory\AddStockRequest;

class InventoryGrpcClient
{
    private InventoryServiceClient $client;

    private int $timeout;

    public function __construct()
    {
        $host = config(
            'services.inventory.grpc_host',
            'inventory-service:9090'
        );

        $this->timeout = (int) config(
            'services.inventory.timeout_ms',
            2000
        ) * 1000;

        $this->client = new InventoryServiceClient(
            $host,
            [
                'credentials' => ChannelCredentials::createInsecure(),
            ]
        );
    }

    private function execute($call)
    {
        [$response, $status] = $call->wait();

        if ($status->code !== 0) {
            throw new RuntimeException(
                "Inventory gRPC error: {$status->code} - {$status->details}"
            );
        }

        return $response;
    }

    public function getAvailableQuantity(int $productId): array
    {
        $request = new GetAvailableQuantityRequest();

        $request->setProductId($productId);

        $response = $this->execute(
            $this->client->GetAvailableQuantity(
                $request,
                [],
                ['timeout' => $this->timeout]
            )
        );

        return [
            'product_id' => $response->getProductId(),
            'total_quantity' => $response->getTotalQuantity(),
            'reserved_quantity' => $response->getReservedQuantity(),
            'available_quantity' => $response->getAvailableQuantity(),
        ];
    }

    public function reserveStock(
        string $reservationId,
        array $items
    ): array {
        $request = new ReserveStockRequest();

        $request->setReservationId($reservationId);

        $stockItems = [];

        foreach ($items as $item) {
            $stockItem = new StockItem();

            $stockItem->setProductId(
                (int) $item['product_id']
            );

            $stockItem->setQuantity(
                (int) $item['quantity']
            );

            $stockItems[] = $stockItem;
        }

        $request->setItems($stockItems);

        $response = $this->execute(
            $this->client->ReserveStock(
                $request,
                [],
                ['timeout' => $this->timeout]
            )
        );

        return [
            'success' => $response->getSuccess(),
            'message' => $response->getMessage(),
            'reservation_id' => $response->getReservationId(),
        ];
    }

    public function releaseReservation(
        string $reservationId
    ): bool {
        $request = new ReleaseReservationRequest();

        $request->setReservationId($reservationId);

        $response = $this->execute(
            $this->client->ReleaseReservation(
                $request,
                [],
                ['timeout' => $this->timeout]
            )
        );

        return $response->getSuccess();
    }

    public function addStock(
    int $productId,
    int $quantity
): array {
    $request = new AddStockRequest();

    $request->setProductId($productId);
    $request->setQuantity($quantity);

    $response = $this->execute(
        $this->client->AddStock(
            $request,
            [],
            ['timeout' => $this->timeout]
        )
    );

    return [
        'success' => $response->getSuccess(),
        'message' => $response->getMessage(),
    ];
}
}

