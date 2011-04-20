<?php
class GeoGadc
{
  // class for address component
  public $long_name;
  public $short_name;
  public $types;

  private function __construct() {
    // cannot directly call constructor
  }

  // search if a gadc exists in redis
  // return gadc:id if it actually exists
  // return false else
  public static function existsInRedis($_redis, $_gadc) {
    
    $index = 'idx:gadcBySerial';
    $gadcGoogle = GeoGadc::constructFromGoogle($_gadc);
    $serial = $gadcGoogle->computeSerial();
    
    $key = $index .':'. $serial;
    if (!$_redis->exists($key)) {
      return false;
    }

    // serial exists, deep comparison needed
    $ids = $_redis->sMembers($key);
    
    foreach ($ids as $id) {
      $gadcRedis = GeoGadc::constructFromRedis($_redis, $id);
      
      if ($gadcRedis->equals($gadcGoogle)) {
        return $id;
      }
    }
    
    return false;
  }

  public function inRedis($_redis) 
  {
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
                  "entering");
	  
	  $index = 'idx:gadcBySerial';
	  $serial = $this->computeSerial();
    
    $key = $index .':'. $serial;
    if (!$_redis->exists($key)) {
	    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                  "leaving, (false 1)");
	    return false;
    }
    
    // serial exists, deep comparison needed
    $ids = $_redis->sMembers($key);
    
    foreach ($ids as $id) {
	    $gadcRedis = GeoGadc::constructFromRedis($_redis, $id);
	    
	    if ($gadcRedis->equals($this)) {
		    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
		                  "leaving, (id=".$id.")");
		    return $id;
	    }
    }
    
    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
                  "leaving, (false 2)");
    return false;
  }
  
  // builds an object instance based on Google equivalent
  public static function constructFromGoogle($_gadc) 
  {
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "entering");
	  $gadc = new GeoGadc();
   
    $gadc->short_name = $_gadc->short_name;
    $gadc->long_name = $_gadc->long_name;
    $gadc->types = $_gadc->types;
    sort($gadc->types);
    
    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
                  "leaving");
    return $gadc;
  }
  
  public static function constructFromRedis($_redis, $_gadcid) 
  {
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "entering");
    $gadc = new GeoGadc();
    
    $gadc->long_name = $_redis->get("gadc:$_gadcid:lname");
    $gadc->short_name = $_redis->get("gadc:$_gadcid:sname");
    $gadc->types = $_redis->sMembers("gadc:$_gadcid:types");
    sort($gadc->types);
    
    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
                  "leaving");
    return $gadc;
  }
  
  public static function constructFromArray($_data) 
  {
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "entering");  
	  $gadc = new GeoGadc();
   
	  $gadc->short_name = $_data['short_name'];
	  $gadc->long_name = $_data['long_name'];
	  $gadc->types = $_data['types'];
	  sort($gadc->types);
    
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "leaving");
	  return $gadc;
  }

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
  
  // stores a gadc object in redis
  // returns redis id of the object
  public function storeInRedis($_redis) {
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "entering");
	  
	  $id = $_redis->incr('next:gadc:id');
	  
	  $serial = $this->computeSerial();
	  
	  // set keys
	  $_redis->set("gadc:$id:lname", $this->long_name);
	  $_redis->set("gadc:$id:sname", $this->short_name);
	  
	  foreach ($this->types as $type) {
		  $_redis->sAdd("gadc:$id:types", $type);
		  // update index for this type
		  // XXX: useless at this time, maybe in the future
		  //$_redis->sAdd("idx:gadcByType:$type", $id);
	  }
	  
	  // index
	  $_redis->sAdd("idx:gadcBySerial:$serial", $id);
	  
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "leaving");
	  return $id;
  }
}
?>