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
    var $_deletedObjects;
    var $_optiontype;
    var $_onlyoption;
    var $_lastObjectStatus;
    var $_readids;
    var $_flagids;

    function ImportContentsChangesStream(&$encoder, $type, $optiontype, $ids) {
        $this->_encoder = &$encoder;
        $this->_type = $type;
        $this->_optiontype = $optiontype;
        $this->_seenObjects = array();
        $this->_deletedObjects = array();
        $this->_readids = $ids['readids'];
        $this->_flagids = $ids['flagids'];
    }
    
    function ImportMessageChange($id, $message) {
    
//	debugLog("Class of this message: ".strtolower(get_class($message)) . " Expected Class ".$this->_type . " Option Type ".$this->_optiontype);
//	debugLog("HERE ImportMessageChange ".$this->_optiontype);
        $class = strtolower(get_class($message));
        if( $class != $this->_type && $class != $this->_optiontype) {
	    $this->_lastObjectStatus = -1;
    	    return true; // ignore other types
	}
	
        // prevent sending the same object twice in one request
        if (in_array($id, $this->_seenObjects)) {
            debugLog("Object $id discarded! Object already sent in this request. Flags=".$message->flags);
	    $this->_lastObjectStatus = -1;
            return true;
        } 

	// prevent sending changes for objects that delete information was sent prior the change details arrived
        if (in_array($id, $this->_deletedObjects)) {
            debugLog("Object $id discarded! Object deleted prior change submission.");
	    $this->_lastObjectStatus = -1;
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

        if (!isset($this->_readids[$id]) && !isset($this->_flagids[$id])) {
    	    $message->encode($this->_encoder);
	} else {
    	    if (isset($this->_readids[$id])) {
		$this->_encoder->startTag(SYNC_POOMMAIL_READ);
    		$this->_encoder->content($message->read);
    		$this->_encoder->endTag();
	    }
	    if (isset($this->_flagids[$id])) {
		if ($message->poommailflag->flagstatus == 0 || $message->poommailflag->flagstatus == "") {
		    $this->_encoder->startTag(SYNC_POOMMAIL_FLAG,false,true);
		} else {
		    $this->_encoder->startTag(SYNC_POOMMAIL_FLAG);
        	    $message->poommailflag->encode($this->_encoder);
    		    $this->_encoder->endTag();
		}
	    }
        }
        $this->_encoder->endTag();
        $this->_encoder->endTag();
        
	$this->_lastObjectStatus = 1;
        return true;
    }
    
    function ImportMessageDeletion($id) {
//	debugLog("HERE ImportMessageDeletion ".$this->_optiontype);

	// prevent sending changes for objects that delete information was sent already
        if (in_array($id, $this->_deletedObjects)) {
            debugLog("Object $id discarded! Object already deleted.");
	    $this->_lastObjectStatus = -1;
    	    return true;
        } 
        $this->_deletedObjects[] = $id;

        $this->_encoder->startTag(SYNC_REMOVE);
	if ($this->_optiontype && $this->_optiontype == "syncsms") {
	    $this->_encoder->startTag(SYNC_FOLDERTYPE);
	    $this->_encoder->content("SMS");
    	    $this->_encoder->endTag();
	}
        $this->_encoder->startTag(SYNC_SERVERENTRYID);
        $this->_encoder->content($id);
        $this->_encoder->endTag();
        $this->_encoder->endTag();
        
	$this->_lastObjectStatus = 1;
        return true;
    }
    
    function ImportMessageReadFlag($id, $flags) {
//	debugLog("HERE ImportMessageReadFlag ".$this->_optiontype);
	// prevent sending readflags for objects that delete information was sentbefore
        if (in_array($id, $this->_deletedObjects)) {
    	    debugLog("Object $id discarded! Object got deleted prior the readflag set request arrived.");
	    $this->_lastObjectStatus = -1;
    	    return true;
        } 
        if($this->_type != "syncmail") {
	    $this->_lastObjectStatus = -1;
    	    return true;
	}
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
        
	$this->_lastObjectStatus = 1;
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