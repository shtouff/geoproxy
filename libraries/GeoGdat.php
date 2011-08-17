<?php 

class GeoGdat 
{
	public $geometry;
	public $formatted_address;
	public $address_components;
	public $types;
	public $ext;
	public $lang;
  
	public function __construct() {
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
		              "leaving");
	}
} // class GeoGdat

?>
