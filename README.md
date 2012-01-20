MY_Xmlrpc
====================

Adds cURL and SSL support to CodeIgniter's Xmlrpc library.

SSL only through cURL.

### Usage

$this->load->library('xmlrpc');
$this->xmlrpc->initialize(array('curl' => TRUE));
