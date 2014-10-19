<?php

/**
 * @category   TC
 * @package    TC_AdmitadImport
 * @author     Alexandr Smaga <smagaan@gmail.com>
 */
class TC_AdmitadImport_Model_Resource_Product extends Mage_Catalog_Model_Resource_Product
{
    const BATCH_SIZE = 1000;

    public $_config;
    const CONF_TABLE = 'art_init';
    const TEXT_ATTRIBUTES_TABLE = 'texts';
    protected $_adapter;


    /**
     * Get SKUs for all existed products
     *
     * @return array
     */
    public function getSKUs()
    {
        $select = $this->_getReadAdapter()->select()
            ->from($this->getTable('catalog/product'), array('sku', 'entity_id'));

        return $this->_getReadAdapter()->fetchPairs($select);
    }
    /**
     * Get Urls for all existed products
     *
     * @return array
     */
    public function getURLs()
    {
        $eavAttribute = new Mage_Eav_Model_Mysql4_Entity_Attribute();
        $codeAttribute = $eavAttribute->getIdByCode('catalog_product', 'ad_redirect_url');
        $select = $this->_getReadAdapter()->select()
            ->from(array('t2'=>'catalog_product_entity_varchar'), array('value') )
            ->join(array('t1'=>$this->getTable('catalog/product')),'t1.entity_id=t2.entity_id',array('sku'))
            ->where('t2.attribute_id = ?',$codeAttribute);

        return  $this->_getReadAdapter()->fetchPairs($select);
    }

    /**
     * Get Configurable Products Ids for all existed products
     *
     * @return array
     */
    public function getConfigurablesId(){
        $select = $this->_getReadAdapter()->select()
            ->from($this->getTable('catalog/product'), array('sku', 'entity_id'))
            ->where('type_id = "configurable"');
        return  $this->_getReadAdapter()->fetchPairs($select);
    }

    public function getAdapter()
    {
        if(!$this->_adapter){
            $this->_adapter = Mage::getModel('core/resource')->getConnection('core_write');
        }
        return $this->_adapter;
    }

    /**
     * Update status for given SKUs
     *
     * @param array $skus
     * @param int   $value
     *
     * @throws Exception
     */
    public function updateStatusAttributeValue(array $skus, $value)
    {
        /* @var $action Mage_Catalog_Model_Product_Action */
        $object = new Varien_Object();
        $object->setIdFieldName('entity_id');

        $adapter = $this->_getReadAdapter();
        $select  = $adapter->select()
            ->from($this->getEntityTable(), 'entity_id')
            ->where('sku IN (?)', $skus);
        $ids     = $adapter->fetchCol($select);

        $adapter = $this->_getWriteAdapter();
        $adapter->beginTransaction();
        try {
            $attribute = $this->getAttribute('status');

            $i = 0;
            foreach ($ids as $id) {
                $i++;
                $object->setId($id);
                // collect data for save
                $this->_saveAttributeValue($object, $attribute, $value);
                // save collected data every 1000 rows
                if ($i % self::BATCH_SIZE == 0) {
                    $this->_processAttributeValues();
                }
            }
            $this->_processAttributeValues();
            $adapter->commit();
        } catch (Exception $e) {
            $adapter->rollBack();
            throw $e;
        }
    }

    public function getConfig(){
        if($this->_config == null){
            $this->_config = Mage::getModel('tc_admitadimport/product_config');
        }

        return $this->_config;
    }
}
