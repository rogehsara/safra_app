<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Inventory;

/**
 */
class InventoryServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \Inventory\GetAvailableQuantityRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Inventory\GetAvailableQuantityResponse>
     */
    public function GetAvailableQuantity(\Inventory\GetAvailableQuantityRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/inventory.InventoryService/GetAvailableQuantity',
        $argument,
        ['\Inventory\GetAvailableQuantityResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Inventory\AddStockRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Inventory\AddStockResponse>
     */
    public function AddStock(\Inventory\AddStockRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/inventory.InventoryService/AddStock',
        $argument,
        ['\Inventory\AddStockResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Inventory\ReserveStockRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Inventory\ReserveStockResponse>
     */
    public function ReserveStock(\Inventory\ReserveStockRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/inventory.InventoryService/ReserveStock',
        $argument,
        ['\Inventory\ReserveStockResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Inventory\ReleaseReservationRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Inventory\ReleaseReservationResponse>
     */
    public function ReleaseReservation(\Inventory\ReleaseReservationRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/inventory.InventoryService/ReleaseReservation',
        $argument,
        ['\Inventory\ReleaseReservationResponse', 'decode'],
        $metadata, $options);
    }

}
