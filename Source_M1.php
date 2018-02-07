<?php

namespace Mygento\Discount\Generator;

class Source_M1 extends Source
{

    public function getCopyright($package = '')
    {
        $y = date("Y");
        return "/**
 * @author Mygento
 * @package {$package}
 * @copyright {$y} NKS LLC. (https://www.mygento.ru)
 */\n\n";
    }

    public static function getProtectedProperties()
    {
        $code = [
            'name'    => '_code',
            'value'   => null,
            'comment' => ''
        ];

        $properties = parent::getProtectedProperties();
        return array_merge([$code], $properties);
    }

    public function getMethod_buildFinalArray()
    {
        $method = call_user_func(['parent', __FUNCTION__]);

        $placeholder = <<<'PHP'
            $receiptObj = (object) $receipt;
    
            Mage::dispatchEvent('mygento_discount_recalculation_after', array('modulecode' => $this->_code, 'receipt' => $receiptObj));
            
            return (array)$receiptObj;
PHP;

        $method['body'] = sprintf($method['body'], $placeholder);

        return $method;
    }

    public function getMethod_getRecalculated()
    {
        $method = call_user_func(['parent', __FUNCTION__]);

        $placeholder = 'Mage_Sales_Model_Order|Mage_Sales_Model_Order_Invoice|Mage_Sales_Model_Order_Creditmemo';
        $method['comments'] = sprintf($method['comments'], $placeholder);

        $placeholder = '$this->generalHelper        = Mage::helper($this->_code);';
        $method['body'] = sprintf($method['body'], $placeholder);

        return $method;
    }

    public function getMethod_addTaxValue()
    {
        $method = call_user_func(['parent', __FUNCTION__]);

        $placeholder = <<<'PHP'
            $storeId  = $this->_entity->getStoreId();
            $store    = $storeId ? Mage::app()->getStore($storeId) : Mage::app()->getStore();

            $taxValue = Mage::getResourceModel('catalog/product')->getAttributeRawValue(
                $item->getProductId(),
                $taxAttributeCode,
                $store
            );

            $attributeModel = Mage::getModel('eav/entity_attribute')->loadByCode('catalog_product', $taxAttributeCode);
            if ($attributeModel->getData('frontend_input') == 'select') {
                $taxValue = $attributeModel->getSource()->getOptionText($taxValue);
            }

            return $taxValue;
PHP;

        $method['body'] = sprintf($method['body'], $placeholder);

        return $method;
    }
}
