<?php

abstract class GeoAbstractFactory
{
	public function __construct() {}

	public function make(GeoAbstractFactoryData $_source)
	{
		switch ($_source->getType()) {
		case 'redis':
			return $this->makeFromRedis($_source->redisConn, $_source->redisID);
			break;
		case 'json':
			return $this->makeFromJSON($_source->json);
			break;
		}
		return NULL;
	}

	abstract protected function makeFromRedis($_redisConn, $_redisID);
	abstract protected function makeFromJSON($_json);
}

abstract class GeoAbstractFactoryData
{
	/* type */
	protected $type;
	
	/* JSON source */
	public $json;
	
	/* REDIS source */
	public $redisConn;
	public $redisID;
	
	/* other sources */
	/* ...*/

	public function __construct() {}
		
	public function setType($_type) 
	{
		switch ($_type) {
		case "redis":
			$this->type = "redis";
			break;

		case "json":
			$this->type = "json";
			break;
			
		default:
			/* log failed status */
			return false;
			break;
		}

		return true;
	}

	public function getType()
	{
		return $this->type;
	}
}

abstract class RedisGeoAbstractFactoryData extends GeoAbstractFactoryData
{
	public function __construct($_redisConn, $_redisID)
	{
		parent::__construct();
		$this->setType('redis');
		
		$this->redisConn = $_redisConn;
		$this->redisID = $_redisID;
	}
}

abstract class JSONGeoAbstractFactoryData extends GeoAbstractFactoryData
{
	public function __construct($_json)
	{
		parent::__construct();
		$this->setType('json');
		
		$this->json = $_json;
	}
}		
?>