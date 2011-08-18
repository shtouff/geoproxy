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
    
    $data['status'] = "OK";
    
    // rebuild final filters
    $searchFilters = array();
    foreach ($filters as $filter=>$value) {
	    switch ($filter) {
	    case 'output':
		    break;
	    case 'ext':
	    case 'lang':
	    case 'lat':
	    case 'lng':
	    case 'query':
		    $searchFilters[$filter] = rawurldecode($value);
		    break;
		    
	    default:
		    $data['status'] = 'ERROR';
		    $data['errmsg'] = "unknown filter: $filter";
		    $searchFilters = array();
	    }
    }
    
    if ($data['status'] == 'OK') {
	    $data['results'] = array();
	    if (count($searchFilters) > 0) {
		    $data['results'] = $this->proxy->getGdatIDs($searchFilters);
	    }
	    
	    if (count($data['results']) == 0) {
		    $data['status'] = 'ZERO_RESULT';
	    }
    }
    
    switch ($output) {
    case 'json':
	    $this->output->set_content_type('application/json');
	    $this->output->set_output(json_encode($data));
	    break;
	    
    case 'html':
	    $this->load->view('geocoder/html/filter/header');
	    $this->load->view('geocoder/html/menu');
	    switch ($data['status']) {
	    case 'ZERO_RESULT':
		    $this->load->view('geocoder/html/filter/zero_result', $data);
		    break;
	    case 'ERROR':
		    $this->load->view('geocoder/html/filter/error', $data);
		    break;
	    case 'OK':
		    $this->load->view('geocoder/html/filter/result', $data);
		    break;
	    }
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
			    $gdatids[] = intval($value);
		    }
	    }
    }

    switch ($output) {
    case 'json':
	    $this->output->set_content_type('application/json');
	    
	    $data['status'] = 'OK';
	    foreach ($gdatids as $id) {
		    if (($gdat = $this->proxy->getGdat($id)) != null) {
			    $data['results'][] = array($id, $gdat);
		    } else {
			    $data['status'] = 'PARTIAL_RESULT';
		    }
	    }
	    
	    if ($data['status'] == 'PARTIAL_RESULT' && count($gdatids) == 1) {
		    $data['status'] = 'ZERO_RESULT';
	    }
	    
	    $this->output->set_output(json_encode($data));
	    break;
	    
    case 'html':
	    $this->load->helper('form');
	    
	    $this->load->view('geocoder/html/view/header');
	    $this->load->view('geocoder/html/menu');
	  
	    foreach ($gdatids as $id) {
		    $data['gdatid'] = $id;
		    if (($gdat = $this->proxy->getGdat($id)) != null) {
			    $data['gdat'] = $gdat;
			    $this->load->view('geocoder/html/view/gdat', $data);
		    } else {
			    $this->load->view('geocoder/html/view/gdatnotfound', $data);
		    }
	    }
	    
	    $this->load->view('geocoder/html/view/footer');  
	    break;
    }
  }
}
?>
