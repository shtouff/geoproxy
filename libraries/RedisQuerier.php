<?php

class RedisQuerier {
  
	private $readConn;
	private $writeConn;
	
	// anybody can instantiate this class
	// only a redis config is needed
	public function __construct(RedisQuerierConfig $_config) 
	{
		$this->readConn = new Redis();
		$this->writeConn = new Redis();

		$this->readConn->connect($_config->readserver, $_config->readport);
		$this->writeConn->connect($_config->writeserver, $_config->writeport);
	}
	
	// search objects in redis
	// returns redis IDs
	public function search($_type, $_filters)
	{
		$indexes = array();
		foreach ($_filters as $filter => $value) {
			$index = "idx:" . $_type . $this->mapF2I($filter) . ":$value";
			GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
			              "index: $index");
			$indexes[] = $index;
		}
		
		return call_user_func_array(array($this->readConn, "sInter"),
		                            $indexes);
	}


	// find a geomid in redis matching $_geom attributes
	public function geomInRedis($_geom) 
	{
		$index = 'idx:geomBySerial';
		$serial = $_geom->computeSerial();
		
		$key = $index .':'. $serial;
		if (!$this->readConn->exists($key)) {
			return false;
		}
		
		// serial exists, deep comparison needed
	  $ids = $this->readConn->sMembers($key);
	  
	  $geomfactory = new GeoGeomFactory();
	  foreach ($ids as $id) {
		  $geomRedis = $geomfactory->make(new RedisGeoGeomFactoryData($this->readConn, $id));
		  if ($geomRedis->equals($_geom)) {
			  return $id;
		  }
	  }
	  
	  return false;
	}

	// find a gadc in redis matching $_gadc attributes
	public function gadcInRedis($_gadc) 
  {
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
                  "entering");
	  
	  $index = 'idx:gadcBySerial';
	  $serial = $_gadc->computeSerial();
    
	  $key = $index .':'. $serial;
    if (!$this->readConn->exists($key)) {
	    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                  "leaving, (false 1)");
	    return false;
    }
    
    // serial exists, deep comparison needed
    $ids = $this->readConn->sMembers($key);
    
    $gadcfactory = new GeoGadcFactory();
    foreach ($ids as $id) {
	    $gadcRedis = $gadcfactory->make(new RedisGeoGadcFactoryData($this->readConn, $id));
	    if ($gadcRedis->equals($_gadc)) {
		    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
		                  "leaving, (id=".$id.")");
		    return $id;
	    }
    }
    
    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
                  "leaving, (false 2)");
    return false;
  }

	// stores a geom object in redis
	// returns redis id of the object
	public function addGeom($_geom) {
		
		$id = $this->writeConn->incr('next:geom:id');
	  
	  $serial = $_geom->computeSerial();
	  
	  // set keys
	  // XXX: probably useless to store serial in redis, cf GeoGadc
	  $this->writeConn->set("geom:$id:serial", $serial);
	  $this->writeConn->set("geom:$id:loc:type", $_geom->location_type);
	  $this->writeConn->set("geom:$id:loc:lat", $_geom->location->lat);
	  $this->writeConn->set("geom:$id:loc:lng", $_geom->location->lng);
	  
	  $this->writeConn->set("geom:$id:vport:sw:lat", $_geom->viewport->southwest->lat); 
	  $this->writeConn->set("geom:$id:vport:sw:lng", $_geom->viewport->southwest->lng); 
	  $this->writeConn->set("geom:$id:vport:ne:lat", $_geom->viewport->northeast->lat); 
	  $this->writeConn->set("geom:$id:vport:ne:lng", $_geom->viewport->northeast->lng); 
	  
	  if (is_object($_geom->bounds)) {
		  $this->writeConn->set("geom:$id:bounds:sw:lat", $_geom->bounds->southwest->lat); 
		  $this->writeConn->set("geom:$id:bounds:sw:lng", $_geom->bounds->southwest->lng); 
		  $this->writeConn->set("geom:$id:bounds:ne:lat", $_geom->bounds->northeast->lat); 
		  $this->writeConn->set("geom:$id:bounds:ne:lng", $_geom->bounds->northeast->lng);
	  }
	  
	  // update indexes
	  $this->writeConn->sadd("idx:geomBySerial:$serial", $id);
	  
	  return $id;
  }

	// stores a gdat object in redis
	// returns redis ID of the object
	public function addGdat($_gdat) 
	{		
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
		              "entering");
		
		$id = $this->writeConn->incr('next:gdat:id');
		
		// set keys
		if (isset($_gdat->formatted_address))
			$this->writeConn->set("gdat:$id:fa", $_gdat->formatted_address);
		if (isset($this->ext))
			$this->writeConn->set("gdat:$id:ext", $_gdat->ext);
		if (isset($_gdat->lang))
			$this->writeConn->set("gdat:$id:lang", $_gdat->lang);
		
		if (isset($_gdat->types)) {
			foreach ($_gdat->types as $type) {
				$this->writeConn->sAdd("gdat:$id:types", $type);
				// update index for this type
				$this->writeConn->sAdd("idx:gdatByType:$type", $id);
			}
		}
		
		// address components
		if (isset($_gdat->address_components)) {
			foreach ($_gdat->address_components as $adc) {
				if (! $gadcid = $this->gadcInRedis($adc)) {
					$gadcid = $this->addGadc($adc);
				}
				
				// attach to gdat, in a linked list to preserve order
				$this->writeConn->rPush("gdat:$id:adc", $gadcid);
			}
		}
		
		// geometry
		if (isset($_gdat->geometry)) {
			// first try to find an already existing geometry
			if (! $geomid = $this->geomInRedis($_gdat->geometry)) {
				$geomid = $this->addGeom($_gdat->geometry);
			}
			
			// attach gdat -> geom
			$this->writeConn->set("gdat:$id:geom", $geomid);
		}
		
		// update indexes
		if (isset($_gdat->lang)) {
			$lang = $_gdat->lang;
			$this->writeConn->sAdd("idx:gdatByLang:$lang", $id);	
		}

		if (isset($_gdat->ext)) {
			$ext = $_gdat->ext;
			$this->writeConn->sAdd("idx:gdatByExt:$ext", $id);
		}

		if (isset($_gdat->geometry->lat)) {
			$lat = $_gdat->geometry->lat;
			$this->writeConn->sAdd("idx:gdatByLat:$lat", $id);
		}
		
		if (isset($_gdat->geometry->lng)) {
			$lng = $_gdat->geometry->lng;
			$this->writeConn->sAdd("idx:gdatByLng:$lng", $id);
		}

		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
		              "leaving (id = ".$id.")");
		return $id;
	}

	// stores a gadc object in redis
  // returns redis id of the object
	public function addGadc($_gadc) {
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
		              "entering");
		
		$id = $this->writeConn->incr('next:gadc:id');
	  
	  $serial = $_gadc->computeSerial();
	  
	  // set keys
	  $this->writeConn->set("gadc:$id:lname", $_gadc->long_name);
	  $this->writeConn->set("gadc:$id:sname", $_gadc->short_name);
	  
	  foreach ($_gadc->types as $type) {
		  $this->writeConn->sAdd("gadc:$id:types", $type);
		  // update index for this type
		  // XXX: useless at this time, maybe in the future
		  //$_redis->sAdd("idx:gadcByType:$type", $id);
	  }
	  
	  // index
	  $this->writeConn->sAdd("idx:gadcBySerial:$serial", $id);
	  
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "leaving");
	  return $id;
  }
	
	// add GdatID to an index
	// if index is a query, it must be rawurlencoded
	public function indexGdatID($_gdatid, $_indexname, $_indexvalue)
	{
		$key = "idx:gdat" . $this->mapF2I($_indexname) .":" . $_indexvalue;
		$this->writeConn->sAdd($key, $_gdatid);
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
		              "index [$_indexname:$_indexvalue] updated with gdat:id=[$_gdatid]");
	}

	// maps filter name to redis index
	private function mapF2I($_filter)
	{
		$map = array("lang"         => "ByLang",
		             "ext"          => "ByExt",
		             "lat"          => "ByLat",
                 "lng"          => "ByLng",
                 "type"         => "ByType",
                 "serial"       => "BySerial",
                 "query"        => "ByQuery",
                 );
    
    return $map["$_filter"];
  }

	// get a redisConn only usable for READ operations
	public function getReadConn()
	{
		return $this->readConn;
	}
}

class RedisQuerierConfig
{
	public $readserver;
	public $readport;

	public $writeserver;
	public $writeport;

	public function  __construct(array $_data)
	{
		$this->readserver = $_data['readserver'];
		$this->readport = $_data['readport'];
		
		$this->writeserver = $_data['writeserver'];
		$this->writeport = $_data['writeport'];
	}
}
?>