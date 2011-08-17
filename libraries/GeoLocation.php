<?php 
class GeoLocation {
	// a location composed of a latitude and longitude
	public $lat;
	public $lng;
	
	public function __construct($_lat, $_lng) {
		
		$this->lat = $_lat;
		$this->lng = $_lng;
	}
  
	public function equals($_instance) {
		
		return ($this->lat == $_instance->lat && 
		        $this->lng == $_instance->lng);
	}
}
?>