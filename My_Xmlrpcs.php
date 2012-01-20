<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');


class MY_Xmlrpcs extends CI_Xmlrpc
{
	public $CI;
	
	
	public function __construct($config=array())
	{
		parent::__construct();

		$this->CI = get_instance();
	}
	
	
	public function parseRequest($data='')
	{
		global $HTTP_RAW_POST_DATA;

		//-------------------------------------
		//  Get Data
		//-------------------------------------

		if ($data == '')
		{
			$data = $HTTP_RAW_POST_DATA;
		}

		//-------------------------------------
		//  Set up XML Parser
		//-------------------------------------

		$parser = xml_parser_create($this->xmlrpc_defencoding);
		$parser_object = new MY_XML_RPC_Message("filler");

		$parser_object->xh[$parser]					= array();
		$parser_object->xh[$parser]['isf']			= 0;
		$parser_object->xh[$parser]['isf_reason']	= '';
		$parser_object->xh[$parser]['params']		= array();
		$parser_object->xh[$parser]['stack']		= array();
		$parser_object->xh[$parser]['valuestack']	= array();
		$parser_object->xh[$parser]['method']		= '';

		xml_set_object($parser, $parser_object);
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, true);
		xml_set_element_handler($parser, 'open_tag', 'closing_tag');
		xml_set_character_data_handler($parser, 'character_data');
		//xml_set_default_handler($parser, 'default_handler');


		//-------------------------------------
		//  PARSE + PROCESS XML DATA
		//-------------------------------------

		if ( ! xml_parse($parser, $data, 1))
		{
			// return XML error as a faultCode
			$r = new XML_RPC_Response(0,
			$this->xmlrpcerrxml + xml_get_error_code($parser),
			sprintf('XML error: %s at line %d',
				xml_error_string(xml_get_error_code($parser)),
				xml_get_current_line_number($parser)));
			xml_parser_free($parser);
		}
		elseif($parser_object->xh[$parser]['isf'])
		{
			return new XML_RPC_Response(0, $this->xmlrpcerr['invalid_return'], $this->xmlrpcstr['invalid_return']);
		}
		else
		{
			xml_parser_free($parser);

			$m = new MY_XML_RPC_Message($parser_object->xh[$parser]['method']);
			$plist='';

			for($i=0; $i < count($parser_object->xh[$parser]['params']); $i++)
			{
				if ($this->debug === TRUE)
				{
					$plist .= "$i - " .  print_r(get_object_vars($parser_object->xh[$parser]['params'][$i]), TRUE). ";\n";
				}

				$m->addParam($parser_object->xh[$parser]['params'][$i]);
			}

			if ($this->debug === TRUE)
			{
				echo "<pre>";
				echo "---PLIST---\n" . $plist . "\n---PLIST END---\n\n";
				echo "</pre>";
			}

			$r = $this->_execute($m);
		}

		//-------------------------------------
		//  SET DEBUGGING MESSAGE
		//-------------------------------------
		if ($this->debug === TRUE)
		{
			$this->debug_msg = "<!-- DEBUG INFO:\n\n".$plist."\n END DEBUG-->\n";
		}

		return $r;
	}
	
	
	public function multicall($m)
	{
		// Disabled
		return new MY_XML_RPC_Response(0, $this->xmlrpcerr['unknown_method'], $this->xmlrpcstr['unknown_method']);

		$parameters = $m->output_parameters();
		$calls = $parameters[0];

		$result = array();

		foreach ($calls as $value)
		{
			//$attempt = $this->_execute(new XML_RPC_Message($value[0], $value[1]));

			$m = new MY_XML_RPC_Message($value[0]);
			$plist='';

			for($i=0; $i < count($value[1]); $i++)
			{
				$m->addParam(new XML_RPC_Values($value[1][$i], 'string'));
			}

			$attempt = $this->_execute($m);

			if ($attempt->faultCode() != 0)
			{
				return $attempt;
			}

			$result[] = new XML_RPC_Values(array($attempt->value()), 'array');
		}

		return new XML_RPC_Response(new XML_RPC_Values($result, 'array'));
	}


	public function do_multicall($call)
	{
		if ($call->kindOf() != 'struct')
			return $this->multicall_error('notstruct');
		elseif ( ! $methName = $call->me['struct']['methodName'])
			return $this->multicall_error('nomethod');

		list($scalar_type,$scalar_value)=each($methName->me);
		$scalar_type = $scalar_type == $this->xmlrpcI4 ? $this->xmlrpcInt : $scalar_type;

		if ($methName->kindOf() != 'scalar' OR $scalar_type != 'string')
			return $this->multicall_error('notstring');
		elseif ($scalar_value == 'system.multicall')
			return $this->multicall_error('recursion');
		elseif ( ! $params = $call->me['struct']['params'])
			return $this->multicall_error('noparams');
		elseif ($params->kindOf() != 'array')
			return $this->multicall_error('notarray');

		list($a,$b)=each($params->me);
		$numParams = count($b);

		$msg = new MY_XML_RPC_Message($scalar_value);
		for ($i = 0; $i < $numParams; $i++)
		{
			$msg->params[] = $params->me['array'][$i];
		}

		$result = $this->_execute($msg);

		if ($result->faultCode() != 0)
		{
			return $this->multicall_error($result);
		}

		return new XML_RPC_Values(array($result->value()), 'array');
	}
	
}

/* End of file MY_Xmlrpcs.php */
