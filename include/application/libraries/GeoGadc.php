<?php
class GeoGadc
{
  // class for address component
  public $long_name;
  public $short_name;
  public $types;

  public function __construct() {}

  /*---------------------------------------------------------------------------------*/
  /* instance methods */

  public function computeSerial() {
    $md5 = md5(serialize($this));
    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
                  "computed value: $md5");
    return $md5;
  }

  public function equals($_gadc) 
  {
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "entering");
    if (! $_gadc->short_name = $this->short_name) { 
	    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                  "leaving (false 1)");
	    return false; 
    }
    if (! $_gadc->long_name = $this->long_name) {
	    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                  "leaving (false 2)"); return false; 
    }
    

    if (count($_gadc->types) != count($this->types)) {
	    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                  "leaving (false 3)");
	    return false;
    }
    
    $s1 = array_diff($_gadc->types, $this->types);
    if (count($s1) > 0) {
	    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                  "leaving (false 4)");
	    return false;
    }
    
    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
                  "leaving (true)");
    return true;
  }
}
?>