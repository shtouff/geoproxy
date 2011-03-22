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
  
  // builds an object instance based on Google equivalent
  public static function constructFromGoogle($_gadc) {
    
    $gadc = new GeoGadc();
   
    $gadc->short_name = $_gadc->short_name;
    $gadc->long_name = $_gadc->long_name;
    $gadc->types = $_gadc->types;
    
    return $gadc;
  }
  
  public static function constructFromRedis($_redis, $_gadcid) {
    
    $gadc = new GeoGadc();
    
    $gadc->long_name = $_redis->get("gadc:$_gadcid:lname");
    $gadc->short_name = $_redis->get("gadc:$_gadcid:sname");
    $gadc->types = $_redis->sMembers("gadc:$_gadcid:types");
    
    return $gadc;
  }
  
  public function computeSerial() {
    $md5 = md5(serialize($this));
    GeoProxy::log(__FILE__, __LINE__,
                  "computed serial: $md5 \n");
    return $md5;
  }

  public function equals($_gadc) {
    if (! $_gadc->short_name = $this->short_name) { return false; }
    if (! $_gadc->long_name = $this->long_name) { return false; }
    
    if (! array_diff(array_merge($_gadc->types, $this->types), 
		     array_intersect($_gadc->types, $this->types))) {
      return false;
    }
    return true;
  }
  
  // stores a gadc object in redis
  // returns redis id of the object
  public function storeInRedis($_redis) {
    
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
    
    // no root index for this kind of objects

    return $id;
  }
}
?>