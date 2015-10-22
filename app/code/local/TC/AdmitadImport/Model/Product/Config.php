<?php
/**
 * @category TC
 * @package TC_Import
 * @author Aleksandr Smaga <smagaan@gmail.com>
 */

class TC_AdmitadImport_Model_Product_Config extends Zend_Config_Json{

	const CONF_FILE = 'config.json';

	public function __construct(){
		parent::__construct(Mage::getModuleDir('data', 'TC_AdmitadImport') . DS . self::CONF_FILE);
	}

	/**
	 * Returns fields for select statement by type
	 * @param  string $for
	 * @param  table prefix $prefix
	 * @return array()
	 */
	public function getSelectFields($for, $prefix = null){
		$data = $this->toArray();

		if (!isset($data['select'][$for])){
			throw new Exception('Section not found');
		}

		$fields = array();
		foreach ($data['select'][$for] as $name => $data){
			$name = is_null($prefix) ? $name : $prefix . '.' . $name;

			if (isset($data['as'])){
				$fields[$data['as']] = $name;
			}else{
				$fields[] = $name;
			}
		}

		return $fields;
	}

	/**
	 * Returns required fields that need to be added in csv
	 * @return array()
	 */
	public function getCsvRequired(){
		$data = $this->toArray();

		if (!isset($data['csv']['required'])){
			throw new Exception('Section not found');
		}

		$fields = array();
		foreach ($data['csv']['required'] as $name => $data){
			$fields[$name] = $data;
		}

		return $fields;
	}

	/**
	 * Get names of configurable attributes for converter
	 * @return array()
	 */
	public function getCsvConfigurableAttributes(){
		$data = $this->toArray();

		if (!isset($data['csv']['configurable_attributes'])){
			throw new Exception('Section not found');
		}

		$fields = array();
		foreach ($data['csv']['configurable_attributes'] as $name => $data){
			$fields[] = $name;
		}

		return $fields;
	}
}