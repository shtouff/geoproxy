<?php
class Json extends CI_Controller {
	
	public function index()
	{
		$this->load->helper('url');
		
		redirect('/json/filter/', 'location');
	}
	
	// list gdat ids, possibly filtered
	public function filter()
	{
		require_once APPPATH . "/libraries/GeoProxy.php";

    // build filter from uri
    $filters = $this->uri->uri_to_assoc(3);
    foreach ($filters as $filter=>$value) {
	    $filters[$filter] = rawurldecode($value);
    }
    
    $proxy = GeoProxy::singleton();
    $gdatids = $proxy->getGdatIDs($filters);
		
		$this->output->set_output(json_encode($gdatids));
	}  
	
	// create a new gdat
	public function create() 
	{
		require_once APPPATH . "/libraries/GeoProxy.php";
		$this->load->helper('form');
		
		$gdatdata = json_decode(file_get_contents("php://input"),
		                       true);
		
		$proxy = GeoProxy::singleton();
		
		$gdatid = $proxy->createGdat($gdatdata);
		
		$this->output->set_output(json_encode($gdatid));
	}

	// modifies an existing gdat
	public function modify()
	{
	}
	
	// view a list of particular gdatids
	public function view()
	{

		require_once APPPATH . "/libraries/GeoProxy.php";
    
		$gdatids = func_get_args();
    $proxy = GeoProxy::singleton();
    
    foreach ($gdatids as $id) {
	    $gdats[] = $proxy->getGdat($id);
    }
    
		$this->output->set_output(json_encode($gdats));
	}
}
?>
