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

    function ImportContentsChangesStream(&$encoder, $type) {
        $this->_encoder = &$encoder;
        $this->_type = $type;
        $this->_seenObjects = array();
        $this->_deletedObjects = array();
    }
    
    function ImportMessageChange($id, $message) {
        if(strtolower(get_class($message)) != $this->_type)
            return true; // ignore other types

        // prevent sending the same object twice in one request
        if (in_array($id, $this->_seenObjects)) {
               debugLog("Object $id discarted! Object already sent in this request.");
               return true;
        } 
        
	// prevent sending changes for objects that delete information was sent prior the change details arrived
        if (in_array($id, $this->_deletedObjects)) {
               debugLog("Object $id discarded! Object deleted prior change submission.");
               return true;
        } 

        $this->_seenObjects[] = $id;
            
        if ($message->flags === false || $message->flags === SYNC_NEWMESSAGE)           
            $this->_encoder->startTag(SYNC_ADD);
        else
            $this->_encoder->startTag(SYNC_MODIFY);
            
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
	// prevent sending changes for objects that delete information was sent already
        if (in_array($id, $this->_deletedObjects)) {
               debugLog("Object $id discarded! Object already deleted.");
               return true;
        } 
        $this->_deletedObjects[] = $id;

        $this->_encoder->startTag(SYNC_REMOVE);
        $this->_encoder->startTag(SYNC_SERVERENTRYID);
        $this->_encoder->content($id);
        $this->_encoder->endTag();
        $this->_encoder->endTag();
        
        return true;
    }
    
    function ImportMessageReadFlag($id, $flags) {
        if($this->_type != "syncmail")
            return true;
	// prevent sending readflags for objects that delete information was sentbefore
        if (in_array($id, $this->_deletedObjects)) {
               debugLog("Object $id discarded! Object got deleted prior the readflag set request arrived.");
               return true;
        } 

        $this->_encoder->startTag(SYNC_MODIFY);
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