<?php 
class GeoBounds {
	// a rectangle composed with two GeoLocation: southwest and northeast
	public $southwest;
	public $northeast;
	
	public function __construct($_sw, $_ne) {
		
		$this->southwest = $_sw;
    $this->northeast = $_ne;
	}
	
	public function equals($_instance) {
		
		return ($this->southwest->equals($_instance->southwest) &&
		        $this->northeast->equals($_instance->northeast));
	}
}
?>