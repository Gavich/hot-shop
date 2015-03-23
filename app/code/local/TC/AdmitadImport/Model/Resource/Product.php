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
            ->from(array('t1'=>'catalog_product_entity_varchar'), array('value') )
            ->join(array('t2'=>$this->getTable('catalog/product')),'t2.entity_id=t1.entity_id',array('sku'))
            ->where('t1.attribute_id = ?',$codeAttribute);

        return  $this->_getReadAdapter()->fetchPairs($select);
    }

    /**
     * Get Configurable Attribute for all existed products
     *
     * @return array
     */
    public function getConfigurableAttribute()
    {
        $select = $this->_getReadAdapter()->select()
            ->from(array('t1'=>'catalog_eav_attribute'), array('attribute_id') )
            ->join(array('t2'=>'eav_attribute'),'t1.attribute_id=t2.attribute_id',array('attribute_code'))
            ->where('t2.frontend_input = ?','select')
            ->where('t1.is_global = ?',1)
            ->where('t1.is_configurable = ?',1)
            ->where('t1.is_filterable = ?',1);

        $result = $this->_getReadAdapter()->fetchPairs($select);
        return  $result;
    }

    /**
     * Get Configurable Products Ids for all existed products
     *
     * @return array
     */
    public function getConfigurablesId(){
        $select = $this->_getReadAdapter()->select()
            ->from(array('t1'=>$this->getTable('catalog/product')), array('sku', 'entity_id'))
            ->where('t1.type_id = ?',"configurable");
        $result = array();
        foreach($this->_getReadAdapter()->fetchPairs($select) as $sku => $id){
            $result[$sku] = array('id' => $id);
        }
        return  $result;
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
