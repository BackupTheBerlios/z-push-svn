<?php
include_once('mime.php');
include_once('mimeDecode.php');
include_once('mimeMagic.php');

class ZPush_mimehelper {
    var $_mobj="";
    var $_nativebodytype="";

	function ZPush_mimehelper($mailmsg,$nativebodytype) {
        $this->_mobj = new Mail_mimeDecode($mailmsg);
		$this->_nativebodytype = $nativebodytype;
	}

	function Convert() {
        $message = $this->_mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'rfc_822bodies' => true, 'include_bodies' => true, 'crlf' => "\n", 'charset' => BACKEND_CHARSET));
       	$rawmessage = $this->_mobj->decode(array('decode_headers' => false, 'decode_bodies' => true, 'rfc_822bodies' => true, 'include_bodies' => true, 'crlf' => "\n", 'charset' => BACKEND_CHARSET));
		$body = "";
		foreach($rawmessage->headers as $key=>$value) {
			if ($key != "content-type" && $key != "mime-version" && $key != "content-transfer-encoding" &&
			    !is_array($value)) {
				$body .= $key.":";
				// Split -> Explode replace
				$tokens = explode(" ",trim($value));
				$line = "";
				foreach($tokens as $valu) {
	   				if ((strlen($line)+strlen($valu)+2) > 60) {
						$line .= "\n";
						$body .= $line;
						$line = " ".$valu;
				    } else {
						$line .= " ".$valu;
				    }
				}
				$body .= $line."\n";
			}
		}
		unset($rawmessage);
		$mimemsg = new Mail_mime(array( 'head_encoding'	=> 'quoted-printable',
						    		    'text_encoding'	=> 'quoted-printable',
						    		    'html_encoding'	=> 'base64',
						    		    'head_charset'	=> 'utf-8',
						    		    'text_charset'	=> 'utf-8',
						    		    'html_charset'	=> 'utf-8',
	   				    			    'eol'		=> "\n",
		    				    	    'delay_file_io'	=> false,
										)
		   					    );
		$this->getAllAttachmentsRecursive($message,$mimemsg); 
		if ($this->_nativebodytype==1) {
		    $this->getBodyRecursive($message, "plain", $plain);
		    $this->getBodyRecursive($message, "html", $html);
   	    	if ($html == "") {
    	    	$this->getBodyRecursive($message, "plain", $html);
    		}
    		if ($html == "" && $plain == "" && strlen($this->_mobj->_body) != "") {
		   		$body .= "Content-Type:".$message->headers['content-type']."\r\n";
			    $body .= "Content-Transfer-Encoding:".$message->headers['content-transfer-encoding']."\r\n";
			    $body .= "\n\n".$this->_mobj->_body;
	    		return $body;
			}
			$mimemsg->setTXTBody(str_replace("\n","\r\n", str_replace("\r","",w2u($plain))));
		   	$html = '<html>'.
	    		    '<head>'.
			        '<meta name="Generator" content="Z-Push">'.
			        '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.
				    '</head>'.
				    '<body>'.
			        str_replace("\n","<BR>",str_replace("\r","", str_replace("\r\n","<BR>",w2u($html)))).
				    '</body>'.
					'</html>';
		   	$mimemsg->setHTMLBody(str_replace("\n","\r\n", str_replace("\r","",$html)));
		}
		if ($this->_nativebodytype==2) {
			$this->getBodyRecursive($message, "plain", $plain);
	    	if ($plain == "") {
			    $this->getBodyRecursive($message, "html", $plain);
			    // remove css-style tags
			    $plain = preg_replace("/<style.*?<\/style>/is", "", $plain);
		    	// remove all other html
			    $plain = preg_replace("/<br.*>/is","<br>",$plain);
			    $plain = preg_replace("/<br >/is","<br>",$plain);
			    $plain = preg_replace("/<br\/>/is","<br>",$plain);
			    $plain = str_replace("<br>","\r\n",$plain);
			    $plain = strip_tags($plain);
    		}
   			$mimemsg->setTXTBody(str_replace("\n","\r\n", str_replace("\r","",w2u($plain))));
	    	$this->getBodyRecursive($message, "html", $html);
	    	$mimemsg->setHTMLBody(str_replace("\n","\r\n", str_replace("\r","",w2u($html))));
		}
		if (!isset($output->airsyncbasebody->data))
		    $body = $body.$mimemsg->txtheaders()."\n\n".$mimemsg->get();
		return $body;
	}

    // Get all parts in the message with specified type and concatenate them together, unless the
    // Content-Disposition is 'attachment', in which case the text is apparently an attachment
    function getBodyRecursive($message, $subtype, &$body) {
        if(!isset($message->ctype_primary)) return;
        if(strcasecmp($message->ctype_primary,"text")==0 && strcasecmp($message->ctype_secondary,$subtype)==0 && isset($message->body))
            $body .= $message->body;

        if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
            foreach($message->parts as $part) {
                if(!isset($part->disposition) || strcasecmp($part->disposition,"attachment"))  {
                    $this->getBodyRecursive($part, $subtype, $body);
                }
            }
        }
    }

    function getAllAttachmentsRecursive($message,&$export_msg) {

//	    debugLog("getAttachmentsRecursive ".$message->disposition." ".$message->ctype_primary." ".$message->ctype_secondary." ".(isset($message->ctype_parameters['charset']) ? trim($message->ctype_parameters['charset']) : ""));
        if(!isset($message->ctype_primary)) return;

		if(isset($message->disposition) ||
			isset($message->headers['content-id'])) {
//	    	debugLog(print_r($message->headers,true));
//	    	debugLog($message->ctype_primary." ".$message->ctype_secondary." ".(isset($message->ctype_parameters['charset']) ? trim($message->ctype_parameters['charset']) : ""));
            if (isset($message->d_parameters['filename'])) 
            	$filename = $message->d_parameters['filename'];
            else if (isset($message->ctype_parameters['name'])) 
            	$filename = $message->ctype_parameters['name'];
			else if(isset($message->headers['content-description']))
				$filename = $message->headers['content-description'];
        	else {
        		if ($message->ctype_primary == "message" &&
        	    	 $message->ctype_secondary == "rfc822") {
        	    	$filename = "message.eml";
        	    } else {
	             	$filename = "unknown attachment";
				}
			}

			if (isset($message->body) && $message->body != "" &&
				($contenttype1 = trim($message->ctype_primary).'/'.trim($message->ctype_secondary)) != ($contenttype2 = trim(get_mime_type_from_content(trim($filename), $message->body)))) {
				debugLog("Content-Type in message differs determined one (".$contenttype1."/".$contenttype2."). Using determined one.");
				$contenttype = $contenttype2;
			} else {
				$contenttype = $contenttype1;
			}

			if (isset($message->headers['content-id'])) {
				$export_msg->addHTMLImage(	$message->body,
						$contenttype,
						$filename,
						false,
						substr(trim($message->headers['content-id']),1,-1));
			} else {
				$export_msg->addAttachment(	$message->body,
						$contenttype,
						$filename,
						false,
						trim($message->headers['content-transfer-encoding']),
						trim($message->disposition),
						(isset($message->ctype_parameters['charset']) ? trim($message->ctype_parameters['charset']) : ""));
			}
		} else {
//			Just for debugging in case something goes wrong and inline attachment is not being recognized right way
//			debugLog(print_r($message,true));
		}

        if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
           	foreach($message->parts as $part) {
               	$this->getAllAttachmentsRecursive($part,$export_msg);
           	}
 		}
    }
}
?>