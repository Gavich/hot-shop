<?php

/**
 * @category   TC
 * @package    TC_AdmitadImport
 * @author     Alexandr Smaga <smagaan@gmail.com>
 */
class TC_AdmitadImport_Processor_Products extends TC_AdmitadImport_Processor_AbstractProcessor
{
    const CUSTOM_CATEGORY_LEVEL = 2;
    const BATCH_SIZE            = 100;

    /** @var array */
    private $_processedSKUs = array();

    /** @var array */
    private $_existSKUs = array();

    /** @var int */
    private $_attributeSetId;

    /** @var int */
    private $_websiteId;

    /** @var Mage_Catalog_Model_Resource_Category_Collection */
    private $_categoryCollection;

    /** @var bool */
    private $_canIncludeInParentCategory = false;

    /** @var TC_AdmitadImport_Helper_Currency */
    private $_currencyHelper;

    /** @var TC_AdmitadImport_Helper_Images */
    private $_helperImages;

    /** @var array */
    private $_existURLs = array();

    public $_existConfigurablesId;

    /**
     * Performs import
     *
     * @param TC_AdmitadImport_Reader_DataInterface $data
     *
     * @return void
     */
    public function process(TC_AdmitadImport_Reader_DataInterface $data)
    {
        $this->_beforeProcess();
        $this->_getLogger()->log('Products import started');

        // fetching store from settings if needed could be done here
        $website          = Mage::app()->getWebsite(true);
        $defaultStore     = $website->getDefaultStore();
        $this->_websiteId = $website->getId();
        // fetching attribute set id if needed could be done here
        $this->_attributeSetId = $this->_getResourceUtilityModel()->getEntityType()->getDefaultAttributeSetId();
        $this->_currencyHelper->init($data);

        $products = $data->getProducts();

        // mark existed products as processed
        $this->_processedSKUs = array_keys(array_intersect_key($products, $this->_existSKUs));

        // process product import
        $this->_processProducts($products, $defaultStore);

         $this->_afterProcess();
        $this->_getLogger()->log('Products import ended');
    }

    public function  existURL($product)
    {
        $url = $product->getAttributeText('ad_redirect_url');//getAdRedirectUrl();
        //$url = trim($url);
        if(array_key_exists($url,$this->_existURLs)){
            $this->updateProduct($product, $this->_existURLs[$url]);
            return true;
        }
        $this->_existURLs[$url] = $product->getSku();
        return false;
    }


    public function updateProduct($product, $sku)
    {
       $sizeFilter = $product->getSizeFilter();
       $colorFilter = $product->getColorFilter();
       $color = ($product->getColor())? explode(', ',$product->getColor()): 0;
       $updateProduct = Mage::getModel('catalog/product')->loadByAttribute('sku', $sku);
       $updateSizeFilter = ($updateProduct->getSizeFilter())? explode(',',$updateProduct->getSizeFilter()): array();
       $updateColorFilter = ($updateProduct->getColorFilter())? explode(',',$updateProduct->getColorFilter()): array();
       $updateColor = ($updateProduct->getColor())? explode(', ',$updateProduct->getColor()): array();

       $arrayOptions = array(
           'size_filter' => array($sizeFilter, $updateSizeFilter ),
           'color_filter' => array($colorFilter,$updateColorFilter),
           'color' => array($color,$updateColor),
       );

      foreach($arrayOptions as $key => $arrays){
          if(!is_array($arrays[0])){
              continue;
          }
          $difference = array_diff($arrays[0],$arrays[1]);
          $merge = array_merge($arrays[1], $difference);
          if($key == 'color'){
              $merge = implode(', ',$merge);
          }
          $updateProduct->setData($key, $merge);
      }
        $updateProduct->save();
    }

    /**
     * Process products
     *
     * @param array                 $products
     * @param Mage_Core_Model_Store $store
     *
     * @throws Exception
     */
    private function _processProducts(array $products, Mage_Core_Model_Store $store)
    {
        /* @var $helper TC_AdmitadImport_Helper_Attributes */
        $helper = Mage::helper('tc_admitadimport/attributes');
        $persisted    = 0;

        $this->_getResourceUtilityModel()->beginTransaction();
        foreach ($products as $sku => $productData) {
            try {
                if (!array_key_exists($sku, $this->_existSKUs) && !in_array($sku, $this->_processedSKUs)) {
                    $product = $this->_prepareProduct($productData, $store);
                    if(!array_key_exists($productData['group_id'],$this->_existConfigurablesId)){
                        $this->createConfigurableProduct(clone $product,$productData);
                    }
                    $product->setData('sku', $sku);
                    if(!$this->existURL($product)){

                        $product->setData('name', ucfirst($product->getData('name'))); // enforce first capital letter

                        $product->getResource()->save($product);
                        $this->_saveStockItem($product);
                        $helper->processCustomOptions($product);
                        $this->_helperImages->collectData($product, $productData);

                        $this->_getLogger()->log(sprintf('Product with SKU: %s processed', $sku));

                        $persisted++;

                        if (0 === $persisted % self::BATCH_SIZE) {
                            $this->_getResourceUtilityModel()->commit();
                            $this->_getResourceUtilityModel()->beginTransaction();
                            $this->_helperImages->processImages();
                        }

                        $product->clearInstance();
                    }else{
                        $product->clearInstance();
                        continue;
                    }
                } else {
                    $this->_getLogger()->log(sprintf('Product with SKU: %s already exist, skipping..', $sku));
                }

                // save sku anyway to process additional actions
                $this->_processedSKUs[] = $sku;
            } catch (TC_AdmitadImport_Exception_InvalidItemException $e) {
                $this->_getLogger()->log(sprintf('%s, Product SKU: %s', $e->getMessage(), $sku), Zend_Log::ERR);
                continue;
            } catch (Exception $e) {
                $this->_getResourceUtilityModel()->rollBack();
                $this->_getResourceUtilityModel()->beginTransaction();

                $this->_getLogger()->log($e->getMessage(), Zend_Log::CRIT);
                throw $e;
            }
        }

        $this->_getResourceUtilityModel()->commit();
        $this->_helperImages->processImages();
    }

    public function createConfigurableProduct($product,$productData)
    {
        $sku = $productData['group_id'];
        $product->setData('sku',$sku);
        $product->setData('type_id','configurable');
        $product->setData('visibility',Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH);
        $product->setData('name', ucfirst($product->getData('name'))); // enforce first capital letter
        $product->getResource()->save($product);
       // $this->_saveStockItem($product);
        Mage::helper('tc_admitadimport/attributes')->processCustomOptions($product);
        $this->_helperImages->collectData($product, $productData);
        $this->_existConfigurablesId[$sku] = $product->getId();

        $this->_getLogger()->log(sprintf('Product with SKU: %s processed', $sku));
    }

    /**
     * Prepare product model
     *
     * @param array                 $data
     * @param Mage_Core_Model_Store $store
     *
     * @return Mage_Catalog_Model_Product
     */
    private function _prepareProduct(array $data, Mage_Core_Model_Store $store)
    {
        /* @var $catalogProduct Mage_Catalog_Model_Product */
        $catalogProduct = Mage::getModel('catalog/product');
        $catalogProduct->setData('type_id', $this->_getProductTypeId());
        $catalogProduct->setData('ignore_url_key', true);
        $catalogProduct->setData('is_mass_update', true);
        $catalogProduct->setData('exclude_url_rewrite', true);
        $catalogProduct->setData('store_id', $store->getId());

        $this->_prepareCategories($data, $catalogProduct);
        $this->_prepareAttributes($data, $catalogProduct);
        $this->_preparePrices($data, $catalogProduct);

        return $catalogProduct;
    }

    /**
     * Prepare product categories
     *
     * @param array                      $data
     * @param Mage_Catalog_Model_Product $product
     *
     * @throws TC_AdmitadImport_Exception_InvalidItemException
     */
    protected function _prepareCategories(array $data, Mage_Catalog_Model_Product $product)
    {
        $categoryId = isset($data['categoryId']) ? trim($data['categoryId']) : 0;
        /** @var Mage_Catalog_Model_Category $category */
        $category = $this->_getCategoryCollection()->getItemByColumnValue(
            TC_AdmitadImport_Processor_Categories::ORIGIN_ID_ATTRIBUTE_CODE, $categoryId
        );

        if (null === $category) {
            throw new TC_AdmitadImport_Exception_InvalidItemException($data, array('Category not found.'));
        }

        $categoryIds = array($category->getId());
        if ($this->_canIncludeInParentCategory) {
            $pathCategoryIds = $category->getPathIds();
            $categoryIds     = array_merge($categoryIds, array_slice($pathCategoryIds, self::CUSTOM_CATEGORY_LEVEL));
        }

        $product->setCategoryIds($categoryIds);
    }

    /**
     * Prepare price data
     *
     * @param array                      $data
     * @param Mage_Catalog_Model_Product $product
     *
     * @throws TC_AdmitadImport_Exception_InvalidItemException
     */
    private function _preparePrices(array $data, Mage_Catalog_Model_Product $product)
    {
        $price    = isset($data['price']) ? $data['price'] : null;
        $oldprice = isset($data['oldprice']) ? $data['oldprice'] : null;
        $currency = isset($data['currencyId']) ? $data['currencyId'] : null;

        if (null === $price) {
            throw new TC_AdmitadImport_Exception_InvalidItemException($data, array('Price not found.'));
        }

        if (null === $oldprice) {
            $product->setData('price', $this->_currencyHelper->getConvertedValue($price, $currency));
        } else {
            $product->setData('price', $this->_currencyHelper->getConvertedValue($oldprice, $currency));
            $product->setData('special_price', $this->_currencyHelper->getConvertedValue($price, $currency));
        }

        $product->setData('tax_class_id', 0);
    }

    /**
     * Process EAV attributes mapping and set to product
     *
     * @param array                      $data
     * @param Mage_Catalog_Model_Product $product
     */
    private function _prepareAttributes(array $data, Mage_Catalog_Model_Product $product)
    {
        /* @var $helper TC_AdmitadImport_Helper_Attributes */
        $helper = Mage::helper('tc_admitadimport/attributes');
        $product->addData($this->_getDefaultProductData());
        $product->addData($helper->getMappedValues($data));
    }

    /**
     * Prepares and save stock item
     *
     * @param Mage_Catalog_Model_Product $product
     */
    private function _saveStockItem($product)
    {
        /* @var $item Mage_CatalogInventory_Model_Stock_Item */
        $item = Mage::getModel('cataloginventory/stock_item');
        $item->addData($product->getData('stock_data'));
        $item->setStockId($item->getStockId());
        $item->setProduct($product);
        $item->getResource()->save($item);
    }

    /**
     * Return categories collection
     *
     * @return Mage_Catalog_Model_Resource_Category_Collection
     */
    private function _getCategoryCollection()
    {
        if (is_null($this->_categoryCollection)) {
            $this->_categoryCollection = Mage::getResourceModel('catalog/category_collection');
            $this->_categoryCollection->addAttributeToSelect(
                array('entity_id', 'path', TC_AdmitadImport_Processor_Categories::ORIGIN_ID_ATTRIBUTE_CODE)
            );
        }

        return $this->_categoryCollection;
    }

    /**
     * Get default product attributes
     *
     * @return array
     */
    protected function _getDefaultProductData()
    {
        return array(
            'website_ids'      => array($this->_websiteId),
            'attribute_set_id' => $this->_attributeSetId,
            'price'            => 0,
            'tax_class_id'     => 0,
            'status'           => Mage_Catalog_Model_Product_Status::STATUS_ENABLED,
            'visibility'       => Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE,
            'stock_data'       => array(
                'use_config_manage_stock' => 0,
                'manage_stock'            => 0,
                'is_in_stock'             => Mage_CatalogInventory_Model_Stock::STOCK_OUT_OF_STOCK,
                'qty'                     => 0
            )
        );
    }

    /**
     * Before import process
     */
    protected function _beforeProcess()
    {
        $this->_existSKUs      = $this->_getResourceUtilityModel()->getSKUs();
        $this->_existConfigurablesId = $this->_getResourceUtilityModel()->getConfigurablesId();
        $this->_existURLs       = $this->_getResourceUtilityModel()->getURLs();
        $this->_currencyHelper = Mage::helper('tc_admitadimport/currency');
        $this->_helperImages = Mage::helper('tc_admitadimport/images');
        $this->_helperImages->setLogger($this->_getLogger());
        $this->_helperImages->init();
    }

    /**
     * After process steps
     */
    protected function _afterProcess()
    {
        //$this->_helperImages->terminate();
        $toDisable = array_diff(array_keys($this->_existSKUs), $this->_processedSKUs);

        $this->_getLogger()->log('Update status process started');
        $this->_getResourceUtilityModel()->updateStatusAttributeValue(
            $toDisable, Mage_Catalog_Model_Product_Status::STATUS_DISABLED
        );
        $this->_getResourceUtilityModel()->updateStatusAttributeValue(
            $this->_processedSKUs, Mage_Catalog_Model_Product_Status::STATUS_ENABLED
        );
    }

    /**
     * Returns utility model
     *
     * @return TC_AdmitadImport_Model_Resource_Product
     */
    private function _getResourceUtilityModel()
    {
            return Mage::getResourceModel('tc_admitadimport/product');
    }

    /**
     * Returns default product type
     *
     * @return string
     */
    private function _getProductTypeId()
    {
        return 'simple';
    }
}
