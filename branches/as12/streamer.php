<?php
/***********************************************
* File      :   streamer.php
* Project   :   Z-Push
* Descr     :   This file handles streaming of
*                WBXML objects. It must be
*                subclassed so the internals of
*                the object can be specified via
*                $mapping. Basically we set/read
*                the object variables of the
*                subclass according to the mappings
*
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/
include_once("zpushdtd.php");

define('STREAMER_VAR', 1);
define('STREAMER_ARRAY', 2);
define('STREAMER_TYPE', 3);

define('STREAMER_TYPE_DATE', 1);
define('STREAMER_TYPE_HEX', 2);
define('STREAMER_TYPE_DATE_DASHES', 3);
define('STREAMER_TYPE_MAPI_STREAM', 4);


class Streamer {
    var $_mapping;

    var $content;
    var $attributes;
    var $flags;

    function Streamer($mapping) {
        $this->_mapping = $mapping;
        $this->flags = false;
    }

    // Decodes the WBXML from $input until we reach the same depth level of WBXML. This
    // means that if there are multiple objects at this level, then only the first is decoded
    // SubOjects are auto-instantiated and decoded using the same functionality
    function decode(&$decoder) {
        while(1) {
            $entity = $decoder->getElement();

            if($entity[EN_TYPE] == EN_TYPE_STARTTAG) {
                if(! ($entity[EN_FLAGS] & EN_FLAGS_CONTENT)) {
                    $map = $this->_mapping[$entity[EN_TAG]];
                    if(!isset($map[STREAMER_TYPE])) {
                        $this->$map[STREAMER_VAR] = "";
                    } else if ($map[STREAMER_TYPE] == STREAMER_TYPE_DATE || $map[STREAMER_TYPE] == STREAMER_TYPE_DATE_DASHES ) {
                        $this->$map[STREAMER_VAR] = "";
                    }
                    continue;
                }
                // Found a start tag
                if(!isset($this->_mapping[$entity[EN_TAG]])) {
                    // This tag shouldn't be here, abort
                    debug("Tag " . $entity[EN_TAG] . " unexpected in type XML type " . get_class($this));
                    return false;
                } else {
                    $map = $this->_mapping[$entity[EN_TAG]];

                    // Handle an array
                    if(isset($map[STREAMER_ARRAY])) {
                        while(1) {
                            if(!$decoder->getElementStartTag($map[STREAMER_ARRAY]))
                                break;
                            if(isset($map[STREAMER_TYPE])) {
                                $decoded = new $map[STREAMER_TYPE];
                                $decoded->decode($decoder);
                            } else {
                                $decoded = $decoder->getElementContent();
                            }

                            if(!isset($this->$map[STREAMER_VAR]))
                                $this->$map[STREAMER_VAR] = array($decoded);
                            else
                                array_push($this->$map[STREAMER_VAR], $decoded);

                            if(!$decoder->getElementEndTag())
                                return false;
                        }
                        if(!$decoder->getElementEndTag())
                            return false;
                    } else { // Handle single value
                        if(isset($map[STREAMER_TYPE])) {
                            // Complex type, decode recursively
                            if($map[STREAMER_TYPE] == STREAMER_TYPE_DATE || $map[STREAMER_TYPE] == STREAMER_TYPE_DATE_DASHES) {
                                $decoded = $this->parseDate($decoder->getElementContent());
                                if(!$decoder->getElementEndTag())
                                    return false;
                            } else if($map[STREAMER_TYPE] == STREAMER_TYPE_HEX) {
                                $decoded = hex2bin($decoder->getElementContent());
                                if(!$decoder->getElementEndTag())
                                    return false;
                            } else {
                                $subdecoder = new $map[STREAMER_TYPE]();
                                if($subdecoder->decode($decoder) === false)
                                    return false;

                                $decoded = $subdecoder;

                                if(!$decoder->getElementEndTag()) {
                                    debug("No end tag for " . $entity[EN_TAG]);
                                    return false;
                                }
                            }
                        } else {
                            // Simple type, just get content
                            $decoded = $decoder->getElementContent();

                            if($decoded === false) {
                                debug("Unable to get content for " . $entity[EN_TAG]);
                                return false;
                            }

                            if(!$decoder->getElementEndTag()) {
                                debug("Unable to get end tag for " . $entity[EN_TAG]);
                                return false;
                            }
                        }
                        // $decoded now contains data object (or string)
                        $this->$map[STREAMER_VAR] = $decoded;
                    }
                }
            }
            else if($entity[EN_TYPE] == EN_TYPE_ENDTAG) {
                $decoder->ungetElement($entity);
                break;
            }
            else {
                debug("Unexpected content in type");
                break;
            }
        }
    }

    // Encodes this object and any subobjects - output is ordered according to mapping
    function encode(&$encoder) {
        $attributes = isset($this->attributes) ? $this->attributes : array();

// START ADDED dw2412 Support V12.0
	if(isset($this->_mapping["AirSyncBase:Attachments"])) {
	    if (isset($this->attachments) &&
		sizeof($this->attachments) > 0) {
		for ($i=0;$i<sizeof($this->attachments);$i++) {
		    $this->airsyncbaseattachments[$i] = new SyncAirSyncBaseAttachment();
		    $this->airsyncbaseattachments[$i]->method = $this->attachments[$i]->attmethod;
	    	    $this->airsyncbaseattachments[$i]->estimateddatasize = $this->attachments[$i]->attsize;
		    $this->airsyncbaseattachments[$i]->displayname = $this->attachments[$i]->displayname;
		    $this->airsyncbaseattachments[$i]->filereference = $this->attachments[$i]->attname;
		    $this->airsyncbaseattachments[$i]->isinline = $this->attachments[$i]->isinline;
		    $this->airsyncbaseattachments[$i]->contentlocation = $this->attachments[$i]->contentlocation;
		    $this->airsyncbaseattachments[$i]->contentid = $this->attachments[$i]->contentid;
		    if (isset($this->attachments[$i]->_data)) $this->airsyncbaseattachments[$i]->_data = $this->attachments[$i]->_data;
		}
		unset($this->attachments);
	    }
	}
	if(isset($this->_mapping["AirSyncBase:Body"])) {
	    if (!isset($this->airsyncbasebody)) {
		$this->airsyncbasebody = new SyncAirSyncBaseBody();
		if (isset($this->rtf) && 
		    isset($encoder->_bodypreference[3]) && 
		    strlen($this->rtf) > 0 &&
		    strlen($this->rtf) < $encoder->_bodypreference[3]["TruncationSize"]) { // rtf in case supported and not truncated
		    $this->airsyncbasebody->type = 3;
		    $this->airsyncbasebody->data = $this->rtf;
		    $this->airsyncbasebody->estimateddatasize = strlen($this->rtf);
		    $this->body="";
		    $this->bodytruncated="";
		    $this->bodysize="";
		    $this->rtf="";
		} elseif (isset($this->body) && isset($encoder->_bodypreference[1])) { // plain text
		    $this->airsyncbasebody->type = 1;
		    $this->airsyncbasebody->estimateddatasize = strlen($this->body);
		    if (isset($this->bodytruncated)) {
			if ($this->bodytruncated > 0) {
			    $this->airsyncbasebody->truncated = $this->bodytruncated;
		    	    $this->airsyncbasebody->estimateddatasize = $this->bodysize;
			} else $this->airsyncbasebody->truncated = 0;
		    } else $this->airsyncbasebody->truncated = 0;
		    $this->airsyncbasebody->data = $this->body;
		    $this->body="";
		    $this->bodytruncated="";
		    $this->bodysize="";
		    $this->rtf="";
		}
	    }
//	    if (isset($this->_mapping["html"]) && isset($encoder->_bodypreference[3])) { // html
//	    }
//	    if (isset($this->_mapping["rtf"]) && isset($encoder->_bodypreference[3])) { // rtf
//		$this->_mapping["AirSyncBase:Body"][STREAMER_VAR]->type = 3;
//	    }
//	    if (isset($this->_mapping["mime"]) && isset($encoder->_bodypreference[4])) { // mime
//		$this->_mapping["AirSyncBase:Body"][STREAMER_VAR]->type = 4;
//	    }
	}
// END ADDED dw2412 Support V12.0
        foreach($this->_mapping as $tag => $map) {
//	    debugLog("streamer encode element ".$tag." ".print_r($map,true));
            if(isset($this->$map[STREAMER_VAR])) {
                // Variable is available
//		debugLog(print_r($this->$map[STREAMER_VAR],true));
                if(is_object($this->$map[STREAMER_VAR])) {
                    // Subobjects can do their own encoding
                    $encoder->startTag($tag);
                    $this->$map[STREAMER_VAR]->encode($encoder);
                    $encoder->endTag();
                } else if(isset($map[STREAMER_ARRAY])) {
                    // Array of objects
                    $encoder->startTag($tag); // Outputs array container (eg Attachments)
                    foreach ($this->$map[STREAMER_VAR] as $element) {
                        if(is_object($element)) {
                            $encoder->startTag($map[STREAMER_ARRAY]); // Outputs object container (eg Attachment)
                            $element->encode($encoder);
                            $encoder->endTag();
                        } else {
                            if(strlen($element) == 0)
                                  // Do not output empty items. Not sure if we should output an empty tag with $encoder->startTag($map[STREAMER_ARRAY], false, true);
                                  ;
                            else {
                                $encoder->startTag($map[STREAMER_ARRAY]);
                                $encoder->content($element);
                                $encoder->endTag();
                            }
                        }
                    }
                    $encoder->endTag();
                } else {
                    // Simple type
                    if(strlen($this->$map[STREAMER_VAR]) == 0) {
                          // Do not output empty items. See above: $encoder->startTag($tag, false, true);
                        continue;
                    } else if ($encoder->_multipart == true &&
                		($tag == SYNC_AIRSYNCBASE_DATA ||
                		 $tag == SYNC_AIRSYNCBASE_ATTACHMENT ||
                		 $tag == SYNC_ITEMOPERATIONS_DATA)) {  // START ADDED dw2412 to support mulitpart output
                	$encoder->_bodyparts[] = $this->$map[STREAMER_VAR];
                	$encoder->startTag(SYNC_ITEMOPERATIONS_PART);
                	$encoder->content("".(sizeof($encoder->_bodyparts)-1)."");
                	$encoder->endTag();
			continue; // END ADDED dw2412 to support mulitpart output
                    } else
                        $encoder->startTag($tag);

                    if(isset($map[STREAMER_TYPE]) && ($map[STREAMER_TYPE] == STREAMER_TYPE_DATE || $map[STREAMER_TYPE] == STREAMER_TYPE_DATE_DASHES)) {
                        if($this->$map[STREAMER_VAR] != 0) // don't output 1-1-1970
                            $encoder->content($this->formatDate($this->$map[STREAMER_VAR], $map[STREAMER_TYPE]));
                    } else if(isset($map[STREAMER_TYPE]) && $map[STREAMER_TYPE] == STREAMER_TYPE_HEX) {
                        $encoder->content(bin2hex($this->$map[STREAMER_VAR]));
                    } else if(isset($map[STREAMER_TYPE]) && $map[STREAMER_TYPE] == STREAMER_TYPE_MAPI_STREAM) {
                        $encoder->content($this->$map[STREAMER_VAR]);
                    } else {
                        $encoder->content($this->$map[STREAMER_VAR]);
                    }
                    $encoder->endTag();
                }
            }
        }
        // Output our own content
        if(isset($this->content))
            $encoder->content($this->content);

    }



    // Oh yeah. This is beautiful. Exchange outputs date fields differently in calendar items
    // and emails. We could just always send one or the other, but unfortunately nokia's 'Mail for
    // exchange' depends on this quirk. So we have to send a different date type depending on where
    // it's used. Sigh.
    function formatDate($ts, $type) {
        if($type == STREAMER_TYPE_DATE)
            return gmstrftime("%Y%m%dT%H%M%SZ", $ts);
        else if($type == STREAMER_TYPE_DATE_DASHES)
            return gmstrftime("%Y-%m-%dT%H:%M:%S.000Z", $ts);
    }

    function parseDate($ts) {
        if(preg_match("/(\d{4})[^0-9]*(\d{2})[^0-9]*(\d{2})T(\d{2})[^0-9]*(\d{2})[^0-9]*(\d{2})(.\d+)?Z/", $ts, $matches)) {
            if ($matches[1] >= 2038){
                $matches[1] = 2038;
                $matches[2] = 1;
                $matches[3] = 18;
                $matches[4] = $matches[5] = $matches[6] = 0;
            }
            return gmmktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]);
        }
        return 0;
    }
};

?>