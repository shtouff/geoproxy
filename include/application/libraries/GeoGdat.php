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
	}
	
	public static function constructFromGoogle($_gdat, $_lang) {
		
		GeoProxy::log(__FILE__, __LINE__,
		              "constructFromGoogle");
		
		$gdat = new GeoGdat();
		
		$gdat->geometry = GeoGeom::constructFromGoogle($_gdat->geometry);
		$gdat->formatted_address = $_gdat->formatted_address;
		$gdat->types = $_gdat->types;
		$gdat->lang = $_lang;
		$gdat->ext = "none";
		$gdat->address_components = $_gdat->address_components;
		
		return $gdat;
	}
	
	// query must be urlencoded
	public static function indexInRedis($_redis, $_query, $_lang, $_gdatid) {
		$_redis->sAdd("idx:gdatByQuery:$_query", $_gdatid);
		$_redis->sAdd("idx:gdatByLang:$_lang", $_gdatid);
		GeoProxy::log(LOG_DEBUG, __FILE__, __LINE__,
		              "query [$_query] cached, with gdat:id=[$_gdatid]");
	}
	
	// va chercher chez google, et renvoie un array de resultats
	// $_query must be url encoded
	public static function retrieveFromGoogle($_query, $_lang)
	{
		GeoProxy::log(LOG_DEBUG, __FILE__, __LINE__,
		              "asking google for: [". $_query ."]");
		
		$headers = array("Host: maps.google.com");
		$url = sprintf('http://%s/maps/api/geocode/json?sensor=%s&address=%s&language=%s',
		               'comtools3:6080',
		               'false',
		               $_query,
		               $_lang
		               );
		
		if (GOOGLE_MAPS_SIGN) {
			$url .= ("&client=" . GOOGLE_MAPS_ID);
			$url = GeoProxy::signUrl($url, GOOGLE_MAPS_KEY);
			GeoProxy::log(LOG_DEBUG, __FILE__, __LINE__,
			              "url signing requested, url is now: [". $url ."]");
		}
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

		if (! $json = curl_exec($ch)) {
			GeoProxy::log(LOG_CRIT, __FILE__, __LINE__,
			              "Curl error: " . curl_errno($ch));
			curl_close($ch);
			return ($result = array());
		}
		$google = json_decode($json);
				
		switch ($google->status) {
			
		case "OK":
			GeoProxy::log(__FILE__, __LINE__,
			              "data found in google");
			if (count($google->results) > 1) {
				GeoProxy::log(__FILE__, __LINE__,
				              "more than 1 result found, using only the first");
			}
			curl_close($ch);
			return $google->results;
			
		case "OVER_QUERY_LIMIT":
			GeoProxy::log(LOG_CRIT, __FILE__, __LINE__,
			              "OVER_QUERY_LIMIT reached !"
			              );
			break;
		default:
			GeoProxy::log(LOG_WARNING, __FILE__, __LINE__,
			              "Google unknown status: " . $google->status);
		}
		curl_close($ch);
		return ($result = array());
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
			return $result[0];
			
		default:
			GeoProxy::log(LOG_WARNING, __FILE__, __LINE__,
			              "more than 1 id found ($num actually)");
		}
	}
	
	public function storeInRedis($_redis) {
		
		$id = $_redis->incr('next:gdat:id');
		
		// set keys
		$_redis->set("gdat:$id:fa", $this->formatted_address);
		$_redis->set("gdat:$id:ext", $this->ext);
		$_redis->set("gdat:$id:lang", $this->lang);
		$_redis->set("gdat:$id:ext", $this->ext);
		
		foreach ($this->types as $type) {
			$_redis->sAdd("gdat:$id:types", $type);
			// update index for this type
			$_redis->sAdd("idx:gdatByType:$type", $id);
		}
		
		// address components
		foreach ($this->address_components as $adc) {
			if (! $gadcid = GeoGadc::existsInRedis($_redis, $adc)) {
				$gadc = GeoGadc::constructFromGoogle($adc);
				$gadcid = $gadc->storeInRedis($_redis);
			}
			
			// attach to gdat, in a linked list to preserve order
			$_redis->rPush("gdat:$id:adc", $gadcid);
		}
		
		// geometry
		// first try to find an already existing geometry
		if (! $geomid = GeoGeom::existsInRedis($_redis, $this->geometry)) {
			$geom = GeoGeom::constructFromGoogle($this->geometry);
			$geomid = $geom->storeInRedis($_redis);
		}
		// attach gdat -> geom
		$_redis->set("gdat:$id:geom", $geomid);
		// reverse path
		$_redis->hset("geom:$geomid:gdat", $this->lang, $id);
		
		// update indexes
		$lang = $this->lang;
		$ext = $this->ext;
		$_redis->sadd("idx:gdatByLang:$lang", $id);
		$_redis->sadd("idx:gdatByExt:$ext", $id);
		
		return $id;
	}
}
?>
