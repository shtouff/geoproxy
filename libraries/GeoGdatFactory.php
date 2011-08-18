<?php

class RedisGeoGdatFactoryData extends RedisGeoAbstractFactoryData
{
}

class JSONGeoGdatFactoryData extends JSONGeoAbstractFactoryData
{
}

class GeoGdatFactory extends GeoAbstractFactory
{
	protected function makeFromRedis($_redisConn, $_redisID) 
	{
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
		              "entering");
		
		$gdat = new GeoGdat();
		$geomfactory = new GeoGeomFactory();
		$gadcfactory = new GeoGadcFactory();
		
		if (! ($gdat->formatted_address = $_redisConn->get("gdat:$_redisID:fa"))) {
			return null;
		}

		// geometry
		$geomid = $_redisConn->get("gdat:$_redisID:geom");
		$gdat->geometry = $geomfactory->make(new RedisGeoGeomFactoryData($_redisConn, $geomid));

		// other attributes
		$gdat->lang = $_redisConn->get("gdat:$_redisID:lang");
		$gdat->ext = $_redisConn->get("gdat:$_redisID:ext");
		$gdat->types = $_redisConn->sMembers("gdat:$_redisID:types");
		
		// address components
		$gadcids = $_redisConn->lGetRange("gdat:$_redisID:adc", 0, -1);
		foreach ($gadcids as $gadcid) {
			$gdat->address_components[] = $gadcfactory->make(new RedisGeoGadcFactoryData($_redisConn, $gadcid));
		}
		return $gdat;
	}

	protected function makeFromJSON($_json)
	{
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
		              "entering");
		
		$gdat = new GeoGdat();
		$geomfactory = new GeoGeomFactory();
		$gadcfactory = new GeoGadcFactory();
		
		$gdat->geometry = $geomfactory->make(new JSONGeoGeomFactoryData($_json['geometry']));
		$gdat->formatted_address = $_json['formatted_address'];
		$gdat->types = $_json['types'];
		$gdat->lang = $_json['lang'];
		$gdat->ext = 0;
		foreach ($_json['address_components'] as $ad) {
			$gdat->address_components[] = $gadcfactory->make(new JSONGeoGadcFactoryData($ad));
		}
		return $gdat;
	}	                                
}
?>