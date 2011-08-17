<?php

class GeoGeom 
{
  public $location_type;
  public $location;
  public $viewport;
  public $bounds;
  
  public $serial;
  
  /*---------------------------------------------------------------------------*/
  
  public function __construct() {
    $this->serial = -1;
  }

  /*----------------------------------------------------------------------------------*/
  /* instance methods */
  
  // verifies deep equality
  public function equals($_geom) {
    
	  if (! $_geom->location_type = $this->location_type) { return false; }
	  if (! $_geom->location->equals($this->location)) { return false; }
	  if (! $_geom->viewport->equals($this->viewport)) { return false; }
	  if (! $_geom->bounds->equals($this->bounds)) { return false; }
	  
	  return true;
  }
  
  // computes a serial number for a geom
  public function computeSerial() {
	  $md5 = md5(serialize($this));
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "computed value: $md5");
	  return $md5;
  }
}
?>