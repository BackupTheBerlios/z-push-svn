<?php
/***********************************************
* File      :   statemachine.php
* Project   :   Z-Push
* Descr     :   This class handles state requests;
*               Each differential mechanism can
*               store its own state information,
*               which is stored through the
*               state machine. SyncKey's are
*               of the  form {UUID}N, in which
*               UUID is allocated during the
*               first sync, and N is incremented
*               for each request to 'getNewSyncKey'.
*               A sync state is simple an opaque
*               string value that can differ
*               for each backend used - normally
*               a list of items as the backend has
*               sent them to the PIM. The backend
*               can then use this backend
*               information to compute the increments
*               with current data.
*
*               Old sync states are not deleted
*               until a sync state is requested.
*               At that moment, the PIM is
*               apparently requesting an update
*               since sync key X, so any sync
*               states before X are already on
*               the PIM, and can therefore be
*               removed. This algorithm is
*               automatically enforced by the
*               StateMachine class.
*
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/


class StateMachine {
    var $_devid;
    
    // Gets the sync state for a specified sync key. Requesting a sync key also implies
    // that previous sync states for this sync key series are no longer needed, and the
    // state machine will tidy up these files.
    function StateMachine($devid) {
	$this->_devid = strtolower($devid);
	debugLog ("Statemachine _devid initialized with ".$this->_devid);
        $dir = opendir(BASE_PATH . STATE_DIR. "/" .$this->_devid);
        if(!$dir) {
	    debugLog("StateMachine: created folder for device ".$this->_devid);
	    if (mkdir(BASE_PATH . STATE_DIR. "/" .$this->_devid, 0744) === false) 
		debugLog("StateMachine: failed to create folder ".$this->_devid);
	}
    }

    function getSyncState($synckey) {

        // No sync state for sync key '0'
        if($synckey == "0" || $synckey == "SMS0") {
	    debugLog("GetSyncState: Sync key 0 detected");
            return "";
	}
	
        // Check if synckey is allowed
        if(!preg_match('/^(s|SMS){0,1}\{([0-9A-Za-z-]+)\}([0-9]+)$/', $synckey, $matches)) {
	    debugLog("GetSyncState: Sync key invalid formatted");
            return -9;
        }

        // Remember synckey GUID and ID
	$key = $matches[1];
        $guid = $matches[2];
        $n = $matches[3];

        // Cleanup all older syncstates
        $dir = opendir(BASE_PATH . STATE_DIR."/".$this->_devid);
        if(!$dir) {
	    debugLog("GetSyncState: Sync key folder not existing");
            return -12;
	}

        while($entry = readdir($dir)) {
            if(preg_match('/^(s|SMS){0,1}\{([0-9A-Za-z-]+)\}([0-9]+)$/', $entry, $matches)) {
                if($matches[1] == $key && $matches[2] == $guid && $matches[3] < $n) {
		    debugLog("GetSyncState: Removing old Sync Key ".BASE_PATH . STATE_DIR . "/". $this->_devid . "/$entry");
                    unlink(BASE_PATH . STATE_DIR . "/".$this->_devid . "/$entry");
                }
            }
        }

        // Read current sync state
        $filename = BASE_PATH . STATE_DIR . "/". $this->_devid . "/$synckey";

	// For SMS Sync take on first sync the Main Sync Key from the folder in question
	if(!file_exists($filename) &&
	    $key=="SMS" &&
	    $n==1) {
	    debugLog("GetSyncState: Initial SMS Sync, take state from the main folder file");
    	    $filename = BASE_PATH . STATE_DIR . "/" .$this->_devid . '/{'.$guid.'}'.$n;
	}

        if(file_exists($filename)) {
	    $content = file_get_contents($filename);
	    // invalidate current syncstate files in case a newer version exists already
	    // Prevent endless loops. Once it might be because of transmission errors
	    // 2nd time we force by this a complete resync.
	    // debugLog("GetSyncState: Does file ".BASE_PATH . STATE_DIR . '/'.$key.'{'.$guid.'}'.($n+1)." exist?");
//            if (file_exists(BASE_PATH . STATE_DIR . "/". $this->_devid . '/'.$key.'{'.$guid.'}'.($n+1))) {
//		debugLog("GetSyncState: Removing ".BASE_PATH . STATE_DIR . "/". $this->_devid . '/'.$key.'{'.$guid.'}'.$n . " since newer version already exists");
//        	unlink(BASE_PATH . STATE_DIR . "/". $this->_devid . '/'.$key.'{'.$guid.'}'.$n);
//            }
            return $content;
        } else {
	    debugLog("GetSyncState: File $filename not existing");
    	    return -9;
    	}
    }

    // Gets the new sync key for a specified sync key. You must save the new sync state
    // under this sync key when done sync'ing (by calling setSyncState);
    function getNewSyncKey($synckey) {
        if(!isset($synckey) || $synckey == "0") {
            return "{" . $this->uuid() . "}" . "1";
        } else {
            if(preg_match('/^(s|SMS){0,1}\{([a-zA-Z0-9-]+)\}([0-9]+)$/', $synckey, $matches)) {
                $n = $matches[3];
                $n++;
                return "{" . $matches[2] . "}" . $n;
            } else return false;
        }
    }

    // Writes the sync state to a new synckey
    function setSyncState($synckey, $syncstate) {
        // Check if synckey is allowed
	debugLog("setSyncState: Try writing to file ".BASE_PATH . STATE_DIR . "/". $this->_devid . "/$synckey");
        if(!preg_match('/^(s|SMS){0,1}\{[0-9A-Za-z-]+\}[0-9]+$/', $synckey)) {
	    debugLog("setSyncState: Format not match!");
            return false;
        }

        return file_put_contents(BASE_PATH . STATE_DIR . "/". $this->_devid . "/$synckey", $syncstate);
    }

    // Writes the sync state to a new synckey
    function setSyncCache($cachestate) {
	return file_put_contents(BASE_PATH . STATE_DIR . "/". $this->_devid . "/cache_".$this->_devid, $cachestate);
    }

    function getSyncCache() {
        if(file_exists(BASE_PATH . STATE_DIR . "/". $this->_devid . "/cache_".$this->_devid))
            return file_get_contents(BASE_PATH . STATE_DIR . "/". $this->_devid . "/cache_".$this->_devid);
        else return false;
    }

    function deleteSyncCache() {
    	// Remove the cache in case full sync is requested with a synckey 0. 
    	if(file_exists(BASE_PATH . STATE_DIR . "/". $this->_devid . "/cache_".$this->_devid))
    	    unlink(BASE_PATH . STATE_DIR . "/". $this->_devid . "/cache_".$this->_devid);
    }

    function updateSyncCacheFolder(&$cache, $serverid, $parentid, $displayname, $type) {
	debugLog((!isset($cache['folders'][$serverid]) ? "Adding" : "Updating")." SyncCache Folder ".$serverid." Parent: ".$parentid." Name: ".$displayname." Type: ". $type);
        if (isset($parentid))    $cache['folders'][$serverid]['parentid'] = $parentid;
        if (isset($displayname)) $cache['folders'][$serverid]['displayname'] = $displayname;
	switch ($type) {
	    case 7	: // These are Task classes
	    case 13	: $cache['folders'][$serverid]['class'] = "Tasks"; break;
	    case 8	: // These are calendar classes
	    case 14	: $cache['folders'][$serverid]['class'] = "Calendar"; break;
	    case 9	: // These are contact classes
	    case 15	: $cache['folders'][$serverid]['class'] = "Contacts"; break;
	    case 1	: // All other types map to Email
	    case 2	: 
	    case 3	: 
	    case 4	: 
	    case 5	: 
	    case 6	: 
	    case 10	: 
	    case 11	: 
	    case 12	: 
	    case 16	: 
	    case 17	: 
	    case 18	: 
	    default	: $cache['folders'][$serverid]['class'] = "Email";
	}
	$cache['folders'][$serverid]['filtertype'] = "0";
	$cache['timestamp'] = time();
    }

    function deleteSyncCacheFolder(&$cache, $serverid) {
	debugLog("Delete SyncCache Folder ".$serverid);
	unset($cache['folders'][$serverid]);
	unset($cache['collections'][$serverid]);
	$cache['timestamp'] = time();
    }
    
    function getProtocolState() {
        if(file_exists(BASE_PATH . STATE_DIR . "/". $this->_devid . "/prot_".$this->_devid))
            return file_get_contents(BASE_PATH . STATE_DIR . "/". $this->_devid . "/prot_".$this->_devid);
        else return false;
    }
    
    function setProtocolState($protstate) {
        return file_put_contents(BASE_PATH . STATE_DIR . "/". $this->_devid . "/prot_".$this->_devid,$protstate);
    }
    
    function uuid()
    {
        return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
                    mt_rand( 0, 0x0fff ) | 0x4000,
                    mt_rand( 0, 0x3fff ) | 0x8000,
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
    }
};

?>
