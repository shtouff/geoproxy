<?php
require_once APPPATH . "/libraries/GeoProxy.php";

class Geocoder extends CI_Controller {
  
	private $proxy;
	
	public function __construct()
	{
		parent::__construct();
		$this->proxy = GeoProxy::getInstance();
	}

  public function index()
  {
	  $this->load->helper('url');
	  
	  redirect('/geocoder/filter/output/html', 'location');
  }

  // list gdat ids, possibly filtered
  public function filter()
  {
    require_once APPPATH . "/libraries/GeoProxy.php";

    $this->load->helper('form');
    $this->load->helper('url');
    
    // build filter from uri
    $filters = $this->uri->uri_to_assoc(3);

    $output = 'html';
    if (array_key_exists('output', $filters)) {
	    switch ($filters['output']) {
	    case 'json':
		    $output = 'json';
		    break;
		    
	    default:
		    $output = 'html';
	    }
    }
    
    // rebuild final filters
    $searchFilters = array();
    foreach ($filters as $filter=>$value) {
	    if ($filter != 'output') {
		    $searchFilters[$filter] = rawurldecode($value);
	    }
    }
    
    $data['results'] = $this->proxy->getGdatIDs($searchFilters);
    
    switch ($output) {
    case 'json':
	    $this->output->set_content_type('application/json');
	    if (count($data['results']) == 0) {
		    $data['status'] = 'ZERO_RESULT';
	    } else {
		    $data['status'] = 'OK';
	    }
	    $this->output->set_output(json_encode($data));
	    break;
	    
    case 'html':
	    $this->load->view('geocoder/html/filter/header');
	    $this->load->view('geocoder/html/menu');
	    $this->load->view('geocoder/html/filter/filter', $data);
	    $this->load->view('geocoder/html/filter/footer');
	    break;
    }
  }  

  // view a list of particular gdatids
  public function view()
  {
    require_once APPPATH . "/libraries/GeoProxy.php";
    
    // build filter from uri
    $filters = $this->uri->uri_to_assoc(3);

    $output = 'html';
    if (array_key_exists('output', $filters)) {
	    switch ($filters['output']) {
	    case 'json':
		    $output = 'json';
		    break;
		    
	    default:
		    $output = 'html';
	    }
    }

    // rebuild IDs list 
    $gdatids = array();
    foreach ($filters as $filter=>$value) {
	    if ($filter != 'output') {
		    $gdatids[] = $filter;
		    if ($value != false) {
			    $gdatids[] = $value;
		    }
	    }
    }

    switch ($output) {
    case 'json':
	    $this->output->set_content_type('application/json');
	    foreach ($gdatids as $id) {
		    $gdats[] = $this->proxy->getGdat($id);
	    }
	    $this->output->set_output(json_encode($gdats));
	    break;
	    
    case 'html':
	    $this->load->helper('form');
	    
	    $this->load->view('geocoder/html/view/header');
	    $this->load->view('geocoder/html/menu');
	  
	    foreach ($gdatids as $id) {
		    $data['gdat'] = $this->proxy->getGdat($id);
		    $data['gdatid'] = $id;
		    $this->load->view('geocoder/html/view/gdat', $data);
	    }
	    
	    $this->load->view('geocoder/html/view/footer');  
	    break;
    }
  }
}
?>
