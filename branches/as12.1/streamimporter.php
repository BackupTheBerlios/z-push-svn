<?php
/***********************************************
* File      :   streamimporter.php
* Project   :   Z-Push
* Descr     :   Stream import classes
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

// We don't support caching changes for messages
class ImportContentsChangesStream {
    var $_encoder;
    var $_type;
    var $_seenObjects;
    var $_optiontype;
    var $_onlyoption;

    function ImportContentsChangesStream(&$encoder, $type, $optiontype) {
        $this->_encoder = &$encoder;
        $this->_type = $type;
        $this->_optiontype = $optiontype;
        $this->_seenObjects = array();
     }
    
    function ImportMessageChange($id, $message) {
	debugLog("Class of this message: ".strtolower(get_class($message)) . " Expected Class ".$this->_type . " Option Type ".$this->_optiontype);
	debugLog("HERE ImportMessageChange ".$this->_optiontype);
        $class = strtolower(get_class($message));
        if( $class != $this->_type && $class != $this->_optiontype)
            return true; // ignore other types

        // prevent sending the same object twice in one request
        if (in_array($id, $this->_seenObjects)) {
               debugLog("Object $id discarted! Object already sent in this request.");
               return true;
        } 
        
        $this->_seenObjects[] = $id;
            
        if ($message->flags === false || $message->flags === SYNC_NEWMESSAGE)           
            $this->_encoder->startTag(SYNC_ADD);
        else
            $this->_encoder->startTag(SYNC_MODIFY);
            
	if ($this->_optiontype && $message->messageclass == "IPM.Note.Mobile.SMS") {
	    $this->_encoder->startTag(SYNC_FOLDERTYPE);
	    $this->_encoder->content("SMS");
    	    $this->_encoder->endTag();
    	}
        $this->_encoder->startTag(SYNC_SERVERENTRYID);
        $this->_encoder->content($id);
        $this->_encoder->endTag();
        $this->_encoder->startTag(SYNC_DATA);
        $message->encode($this->_encoder);
        $this->_encoder->endTag();
        $this->_encoder->endTag();
        
        return true;
    }
    
    function ImportMessageDeletion($id) {
        $this->_encoder->startTag(SYNC_REMOVE);
	debugLog("HERE ImportMessageDeletion ".$this->_optiontype);
	if ($this->_optiontype && $this->_optiontype == "syncsms") {
	    $this->_encoder->startTag(SYNC_FOLDERTYPE);
	    $this->_encoder->content("SMS");
    	    $this->_encoder->endTag();
	}
        $this->_encoder->startTag(SYNC_SERVERENTRYID);
        $this->_encoder->content($id);
        $this->_encoder->endTag();
        $this->_encoder->endTag();
        
        return true;
    }
    
    function ImportMessageReadFlag($id, $flags) {
	debugLog("HERE ImportMessageReadFlag ".$this->_optiontype);
        if($this->_type != "syncmail")
            return true;
        $this->_encoder->startTag(SYNC_MODIFY);
	    if ($this->_optiontype && $this->_optiontype == "syncsms") {
		$this->_encoder->startTag(SYNC_FOLDERTYPE);
		$this->_encoder->content("SMS");
    		$this->_encoder->endTag();
    	    }
            $this->_encoder->startTag(SYNC_SERVERENTRYID);
                $this->_encoder->content($id);
            $this->_encoder->endTag();
            $this->_encoder->startTag(SYNC_DATA);
                $this->_encoder->startTag(SYNC_POOMMAIL_READ);
                    $this->_encoder->content($flags);
                $this->_encoder->endTag();
            $this->_encoder->endTag();
        $this->_encoder->endTag();
        
        return true;
    }

    function ImportMessageMove($message) {
	debugLog("HERE ImportMessageMove ".$this->_optiontype);
        return true;
    }
};

class ImportHierarchyChangesStream {
    
    function ImportHierarchyChangesStream() {
        return true;
    }
    
    function ImportFolderChange($folder) {
        return true;
    }

    function ImportFolderDeletion($folder) {
        return true;
    }
};

?>