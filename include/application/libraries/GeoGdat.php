<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed'); 

require_once 'GeoGeom.php';
require_once 'GeoGdat.php';
require_once 'GeoGadc.php';

class GeoGdat {

	public $geometry;
	public $formatted_address;
	public $address_components;
	public $types;
	public $ext;
	public $lang;
	public $id;
  
	// private constructor since we don't want everyone creates 
	// this kind of object
	private function __construct() {
		$this->id = -1;
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
		              "leaving (id = ".$this->id.")");
	}
	
	public static function constructFromGoogle($_gdat, $_lang) 
	{
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
		              "constructFromGoogle");
		
		$gdat = new GeoGdat();
		
		$gdat->geometry = GeoGeom::constructFromGoogle($_gdat->geometry);
		$gdat->formatted_address = $_gdat->formatted_address;
		$gdat->types = $_gdat->types;
		$gdat->lang = $_lang;
		$gdat->ext = "none";
		foreach ($_gdat->address_components as $ad) {
			$gdat->address_components[] = GeoGadc::constructFromGoogle($ad);
		}
		
		return $gdat;
	}
	
	public static function constructFromArray($_data) 
	{
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
		              "entering");
		
		$gdat = new GeoGdat();

		$gdat->ext = $_data['ext'];
		$gdat->lang = $_data['lang'];
		$gdat->types = $_data['types'];
		$gdat->formatted_address = $_data['formatted_address'];
		
		$geom = GeoGeom::constructFromArray($_data['geometry']);
		// XXX
		$gdat->geometry = $geom;
		
		foreach ($_data['address_components'] as $ad) {
			$gdat->address_components[] = GeoGadc::constructFromArray($ad);
		}
		
		return $gdat;
	}
	
	// query must be urlencoded
	public static function indexInRedis($_redis, $_query, $_gdatid) {
		$_redis->sAdd("idx:gdatByQuery:$_query", $_gdatid);
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
		              "query [$_query] cached, with gdat:id=[$_gdatid]");
	}

		
	public static function constructFromRedis($_redis, $_gdatid) {
		
		$gdat = new GeoGdat();
		
		// geometry
		$geomid = $_redis->get("gdat:$_gdatid:geom");
		$gdat->geometry = GeoGeom::constructFromRedis($_redis, $geomid);
		
		$gdat->formatted_address = $_redis->get("gdat:$_gdatid:fa");
		$gdat->lang = $_redis->get("gdat:$_gdatid:lang");
		$gdat->ext = $_redis->get("gdat:$_gdatid:ext");
		$gdat->types = $_redis->sMembers("gdat:$_gdatid:types");
		
		// address components
		$gadcids = $_redis->lGetRange("gdat:$_gdatid:adc", 0, -1);
		foreach ($gadcids as $gadcid) {
			$gdat->address_components[] = GeoGadc::constructFromRedis($_redis,
			                                                          $gadcid);
		}
		
		$gdat->id = $_gdatid;
		
		return $gdat;
	}
	
	// search if a gdat exists in redis
	// return gdat:id if it actually exists
	// return false else
	// query must be urlencoded
	public static function existsInRedis($_redis, $_query, $_lang) 
	{
		$key1 = 'idx:gdatByQuery:'. $_query; 
		$key2 = 'idx:gdatByLang:'. $_lang; 
		
		$result = $_redis->sInter($key1, $key2);    
		switch ($num = count($result)) {
			
		case 0:
			return false;
			
		case 1:
			break;
			
		default:
			GeoProxy::log(LOG_WARNING, __CLASS__, __FUNCTION__,
			              "more than 1 id found ($num actually)");
		}

		return $result;
	}

	public function deleteFromRedis($_redis) {
		
		// del keys
		$_redis->del("gdat:$id:fa");
		$_redis->del("gdat:$id:ext");
		$_redis->del("gdat:$id:lang");

		$_redis->del("gdat:$id:types");
		foreach ($this->types as $type) {
			// update index for this type
			$_redis->sRem("idx:gdatByType:$type", $id);
		}

		// address components
		// XXX detach from gdat, but dont touch on gadc
		$_redis->del("gdat:$id:adc");

		// geometry
		// first try to find an already existing geometry
		//if ($geomid = GeoGeom::existsInRedis($_redis, $this->geometry)) {
		if ($geomid = $this->geometry->inRedis($_redis)) {
			$_redis->hdel("geom:$geomid:gdat", $this->lang);
		}
		// detach gdat -> geom
		$_redis->del("gdat:$id:geom");
		
		// update indexes
		$lang = $this->lang;
		$ext = $this->ext;
		$_redis->sRem("idx:gdatByLang:$lang");
		$_redis->sRem("idx:gdatByExt:$ext");

		return true;
	}
	
	public function storeInRedis($_redis) 
	{		
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
		              "entering (id = ".$this->id.")");
		
		if ($this->id == -1)
			$this->id = $_redis->incr('next:gdat:id');
		
		$id = $this->id;
		
		// set keys
		if (isset($this->formatted_address))
			$_redis->set("gdat:$id:fa", $this->formatted_address);
		if (isset($this->ext))
			$_redis->set("gdat:$id:ext", $this->ext);
		if (isset($this->lang))
			$_redis->set("gdat:$id:lang", $this->lang);
		
		if (isset($this->types)) {
			foreach ($this->types as $type) {
				$_redis->sAdd("gdat:$id:types", $type);
				// update index for this type
				$_redis->sAdd("idx:gdatByType:$type", $id);
			}
		}
		
		// address components
		if (isset($this->address_components)) {
			foreach ($this->address_components as $adc) {
				//if (! $gadcid = GeoGadc::existsInRedis($_redis, $adc)) {
				if (! $gadcid = $adc->inRedis($_redis)) {
					//$gadc = GeoGadc::constructFromGoogle($adc);
					$gadcid = $adc->storeInRedis($_redis);
				}
				
				// attach to gdat, in a linked list to preserve order
				$_redis->rPush("gdat:$id:adc", $gadcid);
			}
		}
		
		// geometry
		if (isset($this->geometry)) {
			// first try to find an already existing geometry
			if (! $geomid = $this->geometry->inRedis($_redis)) {
				//$geom = GeoGeom::constructFromGoogle($this->geometry);
				$geomid = $this->geometry->storeInRedis($_redis);
			}
			
			// attach gdat -> geom
			$_redis->set("gdat:$id:geom", $geomid);
			// reverse path
			$_redis->hset("geom:$geomid:gdat", $this->lang, $id);
		}
		
		// update indexes
		if (isset($this->lang)) {
			$lang = $this->lang;
			$_redis->sAdd("idx:gdatByLang:$lang", $id);	
		}

		if (isset($this->ext)) {
			$ext = $this->ext;
			$_redis->sAdd("idx:gdatByExt:$ext", $id);
		}
		
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
		              "leaving (id = ".$id.")");
		return $id;
	}
}
?>
