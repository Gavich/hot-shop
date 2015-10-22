<?php

/**
 * @category   TC
 * @package    TC_AdmitadImport
 * @author     Alexandr Smaga <smagaan@gmail.com>
 */
class TC_AdmitadImport_Helper_Attributes extends Mage_Core_Helper_Abstract
{
    /**
     * @var array(ATTRIBUTE_CODE => DATA_KEY)
     */
    private $_map;
    /**
     * @var array(ATTRIBUTE_CODE => [type: CUSTOM_OPTION_TYPE, name:CUSTOM_OPTION_NAME])
     */
    private $_customOptions;

    /** @var bool */
    private $_initialized = false;

    /** @var array Attributes object pool */
    private $_attributes;

    /** @var array Attribute option collection object pool */
    private $_attributeOptionCollection;

    /** @var array Attribute options runtime cache */
    private $_attributeOptionsRuntimeCache;

    private $_colorMap;

    public function checkAttributeCreated($code)
    {
        $objCatalogEavSetup = Mage::getResourceModel('catalog/eav_mysql4_setup', 'core_setup');
        $attrId = $objCatalogEavSetup->getAttributeId(Mage_Catalog_Model_Product::ENTITY, $code);

        if($attrId){
            return $attrId;
        }else{
            return false;
        }

    }
    public function createAttribute($code, $label)
    {

        switch($code){
            case 'brand_filter':
                $position = 2;
                break;
            case 'color_filter':
                $position = 3;
                break;
            case 'size_filter':
                $position = 4;
                break;
            case 'season_filter':
                $position = 5;
                break;
            default:
                $position = 0;
                break;
        }

            $data = array(
                'attribute_code' => $code,
                'is_global' => 1,
                'frontend_input' => 'select',
                'default_value_text' => '',
                'default_value_yesno' => 0,
                'default_value_date' => '',
                'default_value_textarea' => '',
                'is_unique' => 0,
                'is_required' => 0,
                'frontend_class' => '',
                'is_configurable' => 1,
                'is_searchable' => 0,
                'is_visible_in_advanced_search' => 0,
                'is_comparable' => 0,
                'is_filterable' => 1,
                'is_filterable_in_search' => 1,
                'is_used_for_promo_rules' => 0,
                'position' => $position,
                'is_html_allowed_on_front' => 1,
                'is_visible_on_front' => 0,
                'used_in_product_listing' => 0,
                'frontend_label' => array(
                    '0' => ucfirst($label),
                    '1' => ucfirst($label)),
            );

            /* @var $helper Mage_Catalog_Helper_Product */
            $helper = Mage::helper('catalog/product');
            /* @var $model Mage_Catalog_Model_Entity_Attribute */
            $model = Mage::getModel('catalog/resource_eav_attribute');

            $entityTypeId = Mage::getModel('eav/entity')->setType(Mage_Catalog_Model_Product::ENTITY)->getTypeId();

            $data['backend_type'] = $model->getBackendTypeByInput($data['frontend_input']);
            $data['source_model'] = $helper->getAttributeSourceModelByInputType($data['frontend_input']);
            $data['backend_model'] = $helper->getAttributeBackendModelByInputType($data['frontend_input']);
            $model->addData($data);

            $modelGroup = Mage::getModel('eav/entity_setup','core_setup');
            $setid = $modelGroup->getAttributeSetId('catalog_product','Default');

            $group = Mage::getModel('eav/entity_attribute_group')
                ->getCollection()
                ->addFieldToFilter('attribute_set_id', $setid)
                ->setOrder('attribute_group_id','ASC')
                ->getFirstItem();
            $groupId = $group->getId();



            $model->setAttributeSetId($setid);
            $model->setAttributeGroupId($groupId);

            $model->setEntityTypeId($entityTypeId);
            $model->setIsUserDefined(1);
            try{
                $model->save();
            }catch (Exception $e){
                Mage::logException($e);
            }



    }

    public function addToFilter($attributeCode)
    {
       $idFilterAttr = Mage::getResourceModel('amshopby/filter')->addFilterAttribute($this->checkAttributeCreated($attributeCode));
        Mage::app()->cleanCache(array('amshopby'));
        return $idFilterAttr;
    }

    public function saveFilter($filter_id, $attributeCode)
    {
        $display_type = '4';
        $max_options = '18';

        switch ($attributeCode){
            case 'color_filter':
                $display_type = '1';
                break;
            case 'brand_filter':
                $display_type = '3';
                $max_options = '0';
                break;
            case 'size_filter':
                $max_options = '6';
                break;
            default:
                break;
        }

        $data = array(
            'block_pos' => 'left',
            'display_type' => $display_type,
            'show_search' => '0',
            'max_options' => $max_options,
            'hide_counts' => '0',
            'sort_by' => '0',
            'collapsed' => '0',
            'comment' => '',
            'show_on_list' => '0',
            'show_on_view' => '0',
            'seo_nofollow' => '0',
            'seo_noindex' => '0',
            'seo_rel' => '0',
            'include_in' => '',
            'exclude_from' => '',
            'single_choice' => '0',
            'depend_on' => '',
            'depend_on_attribute' => '',
            'page' => '1',
            'limit' => '20',
            'option_id' => '',
            'title' => '',
            'url_alias' => '',
        );

        $model  = Mage::getModel('amshopby/filter');
        $model->setData($data);
        $model->setId($filter_id);
        try{
            $model->save();
        }catch (Exception $e){}
        Mage::app()->cleanCache(array('amshopby'));
    }

    /**
     * Maps given data to correct attributes
     *
     * @param array $data
     *
     * @return array
     */

    public function getMappedValues(array $data)
    {
        $this->_init();

        $attributes           = array();
        $attributesSourceData = array_merge($data, $data['param']);
        foreach ($this->_map as $attributeCode => $sourceCode) {
            /*if(!$this->checkAttributeCreated($attributeCode) && $attributeCode != 'size' && $attributeCode != 'sizes'){
                $this->createAttribute($attributeCode,$sourceCode);
                $filter_id = $this->addToFilter($attributeCode);
                $this->saveFilter($filter_id, $attributeCode);
            }*/
            if (!empty($attributesSourceData[$sourceCode])) {
                $attributes[$attributeCode] = $this->_prepareAttributeValue(
                    $attributeCode, $attributesSourceData[$sourceCode]
                );
            }
        }

        return $attributes;
    }

    /**
     * Process custom options save depends on configuration
     *
     * @param Mage_Catalog_Model_Product $product
     *
     * @throws LogicException
     *
     * @return bool
     */
    public function processCustomOptions(Mage_Catalog_Model_Product $product)
    {
        /* @var $optionModel Mage_Catalog_Model_Product_Option */
        $optionModel = clone $product->getOptionInstance();
        $optionModel->setProduct($product);

        foreach ((array)$this->_customOptions as $attributeCode => $config) {
            $optionTitle = isset($config['title']) ? $config['title'] : $attributeCode;

            if ($product->hasData($attributeCode)) {
                $data = explode(',', $product->getData($attributeCode));

                if (Mage_Catalog_Model_Product_Option::OPTION_TYPE_DROP_DOWN === $config['type']) {
                    $options = $optionModel->getOptions();

                    $optionConfig   = array_merge(array('values' => array(), 'is_require' => true), $config);
                    $existOptionKey = null;
                    foreach ((array)$options as $k => $option) {
                        if ($option['title'] === $optionTitle) {
                            $existOptionKey = $k;
                            $optionConfig   = array_merge($optionConfig, $option);
                        }
                    }

                    foreach ($data as $value) {
                        $value = trim($value);

                        $isExisted = array_filter(
                            $optionConfig['values'],
                            function ($valueConfig) use ($value) {
                                return $valueConfig['title'] === $value;
                            }
                        );

                        if (!$isExisted) {
                            $optionConfig['values'][] = array(
                                'title'      => $value,
                                'price_type' => 'fixed'
                            );
                        }
                    }

                    if (null == $existOptionKey) {
                        $options[] = $optionConfig;
                    } else {
                        $options[$existOptionKey] = $optionConfig;
                    }

                    $optionModel->setOptions($options);
                } else {
                    throw new LogicException('Only drop down custom option supported');
                }

                $product->unsetData($attributeCode);
            }
        }

        if ($optionModel->getOptions()) {
            $resource = $product->getResource();

            $resource->getWriteConnection()->update(
                $resource->getEntityTable(), array('has_options' => true), sprintf('entity_id = %d', $product->getId())
            );

            $optionModel->saveOptions();
        }
        unset($optionModel);
    }

    /**
     * Initialize helper
     *
     * @throws RuntimeException
     */
    private function _init()
    {
        if (!$this->_initialized) {
            $file    = Mage::getBaseDir('var') . DS . 'data' . DS . 'attributes.json';
            $content = file_get_contents($file);

            if (false === $content) {
                throw new RuntimeException('Could not read attributes map from data folder');
            }

            $data = Zend_Json::decode($content);
            if (!is_array($data) || empty($data['map']) || empty($data['custom_options'])) {
                throw new RuntimeException('Malformed attributes data');
            }

            $this->_map           = $data['map'];
            $this->_customOptions = $data['custom_options'];
            $this->_initialized   = true;
        }
    }

    public function addColorImage($color)
    {
        if (!$this->_colorMap) {
            $file    = Mage::getBaseDir('var') . DS . 'data' . DS . 'color.json';
            $content = file_get_contents($file);

            if (false === $content) {
                throw new RuntimeException('Could not read attributes map from data folder');
            }

            $data = Zend_Json::decode($content);

            $this->_colorMap  = $data['color'];
        }

        $model  = Mage::getModel('amshopby/value');
        $collection = $model->getCollection()->addFilter('title', $color)->load();
        $id = null;
        foreach($collection as $_item){
            $id = $_item->getId();
        }
        $value = $model->load($id);

        $data = array(
            'is_featured' => '0',
            'featured_order' => '0',
            'title' => $color,
            'url_alias' => '',
            'descr' => '',
            'cms_block' => '',
            'meta_title' => $color,
            'meta_descr' => '',
            'meta_kw' => '',

        );

        $field = 'img_small';
        if(isset($this->_colorMap[mb_strtolower($color, 'UTF-8')])){
            $path = Mage::getBaseDir('media') . DS . 'amshopby' . DS;
            if(!file_exists($path)){
                mkdir($path);
            }
            $name = 'small'.$id.'.png';
            $rgb = explode(' ',$this->_colorMap[mb_strtolower($color, 'UTF-8')]);
            $r = $rgb[0];
            $g = $rgb[1];
            $b = $rgb[2];
            $img = imagecreate(20,20);
            $border = imagecolorallocate($img, 0,0,0);
            $body = imagecolorallocate($img, $r,$g,$b);
            imagefilledrectangle($img,1,1,18,18,$body);
            imagefilltoborder($img, 0, 0, $border, $body);
            imagepng($img,$path.$name);
            imagedestroy($img);
        }else{
            $name = "multi-color.png";
        }
        $data[$field] = $name;

        $value->setData($data)->setId($id);
         try{
             $value->save();
        }catch (Exception $e){}
        Mage::app()->cleanCache(array('amshopby'));
    }

    /**
     * Prepare attribute value based on it's type
     *
     * @param string $attributeCode
     * @param mixed  $value
     *
     * @return array|float|int|string
     */
    private function _prepareAttributeValue($attributeCode, $value)
    {
        $attribute = $this->_getAttribute($attributeCode);

        if (is_object($attribute)) {
            switch ($attribute->getData('frontend_input')) {
                case 'multiselect':
                    $value = $this->prepareAttributeOptions($attributeCode, $value);
                    $attribute = $this->_getAttribute($attributeCode);
                    $newValue = array();
                    break;
                case 'select':
                    /*$this->_prepareAttributeOptions($attribute, is_array($value) ? $value : explode(',', $value));*/
                    $value = $this->prepareAttributeOptions($attributeCode, $value);
                    $attribute = $this->_getAttribute($attributeCode);
                    $newValue = array();
                    break;
                case 'decimal':
                    $newValue = floatval($value);
                    break;
                case 'int':
                    $newValue = (int)$value;
                    break;
                default:
                    $newValue = (string)$value;
                    break;
            }
            if ($attribute->usesSource()) {

                $optionCollection = $this->_getAttributeOptionCollection($attribute);
                $options          = $optionCollection->toOptionArray();

                if ('multiselect' === $attribute->getData('frontend_input')) {
                    foreach ($options as $item) {
                        if (in_array($item['label'], $value)) {
                            $newValue[] = $item['value'];
                        }
                    }
                } else {
                    $setValue = null;
                    foreach ($options as $item) {
                        if (in_array($item['label'], $value)) {
                            $newValue = $item['value'];
                            break;
                        }
                    }
                }
            }
        } else {
            $newValue = $value;
        }

        return $newValue;
    }


    public function prepareAttributeOptions($attributeCode, $value)
    {
        $prepareValue = array();
        if(!is_array($value)){
            $value = explode(',',$value);
        }
        foreach($value as $options){
            $prepareValue[] = $options = ($attributeCode == 'color_filter')?
                mb_strtolower(trim($options), 'UTF-8'):
                trim($options);

            if(!$this->attributeValueExists($attributeCode, $options)){
                $this->addAttributeValue($attributeCode, $options);
                $this->addToFilter($attributeCode);
            }
            if($attributeCode == 'color_filter'){
                $this->addColorImage($options);
            }

        }
        Mage::app()->cleanCache(array('amshopby'));
        unset($this->_attributes[$attributeCode]);
        return $prepareValue;
    }

    public function addAttributeValue($arg_attribute, $arg_value)
    {
        $attribute_model        = Mage::getModel('eav/entity_attribute');
        $attribute_code         = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute              = $attribute_model->load($attribute_code);

        $value['option'] = array($arg_value,$arg_value);
        $result = array('value' => $value);
        $attribute->setData('option',$result);
        $attribute->save();
        unset($this->_attributeOptionCollection[$attribute->getId()]);
    }

    function attributeValueExists($arg_attribute, $arg_value)
    {
        $attribute_model        = Mage::getModel('eav/entity_attribute');
        $attribute_options_model= Mage::getModel('eav/entity_attribute_source_table') ;

        $attribute_code         = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute              = $attribute_model->load($attribute_code);
        $options                = $attribute_options_model->setAttribute($attribute)->getAllOptions(false);

        foreach($options as $option)
        {
            if ($option['label'] == $arg_value)
            {
                return true;
            }
        }

        return false;
    }
    /**
     * Prepare attribute options and adds if not exist
     *
     * @param Mage_Eav_Model_Entity_Attribute $attribute
     * @param array|string                    $value
     */
    private function _prepareAttributeOptions(Mage_Eav_Model_Entity_Attribute $attribute, $value)
    {
        if ($attribute->getSource() instanceof Mage_Eav_Model_Entity_Attribute_Source_Table) {
            if (!is_array($value)) {
                $value = array($value);
            }
            sort($value);
            $cacheKey = md5($attribute->getId() . implode('', $value));
            if (in_array($cacheKey, $this->_attributeOptionsRuntimeCache)) {
                return;
            }
            $this->_attributeOptionsRuntimeCache[] = $cacheKey;
            $collection                            = $this->_getAttributeOptionCollection($attribute);
            $optionsData                           = $collection->toOptionArray();
            $labels                                = array();
            foreach ($optionsData as $option) {
                $labels[] = $option['label'];
            }

            $value = array_diff($value, $labels);

            if (!empty($value)) {
                $_option = array();
                foreach ($optionsData as $option) {
                    $_option['value'][$option['value']][0] = $option['label'];
                }
                $i = 0;
                foreach ($value as $label) {
                    $_option['value']['options_' . $i][0] = $label;
                    $_option['order']['options_' . $i]    = 0;
                    $i++;
                }
                $attribute->setData('option', $_option);
                $attribute->save();
                $collection->clear();
                $collection->load();
            }
        }
    }

    /**
     * Returns options collection
     *
     * @param Mage_Core_Model_Abstract $attribute
     *
     * @return Mage_Eav_Model_Resource_Entity_Attribute_Option_Collection
     */
    private function _getAttributeOptionCollection(Mage_Core_Model_Abstract $attribute)
    {
        $attributeId      = $attribute->getId();
        $attributeStoreId = $attribute->getData('store_id');
        if (!isset($this->_attributeOptionCollection[$attributeId])) {
            /** @var $collection Mage_Eav_Model_Resource_Entity_Attribute_Option_Collection */
            $collection = Mage::getResourceModel('eav/entity_attribute_option_collection');
            $collection->setAttributeFilter($attributeId);
            $collection->setStoreFilter($attributeStoreId);
            $collection->load();
            $this->_attributeOptionCollection[$attributeId] = $collection;
        }

        return $this->_attributeOptionCollection[$attributeId];
    }

    /**
     * Retrieve eav entity attribute model
     *
     * @param string $code
     *
     * @return Mage_Eav_Model_Entity_Attribute
     */
    private function _getAttribute($code)
    {
        if (!isset($this->_attributes[$code])) {
            /* @var $resource Mage_Catalog_Model_Resource_Product */
            $resource                 = Mage::getResourceModel('catalog/product');
            $this->_attributes[$code] = $resource->getAttribute($code);
        }

        return $this->_attributes[$code];
    }
}
