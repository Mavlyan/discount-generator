<?php

namespace Mygento\Discount\Generator;

class Source_M2 extends Source
{
    public function getCopyright()
    {
        return "/**
 * @author Mygento
 * @copyright See COPYING.txt for license details.
 * @package Mygento_Base
 */\n\n";
    }

    public function getMethod_getRecalculated()
    {
        $method = call_user_func(['parent', __FUNCTION__]);

        $comments = sprintf($method['comments'], '\Magento\Sales\Model\Order\Invoice|\Magento\Sales\Model\Order\Creditmemo|\Magento\Sales\Model\Order');
        $method['comments'] = explode("\n            ", $comments);

        $method['body'] = sprintf($method['body'], '');

        return $method;
    }

    public function getMethod_buildFinalArray()
    {
        $method = call_user_func(['parent', __FUNCTION__]);
        $method['body'] = sprintf($method['body'], 'return $receipt;');

        return $method;
    }

    public function getMethod_addTaxValue()
    {
        $method = call_user_func(['parent', __FUNCTION__]);

        $placeholder = 'return $this->generalHelper->getAttributeValue($taxAttributeCode, $item->getProductId());';
        $method['body'] = sprintf($method['body'], $placeholder);

        return $method;
    }
}
