<?php

require_once 'GeoLocation.php';
require_once 'GeoBounds.php';

class GeoGeom {
  
  public $location_type;
  public $location;
  public $viewport;
  public $bounds;
  
  public $id;
  public $serial;
  
  // cannot directly call constructor
  private function __construct() {
    $this->id = -1;
    $this->serial = -1;
  }
  
  // builds a new object instance mapping a particular redis key
  public static function constructFromRedis($_redis, $_geomid) 
  {
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "entering");

    $geom = new GeoGeom();

    $geom->serial = $_redis->get("geom:$_geomid:serial");
    $geom->location_type = $_redis->get("geom:$_geomid:loc:type");
    $geom->location = new GeoLocation($_redis->get("geom:$_geomid:loc:lat"),
                                      $_redis->get("geom:$_geomid:loc:lng"));
    
    $sw = new GeoLocation($_redis->get("geom:$_geomid:vport:sw:lat"),
                          $_redis->get("geom:$_geomid:vport:sw:lng"));
    $ne = new GeoLocation($_redis->get("geom:$_geomid:vport:ne:lat"),
                          $_redis->get("geom:$_geomid:vport:ne:lng"));
    $geom->viewport = new GeoBounds($sw, $ne);
    
    $sw = new GeoLocation($_redis->get("geom:$_geomid:bounds:sw:lat"),
                          $_redis->get("geom:$_geomid:bounds:sw:lng"));
    $ne = new GeoLocation($_redis->get("geom:$_geomid:bounds:ne:lat"),
                          $_redis->get("geom:$_geomid:bounds:ne:lng"));
    $geom->bounds = new GeoBounds($sw, $ne);
    
    $geom->id = $_geomid;
    
    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
                  "leaving");
    return $geom;
  }
  
  // builds an object instance based on Google equivalent
  public static function constructFromGoogle($_geom) 
  {
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "entering");
	  
	  $geom = new GeoGeom();
	  $reflected = new ReflectionObject($_geom);
    
    $geom->location_type = $_geom->location_type;
    $geom->location = new GeoLocation($_geom->location->lat,
                                      $_geom->location->lng);
    
    $sw = new GeoLocation($_geom->viewport->southwest->lat,
                          $_geom->viewport->southwest->lng);
    $ne = new GeoLocation($_geom->viewport->northeast->lat,
                          $_geom->viewport->northeast->lng);
    $geom->viewport = new GeoBounds($sw, $ne);    
    
    if ($reflected->hasProperty("bounds") && is_object($_geom->bounds)) {
	    $sw = new GeoLocation($_geom->bounds->southwest->lat,
	                          $_geom->bounds->southwest->lng);
	    $ne = new GeoLocation($_geom->bounds->northeast->lat,
	                          $_geom->bounds->northeast->lng);
	    $geom->bounds = new GeoBounds($sw, $ne);
    }
    
    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
                  "leaving");
    return $geom;
  }

  public static function constructFromArray($_data)
  {
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
	                "entering");
	  $geom = new GeoGeom();
	  
	  $geom->location_type = $_data['location_type'];
	  $geom->location = new GeoLocation($_data['location']['lat'],
	                                    $_data['location']['lng']);
	  
	  if (isset($_data['viewport'])) {
		  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
		                "viewport exists in array, propagating to object");
		  $sw = new GeoLocation($_data['viewport']['southwest']['lat'],
		                        $_data['viewport']['southwest']['lng']);
		  $ne = new GeoLocation($_data['viewport']['northeast']['lat'],
		                        $_data['viewport']['northeast']['lng']);
		  $geom->viewport = new GeoBounds($sw, $ne);
	  } else {
		  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
		                "viewport doesn't exist in array");
	  }
	  
	  if (isset($_data['bounds'])) {
		  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
		                "bounds exists in array, propagating to object");
		  $sw = new GeoLocation($_data['bounds']['southwest']['lat'],
		                        $_data['bounds']['southwest']['lng']);
		  $ne = new GeoLocation($_data['bounds']['northeast']['lat'],
		                        $_data['bounds']['northeast']['lng']);
		  $geom->bounds = new GeoBounds($sw, $ne);
	  } else {
		  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
		                "bounds doesn't exist in array");
	  }
	  return $geom;
  }
  
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
  
  // search if a geom exists in redis
  // return geom:id if it actually exists
  // return false else
  public static function existsInRedis($_redis, $_geom) {
    
	  $index = 'idx:geomBySerial';
	  $geomGoogle = GeoGeom::constructFromGoogle($_geom);
	  $serial = $geomGoogle->computeSerial();
	  
	  $key = $index .':'. $serial;
	  if (!$_redis->exists($key)) {
		  return false;
	  }
	  
	  // serial exists, deep comparison needed
    $ids = $_redis->sMembers($key);
    
    foreach ($ids as $id) {
	    $geomRedis = GeoGeom::constructFromRedis($_redis, $id);
	    
	    if ($geomRedis->equals($geomGoogle)) {
		    return $id;
	    }
    }
    
    return false;
  }
  
  public function inRedis($_redis) {
	  
	  $index = 'idx:geomBySerial';
	  //$geomGoogle = GeoGeom::constructFromGoogle($_geom);
	  $serial = $this->computeSerial();
	  
	  $key = $index .':'. $serial;
	  if (!$_redis->exists($key)) {
		  return false;
	  }
	  
	  // serial exists, deep comparison needed
	  $ids = $_redis->sMembers($key);
	  
	  foreach ($ids as $id) {
		  $geomRedis = GeoGeom::constructFromRedis($_redis, $id);
		  if ($geomRedis->equals($this)) {
			  return $id;
		  }
	  }
	  
	  return false;
  }
  
  // stores a geom object in redis
  // returns redis id of the object
  public function storeInRedis($_redis) {
	  
	  $id = $_redis->incr('next:geom:id');
	  
	  $serial = $this->computeSerial();
	  
	  // set keys
	  // XXX: probably useless to store serial in redis, cf GeoGadc
	  $_redis->set("geom:$id:serial", $serial);
	  $_redis->set("geom:$id:loc:type", $this->location_type);
	  $_redis->set("geom:$id:loc:lat", $this->location->lat);
	  $_redis->set("geom:$id:loc:lng", $this->location->lng);
	  
	  $_redis->set("geom:$id:vport:sw:lat", $this->viewport->southwest->lat); 
	  $_redis->set("geom:$id:vport:sw:lng", $this->viewport->southwest->lng); 
	  $_redis->set("geom:$id:vport:ne:lat", $this->viewport->northeast->lat); 
	  $_redis->set("geom:$id:vport:ne:lng", $this->viewport->northeast->lng); 
	  
	  if (is_object($this->bounds)) {
		  $_redis->set("geom:$id:bounds:sw:lat", $this->bounds->southwest->lat); 
		  $_redis->set("geom:$id:bounds:sw:lng", $this->bounds->southwest->lng); 
		  $_redis->set("geom:$id:bounds:ne:lat", $this->bounds->northeast->lat); 
		  $_redis->set("geom:$id:bounds:ne:lng", $this->bounds->northeast->lng);
	  }
	  
	  // update indexes
	  $lat = $this->location->lat;
	  $lng = $this->location->lng;
	  $_redis->sadd("idx:geomByLat:$lat", $id);
	  $_redis->sadd("idx:geomByLng:$lng", $id);
	  
	  $_redis->sadd("idx:geomBySerial:$serial", $id);
	  
	  return $id;
  }
  
  public static function intersectInRedis($_redis, $_lat, $_lng) 
  {
	  $latset = "idx:geomByLat:$_lat";
	  $lngset = "idx:geomByLng:$_lng";
	  $geomids = $_redis->sInter($latset, $lngset);
	  
	  if (count($geomids) > 1) {
		  GeoProxy::log(__FILE__, __LINE__,
		                "found more than 1 geom:id, using first");
	  }
	  
	  foreach ($geomids as $geomid) {
		  return $geomid;
	  }
	  GeoProxy::log(__FILE__, __LINE__,
	                "no geom:id found");
	  return false;
  }
}
?>