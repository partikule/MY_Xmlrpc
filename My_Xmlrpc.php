<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * XML-RPC request handler class
 *
 * Adds cURL support for CI XML-RPC
 *
 * @package		CodeIgniter
 * @subpackage	Libraries
 * @category	XML-RPC
 * @author		Partikule (www.partikule.com)
 *
 */
class MY_Xmlrpc extends CI_Xmlrpc
{
	// Use cURL or not. Default to TRUE.
	protected $curl = FALSE;

	public $CI;

	public function __construct($config = array())
	{
		parent::__construct($config = array());

		$this->CI = get_instance();

	}
	

	public function server($url, $port=80)
	{
		if (substr($url, 0, 4) != "http")
		{
			$url = "http://".$url;
		}

		$parts = parse_url($url);

		$path = ( ! isset($parts['path'])) ? '/' : $parts['path'];

		if (isset($parts['query']) && $parts['query'] != '')
		{
			$path .= '?'.$parts['query'];
		}

		$this->client = new MY_XML_RPC_Client($path, $parts['host'], $port);
		$this->client->curl =  $this->curl;
	}
	
	public function send_request()
	{
log_message('error', 'curl : ' . $this->curl);

		$this->message = new MY_XML_RPC_Message($this->method,$this->data);
		$this->message->debug = $this->debug;
		$this->message->curl =  $this->curl;

		if ( ! $this->result = $this->client->send($this->message))
		{
			$this->error = $this->result->errstr;
			return FALSE;
		}
		elseif( ! is_object($this->result->val))
		{
			$this->error = $this->result->errstr;
			return FALSE;
		}

		$this->response = $this->result->decode();

		return TRUE;
	}
}


class MY_XML_RPC_Client extends XML_RPC_Client
{
	public function __construct($path, $server, $port=80)
	{
		parent::__construct($path, $server, $port);

		$this->CI = get_instance();
	}


	function sendPayload($msg)
	{
		if(empty($msg->payload))
		{
			// $msg = XML_RPC_Messages
			$msg->createPayload();
		}

		if ($this->curl == TRUE)
		{
			$fp = curl_init();
			
			if ( ! $fp)
			{
				error_log($this->xmlrpcstr['http_error']);
				$r = new XML_RPC_Response(0, $this->xmlrpcerr['http_error'],$this->xmlrpcstr['http_error']);
				return $r;
			}

			if( isset( $_SERVER['HTTPS'] ) )
			{
				$pre = 'https://';
				curl_setopt( $fp, CURLOPT_SSL_VERIFYPEER, FALSE );
			}
			else
			{
				$pre = 'http://';
			}

			curl_setopt($fp, CURLOPT_URL, $this->server . $this->path);
			curl_setopt($fp, CURLOPT_PORT, $this->port);
			curl_setopt($fp, CURLOPT_HEADER, 1);
			curl_setopt($fp, CURLOPT_HTTP_VERSION, 1.0);
			curl_setopt($fp, CURLOPT_FRESH_CONNECT, 1);
			curl_setopt($fp, CURLOPT_POST, 1);
			curl_setopt($fp, CURLOPT_TIMEOUT, 60);
			curl_setopt($fp, CURLOPT_POSTFIELDS, $msg->payload);
			curl_setopt($fp, CURLOPT_RETURNTRANSFER, 1);
			
			$resp = curl_exec($fp);

			curl_close($fp);

			if ($resp === FALSE)
			{
				error_log($this->xmlrpcstr['http_error']);
				$r = new XML_RPC_Response(0, $this->xmlrpcerr['http_error'], $this->xmlrpcstr['http_error']);
				return $r;
			}

			$resp = $msg->parseResponse($resp);
		}
		else
		{
			$fp = @fsockopen($this->server, $this->port,$this->errno, $this->errstr, $this->timeout);
			if ( ! is_resource($fp))
			{
				error_log($this->xmlrpcstr['http_error']);
				$r = new XML_RPC_Response(0, $this->xmlrpcerr['http_error'],$this->xmlrpcstr['http_error']);
				return $r;
			}

			$r = "\r\n";
			$op  = "POST {$this->path} HTTP/1.0$r";
			$op .= "Host: {$this->server}$r";
			$op .= "Content-Type: text/xml$r";
			$op .= "User-Agent: {$this->xmlrpcName}$r";
			$op .= "Content-Length: ".strlen($msg->payload). "$r$r";
			$op .= $msg->payload;
	
			if ( ! fputs($fp, $op, strlen($op)))
			{
				error_log($this->xmlrpcstr['http_error']);
				$r = new XML_RPC_Response(0, $this->xmlrpcerr['http_error'], $this->xmlrpcstr['http_error']);
				return $r;
			}
			$resp = $msg->parseResponse($fp);
			fclose($fp);

		}		
		
		return $resp;
	}
}


class MY_XML_RPC_Message extends XML_RPC_Message
{

	public function __construct($method, $pars=0)
	{
		parent::__construct($method, $pars);

		$this->CI = get_instance();
	}
	
	function parseResponse($fp)
	{
		$data = '';
		
		if ($this->curl == FALSE)
		{
			while($datum = fread($fp, 4096))
			{
				$data .= $datum;
			}
		}
		else
		{
			$data = $fp;
		}

		//-------------------------------------
		//  DISPLAY HTTP CONTENT for DEBUGGING
		//-------------------------------------
		
		if ($this->debug === TRUE)
		{
			echo "<pre>";
			echo "---DATA---\n" . htmlspecialchars($data) . "\n---END DATA---\n\n";
			echo "</pre>";
		}
		
		//-------------------------------------
		//  Check for data
		//-------------------------------------

		if($data == "")
		{
			error_log($this->xmlrpcstr['no_data']);
			$r = new XML_RPC_Response(0, $this->xmlrpcerr['no_data'], $this->xmlrpcstr['no_data']);
			return $r;
		}
		
		
		//-------------------------------------
		//  Check for HTTP 200 Response
		//-------------------------------------
		
		if (strncmp($data, 'HTTP', 4) == 0 && ! preg_match('/^HTTP\/[0-9\.]+ 200 /', $data))
		{
			$errstr= substr($data, 0, strpos($data, "\n")-1);
			$r = new XML_RPC_Response(0, $this->xmlrpcerr['http_error'], $this->xmlrpcstr['http_error']. ' (' . $errstr . ')');
			return $r;
		}
		
		//-------------------------------------
		//  Create and Set Up XML Parser
		//-------------------------------------
	
		$parser = xml_parser_create($this->xmlrpc_defencoding);

		$this->xh[$parser]				 = array();
		$this->xh[$parser]['isf']		 = 0;
		$this->xh[$parser]['ac']		 = '';
		$this->xh[$parser]['headers'] 	 = array();
		$this->xh[$parser]['stack']		 = array();
		$this->xh[$parser]['valuestack'] = array();
		$this->xh[$parser]['isf_reason'] = 0;

		xml_set_object($parser, $this);
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, true);
		xml_set_element_handler($parser, 'open_tag', 'closing_tag');
		xml_set_character_data_handler($parser, 'character_data');
		//xml_set_default_handler($parser, 'default_handler');


		//-------------------------------------
		//  GET HEADERS
		//-------------------------------------
		$lines = explode("\r\n", $data);
		while (($line = array_shift($lines)))
		{
			if (strlen($line) < 1)
			{
				break;
			}
			$this->xh[$parser]['headers'][] = $line;
		}
		$data = implode("\r\n", $lines);
		
		
		//-------------------------------------
		//  PARSE XML DATA
		//-------------------------------------  	
		if ( ! xml_parse($parser, $data, count($data)))
		{
			$errstr = sprintf('XML error: %s at line %d',
					xml_error_string(xml_get_error_code($parser)),
					xml_get_current_line_number($parser));
			//error_log($errstr);
			$r = new XML_RPC_Response(0, $this->xmlrpcerr['invalid_return'], $this->xmlrpcstr['invalid_return']);
			xml_parser_free($parser);
			return $r;
		}
		xml_parser_free($parser);
		
		// ---------------------------------------
		//  Got Ourselves Some Badness, It Seems
		// ---------------------------------------
		
		if ($this->xh[$parser]['isf'] > 1)
		{
			if ($this->debug === TRUE)
			{
				echo "---Invalid Return---\n";
				echo $this->xh[$parser]['isf_reason'];
				echo "---Invalid Return---\n\n";
			}
				
			$r = new XML_RPC_Response(0, $this->xmlrpcerr['invalid_return'],$this->xmlrpcstr['invalid_return'].' '.$this->xh[$parser]['isf_reason']);
			return $r;
		}
		elseif ( ! is_object($this->xh[$parser]['value']))
		{
			$r = new XML_RPC_Response(0, $this->xmlrpcerr['invalid_return'],$this->xmlrpcstr['invalid_return'].' '.$this->xh[$parser]['isf_reason']);
			return $r;
		}
		
		//-------------------------------------
		//  DISPLAY XML CONTENT for DEBUGGING
		//-------------------------------------  	
		
		if ($this->debug === TRUE)
		{
			echo "<pre>";
			
			if (count($this->xh[$parser]['headers'] > 0))
			{
				echo "---HEADERS---\n";
				foreach ($this->xh[$parser]['headers'] as $header)
				{
					echo "$header\n";
				}
				echo "---END HEADERS---\n\n";
			}
			
			echo "---DATA---\n" . htmlspecialchars($data) . "\n---END DATA---\n\n";
			
			echo "---PARSED---\n" ;
			var_dump($this->xh[$parser]['value']);
			echo "\n---END PARSED---</pre>";
		}
		
		//-------------------------------------
		//  SEND RESPONSE
		//-------------------------------------
		
		$v = $this->xh[$parser]['value'];
			
		if ($this->xh[$parser]['isf'])
		{
			$errno_v = $v->me['struct']['faultCode'];
			$errstr_v = $v->me['struct']['faultString'];
			$errno = $errno_v->scalarval();

			if ($errno == 0)
			{
				// FAULT returned, errno needs to reflect that
				$errno = -1;
			}

			$r = new XML_RPC_Response($v, $errno, $errstr_v->scalarval());
		}
		else
		{
			$r = new XML_RPC_Response($v);
		}

		$r->headers = $this->xh[$parser]['headers'];
		return $r;
	}
}

/* End of file My_Xmlrpc.php */
