<?php

namespace App\Services\Order;

use App\Contracts\Repositories\OrderCoinPairRepositoryInterface;
use App\Dtos\TolerenceDto;
use App\Exceptions\CustomException;

class TolerenceFinderService
{
    public function __construct(
        private OrderCoinPairRepositoryInterface $coinPairRepository
    ) {
    }

    public function findTolerence(int $baseCoinId, int $tradeCoinId): TolerenceDto
    {
        $tolerence = settings('trading_price_tolerance');
        $coinPair = $this->coinPairRepository->findByCoinIds($baseCoinId, $tradeCoinId);
        if (!$coinPair) {
            throw new CustomException('Could not find coin pair');
        }

        $tolerancePrice = bcdivx(bcmulx($coinPair->price, $tolerence), '100');
        $highTolerance = bcaddx($coinPair->price, $tolerancePrice);
        $lowTolerance = bcsubx($coinPair->price, $tolerancePrice);

        return new TolerenceDto($lowTolerance, $highTolerance);
    }
}
