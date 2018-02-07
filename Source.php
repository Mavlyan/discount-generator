<?php

namespace Mygento\Discount\Generator;

abstract class Source
{
    const DISCOUNT_VERSION = '1.0.13';

    public static function getConstants()
    {
        return [
            'VERSION'         => self::DISCOUNT_VERSION,
            'NAME_UNIT_PRICE' => 'disc_hlpr_price',
            'NAME_ROW_DIFF'   => 'recalc_row_diff'
        ];
    }

    public static function getPublicProperties()
    {
        return [];
    }

    public static function getProtectedProperties()
    {

        return [
            [
                'name'     => 'generalHelper',
                'value'    => null,
                'comment' => ''
            ],
            [
                'name'     => '_entity',
                'value'    => null,
                'comment' => ''
            ],
            [
                'name'     => '_taxValue',
                'value'    => null,
                'comment' => ''
            ],
            [
                'name'     => '_taxAttributeCode',
                'value'    => null,
                'comment' => ''
            ],
            [
                'name'     => '_shippingTaxValue',
                'value'    => null,
                'comment' => ''
            ],
            [
                'name'     => '_discountlessSum',
                'value'    => 0.0,
                'comment' => ''
            ],
            [
                'name'     => '_wryItemUnitPriceExists',
                'value'    => false,
                'comment' => '@var bool Does item exist with price not divisible evenly? Есть ли item, цена которого не делится нацело'
            ],
            [
                'name'     => 'isSplitItemsAllowed',
                'value'    => false,
                'comment' => '@var bool Возможность разделять одну товарную позицию на 2, если цена не делится нацело'
            ],
            [
                'name'     => 'doCalculation',
                'value'    => true,
                'comment' => '@var bool Включить перерасчет?'
            ],
            [
                'name'     => 'spreadDiscOnAllUnits',
                'value'    => false,
                'comment' => '@var bool Размазывать ли скидку по всей позициям?'
            ],

        ];
    }

    public static function getPrivateProperties()
    {
        return [];
    }

    abstract public function getCopyright();

    public function getMethod_getRecalculated()
    {
        $comment = <<<'NOW'
            Returns all items of the entity (order|invoice|creditmemo) with properly calculated discount and properly calculated Sum
            @param %s $entity
            @param string $taxValue
            @param string $taxAttributeCode Set it if info about tax is stored in product in certain attr
            @param string $shippingTaxValue
            @return array with calculated items and sum
            @throws \Exception
NOW;
        $body = <<<'PHP'
            if (!$entity) {
                return;
            }

            if (!extension_loaded('bcmath')) {
                $this->generalHelper->addLog('Fatal Error: bcmath php extension is not available.');
                throw new \Exception('BCMath extension is not available in this PHP version.');
            }
            $this->_entity              = $entity;
            $this->_taxValue            = $taxValue;
            $this->_taxAttributeCode    = $taxAttributeCode;
            $this->_shippingTaxValue    = $shippingTaxValue;
            %s

            $globalDiscount = $this->getGlobalDiscount();

            $this->generalHelper->addLog("== START == Recalculation of entity prices. Helper Version: " . self::VERSION . ".  Entity class: " . get_class($entity) . ". Entity id: {$entity->getId()}");
            $this->generalHelper->addLog("Do calculation: " . ($this->doCalculation ? 'Yes' : 'No'));
            $this->generalHelper->addLog("Spread discount: " . ($this->spreadDiscOnAllUnits ? 'Yes' : 'No'));
            $this->generalHelper->addLog("Split items: " . ($this->isSplitItemsAllowed ? 'Yes' : 'No'));
            //Если есть RewardPoints - то калькуляцию применять необходимо принудительно
            if (!$this->doCalculation && ($globalDiscount !== 0.00)) {
                $this->doCalculation       = true;
                $this->isSplitItemsAllowed = true;
                $this->generalHelper->addLog("SplitItems and DoCalculation set to true because of global Discount (e.g. reward points)");
            }
            switch (true) {
                case (!$this->doCalculation):
                    $this->generalHelper->addLog("No calculation at all.");
                    break;
                case ($this->checkSpread()):
                    $this->applyDiscount();
                    $this->generalHelper->addLog("'Apply Discount' logic was applied");
                    break;
                default:
                    //Это случай, когда не нужно размазывать копейки по позициям
                    //и при этом, позиции могут иметь скидки, равномерно делимые.
                    $this->setSimplePrices();
                    $this->generalHelper->addLog("'Simple prices' logic was applied");
                    break;
            }
            $this->generalHelper->addLog("== STOP == Recalculation. Entity class: " . get_class($entity) . ". Entity id: {$entity->getId()}");

            return $this->buildFinalArray();
PHP;

        return [
            'comments' => $comment,
            'params'   => [
                'entity'           => 'none',
                'taxValue'         => '',
                'taxAttributeCode' => '',
                'shippingTaxValue' => '',
            ],
            'body'     => $body
        ];
    }

    public function getMethod_applyDiscount()
    {
        $comment = <<<'NOW'
            @SuppressWarnings(PHPMD.CyclomaticComplexity)
            @SuppressWarnings(PHPMD.NPathComplexity)
NOW;

        $body = <<<'PHP'
            $subTotal       = $this->_entity->getData('subtotal_incl_tax');
            $shippingAmount = $this->_entity->getData('shipping_incl_tax');
            $grandTotal     = round($this->_entity->getData('grand_total'), 2);
    
            /** @var float $superGrandDiscount Скидка на весь заказ. Например, rewardPoints или storeCredit */
            $superGrandDiscount = $this->getGlobalDiscount();
            $grandDiscount      = $superGrandDiscount;
    
            //Если размазываем скидку - то размазываем всё: (скидки товаров + $superGrandDiscount)
            if ($this->spreadDiscOnAllUnits) {
                $grandDiscount  = floatval($grandTotal - $subTotal - $shippingAmount);
            }
    
            $percentageSum = 0;
    
            $items      = $this->getAllItems();
            $itemsSum   = 0.00;
            foreach ($items as $item) {
                if (!$this->isValidItem($item)) {
                    continue;
                }
    
                $price       = $item->getData('price_incl_tax');
                $qty         = $item->getQty() ?: $item->getQtyOrdered();
                $rowTotal    = $item->getData('row_total_incl_tax');
                $rowDiscount = round((-1.00) * $item->getDiscountAmount(), 2);
    
                // ==== Start Calculate Percentage. The heart of logic. ====
    
                /** @var float $denominator Это знаменатель дроби (rowTotal/сумма).
                 * Если скидка должна распространиться на все позиции - то это subTotal.
                 * Если же позиции без скидок должны остаться без изменений - то это
                 * subTotal за вычетом всех позиций без скидок.*/
                $denominator = $subTotal - $this->_discountlessSum;
    
                if ($this->spreadDiscOnAllUnits || ($subTotal == $this->_discountlessSum) || ($superGrandDiscount !== 0.00)) {
                    $denominator = $subTotal;
                }
    
                $rowPercentage = $rowTotal / $denominator;
    
                // ==== End Calculate Percentage. ====
    
                if (!$this->spreadDiscOnAllUnits && (floatval($rowDiscount) === 0.00) && ($superGrandDiscount === 0.00)) {
                    $rowPercentage = 0;
                }
                $percentageSum += $rowPercentage;
    
                if ($this->spreadDiscOnAllUnits) {
                    $rowDiscount = 0;
                }
    
                $discountPerUnit = $this->slyCeil(($rowDiscount + $rowPercentage * $grandDiscount) / $qty);
    
                $priceWithDiscount = bcadd($price, $discountPerUnit, 2);
    
                //Set Recalculated unit price for the item
                $item->setData(self::NAME_UNIT_PRICE, $priceWithDiscount);
    
                $rowTotalNew = round($priceWithDiscount * $qty, 2);
                $itemsSum += $rowTotalNew;
    
                $rowDiscountNew = $rowDiscount + round($rowPercentage * $grandDiscount, 2);
    
                $rowDiff = round($rowTotal + $rowDiscountNew - $rowTotalNew, 2) * 100;
    
                $item->setData(self::NAME_ROW_DIFF, $rowDiff);
            }
    
            $this->generalHelper->addLog("Sum of all percentages: {$percentageSum}");
PHP;

        return [
            'comments' => $comment,
            'params'   => [],
            'body'     => $body
        ];
    }

    public function getMethod_getGlobalDiscount()
    {
        $comment = <<<'NOW'
Возвращает скидку на весь заказ (если есть). Например, rewardPoints или storeCredit.
Если нет скидки - возвращает 0.00
@return float
NOW;

        $body = <<<'PHP'
            $subTotal       = $this->_entity->getData('subtotal_incl_tax');
            $shippingAmount = $this->_entity->getData('shipping_incl_tax');
            $grandTotal     = round($this->_entity->getData('grand_total'), 2);
    
            return round($grandTotal - $subTotal - $shippingAmount - $this->_entity->getData('discount_amount'), 2);
PHP;

        return [
            'comments'   => $comment,
            'params'     => [],
            'body'       => $body,
            'visibility' => 'protected'
        ];
    }

    public function getMethod_setSimplePrices()
    {
        $comment = <<<'NOW'
If everything is evenly divisible - set up prices without extra recalculations
like applyDiscount() method does.
NOW;

        $body = <<<'PHP'
            $items    = $this->getAllItems();
            foreach ($items as $item) {
                if (!$this->isValidItem($item)) {
                    continue;
                }
    
                $qty               = $item->getQty() ?: $item->getQtyOrdered();
                $rowTotal          = $item->getData('row_total_incl_tax');
    
                $priceWithDiscount = ($rowTotal - $item->getData('discount_amount')) / $qty;
                $item->setData(self::NAME_UNIT_PRICE, $priceWithDiscount);
            }
PHP;

        return [
            'comments' => $comment,
            'params'   => [],
            'body'     => $body
        ];
    }

    public function getMethod_buildFinalArray()
    {
        $body = <<<'PHP'
            $grandTotal = round($this->_entity->getData('grand_total'), 2);
    
            $items      = $this->getAllItems();
            $itemsFinal = [];
            $itemsSum   = 0.00;
            foreach ($items as $item) {
                if (!$this->isValidItem($item)) {
                    continue;
                }
    
                $splitedItems = $this->getProcessedItem($item);
    
                $itemsFinal = array_merge($itemsFinal, $splitedItems);
            }
    
            //Calculate sum
            foreach ($itemsFinal as $item) {
                $itemsSum += $item['sum'];
            }
    
            $receipt = [
                'sum'            => $itemsSum,
                'origGrandTotal' => floatval($grandTotal)
            ];
    
            $shippingAmount = $this->_entity->getData('shipping_incl_tax') + 0.00;
            $itemsSumDiff   = round($this->slyFloor($grandTotal - $itemsSum - $shippingAmount, 3), 2);
    
            $this->generalHelper->addLog("Items sum: {$itemsSum}. Shipping increase: {$itemsSumDiff}");
    
            $shippingItem = [
                'name'     => $this->getShippingName($this->_entity),
                'price'    => $shippingAmount + $itemsSumDiff,
                'quantity' => 1.0,
                'sum'      => $shippingAmount + $itemsSumDiff,
                'tax'      => $this->_shippingTaxValue,
            ];
    
            $itemsFinal['shipping'] = $shippingItem;
            $receipt['items']       = $itemsFinal;
    
            if (!$this->_checkReceipt($receipt)) {
                $this->generalHelper->addLog("WARNING: Calculation error! Sum of items is not equal to grandTotal!");
            }
    
            $this->generalHelper->addLog("Final array:");
            $this->generalHelper->addLog($receipt);
    
            %s
PHP;

        return [
            'comments' => '',
            'params'   => [],
            'body'     => $body
        ];
    }

    public function getMethod__buildItem()
    {
        $body = <<<'PHP'
            $qty = $item->getQty() ?: $item->getQtyOrdered();
            if (!$qty) {
                throw new \Exception('Divide by zero. Qty of the item is equal to zero! Item: ' . $item->getId());
            }
    
            $entityItem = [
                'price' => round($price, 2),
                'name' => $item->getName(),
                'quantity' => round($qty, 2),
                'sum' => round($price * $qty, 2),
                'tax' => $taxValue,
            ];
    
            if (!$this->doCalculation) {
                $entityItem['sum']   = round($item->getData('row_total_incl_tax') - $item->getData('discount_amount'), 2);
                $entityItem['price'] = 1;
            }
    
            $this->generalHelper->addLog("Item calculation details:");
            $this->generalHelper->addLog("Item id: {$item->getId()}. Orig price: {$price} Item rowTotalInclTax: {$item->getData('row_total_incl_tax')} PriceInclTax of 1 piece: {$price}. Result of calc:");
            $this->generalHelper->addLog($entityItem);
    
            return $entityItem;
PHP;

        return [
            'comments'   => '',
            'params'     => [
                'item'     => 'none',
                'price'    => 'none',
                'taxValue' => '',
            ],
            'body'       => $body,
            'visibility' => 'protected'
        ];
    }

    public function getMethod_getProcessedItem()
    {
        $comment = <<<'NOW'
Make item array and split (if needed) it into 2 items with different prices

@param type $item
@return array
NOW;

        $body = <<<'PHP'
            $final = [];
    
            $taxValue = $this->_taxAttributeCode ? $this->addTaxValue($this->_taxAttributeCode, $item) : $this->_taxValue;
            $price    = !is_null($item->getData(self::NAME_UNIT_PRICE)) ? $item->getData(self::NAME_UNIT_PRICE) : $item->getData('price_incl_tax');
    
            $entityItem = $this->_buildItem($item, $price, $taxValue);
    
            $rowDiff = $item->getData(self::NAME_ROW_DIFF);
    
            if (!$rowDiff || !$this->isSplitItemsAllowed || !$this->doCalculation) {
                $final[$item->getId()] = $entityItem;
                return $final;
            }
    
            $qty = $item->getQty() ?: $item->getQtyOrdered();
    
            /** @var int $qtyUpdate Сколько товаров из ряда нуждаются в увеличении цены
             *  Если $qtyUpdate =0 - то цена всех товаров должна быть увеличина
             */
            $qtyUpdate = $rowDiff % $qty;
    
            //2 кейса:
            //$qtyUpdate == 0 - то всем товарам увеличить цену, не разделяя.
            //$qtyUpdate > 0  - считаем сколько товаров будут увеличены
    
            /** @var int "$inc + 1 коп" На столько должны быть увеличены цены */
            $inc = intval($rowDiff / $qty);
    
            $this->generalHelper->addLog("Item {$item->getId()} has rowDiff={$rowDiff}.");
            $this->generalHelper->addLog("qtyUpdate={$qtyUpdate}. inc={$inc} kop.");
    
            $item1 = $entityItem;
            $item2 = $entityItem;
    
            $item1['price'] = $item1['price'] + $inc / 100;
            $item1['quantity'] = $qty - $qtyUpdate;
            $item1['sum'] = round($item1['quantity'] * $item1['price'], 2);
    
            if ($qtyUpdate == 0) {
                $final[$item->getId()] = $item1;
    
                return $final;
            }
    
            $item2['price'] = $item2['price'] + 0.01 + $inc / 100;
            $item2['quantity'] = $qtyUpdate;
            $item2['sum'] = round($item2['quantity'] * $item2['price'], 2);
    
            $final[$item->getId() . '_1'] = $item1;
            $final[$item->getId() . '_2'] = $item2;
    
            return $final;
PHP;

        return [
            'comments' => $comment,
            'params'   => [
                'item'     => 'none',
            ],
            'body'     => $body
        ];
    }

    public function getMethod_getShippingName()
    {
        $body = <<<'PHP'
        return $entity->getShippingDescription()
            ?: ($entity->getOrder() ? $entity->getOrder()->getShippingDescription() : '');
PHP;

        return [
            'comments' => '',
            'params'   => [
                'entity'     => 'none',
            ],
            'body'     => $body
        ];
    }

    public function getMethod__checkReceipt()
    {
        $comment = <<<'NOW'
            Validation method. It sums up all items and compares it to grandTotal.
            @param array $receipt
            @return bool True if all items price equal to grandTotal. False - if not.
NOW;
        $body = <<<'PHP'
            $sum = array_reduce($receipt['items'], function ($carry, $item) {
                $carry += $item['sum'];
                return $carry;
            });
    
            return bcsub($sum, $receipt['origGrandTotal'], 2) === '0.00';
PHP;

        return [
            'comments'   => $comment,
            'params'     => [
                'receipt' => 'none',
            ],
            'body'       => $body,
            'visibility' => 'protected'
        ];
    }

    public function getMethod_isValidItem()
    {
        $body = <<<'PHP'
            return $item->getData('row_total_incl_tax') !== null;
PHP;

        return [
            'comments' => '',
            'params'   => [
                'item'     => 'none',
            ],
            'body'     => $body
        ];
    }

    public function getMethod_slyFloor()
    {
        $body = <<<'PHP'
            $factor  = 1.00;
            $divider = pow(10, $precision);
    
            if ($val < 0) {
                $factor = -1.00;
            }
    
            return (floor(abs($val) * $divider) / $divider) * $factor;
PHP;

        return [
            'comments' => '',
            'params'   => [
                'val'     => 'none',
                'precision'     => 2,
            ],
            'body'     => $body
        ];
    }

    public function getMethod_slyCeil()
    {
        $body = <<<'PHP'
            $factor  = 1.00;
            $divider = pow(10, $precision);
    
            if ($val < 0) {
                $factor = -1.00;
            }
    
            return (ceil(abs($val) * $divider) / $divider) * $factor;
PHP;

        return [
            'comments' => '',
            'params'   => [
                'val'     => 'none',
                'precision'     => 2,
            ],
            'body'     => $body
        ];
    }

    public function getMethod_addTaxValue()
    {
        $body = <<<'PHP'
            if (!$taxAttributeCode) {
                return '';
            }

            %s
PHP;

        return [
            'comments'   => '',
            'params'     => [
                'taxAttributeCode' => 'none',
                'item'             => 'none',
            ],
            'body'       => $body,
            'visibility' => 'protected'
        ];
    }

    public function getMethod_checkSpread()
    {
        $comment = <<<'NOW'
            It checks do we need to spread discount on all units and sets flag $this->spreadDiscOnAllUnits
            @return bool
NOW;
        $body = <<<'PHP'
            $items = $this->getAllItems();
    
            $sum                    = 0.00;
            $sumDiscountAmount      = 0.00;
            $this->_discountlessSum = 0.00;
            foreach ($items as $item) {
                $qty      = $item->getQty() ?: $item->getQtyOrdered();
                $rowPrice = $item->getData('row_total_incl_tax') - $item->getData('discount_amount');
    
                if (floatval($item->getData('discount_amount')) === 0.00) {
                    $this->_discountlessSum += $item->getData('row_total_incl_tax');
                }
    
                /* Означает, что есть item, цена которого не делится нацело*/
                if (!$this->_wryItemUnitPriceExists) {
                    $decimals = $this->getDecimalsCountAfterDiv($rowPrice, $qty);
    
                    $this->_wryItemUnitPriceExists = $decimals > 2 ? true : false;
                }
    
                $sum               += $rowPrice;
                $sumDiscountAmount += $item->getData('discount_amount');
            }
    
            $grandTotal     = round($this->_entity->getData('grand_total'), 2);
            $shippingAmount = $this->_entity->getData('shipping_incl_tax');
    
            //Есть ли общая скидка на Чек. bccomp returns 0 if operands are equal
            if (bccomp($grandTotal - $shippingAmount - $sum, 0.00, 2) !== 0) {
                $this->generalHelper->addLog("1. Global discount on whole cheque.");
    
                return true;
            }
    
            //ok, есть товар, который не делится нацело
            if ($this->_wryItemUnitPriceExists) {
                $this->generalHelper->addLog("2. Item with price which is not divisible evenly.");
    
                return true;
            }
    
            if ($this->spreadDiscOnAllUnits) {
                $this->generalHelper->addLog("3. SpreadDiscount = Yes.");
    
                return true;
            }
    
            return false;
PHP;

        return [
            'comments' => $comment,
            'params'   => [],
            'body'     => $body
        ];
    }

    public function getMethod_getDecimalsCountAfterDiv()
    {
        $body = <<<'PHP'
            $divRes   = strval(round($x / $y, 20));
            $decimals = strrchr($divRes, ".") ? strlen(strrchr($divRes, ".")) - 1 : 0;
    
            return $decimals;
PHP;

        return [
            'comments' => '',
            'params'   => [
                'x' => 'none',
                'y' => 'none',
            ],
            'body'     => $body
        ];
    }

    public function getMethod_getAllItems()
    {
        $body = <<<'PHP'
        return $this->_entity->getAllVisibleItems() 
            ? $this->_entity->getAllVisibleItems() 
            : $this->_entity->getAllItems();
PHP;

        return [
            'comments' => '',
            'params'   => [],
            'body'     => $body
        ];
    }

    public function getMethod_setIsSplitItemsAllowed()
    {
        $comment = <<<'NOW'
            @param bool $isSplitItemsAllowed
NOW;

        $body = <<<'PHP'
            $this->isSplitItemsAllowed = (bool)$isSplitItemsAllowed;
PHP;

        return [
            'comments' => $comment,
            'params'   => [
                'isSplitItemsAllowed' => 'none'
            ],
            'body'     => $body
        ];
    }

    public function getMethod_setDoCalculation()
    {
        $comment = <<<'NOW'
            @param bool $doCalculation
NOW;

        $body = <<<'PHP'
             $this->doCalculation = (bool)$doCalculation;
PHP;

        return [
            'comments' => $comment,
            'params'   => [
                'doCalculation' => 'none'
            ],
            'body'     => $body
        ];
    }

    public function getMethod_setSpreadDiscOnAllUnits()
    {
        $comment = <<<'NOW'
            @param bool $spreadDiscOnAllUnits
NOW;

        $body = <<<'PHP'
            $this->spreadDiscOnAllUnits = (bool)$spreadDiscOnAllUnits;
PHP;

        return [
            'comments' => $comment,
            'params'   => [
                'spreadDiscOnAllUnits' => 'none'
            ],
            'body'     => $body
        ];
    }






}
