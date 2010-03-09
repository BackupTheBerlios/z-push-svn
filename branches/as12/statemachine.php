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
    // Gets the sync state for a specified sync key. Requesting a sync key also implies
    // that previous sync states for this sync key series are no longer needed, and the
    // state machine will tidy up these files.
    function getSyncState($synckey) {
        // No sync state for sync key '0'
        if($synckey == "0") {
            return "";
	}
	
        // Check if synckey is allowed
        if(!preg_match('/^(s|SMS){0,1}\{([0-9A-Za-z-]+)\}([0-9]+)$/', $synckey, $matches)) {
	    debugLog("GetSyncState: Sync key invalid formatted");
            return -9;
        }

        // Remember synckey key, GUID and ID
	$key = $matches[1];
        $guid = $matches[2];
        $n = $matches[3];

        // Cleanup all older syncstates
        $dir = opendir(BASE_PATH . STATE_DIR);
        if(!$dir) {
	    debugLog("GetSyncState: Sync key folder not existing");
            return -12;
	}

        while($entry = readdir($dir)) {
            if(preg_match('/^s{0,1}\{([0-9A-Za-z-]+)\}([0-9]+)$/', $entry, $matches)) {
                if($matches[1] == $guid && $matches[2] < $n) {
		    debugLog("GetSyncState: Removing old Sync Key ".BASE_PATH . STATE_DIR . "/$entry");
                    unlink(BASE_PATH . STATE_DIR . "/$entry");
                }
            }
        }

        // Read current sync state
        $filename = BASE_PATH . STATE_DIR . "/$synckey";

        if(file_exists($filename)) {
	    $content = file_get_contents(BASE_PATH . STATE_DIR . "/$synckey");
	    // In case a newer file exists, we read the newer state even in case an old state is being requested
	    // on 2nd sync attempt in case there is already a newer state available.
	    // At Nokia MfE 3.0 this occurs only at the 2nd sync where it requests with sync key of 1st sync.
            if ($n==1 &&
        	file_exists(BASE_PATH . STATE_DIR . '/'.$key.'{'.$guid.'}'.($n+1))) {
		debugLog("GetSyncState: Reading SyncKey ".($n+1)." state instead of ".$n." since new State exists but Old being requested. Normal behaviour with Nokia MfE 3.0");
		$content = file_get_contents(BASE_PATH . STATE_DIR . '/'.$key.'{'.$guid.'}'.($n+1));
            }
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
            if(preg_match('/^s{0,1}\{([a-fA-F0-9-]+)\}([0-9]+)$/', $synckey, $matches)) {
                $n = $matches[2];
                $n++;
                return "{" . $matches[1] . "}" . $n;
            } else return false;
        }
    }

    // Writes the sync state to a new synckey
    function setSyncState($synckey, $syncstate) {
        // Check if synckey is allowed
        if(!preg_match('/^s{0,1}\{[0-9A-Za-z-]+\}[0-9]+$/', $synckey)) {
            return false;
        }

        return file_put_contents(BASE_PATH . STATE_DIR . "/$synckey", $syncstate);
    }

    // Writes the sync state to a new synckey
    function setSyncCache($devid, $cachestate) {
        return file_put_contents(BASE_PATH . STATE_DIR . "/cache_$devid", $cachestate);
    }
    function getSyncCache($devid) {
        if(file_exists(BASE_PATH . STATE_DIR . "/cache_".$devid))
            return file_get_contents(BASE_PATH . STATE_DIR . "/cache_".$devid);
        else return false;
    }
    function deleteSyncCache($devid) {
    	// Remove the cache in case full sync is requested with a synckey 0. 
    	if(file_exists(BASE_PATH . STATE_DIR . "/cache_".$devid))
    	    unlink(BASE_PATH . STATE_DIR . "/cache_".$devid);
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
