<?php
/***********************************************
* File      :   request.php
* Project   :   Z-Push
* Descr     :   This file contains the actual
*               request handling routines.
*               The request handlers are optimised
*               so that as little as possible
*               data is kept-in-memory, and all
*               output data is directly streamed
*               to the client, while also streaming
*               input data from the client.
*
* Created   :   01.10.2007
*
*  Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

include_once("proto.php");
include_once("wbxml.php");
include_once("statemachine.php");
include_once("backend/backend.php");
include_once("memimporter.php");
include_once("streamimporter.php");
include_once("zpushdtd.php");
include_once("zpushdefs.php");
include_once("include/utils.php");

function GetObjectClassFromFolderClass($folderclass)
{
    $classes = array ( "Email" => "syncmail", "Contacts" => "synccontact", "Calendar" => "syncappointment", "Tasks" => "synctask" );

    return $classes[$folderclass];
}

function HandleMoveItems($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_MOVE_MOVES))
        return false;

    $moves = array();
    while($decoder->getElementStartTag(SYNC_MOVE_MOVE)) {
        $move = array();
        if($decoder->getElementStartTag(SYNC_MOVE_SRCMSGID)) {
            $move["srcmsgid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                break;
        }
        if($decoder->getElementStartTag(SYNC_MOVE_SRCFLDID)) {
            $move["srcfldid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                break;
        }
        if($decoder->getElementStartTag(SYNC_MOVE_DSTFLDID)) {
            $move["dstfldid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                break;
        }
        array_push($moves, $move);

        if(!$decoder->getElementEndTag())
            return false;
    }

    if(!$decoder->getElementEndTag())
        return false;

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_MOVE_MOVES);

    foreach($moves as $move) {
        $encoder->startTag(SYNC_MOVE_RESPONSE);
        $encoder->startTag(SYNC_MOVE_SRCMSGID);
        $encoder->content($move["srcmsgid"]);
        $encoder->endTag();

        $importer = $backend->GetContentsImporter($move["srcfldid"]);
        $result = $importer->ImportMessageMove($move["srcmsgid"], $move["dstfldid"]);
        // We discard the importer state for now.

        $encoder->startTag(SYNC_MOVE_STATUS);
        $encoder->content($result ? 3 : 1);
        $encoder->endTag();

        $encoder->startTag(SYNC_MOVE_DSTMSGID);
        $encoder->content(is_string($result)?$result:$move["srcmsgid"]);
        $encoder->endTag();
        $encoder->endTag();
    }

    $encoder->endTag();
    return true;
}

function HandleNotify($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_AIRNOTIFY_NOTIFY))
        return false;

    if(!$decoder->getElementStartTag(SYNC_AIRNOTIFY_DEVICEINFO))
        return false;

    if(!$decoder->getElementEndTag())
        return false;

    if(!$decoder->getElementEndTag())
        return false;

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_AIRNOTIFY_NOTIFY);
    {
        $encoder->startTag(SYNC_AIRNOTIFY_STATUS);
        $encoder->content(1);
        $encoder->endTag();

        $encoder->startTag(SYNC_AIRNOTIFY_VALIDCARRIERPROFILES);
        $encoder->endTag();
    }

    $encoder->endTag();

    return true;

}

// Handle GetHierarchy method - simply returns current hierarchy of all folders
function HandleGetHierarchy($backend, $protocolversion, $devid) {
    global $zpushdtd;
    global $output;

    // Input is ignored, no data is sent by the PIM
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    $folders = $backend->GetHierarchy();

    if(!$folders)
        return false;

    // save folder-ids for fourther syncing
    _saveFolderData($devid, $folders);

    $encoder->StartWBXML();
    $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERS);

    foreach ($folders as $folder) {
        $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDER);
        $folder->encode($encoder);
        $encoder->endTag();
    }

    $encoder->endTag();
    return true;
}

// Handles a 'FolderSync' method - receives folder updates, and sends reply with
// folder changes on the server
function HandleFolderSync($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    // Maps serverid -> clientid for items that are received from the PIM
    $map = array();

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    // Parse input

    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC))
        return false;

    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SYNCKEY))
        return false;

    $synckey = $decoder->getElementContent();

    if(!$decoder->getElementEndTag())
        return false;

    // First, get the syncstate that is associated with this synckey
    $statemachine = new StateMachine();

    // The state machine will discard any sync states before this one, as they are no
    // longer required
    $syncstate = $statemachine->getSyncState($synckey);

    // additional information about already seen folders
    $sfolderstate = $statemachine->getSyncState("s".$synckey);
    
    if ($synckey != "0" && (is_numeric($sfolderstate) && $sfolderstate < 0) || 
			   (is_numeric($syncstate) && $syncstate < 0)) {
	debugLog("GetSyncState ERROR (sfolderstate: ".abs($sfolderstate).", Syncstate: ".abs($syncstate).")");
	if ($sfolderstate < 0) $status = abs($sfolderstate);
	if ($syncstate < 0) $status = abs($syncstate);
	// Output our WBXML reply now
	$encoder->StartWBXML();

        $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC);
        {
	    $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
	    $encoder->content(abs($status));
    	    $encoder->endTag();
	}
    	$encoder->endTag();
    	return true;
    } else {
	debugLog("GetSyncState OK");
    }


    if (!$sfolderstate ||
	$sfolderstate == "") {
        $foldercache = array();
        if ($sfolderstate === false) 
            debugLog("Error: FolderChacheState for state 's". $synckey ."' not found. Reinitializing...");
    }
    else {
    	$foldercache = unserialize($sfolderstate);

    	// transform old seenfolder array
    	if (array_key_exists("0", $foldercache)) {
    		$tmp = array();
    		foreach($foldercache as $s) $tmp[$s] = new SyncFolder();
    		$foldercache = $tmp;
    	}
    }
        
    // We will be saving the sync state under 'newsynckey'
    $newsynckey = $statemachine->getNewSyncKey($synckey);
    $changes = false;
    
    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_CHANGES)) {
        // Ignore <Count> if present
        if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_COUNT)) {
            $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        // Process the changes (either <Add>, <Modify>, or <Remove>)
        $element = $decoder->getElement();

        if($element[EN_TYPE] != EN_TYPE_STARTTAG)
            return false;

        while(1) {
            $folder = new SyncFolder();
            if(!$folder->decode($decoder))
                break;

            // Configure importer with last state
            $importer = $backend->GetHierarchyImporter();
            $importer->Config($syncstate);


            switch($element[EN_TAG]) {
                case SYNC_ADD:
                case SYNC_MODIFY:
                    $serverid = $importer->ImportFolderChange($folder);
                    // add folder to the serverflags
                    $foldercache[$serverid] = $folder;
                    $changes = true;
                    break;
                case SYNC_REMOVE:
                    $serverid = $importer->ImportFolderDeletion($folder);
                    $changes = true;
                    // remove folder from the folderchache
                    if (array_key_exists($serverid, $foldercache)) 
                        unset($foldercache[$serverid]);
                    break;
            }

            if($serverid)
                $map[$serverid] = $folder->clientid;
        }

        if(!$decoder->getElementEndTag())
            return false;
    }

    if(!$decoder->getElementEndTag())
        return false;

    // We have processed incoming foldersync requests, now send the PIM
    // our changes

    // The MemImporter caches all imports in-memory, so we can send a change count
    // before sending the actual data. As the amount of data done in this operation
    // is rather low, this is not memory problem. Note that this is not done when
    // sync'ing messages - we let the exporter write directly to WBXML.
    $importer = new ImportHierarchyChangesMem($foldercache);

    // Request changes from backend, they will be sent to the MemImporter passed as the first
    // argument, which stores them in $importer. Returns the new sync state for this exporter.
    $exporter = $backend->GetExporter();

    $exporter->Config($importer, false, false, $syncstate, 0, 0, false);

    while(is_array($exporter->Synchronize()));

    // Output our WBXML reply now
    $encoder->StartWBXML();

    $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC);
    {
        $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
        $encoder->content(1);
        $encoder->endTag();

        $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
        // only send new synckey if changes were processed or there are outgoing changes
        $encoder->content((($changes || $importer->count > 0)?$newsynckey:$synckey));
        $encoder->endTag();

        $encoder->startTag(SYNC_FOLDERHIERARCHY_CHANGES);
        {
            $encoder->startTag(SYNC_FOLDERHIERARCHY_COUNT);
            $encoder->content($importer->count);
            $encoder->endTag();

            if(count($importer->changed) > 0) {
                foreach($importer->changed as $folder) {
                	// send a modify flag if the folder is already known on the device
                	if (isset($folder->serverid) && array_key_exists($folder->serverid, $foldercache) !== false)
                        $encoder->startTag(SYNC_FOLDERHIERARCHY_UPDATE);
                	else 
                        $encoder->startTag(SYNC_FOLDERHIERARCHY_ADD);
                    $foldercache[$folder->serverid] = $folder;
                    
                    $folder->encode($encoder);
                    $encoder->endTag();
                }
            }

            if(count($importer->deleted) > 0) {
                foreach($importer->deleted as $folder) {
                    $encoder->startTag(SYNC_FOLDERHIERARCHY_REMOVE);
                        $encoder->startTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID);
                            $encoder->content($folder);
                        $encoder->endTag();
                    $encoder->endTag();

                    // remove folder from the folderflags array
                    if (array_key_exists($folder, $foldercache)) 
                        unset($foldercache[$folder]);
                }
            }
        }
        $encoder->endTag();
    }
    $encoder->endTag();

    // Save the sync state for the next time
    $syncstate = $exporter->GetState();
    $statemachine->setSyncState($newsynckey, $syncstate);
    $statemachine->setSyncState("s".$newsynckey, serialize($foldercache));


    return true;
}

function HandleSync($backend, $protocolversion, $devid) {
    global $zpushdtd;
    global $input, $output;

    // Contains all containers requested
    $collections = array();

    // Init WBXML decoder
    $decoder = new WBXMLDecoder($input, $zpushdtd);

    // Init state machine
    $statemachine = new StateMachine();

    // Start decode
    if(!$decoder->getElementStartTag(SYNC_SYNCHRONIZE))
        return false;

    if(!$decoder->getElementStartTag(SYNC_FOLDERS))
        return false;

    $status = 1; // SYNC_STATUS
    while($decoder->getElementStartTag(SYNC_FOLDER))
    {
        $collection = array();
        $collection["truncation"] = SYNC_TRUNCATION_ALL;
        $collection["clientids"] = array();
        $collection["fetchids"] = array();

        if(!$decoder->getElementStartTag(SYNC_FOLDERTYPE))
            return false;

        $collection["class"] = $decoder->getElementContent();
        debugLog("Sync folder:{$collection["class"]}");

        if(!$decoder->getElementEndTag())
            return false;

        if(!$decoder->getElementStartTag(SYNC_SYNCKEY))
            return false;

        $collection["synckey"] = $decoder->getElementContent();

        if(!$decoder->getElementEndTag())
            return false;

        if($decoder->getElementStartTag(SYNC_FOLDERID)) {
            $collection["collectionid"] = $decoder->getElementContent();

            if(!$decoder->getElementEndTag())
                return false;
        }

        if($decoder->getElementStartTag(SYNC_SUPPORTED)) {
            while(1) {
                $el = $decoder->getElement();
                if($el[EN_TYPE] == EN_TYPE_ENDTAG)
                    break;
            }
        }

// START CHANGED dw2412 since according to MS Specs this Element may have a value
        if($decoder->getElementStartTag(SYNC_DELETESASMOVES)) {
            if (($collection["deletesasmoves"] = $decoder->getElementContent())) {
        	if(!$decoder->getElementEndTag()) {
            	    return false;
            	};
	    } else {
                $collection["deletesasmoves"] = true;
	    }
	}
// END CHANGED dw2412 since according to MS Specs this Element may have a value
	
// START CHANGED dw2412 since according to MS Specs this Element may have a value
        if($decoder->getElementStartTag(SYNC_GETCHANGES)) {
            if (($collection["getchanges"] = $decoder->getElementContent())) {
        	if(!$decoder->getElementEndTag()) {
            	    return false;
            	};
	    } else {
                $collection["getchanges"] = true;
	    }
	}
// END CHANGED dw2412 since according to MS Specs this Element may have a value

        if($decoder->getElementStartTag(SYNC_MAXITEMS)) {
            $collection["maxitems"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        if($decoder->getElementStartTag(SYNC_OPTIONS)) {
            while(1) {
                if($decoder->getElementStartTag(SYNC_FILTERTYPE)) {
                    $collection["filtertype"] = $decoder->getElementContent();
                    if(!$decoder->getElementEndTag())
                        return false;
                }
                if($decoder->getElementStartTag(SYNC_TRUNCATION)) {
                    $collection["truncation"] = $decoder->getElementContent();
                    if(!$decoder->getElementEndTag())
                        return false;
                }
                if($decoder->getElementStartTag(SYNC_RTFTRUNCATION)) {
                    $collection["rtftruncation"] = $decoder->getElementContent();
                    if(!$decoder->getElementEndTag())
                        return false;
                }

                if($decoder->getElementStartTag(SYNC_MIMESUPPORT)) {
                    $collection["mimesupport"] = $decoder->getElementContent();
                    if(!$decoder->getElementEndTag())
                        return false;
                }

                if($decoder->getElementStartTag(SYNC_MIMETRUNCATION)) {
                    $collection["mimetruncation"] = $decoder->getElementContent();
                    if(!$decoder->getElementEndTag())
                        return false;
                }

                if($decoder->getElementStartTag(SYNC_CONFLICT)) {
                    $collection["conflict"] = $decoder->getElementContent();
                    if(!$decoder->getElementEndTag())
                        return false;
                }
	
		// START ADDED dw2412 V12.0 Sync Support
		if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE)) {
		    $bodypreference=array();
        	    while(1) {
            		if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
	                    $bodypreference["Type"] = $decoder->getElementContent();
    		            if(!$decoder->getElementEndTag())
                        	return false;
    	    		}

            		if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
	                    $bodypreference["TruncationSize"] = $decoder->getElementContent();
    		            if(!$decoder->getElementEndTag())
                        	return false;
    	    		}

            		if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
	                    $bodypreference["AllOrNone"] = $decoder->getElementContent();
    		            if(!$decoder->getElementEndTag())
                        	return false;
    	    		}

            		$e = $decoder->peek();
            		if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
            		    $decoder->getElementEndTag();
			    if (isset($bodypreference["Type"]))
				if (!isset($collection["BodyPreference"]["wanted"]))
				    $collection["BodyPreference"]["wanted"] = $bodypreference["Type"];
				$collection["BodyPreference"][$bodypreference["Type"]] = $bodypreference;
    		    	    break;
	        	}
                    }
		}
		// END ADDED dw2412 V12.0 Sync Support
                $e = $decoder->peek();
                if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
                    $decoder->getElementEndTag();
                    break;
                }
            }
        }

        // compatibility mode - get folderid from the state directory
        if (!isset($collection["collectionid"])) {
            $collection["collectionid"] = _getFolderID($devid, $collection["class"]);
        }

        // set default conflict behavior from config if the device doesn't send a conflict resolution parameter
        if (!isset($collection["conflict"])) {
            $collection["conflict"] = SYNC_CONFLICT_DEFAULT;
        }
                                    
        // Get our sync state for this collection
        $collection["syncstate"] = $statemachine->getSyncState($collection["synckey"]);
	if(is_numeric($collection["syncstate"]) && 
	    $collection["syncstate"] < 0) {
	    debugLog("GetSyncState: Got an error in HandleSync");
	    $collection["syncstate"] = false;
	    $status = 3;
	} 
        if($decoder->getElementStartTag(SYNC_PERFORM)) {

            // Configure importer with last state
            $importer = $backend->GetContentsImporter($collection["collectionid"]);
	    $filtertype = (isset($collection["filtertype"]) ? $collection["filtertype"] : 0);
	    $mclass = (isset($collection["class"]) ? $collection["class"] : false);
	    $bodypreference = (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false);
            $importer->Config($collection["syncstate"], $collection["conflict"], $mclass, $filtertype, $bodypreference);

            $nchanges = 0;
            while(1) {
                $element = $decoder->getElement(); // MODIFY or REMOVE or ADD or FETCH

                if($element[EN_TYPE] != EN_TYPE_STARTTAG) {
                    $decoder->ungetElement($element);
                    break;
                }

                $nchanges++;

                if($decoder->getElementStartTag(SYNC_SERVERENTRYID)) {
                    $serverid = $decoder->getElementContent();

                    if(!$decoder->getElementEndTag()) // end serverid
                        return false;
                } else {
                    $serverid = false;
                }

                if($decoder->getElementStartTag(SYNC_CLIENTENTRYID)) {
                    $clientid = $decoder->getElementContent();

                    if(!$decoder->getElementEndTag()) // end clientid
                        return false;
                } else {
                    $clientid = false;
                }

                // Get application data if available
                if($decoder->getElementStartTag(SYNC_DATA)) {
                    switch($collection["class"]) {
                        case "Email":
                            $appdata = new SyncMail();
                            $appdata->decode($decoder);
                            break;
                        case "Contacts":
                            $appdata = new SyncContact($protocolversion);
                            $appdata->decode($decoder);
                            break;
                        case "Calendar":
                            $appdata = new SyncAppointment();
                            $appdata->decode($decoder);
                            break;
                        case "Tasks":
                            $appdata = new SyncTask();
                            $appdata->decode($decoder);
                            break;
                    }
                    if(!$decoder->getElementEndTag()) // end applicationdata
                        return false;

                }

                switch($element[EN_TAG]) {
                    case SYNC_MODIFY:
                        if(isset($appdata)) {
                            if(isset($appdata->poommailflag) && is_object($appdata->poommailflag)) { // ADDED DW2412 AS12 Protocol Support
				$importer->ImportMessageFlag($serverid, $appdata->poommailflag);
                            };
                            if(isset($appdata->read)) // Currently, 'read' is only sent by the PDA when it is ONLY setting the read flag.
                                $importer->ImportMessageReadFlag($serverid, $appdata->read);
                            else
                                $importer->ImportMessageChange($serverid, $appdata);
                            $collection["importedchanges"] = true;
                        }
                        break;
                    case SYNC_ADD:
                        if(isset($appdata)) {
                            $id = $importer->ImportMessageChange(false, $appdata);

                            if($clientid && $id) {
                                $collection["clientids"][$clientid] = $id;
                                $collection["importedchanges"] = true;
                            }
                        }
                        break;
                    case SYNC_REMOVE:
                        if(isset($collection["deletesasmoves"])) {
                            $folderid = $backend->GetWasteBasket();

                            if($folderid) {
                                $importer->ImportMessageMove($serverid, $folderid);
                                $collection["importedchanges"] = true;
                                break;
                            }
                        }

                        $importer->ImportMessageDeletion($serverid);
                        $collection["importedchanges"] = true;
                        break;
                    case SYNC_FETCH:
                        array_push($collection["fetchids"], $serverid);
                        break;
                }

                if(!$decoder->getElementEndTag()) // end change/delete/move
                    return false;
            }

            debugLog("Processed $nchanges incoming changes");

            // Save the updated state, which is used for the exporter later
            $collection["syncstate"] = $importer->getState();


            if(!$decoder->getElementEndTag()) // end commands
                return false;
        }

        if(!$decoder->getElementEndTag()) // end collection
            return false;

        array_push($collections, $collection);
    }

    if(!$decoder->getElementEndTag()) // end collections
        return false;

    if(!$decoder->getElementEndTag()) // end sync
        return false;

    $encoder = new WBXMLEncoder($output, $zpushdtd);
    $encoder->startWBXML();
    // START ADDED dw2412 Protocol Version 12 Support
    if (isset($collection["BodyPreference"])) $encoder->_bodypreference = $collection["BodyPreference"];
    // END ADDED dw2412 Protocol Version 12 Support
    
    $encoder->startTag(SYNC_SYNCHRONIZE);
    {
        $encoder->startTag(SYNC_FOLDERS);
        {
            foreach($collections as $collection) {
            	// initialize exporter to get changecount
            	$changecount = 0;
            	if(isset($collection["getchanges"])) {
                    // Use the state from the importer, as changes may have already happened
                    $exporter = $backend->GetExporter($collection["collectionid"]);

                    $filtertype = isset($collection["filtertype"]) ? $collection["filtertype"] : false;
                    $exporter->Config($importer, $collection["class"], $filtertype, $collection["syncstate"], 0, $collection["truncation"], (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false));

                    $changecount = $exporter->GetChangeCount();
            	}
            	
                // Get a new sync key to output to the client if any changes have been requested or will be send
                if (isset($collection["importedchanges"]) || $changecount > 0 || $collection["synckey"] == "0")
                    $collection["newsynckey"] = $statemachine->getNewSyncKey($collection["synckey"]);

                $encoder->startTag(SYNC_FOLDER);

                $encoder->startTag(SYNC_FOLDERTYPE);
                $encoder->content($collection["class"]);
                $encoder->endTag();

                $encoder->startTag(SYNC_SYNCKEY);

                if(isset($collection["newsynckey"]))
                    $encoder->content($collection["newsynckey"]);
                else
                    $encoder->content($collection["synckey"]);

                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERID);
                $encoder->content($collection["collectionid"]);
                $encoder->endTag();

                $encoder->startTag(SYNC_STATUS);
                $encoder->content($status);
                $encoder->endTag();

                //check the mimesupport because we need it for advanced emails
                $mimesupport = isset($collection['mimesupport']) ? $collection['mimesupport'] : 0;

                // Output server IDs for new items we received from the PDA
                if(isset($collection["clientids"]) || count($collection["fetchids"]) > 0) {
                    $encoder->startTag(SYNC_REPLIES);
                    foreach($collection["clientids"] as $clientid => $serverid) {
                        $encoder->startTag(SYNC_ADD);
                        $encoder->startTag(SYNC_CLIENTENTRYID);
                        $encoder->content($clientid);
                        $encoder->endTag();
                        $encoder->startTag(SYNC_SERVERENTRYID);
                        $encoder->content($serverid);
                        $encoder->endTag();
                        $encoder->startTag(SYNC_STATUS);
                        $encoder->content(1);
                        $encoder->endTag();
                        $encoder->endTag();
                    }
                    foreach($collection["fetchids"] as $id) {
			// CHANGED dw2412 to support bodypreference
                        $data = $backend->Fetch($collection["collectionid"], $id, (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false), $mimesupport);
                        if($data !== false) {
                            $encoder->startTag(SYNC_FETCH);
                            $encoder->startTag(SYNC_SERVERENTRYID);
                            $encoder->content($id);
                            $encoder->endTag();
                            $encoder->startTag(SYNC_STATUS);
                            $encoder->content(1);
                            $encoder->endTag();
                            $encoder->startTag(SYNC_DATA);
                            $data->encode($encoder);
                            $encoder->endTag();
                            $encoder->endTag();
                        } else {
                            debugLog("unable to fetch $id");
                        }
                    }
                    $encoder->endTag();
                }

                if(isset($collection["getchanges"])) {
		    // exporter already intialized

                    if($changecount > $collection["maxitems"]) {
                        $encoder->startTag(SYNC_MOREAVAILABLE, false, true);
                    }

                    // Output message changes per folder
                    $encoder->startTag(SYNC_PERFORM);

                    // Stream the changes to the PDA
                    $importer = new ImportContentsChangesStream($encoder, GetObjectClassFromFolderClass($collection["class"]));

                    $filtertype = isset($collection["filtertype"]) ? $collection["filtertype"] : 0;

                    $n = 0;
                    while(1) {
                        $progress = $exporter->Synchronize();
                        if(!is_array($progress))
                            break;
                        $n++;

                        if($n >= $collection["maxitems"]) {
                    	    debugLog("Exported maxItems of messages: ". $collection["maxitems"] . " - more available");
	                    break;
                        }

                    }
                    $encoder->endTag();
                }

                $encoder->endTag();

                // Save the sync state for the next time
                if(isset($collection["newsynckey"])) {
                    if (isset($exporter) && $exporter)
                        $state = $exporter->GetState();

                    // nothing exported, but possible imported
                    else if (isset($importer) && $importer)
                        $state = $importer->GetState();

                    // if a new request without state information (hierarchy) save an empty state
                    else if ($collection["synckey"] == "0")
                        $state = "";

                    if (isset($state)) $statemachine->setSyncState($collection["newsynckey"], $state);
                    else debugLog("error saving " . $collection["newsynckey"] . " - no state information available");
                }
            }
        }
        $encoder->endTag();
    }
    $encoder->endTag();

    return true;
}

function HandleGetItemEstimate($backend, $protocolversion, $devid) {
    global $zpushdtd;
    global $input, $output;

    $collections = array();

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE))
        return false;

    if(!$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERS))
        return false;

    while($decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDER)) {
        $collection = array();

        if(!$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERTYPE))
            return false;

        $class = $decoder->getElementContent();

        if(!$decoder->getElementEndTag())
            return false;

        if($decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERID)) {
            $collectionid = $decoder->getElementContent();

            if(!$decoder->getElementEndTag())
                return false;
        }

        if(!$decoder->getElementStartTag(SYNC_FILTERTYPE))
            return false;

        $filtertype = $decoder->getElementContent();

        if(!$decoder->getElementEndTag())
            return false;

        if(!$decoder->getElementStartTag(SYNC_SYNCKEY))
            return false;

        $synckey = $decoder->getElementContent();

        if(!$decoder->getElementEndTag())
            return false;
        if(!$decoder->getElementEndTag())
            return false;

        // compatibility mode - get folderid from the state directory
        if (!isset($collectionid)) {
            $collectionid = _getFolderID($devid, $class);
        }

        $collection = array();
        $collection["synckey"] = $synckey;
        $collection["class"] = $class;
        $collection["filtertype"] = $filtertype;
        $collection["collectionid"] = $collectionid;

        array_push($collections, $collection);
    }

    $encoder->startWBXML();

    $encoder->startTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE);
    {
        foreach($collections as $collection) {
            $encoder->startTag(SYNC_GETITEMESTIMATE_RESPONSE);
            {
                $encoder->startTag(SYNC_GETITEMESTIMATE_STATUS);
                $encoder->content(1);
                $encoder->endTag();

                $encoder->startTag(SYNC_GETITEMESTIMATE_FOLDER);
                {
                    $encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERTYPE);
                    $encoder->content($collection["class"]);
                    $encoder->endTag();

                    $encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERID);
                    $encoder->content($collection["collectionid"]);
                    $encoder->endTag();

                    $encoder->startTag(SYNC_GETITEMESTIMATE_ESTIMATE);

                    $importer = new ImportContentsChangesMem();

                    $statemachine = new StateMachine();
                    $syncstate = $statemachine->getSyncState($collection["synckey"]);
		    if(is_numeric($syncstate) &&
			$syncstate < 0) {
			debugLog("GetSyncState: Got an error in HandleGetItemEstimate");
			$syncstate = false;
		    } 

                    $exporter = $backend->GetExporter($collection["collectionid"]);
                    $exporter->Config($importer, $collection["class"], $collection["filtertype"], $syncstate, 0, 0, false);

                    $encoder->content($exporter->GetChangeCount());

                    $encoder->endTag();
                }
                $encoder->endTag();
            }
            $encoder->endTag();
        }
    }
    $encoder->endTag();

    return true;
}

function HandleGetAttachment($backend, $protocolversion) {
    $attname = $_GET["AttachmentName"];

    if(!isset($attname))
        return false;

    header("Content-Type: application/octet-stream");

    $backend->GetAttachmentData($attname);

    return true;
}

function HandlePing($backend, $devid) {
    global $zpushdtd, $input, $output;
    global $user, $auth_pw;
    $timeout = 5;

    debugLog("Ping received");

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    $collections = array();
    $lifetime = 0;

    // Get previous defaults if they exist
    $file = BASE_PATH . STATE_DIR . "/" . $devid;
    if (file_exists($file)) {
        $ping = unserialize(file_get_contents($file));
        $collections = $ping["collections"];
        $lifetime = $ping["lifetime"];
    }

    if($decoder->getElementStartTag(SYNC_PING_PING)) {
        debugLog("Ping init");
        if($decoder->getElementStartTag(SYNC_PING_LIFETIME)) {
            $lifetime = $decoder->getElementContent();
            $decoder->getElementEndTag();
        }

        if($decoder->getElementStartTag(SYNC_PING_FOLDERS)) {
            // avoid ping init if not necessary
            $saved_collections = $collections;

            $collections = array();

            while($decoder->getElementStartTag(SYNC_PING_FOLDER)) {
                $collection = array();

                if($decoder->getElementStartTag(SYNC_PING_SERVERENTRYID)) {
                    $collection["serverid"] = $decoder->getElementContent();
                    $decoder->getElementEndTag();
                }
                if($decoder->getElementStartTag(SYNC_PING_FOLDERTYPE)) {
                    $collection["class"] = $decoder->getElementContent();
                    $decoder->getElementEndTag();
                }

                $decoder->getElementEndTag();

                // initialize empty state
                $collection["state"] = "";

                // try to find old state in saved states
                foreach ($saved_collections as $saved_col) {
                    if ($saved_col["serverid"] == $collection["serverid"] && $saved_col["class"] == $collection["class"]) {
                        $collection["state"] = $saved_col["state"];
                        debugLog("reusing saved state for ". $collection["class"]);
                        break;
                    }
                }

                if ($collection["state"] == "")
                    debugLog("empty state for ". $collection["class"]);

                // Create start state for this collection
                $exporter = $backend->GetExporter($collection["serverid"]);
                $importer = false;
                $exporter->Config($importer, false, false, $collection["state"], BACKEND_DISCARD_DATA, 0, (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false));
                while(is_array($exporter->Synchronize()));
                $collection["state"] = $exporter->GetState();
                array_push($collections, $collection);
            }

            if(!$decoder->getElementEndTag())
                return false;
        }

        if(!$decoder->getElementEndTag())
            return false;
    }

    $changes = array();
    $dataavailable = false;

    debugLog("Waiting for changes... (lifetime $lifetime)");
    // Wait for something to happen
    for($n=0;$n<$lifetime / $timeout; $n++ ) {
        //check the remote wipe status
        if (PROVISIONING === true) {
	    $rwstatus = $backend->getDeviceRWStatus($user, $auth_pw, $devid);
	    if ($rwstatus == SYNC_PROVISION_RWSTATUS_PENDING || $rwstatus == SYNC_PROVISION_RWSTATUS_WIPED) {
	        //return 7 because it forces folder sync
	        $pingstatus = 7;
	        break;
	    }
        }

        if(count($collections) == 0) {
            $error = 1;
            break;
        }

        for($i=0;$i<count($collections);$i++) {
            $collection = $collections[$i];

            $exporter = $backend->GetExporter($collection["serverid"]);
            $state = $collection["state"];
            $importer = false;
            $ret = $exporter->Config($importer, false, false, $state, BACKEND_DISCARD_DATA, 0, (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false));

            // stop ping if exporter can not be configured (e.g. after Zarafa-server restart)
            if ($ret === false ) {
                // force "ping" to stop
                $n = $lifetime / $timeout;
                debugLog("Ping error: Exporter can not be configured. Waiting 30 seconds before ping is retried.");
                sleep(30);
                break;
            }

            $changecount = $exporter->GetChangeCount();

            if($changecount > 0) {
                $dataavailable = true;
                $changes[$collection["serverid"]] = $changecount;
            }

            // Discard any data
            while(is_array($exporter->Synchronize()));

            // Record state for next Ping
            $collections[$i]["state"] = $exporter->GetState();
        }

        if($dataavailable) {
            debugLog("Found change");
            break;
        }

        sleep($timeout);
    }

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_PING_PING);
    {
        $encoder->startTag(SYNC_PING_STATUS);
        if(isset($error))
            $encoder->content(3);
        elseif (isset($pingstatus))
            $encoder->content($pingstatus);
        else
            $encoder->content(count($changes) > 0 ? 2 : 1);
        $encoder->endTag();

        $encoder->startTag(SYNC_PING_FOLDERS);
        foreach($collections as $collection) {
            if(isset($changes[$collection["serverid"]])) {
                $encoder->startTag(SYNC_PING_FOLDER);
                $encoder->content($collection["serverid"]);
                $encoder->endTag();
            }
        }
        $encoder->endTag();
    }
    $encoder->endTag();

    // Save the ping request state for this device
    file_put_contents(BASE_PATH . "/" . STATE_DIR . "/" . $devid, serialize(array("lifetime" => $lifetime, "collections" => $collections)));

    return true;
}

function HandleSendMail($backend, $protocolversion) {
    // All that happens here is that we receive an rfc822 message on stdin
    // and just forward it to the backend. We provide no output except for
    // an OK http reply

    global $input;

    $rfc822 = readStream($input);

    return $backend->SendMail($rfc822);
}

function HandleSmartForward($backend, $protocolversion) {
    global $input;
    // SmartForward is a normal 'send' except that you should attach the
    // original message which is specified in the URL

    $rfc822 = readStream($input);

    if(isset($_GET["ItemId"]))
        $orig = $_GET["ItemId"];
    else
        $orig = false;

    if(isset($_GET["CollectionId"]))
        $parent = $_GET["CollectionId"];
    else
        $parent = false;

    return $backend->SendMail($rfc822, $orig, false, $parent, $protocolversion);
}

function HandleSmartReply($backend, $protocolversion) {
    global $input;
    // Smart reply should add the original message to the end of the message body

    $rfc822 = readStream($input);

    if(isset($_GET["ItemId"]))
        $orig = $_GET["ItemId"];
    else
        $orig = false;

    if(isset($_GET["CollectionId"]))
        $parent = $_GET["CollectionId"];
    else
        $parent = false;

    return $backend->SendMail($rfc822, false, $orig, $parent, $protocolversion);
}

function HandleFolderCreate($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    $el = $decoder->getElement();

    if($el[EN_TYPE] != EN_TYPE_STARTTAG)
        return false;

    $create = $update = $delete = false;

    if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERCREATE)
        $create = true;
    else if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERUPDATE)
        $update = true;
    else if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERDELETE)
        $delete = true;

    if(!$create && !$update && !$delete)
        return false;

    // SyncKey
    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SYNCKEY))
        return false;
    $synckey = $decoder->getElementContent();
    if(!$decoder->getElementEndTag())
        return false;

    // ServerID
    $serverid = false;
    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID)) {
        $serverid = $decoder->getElementContent();
        if(!$decoder->getElementEndTag())
            return false;
    }

    // when creating or updating more information is necessary
    if (!$delete) {
	    // Parent
	    $parentid = false;
	    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_PARENTID)) {
	        $parentid = $decoder->getElementContent();
	        if(!$decoder->getElementEndTag())
	            return false;
	    }
	
	    // Displayname
	    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_DISPLAYNAME))
	        return false;
	    $displayname = $decoder->getElementContent();
	    if(!$decoder->getElementEndTag())
	        return false;
	
	    // Type
	    $type = false;
	    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_TYPE)) {
	        $type = $decoder->getElementContent();
	        if(!$decoder->getElementEndTag())
	            return false;
	    }
    }

    if(!$decoder->getElementEndTag())
        return false;

    // Get state of hierarchy
    $statemachine = new StateMachine();
    $syncstate = $statemachine->getSyncState($synckey);
    if (is_numeric($syncstate) &&
	$syncstate < 0) {
	debugLog("GetSyncState: Got an error in HandleGetFolderCreate - syncstate");
	$syncstate = false;
    } 
    $newsynckey = $statemachine->getNewSyncKey($synckey);

    // additional information about already seen folders
    $seenfolders = $statemachine->getSyncState("s".$synckey);
    if ($synckey != "0" &&
	is_numeric($seenfolders) &&
	$seenfolders < 0) {
	debugLog("GetSyncState: Got an error in HandleGetFolderCreate - seenfolders");
	$seenfolders = false;
    } 
    $seenfolders = unserialize($seenfolders);;
    if (!$seenfolders) $seenfolders = array();
    
    // Configure importer with last state
    $importer = $backend->GetHierarchyImporter();
    $importer->Config($syncstate);

    if (!$delete) {
	    // Send change
	    $serverid = $importer->ImportFolderChange($serverid, $parentid, $displayname, $type);
    }
    else {
    	// delete folder
    	$deletedstat = $importer->ImportFolderDeletion($serverid, 0);
    }
                   
    $encoder->startWBXML();
    if ($create) {
    	// add folder id to the seen folders
        $seenfolders[] = $serverid;
        
        $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERCREATE);
        {
            {
                $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
                $encoder->content(1);
                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                $encoder->content($newsynckey);
                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID);
                $encoder->content($serverid);
                $encoder->endTag();
            }
            $encoder->endTag();
        }
        $encoder->endTag();
    }

    elseif ($update) {

        $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERUPDATE);
        {
            {
                $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
                $encoder->content(1);
                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                $encoder->content($newsynckey);
                $encoder->endTag();
            }
            $encoder->endTag();
        }
    }
    elseif ($delete) {

        $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERDELETE);
        {
            {
                $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
                $encoder->content($deletedstat);
                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                $encoder->content($newsynckey);
                $encoder->endTag();
            }
            $encoder->endTag();
        }
        
        // remove folder from the folderflags array
        if (($sid = array_search($serverid, $seenfolders)) !== false) {
            unset($seenfolders[$sid]);
            $seenfolders = array_values($seenfolders);
            debugLog("deleted from seenfolders: ". $serverid);    
        }
    }   

    $encoder->endTag();
    // Save the sync state for the next time
    $statemachine->setSyncState($newsynckey, $importer->GetState());
    $statemachine->setSyncState("s".$newsynckey, serialize($seenfolders));
    
    return true;
}

// Handle meetingresponse method
function HandleMeetingResponse($backend, $protocolversion) {
    global $zpushdtd;
    global $output, $input;

    $requests = Array();

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_MEETINGRESPONSE_MEETINGRESPONSE))
        return false;

    while($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_REQUEST)) {
        $req = Array();

        if($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_USERRESPONSE)) {
            $req["response"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        if($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_FOLDERID)) {
            $req["folderid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        if($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_REQUESTID)) {
            $req["requestid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        if(!$decoder->getElementEndTag())
            return false;

        array_push($requests, $req);
    }

    if(!$decoder->getElementEndTag())
        return false;

    // Start output, simply the error code, plus the ID of the calendar item that was generated by the
    // accept of the meeting response

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_MEETINGRESPONSE_MEETINGRESPONSE);

    foreach($requests as $req) {
        $calendarid = "";
        $ok = $backend->MeetingResponse($req["requestid"], $req["folderid"], $req["response"], $calendarid);
        $encoder->startTag(SYNC_MEETINGRESPONSE_RESULT);
            $encoder->startTag(SYNC_MEETINGRESPONSE_REQUESTID);
                $encoder->content($req["requestid"]);
            $encoder->endTag();

            $encoder->startTag(SYNC_MEETINGRESPONSE_STATUS);
                $encoder->content($ok ? 1 : 2);
            $encoder->endTag();

            if($ok) {
                $encoder->startTag(SYNC_MEETINGRESPONSE_CALENDARID);
                    $encoder->content($calendarid);
                $encoder->endTag();
            }

        $encoder->endTag();
    }

    $encoder->endTag();

    return true;
}

function HandleFolderUpdate($backend, $protocolversion) {
    return HandleFolderCreate($backend, $protocolversion);
}

function HandleFolderDelete($backend, $protocolversion) {
    return HandleFolderCreate($backend, $protocolversion);
}

function HandleProvision($backend, $devid, $protocolversion) {
    global $user, $auth_pw, $policykey;

    global $zpushdtd, $policies;
    global $output, $input;

    $status = SYNC_PROVISION_STATUS_SUCCESS;

    $phase2 = true;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_PROVISION_PROVISION))
        return false;

    //handle android remote wipe.
    if ($decoder->getElementStartTag(SYNC_PROVISION_REMOTEWIPE)) {
        if(!$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
            return false;

        $status = $decoder->getElementContent();

        if(!$decoder->getElementEndTag())
            return false;

        if(!$decoder->getElementEndTag())
            return false;
    }

    else {

        if(!$decoder->getElementStartTag(SYNC_PROVISION_POLICIES))
            return false;

        if(!$decoder->getElementStartTag(SYNC_PROVISION_POLICY))
            return false;

        if(!$decoder->getElementStartTag(SYNC_PROVISION_POLICYTYPE))
            return false;

        $policytype = $decoder->getElementContent();
// START CHANGED dw2412 Support V12.0
	if ($protocolversion >= 12.0) {
    	    if ($policytype != 'MS-EAS-Provisioning-WBXML') {
        	$status = SYNC_PROVISION_STATUS_SERVERERROR;
    	    }
    	} else {
    	    if ($policytype != 'MS-WAP-Provisioning-XML') {
        	$status = SYNC_PROVISION_STATUS_SERVERERROR;
    	    }
        }
// END CHANGED dw2412 Support V12.0
        if(!$decoder->getElementEndTag()) //policytype
            return false;

        if ($decoder->getElementStartTag(SYNC_PROVISION_POLICYKEY)) {
            $devpolicykey = $decoder->getElementContent();

            if(!$decoder->getElementEndTag())
                return false;

            if(!$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
                return false;

            $status = $decoder->getElementContent();
            //do status handling
            $status = SYNC_PROVISION_STATUS_SUCCESS;

            if(!$decoder->getElementEndTag())
                return false;

            $phase2 = false;
        }

        if(!$decoder->getElementEndTag()) //policy
            return false;

        if(!$decoder->getElementEndTag()) //policies
            return false;

        if ($decoder->getElementStartTag(SYNC_PROVISION_REMOTEWIPE)) {
            if(!$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
                return false;

            $status = $decoder->getElementContent();

            if(!$decoder->getElementEndTag())
                return false;

            if(!$decoder->getElementEndTag())
                return false;
        }
    }
    if(!$decoder->getElementEndTag()) //provision
        return false;

    $encoder->StartWBXML();

    //set the new final policy key in the backend
    //in case the send one does not macht the one already in backend. If it matches, we
    //just return the already defined key. (This Helps at least the RoadSync 5.0 Client to sync...
    if ($backend->CheckPolicy($policykey,$devid) == SYNC_PROVISION_STATUS_SUCCESS) {
        debugLog("Policykey is OK! Will not generate a new one!");
    } else {
	if (!$phase2) {
    	    $policykey = $backend->generatePolicyKey();
    	    $backend->setPolicyKey($policykey, $devid);
	} else {
	    // just create a temporary key (i.e. iPhone OS4 Beta does not like policykey 0 in response)
    	    $policykey = $backend->generatePolicyKey();
	}
    }

    $encoder->startTag(SYNC_PROVISION_PROVISION);
    {
        $encoder->startTag(SYNC_PROVISION_STATUS);
            $encoder->content($status);
        $encoder->endTag();

        $encoder->startTag(SYNC_PROVISION_POLICIES);
            $encoder->startTag(SYNC_PROVISION_POLICY);

            $encoder->startTag(SYNC_PROVISION_POLICYTYPE);
                   $encoder->content($policytype);
            $encoder->endTag();

            $encoder->startTag(SYNC_PROVISION_STATUS);
                $encoder->content($status);
            $encoder->endTag();

            $encoder->startTag(SYNC_PROVISION_POLICYKEY);
                   $encoder->content($policykey);
            $encoder->endTag();

            if ($phase2) {
                $encoder->startTag(SYNC_PROVISION_DATA);
                if ($policytype == 'MS-WAP-Provisioning-XML') {
                    $encoder->content('<wap-provisioningdoc><characteristic type="SecurityPolicy"><parm name="4131" value="1"/><parm name="4133" value="1"/></characteristic></wap-provisioningdoc>');
/* dw2412 maybe we can make use of this later on in as2.5 proivsioning.
        	    <characteristic type="Registry">
// 0 = no frequency 1 = set and take minutes from FrequencyValue
                        <characteristic type="HKLM\Comm\Security\Policy\LASSD\AE\{50C13377-C66D-400C-889E-C316FC4AB374}">
                            <parm name="AEFrequencyType" value="1"/>
                            <parm name="AEFrequencyValue" value="3"/>
                        </characteristic>
// Wipe after n unsuccessful password entries.
                	<characteristic type="HKLM\Comm\Security\Policy\LASSD">
                    	    <parm name="DeviceWipeThreshold" value="6"/>
            		</characteristic>
// Show password reminder after n attemps
                	<characteristic type="HKLM\Comm\Security\Policy\LASSD">
                    	    <parm name="CodewordFrequency" value="3"/>
                	</characteristic>
// if not send there is no PIN required
                        <characteristic type="HKLM\Comm\Security\Policy\LASSD\LAP\lap_pw">
                            <parm name="MinimumPasswordLength" value="5"/>
                        </characteristic>
// 0 = require alphanum, 1 = require numeric, 2 = anything
                        <characteristic type="HKLM\Comm\Security\Policy\LASSD\LAP\lap_pw">
                            <parm name="PasswordComplexity" value="2"/>
                        </characteristic>
                    </characteristic>
*/
                } else if ($policytype == 'MS-EAS-Provisioning-WBXML') {
		    $encoder->startTag('Provision:EASProvisionDoc');
		    $devicepasswordenable = 0;
		    $encoder->startTag('Provision:DevicePasswordEnabled');$encoder->content($devicepasswordenable);$encoder->endTag();
		    if ($devicepasswordenable == 1 || (defined('NOKIA_DETECTED') && NOKIA_DETECTED == true)) {
			$encoder->startTag('Provision:AlphanumericDevicePasswordRequired');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:PasswordRecoveryEnabled');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:MinDevicePasswordLength');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:MaxDevicePasswordFailedAttempts');$encoder->content('5');$encoder->endTag();
			$encoder->startTag('Provision:AllowSimpleDevicePassword');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:DevicePasswordExpiration',false,true); // was 0
			$encoder->startTag('Provision:DevicePasswordHistory');$encoder->content('0');$encoder->endTag();
		    }
		    $encoder->startTag('Provision:DeviceEncryptionEnabled');$encoder->content('0');$encoder->endTag();
		    $encoder->startTag('Provision:AttachmentsEnabled');$encoder->content('1');$encoder->endTag();
		    $encoder->startTag('Provision:MaxInactivityTimeDeviceLock');$encoder->content('9999');$encoder->endTag();
		    $encoder->startTag('Provision:MaxAttachmentSize');$encoder->content('5000000');$encoder->endTag();
		    if ($protocolversion >= 12.1) {
			$encoder->startTag('Provision:AllowStorageCard');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowCamera');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:RequireDeviceEncryption');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:AllowUnsignedApplications');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowUnsignedInstallationPackages');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:MinDevicePasswordComplexCharacters');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:AllowWiFi');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowTextMessaging');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowPOPIMAPEmail');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowBluetooth');$encoder->content('2');$encoder->endTag();
			$encoder->startTag('Provision:AllowIrDA');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:RequireManualSyncWhenRoaming');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowDesktopSync');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:MaxCalendarAgeFilter');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:AllowHTMLEmail');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:MaxEmailAgeFilter');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:MaxEmailBodyTruncationSize');$encoder->content('-1');$encoder->endTag();
			$encoder->startTag('Provision:MaxHTMLBodyTruncationSize');$encoder->content('-1');$encoder->endTag();
			$encoder->startTag('Provision:RequireSignedSMIMEMessages');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:RequireEncryptedSMIMEMessages');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:RequireSignedSMIMEAlgorithm');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:RequireEncryptedSMIMEAlgorithm');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:AllowSMIMEEncryptionAlgorithmNegotiation');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowSMIMESoftCerts');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowBrowser');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowConsumerEmail');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowRemoteDesktop');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowInternetSharing');$encoder->content('1');$encoder->endTag();
//			$encoder->startTag('Provision:UnapprovedInROMApplicationList');$encoder->content('');$encoder->endTag();
//			$encoder->startTag('Provision:ApplicationName');$encoder->content('');$encoder->endTag();
//			$encoder->startTag('Provision:ApprovedApplicationList');$encoder->content('');$encoder->endTag();
//			$encoder->startTag('Provision:Hash');$encoder->content('');$encoder->endTag();
		    };
		    $encoder->endTag();
		}
                else {
                    debugLog("Wrong policy type");
                    return false;
                }

                $encoder->endTag();//data
            }
            $encoder->endTag();//policy
        $encoder->endTag(); //policies
    }
    $rwstatus = $backend->getDeviceRWStatus($user, $auth_pw, $devid);


    //wipe data if status is pending or wiped
    if ($rwstatus == SYNC_PROVISION_RWSTATUS_PENDING || $rwstatus == SYNC_PROVISION_RWSTATUS_WIPED) {
        $encoder->startTag(SYNC_PROVISION_REMOTEWIPE, false, true);
        $backend->setDeviceRWStatus($user, $auth_pw, $devid, SYNC_PROVISION_RWSTATUS_WIPED);
        //$rwstatus = SYNC_PROVISION_RWSTATUS_WIPED;
    }

    $encoder->endTag();//provision

    return true;
}
function ParseQuery($decoder, $subquery=NULL) {
    $query = array();
    while (($type = ($decoder->getElementStartTag(SYNC_SEARCH_AND)  		? SYNC_SEARCH_AND :
		    ($decoder->getElementStartTag(SYNC_SEARCH_OR)  		? SYNC_SEARCH_OR :
		    ($decoder->getElementStartTag(SYNC_SEARCH_EQUALTO)  	? SYNC_SEARCH_EQUALTO :
		    ($decoder->getElementStartTag(SYNC_SEARCH_LESSTHAN)  	? SYNC_SEARCH_LESSTHAN :
		    ($decoder->getElementStartTag(SYNC_SEARCH_GREATERTHAN)  	? SYNC_SEARCH_GREATERTHAN :
		    ($decoder->getElementStartTag(SYNC_SEARCH_FREETEXT)  	? SYNC_SEARCH_FREETEXT :
		    ($decoder->getElementStartTag(SYNC_FOLDERID)	  	? SYNC_FOLDERID :
		    ($decoder->getElementStartTag(SYNC_FOLDERTYPE)	  	? SYNC_FOLDERTYPE :
		    ($decoder->getElementStartTag(SYNC_DOCUMENTLIBRARY_LINKID) 	? SYNC_DOCUMENTLIBRARY_LINKID :
		    ($decoder->getElementStartTag(SYNC_POOMMAIL_DATERECEIVED)  	? SYNC_POOMMAIL_DATERECEIVED :
		    -1))))))))))) != -1) {
	switch ($type) {
	    case SYNC_SEARCH_AND 		:
	    case SYNC_SEARCH_OR  		:
	    case SYNC_SEARCH_EQUALTO	 	:
	    case SYNC_SEARCH_LESSTHAN 		:
	    case SYNC_SEARCH_GREATERTHAN	:
		    $q["op"] = $type;
		    $q["value"] = ParseQuery($decoder,true);
		    if ($subquery==true) {
			$query["subquery"][] = $q;
		    } else {
			$query[] = $q;
		    }
		    $decoder->getElementEndTag();
		    break;
	    default 			:
		    if (($query[$type] = $decoder->getElementContent())) {
			$decoder->getElementEndTag();
	    	    } else {
			$decoder->getElementStartTag(SYNC_SEARCH_VALUE);
		        $query[$type] = $decoder->getElementContent();
			switch ($type) {
			    case SYNC_POOMMAIL_DATERECEIVED :
    				if(preg_match("/(\d{4})[^0-9]*(\d{2})[^0-9]*(\d{2})T(\d{2})[^0-9]*(\d{2})[^0-9]*(\d{2})(.\d+)?Z/", $query[$type], $matches)) {
        			    if ($matches[1] >= 2038){
            				$matches[1] = 2038;
            				$matches[2] = 1;
            				$matches[3] = 18;
            				$matches[4] = $matches[5] = $matches[6] = 0;
        			    }
        			$query[$type] = gmmktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]);
    				}
				break;
			}
			$decoder->getElementEndTag();
		    };
		    break;
	};
    };	
    return $query;	    
}

function HandleSearch($backend, $devid, $protocolversion) {
    global $zpushdtd;
    global $input, $output;
    global $auth_user,$auth_domain,$auth_pw;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_SEARCH_SEARCH))
        return false;

    if(!$decoder->getElementStartTag(SYNC_SEARCH_STORE))
        return false;

    if(!$decoder->getElementStartTag(SYNC_SEARCH_NAME))
        return false;
    $searchname = $decoder->getElementContent();
    if(!$decoder->getElementEndTag())
        return false;

    if(!$decoder->getElementStartTag(SYNC_SEARCH_QUERY))
        return false;
    //START CHANGED dw2412 V12.0 Support
    switch (strtolower($searchname)) {
	case 'documentlibrary'  : 
		$searchquery['query'] = ParseQuery($decoder);
		break;	
	case 'mailbox'  : 
		$searchquery['query'] = ParseQuery($decoder);
		break;	
	case 'gal'	: 
		$searchquery = $decoder->getElementContent(); 
		break;
    }
    if(!$decoder->getElementEndTag())
        return false;

    if($decoder->getElementStartTag(SYNC_SEARCH_OPTIONS)) {
        while(1) {
            if($decoder->getElementStartTag(SYNC_SEARCH_RANGE)) {
                $searchrange = $decoder->getElementContent();
                if(!$decoder->getElementEndTag())
                    return false;
                }
    //START ADDED dw2412 V12.0 Support
            if($decoder->getElementStartTag(SYNC_SEARCH_DEEPTRAVERSAL)) {
                if (!($searchdeeptraversal = $decoder->getElementContent()))  
            	    $searchquerydeeptraversal = true;
            	else
            	    if(!$decoder->getElementEndTag())
                	return false;
            }
            if($decoder->getElementStartTag(SYNC_SEARCH_REBUILDRESULTS)) {
                if (!($searchrebuildresults = $decoder->getElementContent()))  
            	    $searchqueryrebuildresults = true;
            	else
            	    if(!$decoder->getElementEndTag())
                	return false;
            }
            if($decoder->getElementStartTag(SYNC_SEARCH_USERNAME)) {
                if (!($searchqueryusername = $decoder->getElementContent()))  
            	    return false;
            	else
            	    if(!$decoder->getElementEndTag())
                	return false;
            }
            if($decoder->getElementStartTag(SYNC_SEARCH_PASSWORD)) {
                if (!($searchquerypassword = $decoder->getElementContent()))  
            	    return false;
            	else
            	    if(!$decoder->getElementEndTag())
                	return false;
            }
            if($decoder->getElementStartTag(SYNC_SEARCH_SCHEMA)) {
                if (!($searchschema = $decoder->getElementContent()))  
            	    $searchschema = true;
            	else
            	    if(!$decoder->getElementEndTag())
                	return false;
            }
	    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE)) {
	        $bodypreference=array();
    	        while(1) {
            	    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
	        	$bodypreference["Type"] = $decoder->getElementContent();
    		        if(!$decoder->getElementEndTag())
                    	    return false;
    	    		}

            		if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
	                    $bodypreference["TruncationSize"] = $decoder->getElementContent();
    		            if(!$decoder->getElementEndTag())
                        	return false;
    	    		}

            		if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
	                    $bodypreference["AllOrNone"] = $decoder->getElementContent();
    		            if(!$decoder->getElementEndTag())
                        	return false;
    	    		}

            		$e = $decoder->peek();
            		if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
            		    $decoder->getElementEndTag();
			    if (!isset($searchbodypreference["wanted"]))
				$searchbodypreference["wanted"] = $bodypreference["Type"];
			    if (isset($bodypreference["Type"]))
				$searchbodypreference[$bodypreference["Type"]] = $bodypreference;
    		    	    break;
	        	}
                    }
		}
    
    //END ADDED dw2412 V12.0 Support
            
                $e = $decoder->peek();
                if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
                    $decoder->getElementEndTag();
                    break;
                }
            }
	}
    if(!$decoder->getElementEndTag()) //store
        return false;

    if(!$decoder->getElementEndTag()) //search
        return false;


    //START CHANGED dw2412 V12.0 Support
    switch (strtolower($searchname)) {
	case 'documentlibrary'  : 
		if (isset($searchqueryusername)) {
		    if (strpos($searchqueryusername,"\\")) {
			list($searchquery['username']['domain'],$searchquery['username']['username']) = explode("\\",$searchqueryusername);
		    } else {
			$searchquery['username'] = array('domain' => "",'username' => $searchqueryusername);
		    }
		} else {
		    $searchquery['username']['domain'] = $auth_domain;
		    $searchquery['username']['username'] = $auth_user;
		};
            	$searchquery['password'] = (isset($searchquerypassword) ? $searchquerypassword : $auth_pw);
                $searchquery['range'] = $searchrange;
            	break;
	case 'mailbox'  : 
            	$searchquery['rebuildresults'] = $searchqueryrebuildresults;
            	$searchquery['deeptraversal'] =  $searchquerydeeptraversal;
                $searchquery['range'] = $searchrange;
		break;	
    }
    //get search results from backend
    $result = $backend->getSearchResults($searchquery,$searchname);
    //END CHANGED dw2412 V12.0 Support
    

    $encoder->startWBXML();
    // START ADDED dw2412 Protocol Version 12 Support
    if (isset($searchbodypreference)) $encoder->_bodypreference = $searchbodypreference;
    // END ADDED dw2412 Protocol Version 12 Support

    $encoder->startTag(SYNC_SEARCH_SEARCH);

        $encoder->startTag(SYNC_SEARCH_STATUS);
        $encoder->content(1);
        $encoder->endTag();

        $encoder->startTag(SYNC_SEARCH_RESPONSE);
            $encoder->startTag(SYNC_SEARCH_STORE);

                $encoder->startTag(SYNC_SEARCH_STATUS);
                $encoder->content($result['status']);
                $encoder->endTag();

		// CHANGED dw2412 AS V12.0 Support (mentain single return way...)
                if (is_array($result['rows']) && !empty($result['rows'])) {
                    $searchtotal = count($result['rows']);
		    // CHANGED dw2412 AS V12.0 Support (honor the range in request...)
		    eregi("(.*)\-(.*)",$searchrange,$range);
		    $returnitems = $range[2] - $range[1];
                    $returneditems=0;
		    $result['rows'] = array_slice($result['rows'],$range[1],$returnitems+1,true);
		    // CHANGED dw2412 AS V12.0 Support (mentain single return way...)
                    foreach ($result['rows'] as $u) {

			    // CHANGED dw2412 AS V12.0 Support (honor the range in request...)
                    	    if ($returneditems>$returnitems) break; 
                    	    $returneditems++;

			    switch (strtolower($searchname)) {
				case 'documentlibrary'  : 
                    		    $encoder->startTag(SYNC_SEARCH_RESULT);
                        		$encoder->startTag(SYNC_SEARCH_PROPERTIES);
					$encoder->startTag(SYNC_DOCUMENTLIBRARY_LINKID);
                    		    	$encoder->content($u['linkid']);
					$encoder->endTag();
					$encoder->startTag(SYNC_DOCUMENTLIBRARY_DISPLAYNAME);
                    		    	$encoder->content($u['displayname']);
					$encoder->endTag();
					$encoder->startTag(SYNC_DOCUMENTLIBRARY_CREATIONDATE);
                    		    	$encoder->content($u['creationdate']);
					$encoder->endTag();
					$encoder->startTag(SYNC_DOCUMENTLIBRARY_LASTMODIFIEDDATE);
                    		    	$encoder->content($u['lastmodifieddate']);
					$encoder->endTag();
					$encoder->startTag(SYNC_DOCUMENTLIBRARY_ISHIDDEN);
                    		    	$encoder->content($u['ishidden']);
					$encoder->endTag();
	    				$encoder->startTag(SYNC_DOCUMENTLIBRARY_ISFOLDER);
					$encoder->content($u['isfolder']);
					$encoder->endTag();
	    				if ($u['isfolder'] == "0") {
					    $encoder->startTag(SYNC_DOCUMENTLIBRARY_CONTENTLENGTH);
                    		    	    $encoder->content($u['contentlength']);
					    $encoder->endTag();
					    $encoder->startTag(SYNC_DOCUMENTLIBRARY_CONTENTTYPE);
					    $encoder->content($u['contenttype']);
					    $encoder->endTag();
				        }
                            		$encoder->endTag();//result
                    		    $encoder->endTag();//properties
				    break;
				case 'mailbox'  : 
                    		    $encoder->startTag(SYNC_SEARCH_RESULT);
                        		$encoder->startTag(SYNC_FOLDERTYPE);
                        		$encoder->content('Email');
                        		$encoder->endTag();
                        		$encoder->startTag(SYNC_SEARCH_LONGID);
                        		$encoder->content($u['uniqueid']);
                        		$encoder->endTag();
                        		$encoder->startTag(SYNC_FOLDERID);
                        		$encoder->content($u['searchfolderid']);
                        		$encoder->endTag();
                        		$encoder->startTag(SYNC_SEARCH_PROPERTIES);
				    $msg = $backend->ItemOperationsFetchMailbox($u['uniqueid'], $searchbodypreference);
				    $msg->encode($encoder);
                    		        $encoder->endTag();//properties
                        	    $encoder->endTag();//result
				    break;
				case 'gal'  : 
                    		    $encoder->startTag(SYNC_SEARCH_RESULT);
                        		$encoder->startTag(SYNC_SEARCH_PROPERTIES);
                            	    $encoder->startTag(SYNC_GAL_DISPLAYNAME);
                            	    $encoder->content($u["fullname"]);
                        	    $encoder->endTag();

                            	    $encoder->startTag(SYNC_GAL_PHONE);
            			    $encoder->content($u["businessphone"]);
                            	    $encoder->endTag();

                            	    $encoder->startTag(SYNC_GAL_ALIAS);
                            	    $encoder->content($u["username"]);
                            	    $encoder->endTag();

                            	    //it's not possible not get first and last name of an user
                            	    //from the gab and user functions, so we just set fullname
                            	    //to lastname and leave firstname empty because nokia needs
                            	    //first and lastname in order to display the search result
                            	    $encoder->startTag(SYNC_GAL_FIRSTNAME);
                            	    $encoder->content("");
                            	    $encoder->endTag();

                            	    $encoder->startTag(SYNC_GAL_LASTNAME);
                        	    $encoder->content($u["fullname"]);
                            	    $encoder->endTag();

                        	    $encoder->startTag(SYNC_GAL_EMAILADDRESS);
                        	    $encoder->content($u["emailaddress"]);
	                    	    $encoder->endTag();
                    		
                    		    $encoder->endTag();//result
                		    $encoder->endTag();//properties
				    break;
			    };
                    }
                    $searchrange = $range[1]."-".($range[1]+$returneditems-1);
                    $encoder->startTag(SYNC_SEARCH_RANGE);
                    $encoder->content($searchrange);
                    $encoder->endTag();

                    $encoder->startTag(SYNC_SEARCH_TOTAL);
                    $encoder->content($searchtotal);
                    $encoder->endTag();
                }

            $encoder->endTag();//store
        $encoder->endTag();//response
    $encoder->endTag();//search


    return true;
}

// START ADDED dw2412 Settings Support
function HandleSettings($backend, $devid, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_SETTINGS_SETTINGS))
        return false;

    $request = array();
    while (($reqtype = ($decoder->getElementStartTag(SYNC_SETTINGS_OOF) 	      ?   SYNC_SETTINGS_OOF               :
		       ($decoder->getElementStartTag(SYNC_SETTINGS_DEVICEINFORMATION) ?   SYNC_SETTINGS_DEVICEINFORMATION :
		       ($decoder->getElementStartTag(SYNC_SETTINGS_USERINFORMATION)   ?   SYNC_SETTINGS_USERINFORMATION   :
 		       ($decoder->getElementStartTag(SYNC_SETTINGS_DEVICEPASSWORD)    ?   SYNC_SETTINGS_DEVICEPASSWORD    :
		       -1))))) != -1) {
	if($decoder->getElementStartTag(SYNC_SETTINGS_GET)) {
	    if($reqtype == SYNC_SETTINGS_OOF) {
		if(!$decoder->getElementStartTag(SYNC_SETTINGS_BODYTYPE))
        	    return false;
                $bodytype = $decoder->getElementContent();
                if(!$decoder->getElementEndTag())
                    return false; // end SYNC_SETTINGS BODYTYPE
                if(!$decoder->getElementEndTag())
                    return false; // end SYNC_SETTINGS GET
                if(!$decoder->getElementEndTag())
                    return false; // end SYNC_SETTINGS_OOF
		$request["get"]["oof"]["bodytype"] = $bodytype;    


	    } elseif ($reqtype == SYNC_SETTINGS_USERINFORMATION) {
		$request["get"]["userinformation"] = array();    
    	    } else { return false; };
    	} elseif($decoder->getElementStartTag(SYNC_SETTINGS_SET)) {
    	    if($reqtype == SYNC_SETTINGS_OOF) {
        	$decoder->getElementStartTag(SYNC_SETTINGS_OOFSTATE);
        	$oofstate = $decoder->getElementContent();
        	$decoder->getElementEndTag(); // end SYNC_SETTINGS_OOFSTATE
		$request["set"]["oof"]["oofstate"] = $oofstate;    
    	        if ($oofstate != 0) {
    		    $decoder->getElementStartTag(SYNC_SETTINGS_OOFMESSAGE);

		    $oofmsgs = array();
		    while (($type = ($decoder->getElementStartTag(SYNC_SETTINGS_APPLIESTOINTERNAL)        ? SYNC_SETTINGS_APPLIESTOINTERNAL :
				    ($decoder->getElementStartTag(SYNC_SETTINGS_APPLIESTOEXTERNALKNOWN)   ? SYNC_SETTINGS_APPLIESTOEXTERNALKNOWN :
				    ($decoder->getElementStartTag(SYNC_SETTINGS_APPLIESTOEXTERNALUNKNOWN) ? SYNC_SETTINGS_APPLIESTOEXTERNALUNKNOWN :
				    -1)))) != -1) {
			$oof = array();
        		$oof["appliesto"] = $type;
        		$decoder->getElementStartTag(SYNC_SETTINGS_ENABLED);
        		$oof["enabled"] = $decoder->getElementContent();
        		$decoder->getElementEndTag(); // end SYNC_SETTINGS_ENABLED
        		$decoder->getElementStartTag(SYNC_SETTINGS_REPLYMESSAGE);
        		$oof["replymessage"] = $decoder->getElementContent();
        		$decoder->getElementEndTag(); // end SYNC_SETTINGS_REPLYMESSAGE
        		$decoder->getElementStartTag(SYNC_SETTINGS_BODYTYPE);
        		$oof["bodytype"] = $decoder->getElementContent();
        		$decoder->getElementEndTag(); // end SYNC_SETTINGS_BODYTYPE
			$oofmsgs[]=$oof;
		    }; 
        	    $request["set"]["oof"]["oofmsgs"] = $oofmsgs;    

        	    $decoder->getElementEndTag(); // end SYNC_SETTINGS_OOFMESSAGE
		};
    		$decoder->getElementEndTag(); // end SYNC_SETTINGS_SET
        	$decoder->getElementEndTag(); // end SYNC_SETTINGS_OOF


	    } elseif ($reqtype == SYNC_SETTINGS_DEVICEINFORMATION) {
		while (($field = ($decoder->getElementStartTag(SYNC_SETTINGS_MODEL) 				 ? SYNC_SETTINGS_MODEL					: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_IMEI) 				 ? SYNC_SETTINGS_IMEI 					: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_FRIENDLYNAME) 			 ? SYNC_SETTINGS_FRIENDLYNAME 				: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_OS) 				 ? SYNC_SETTINGS_OS 					: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_OSLANGUAGE) 			 ? SYNC_SETTINGS_OSLANGUAGE 				: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_PHONENUMBER) 			 ? SYNC_SETTINGS_PHONENUMBER 				: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_USERAGENT) 			 ? SYNC_SETTINGS_USERAGENT 				: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_ENABLEOUTBOUNDSMS)		 	 ? SYNC_SETTINGS_ENABLEOUTBOUNDSMS			: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_MOBILEOPERATOR) 			 ? SYNC_SETTINGS_MOBILEOPERATOR 			: 
				 -1)))))))))) != -1) {

        	    if (($deviceinfo[$field] = $decoder->getElementContent()) !== false) $decoder->getElementEndTag(); // end $field
		};
		$request["set"]["deviceinformation"] = $deviceinfo;    
    		$decoder->getElementEndTag(); // end SYNC_SETTINGS_SET
        	$decoder->getElementEndTag(); // end SYNC_SETTINGS_DEVICEINFORMATION

	    } elseif ($reqtype == SYNC_SETTINGS_DEVICEPASSWORD) {
		$decoder->getElementStartTag(SYNC_SETTINGS_PASSWORD);
        	if (($password = $decoder->getElementContent())) $decoder->getElementEndTag(); // end $field
		$request["set"]["devicepassword"] = $password;    
	
    	    } else { return false; };
    
	} else { return false; };
    }
    $decoder->getElementEndTag(); // end SYNC_SETTINGS_SETTINGS

    if (isset($request["set"])) $result["set"] = $backend->setSettings($request["set"],$devid);
    if (isset($request["get"])) $result["get"] = $backend->getSettings($request["get"],$devid);

    $encoder->startWBXML();
    $encoder->startTag(SYNC_SETTINGS_SETTINGS);
    $encoder->startTag(SYNC_SETTINGS_STATUS);
    $encoder->content(1);
    $encoder->endTag(); // end SYNC_SETTINGS_STATUS
    if (isset($request["set"]["oof"])) {
        $encoder->startTag(SYNC_SETTINGS_OOF);
        $encoder->startTag(SYNC_SETTINGS_STATUS);
	if (!isset($result["set"]["oof"]["status"])) {
    	    $encoder->content(0);
    	} else {
    	    $encoder->content($result["set"]["oof"]["status"]);
    	}
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
        $encoder->endTag(); // end SYNC_SETTINGS_OOF
    };
    if (isset($request["set"]["deviceinformation"])) {
        $encoder->startTag(SYNC_SETTINGS_DEVICEINFORMATION);
        $encoder->startTag(SYNC_SETTINGS_SET);
        $encoder->startTag(SYNC_SETTINGS_STATUS);
	if (!isset($result["set"]["deviceinformation"]["status"])) {
    	    $encoder->content(0);
    	} else {
    	    $encoder->content($result["set"]["deviceinformation"]["status"]);
    	}
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
        $encoder->endTag(); // end SYNC_SETTINGS_SET
        $encoder->endTag(); // end SYNC_SETTINGS_DEVICEINFORMATION
    };
    if (isset($request["set"]["devicepassword"])) {
        $encoder->startTag(SYNC_SETTINGS_DEVICEPASSWORD);
        $encoder->startTag(SYNC_SETTINGS_SET);
        $encoder->startTag(SYNC_SETTINGS_STATUS);
	if (!isset($result["set"]["devicepassword"]["status"])) {
    	    $encoder->content(0);
    	} else {
    	    $encoder->content($result["set"]["devicepassword"]["status"]);
    	}
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
        $encoder->endTag(); // end SYNC_SETTINGS_SET
        $encoder->endTag(); // end SYNC_SETTINGS_DEVICEPASSWORD
    };
    if (isset($request["get"]["userinformation"])) {
        $encoder->startTag(SYNC_SETTINGS_USERINFORMATION);
        $encoder->startTag(SYNC_SETTINGS_STATUS);
        $encoder->content($result["get"]["userinformation"]["status"]);
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
        $encoder->startTag(SYNC_SETTINGS_GET);
        $encoder->startTag(SYNC_SETTINGS_EMAILADDRESSES);
	foreach($result["get"]["userinformation"]["emailaddresses"] as $value) {
	    $encoder->startTag(SYNC_SETTINGS_SMTPADDRESS);
    	    $encoder->content($value);
    	    $encoder->endTag(); // end SYNC_SETTINGS_SMTPADDRESS
        };
        $encoder->endTag(); // end SYNC_SETTINGS_EMAILADDRESSES
        $encoder->endTag(); // end SYNC_SETTINGS_GET
        $encoder->endTag(); // end SYNC_SETTINGS_USERINFORMATION
    };
    if (isset($request["get"]["oof"])) {
        $encoder->startTag(SYNC_SETTINGS_OOF);
            
        $encoder->startTag(SYNC_SETTINGS_STATUS);
        $encoder->content(1);
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
            
        $encoder->startTag(SYNC_SETTINGS_GET);
        $encoder->startTag(SYNC_SETTINGS_OOFSTATE);
        $encoder->content($result["get"]["oof"]["oofstate"]);
        $encoder->endTag(); // end SYNC_SETTINGS_OOFSTATE
//	This we maybe need later on (OOFSTATE=2). It shows that OOF Messages could be send depending on Time being set in here. 
//	Unfortunately cannot proof it working on my device.
/*      $encoder->startTag(SYNC_SETTINGS_STARTTIME);
        $encoder->content("2007-05-08T10:45:51.250Z");
        $encoder->endTag(); // end SYNC_SETTINGS_STARTTIME
        $encoder->startTag(SYNC_SETTINGS_ENDTIME);
        $encoder->content("2007-05-11T10:45:51.250Z");
        $encoder->endTag(); // end SYNC_SETTINGS_ENDTIME
*/
        foreach($result["get"]["oof"]["oofmsgs"] as $oofentry) {
            $encoder->startTag(SYNC_SETTINGS_OOFMESSAGE);
            $encoder->startTag($oofentry["appliesto"],false,true);
            $encoder->startTag(SYNC_SETTINGS_ENABLED);
            $encoder->content($oofentry["enabled"]);
            $encoder->endTag(); // end SYNC_SETTINGS_ENABLED
    	    $encoder->startTag(SYNC_SETTINGS_REPLYMESSAGE);
            $encoder->content($oofentry["replymessage"]);
            $encoder->endTag(); // end SYNC_SETTINGS_REPLYMESSAGE
            $encoder->startTag(SYNC_SETTINGS_BODYTYPE);
	    switch (strtolower($oofentry["bodytype"])) {
		case "text" : $encoder->content("Text"); break;
		case "HTML" : $encoder->content("HTML"); break;
	    };
            $encoder->endTag(); // end SYNC_SETTINGS_BODYTYPE
            $encoder->endTag(); // end SYNC_SETTINGS_OOFMESSAGE
        };
    
        $encoder->endTag(); // end SYNC_SETTINGS_GET
        $encoder->endTag(); // end SYNC_SETTINGS_OOF
    
    };
    $encoder->endTag(); // end SYNC_SETTINGS_SETTINGS
    
    return true;
}

// END ADDED dw2412 Settings Support

// START ADDED dw2412 ItemOperations Support
function HandleItemOperations($backend, $devid, $protocolversion, $multipart) {
    global $zpushdtd;
    global $input, $output;
    global $auth_user,$auth_domain,$auth_pw;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_ITEMOPERATIONS))
        return false;

    $request = array();
    while (($reqtype = ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_FETCH)       		?   SYNC_ITEMOPERATIONS_FETCH      	  	:
		       ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_EMPTYFOLDERCONTENT) 	?   SYNC_ITEMOPERATIONS_EMPTYFOLDERCONTENTS	:
		       -1))) != -1) {
	if ($reqtype == SYNC_ITEMOPERATIONS_FETCH) {
	    $thisio["type"] = "fetch";
	    while (($reqtag = ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_STORE)       		?   SYNC_ITEMOPERATIONS_STORE  	  	:
			      ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_OPTIONS)	 	?   SYNC_ITEMOPERATIONS_OPTIONS		:
			      ($decoder->getElementStartTag(SYNC_SERVERENTRYID)			 	?   SYNC_SERVERENTRYID			:
			      ($decoder->getElementStartTag(SYNC_FOLDERID)			 	?   SYNC_FOLDERID			:
			      ($decoder->getElementStartTag(SYNC_DOCUMENTLIBRARY_LINKID)	 	?   SYNC_DOCUMENTLIBRARY_LINKID		:
			      ($decoder->getElementStartTag(SYNC_AIRSYNCBASE_FILEREFERENCE)	 	?   SYNC_AIRSYNCBASE_FILEREFERENCE	:
			      ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_USERNAME)	 	?   SYNC_ITEMOPERATIONS_USERNAME	:
			      ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_PASSWORD)	 	?   SYNC_ITEMOPERATIONS_PASSWORD	:
			      ($decoder->getElementStartTag(SYNC_SEARCH_LONGID)			 	?   SYNC_SEARCH_LONGID			:
		    	      -1)))))))))) != -1) {
    		if ($reqtag == SYNC_ITEMOPERATIONS_OPTIONS) {
		    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE)) {
		        $bodypreference=array();
        	        while(1) {
            	    	    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
	                        $bodypreference["Type"] = $decoder->getElementContent();
    		                if(!$decoder->getElementEndTag())
                            	    return false;
    	    	    	    }
    
                	    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
        	        	$bodypreference["TruncationSize"] = $decoder->getElementContent();
        		        if(!$decoder->getElementEndTag())
                            	    return false;
        	    	    }
    
                	    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
        	                $bodypreference["AllOrNone"] = $decoder->getElementContent();
        		        if(!$decoder->getElementEndTag())
                    		    return false;
    	    		    }

            	    	    $e = $decoder->peek();
            		    if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
            			$decoder->getElementEndTag();
				if (!isset($thisio["bodypreference"]["wanted"]))
				    $thisio["bodypreference"]["wanted"] = $bodypreference["Type"];
				if (isset($bodypreference["Type"]))
				    $thisio["bodypreference"][$bodypreference["Type"]] = $bodypreference;
    		    		break;
	        	    }
                	}
		    }
		} elseif ($reqtag == SYNC_ITEMOPERATIONS_STORE) {
    	    	    $thisio["store"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_ITEMOPERATIONS_USERNAME) {
    	    	    $thisio["username"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_ITEMOPERATIONS_PASSWORD) {
    	    	    $thisio["password"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_SEARCH_LONGID) {
    	    	    $thisio["searchlongid"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_AIRSYNCBASE_FILEREFERENCE) {
		    $thisio["airsyncbasefilereference"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_SERVERENTRYID) {
		    $thisio["serverentryid"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_FOLDERID) {
		    $thisio["folderid"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_DOCUMENTLIBRARY_LINKID) {
		    $thisio["documentlibrarylinkid"] = $decoder->getElementContent();
		} 
    		$e = $decoder->peek();
    	        if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
    		    $decoder->getElementEndTag();
		}
	    }
	    $itemoperations[] = $thisio;		    
	    $decoder->getElementEndTag(); // end SYNC_ITEMOPERATIONS_FETCH
	}
    }
    $decoder->getElementEndTag(); // end SYNC_ITEMOPERATIONS_ITEMOPERATIONS
    if ($multipart == true) {
        $encoder->startWBXML(true);
    } else {
        $encoder->startWBXML(false);
    }
    $encoder->startTag(SYNC_ITEMOPERATIONS_ITEMOPERATIONS);
    $encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
    $encoder->content(1);
    $encoder->endTag(); // end SYNC_ITEMOPERATIONS_STATUS
    $encoder->startTag(SYNC_ITEMOPERATIONS_RESPONSE);
    foreach($itemoperations as $value) {
	switch($value["type"]) {
	    case "fetch" :
		switch(strtolower($value["store"])) {
		    case "mailbox" :
	    		$encoder->startTag(SYNC_ITEMOPERATIONS_FETCH);
			$encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
			$encoder->content(1);
			$encoder->endTag(); // end SYNC_ITEMOPERATIONS_STATUS
			if (isset($value["airsyncbasefilereference"])) {
			    $encoder->startTag(SYNC_AIRSYNCBASE_FILEREFERENCE);
			    $encoder->content($value["airsyncbasefilereference"]);
			    $encoder->endTag(); // end SYNC_SERVERENTRYID
			} else {
			    if (isset($value["folderid"])) {
    		    		$encoder->startTag(SYNC_FOLDERID);
				$encoder->content($value["folderid"]);
	    			$encoder->endTag(); // end SYNC_FOLDERID
			    }
		    	    if (isset($value["serverentryid"])) {
				$encoder->startTag(SYNC_SERVERENTRYID);
				$encoder->content($value["serverentryid"]);
				$encoder->endTag(); // end SYNC_SERVERENTRYID
			    } 
			    if (isset($value["searchlongid"])) {
				$ids = $backend->ItemOperationsGetIDs($value['searchlongid']);
    		    		$encoder->startTag(SYNC_FOLDERID);
				$encoder->content($ids["folderid"]);
	    	    		$encoder->endTag(); // end SYNC_FOLDERID
				$encoder->startTag(SYNC_SERVERENTRYID);
				$encoder->content($ids["serverentryid"]);
				$encoder->endTag(); // end SYNC_SERVERENTRYID
			    } 
            		    $encoder->startTag(SYNC_FOLDERTYPE);
                	    $encoder->content("Email");
            		    $encoder->endTag();
		        }
            		$encoder->startTag(SYNC_ITEMOPERATIONS_PROPERTIES);
			if (isset($value['bodypreference'])) $encoder->_bodypreference = $value['bodypreference'];
			if (isset($value["searchlongid"])) {
			    $msg = $backend->ItemOperationsFetchMailbox($value['searchlongid'], $value['bodypreference']);
			} else if(isset($value["airsyncbasefilereference"])) {
			    $msg = $backend->ItemOperationsGetAttachmentData($value["airsyncbasefilereference"]);
			} else {
			    $msg = $backend->Fetch($value['folderid'], $value['serverentryid'], $value['bodypreference']);
			};
			$msg->encode($encoder);
		    
            		$encoder->endTag(); // end SYNC_ITEMOPERATIONS_PROPERTIES
			$encoder->endTag(); // end SYNC_ITEMOPERATIONS_FETCH
			break;
		    case "documentlibrary" :
			if (isset($value['username'])) {
			    if (strpos($value['username'],"\\")) {
				list($cred['username']['domain'],$cred['username']['username']) = explode("\\",$value['username']);
			    } else {
				$cred['username'] = array('domain' => "",'username' => $value['username']);
			    }
			} else {
			    $cred['username']['domain'] = $auth_domain;
			    $cred['username']['username'] = $auth_user;
			}
        		$cred['password'] = (isset($value['password']) ? $value['password'] : $auth_pw);
			$result = $backend->ItemOperationsGetDocumentLibraryLink($value["documentlibrarylinkid"],$cred);
	    		$encoder->startTag(SYNC_ITEMOPERATIONS_FETCH);
			$encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
			$encoder->content($result['status']);
			// $encoder->content(1);
			$encoder->endTag(); // end SYNC_ITEMOPERATIONS_STATUS
			$encoder->startTag(SYNC_DOCUMENTLIBRARY_LINKID);
			$encoder->content($value["documentlibrarylinkid"]);
			$encoder->endTag(); // end SYNC_DOCUMENTLIBRARY_LINKID
			if ($result['status'] == 1) {
            		    $encoder->startTag(SYNC_ITEMOPERATIONS_PROPERTIES);
                	    if ($multipart == true) {
                		$encoder->_bodyparts[] = $result['data'];
                		$encoder->startTag(SYNC_ITEMOPERATIONS_PART);
                		$encoder->content("".(sizeof($encoder->_bodyparts))."");
                		$encoder->endTag();
			    } else {
        			$encoder->startTag(SYNC_ITEMOPERATIONS_DATA);
				$encoder->content($result['data']);
				$encoder->endTag(); // end SYNC_ITEMOPERATIONS_DATA
            		    };
            		    $encoder->startTag(SYNC_ITEMOPERATIONS_VERSION);
            		    $encoder->content(gmstrftime("%Y-%m-%dT%H:%M:%S.000Z", $result['version']));
			    $encoder->endTag(); // end SYNC_ITEMOPERATIONS_VERSION
			    $encoder->endTag(); // end SYNC_ITEMOPERATIONS_PROPERTIES
			} else {
			    $encoder->_bodyparts = array();
			}
			$encoder->endTag(); // end SYNC_ITEMOPERATIONS_FETCH
			break;
		    default :	    
			debugLog ("Store ".$value["type"]." not supported by HandleItemOperations");
		        break;
		}
		break;
	    default :
		debugLog ("Operations ".$value["type"]." not supported by HandleItemOperations");
		break;
	}
    }
    $encoder->endTag(); //end SYNC_ITEMOPERATIONS_RESPONSE
    $encoder->endTag(); //end SYNC_ITEMOPERATIONS_ITEMOPERATIONS

    return true;
}

// END ADDED dw2412 ItemOperations Support

function HandleRequest($backend, $cmd, $devid, $protocolversion, $multipart) {

    switch($cmd) {
        case 'Sync':
            $status = HandleSync($backend, $protocolversion, $devid);
            break;
        case 'SendMail':
            $status = HandleSendMail($backend, $protocolversion);
            break;
        case 'SmartForward':
            $status = HandleSmartForward($backend, $protocolversion);
            break;
        case 'SmartReply':
            $status = HandleSmartReply($backend, $protocolversion);
            break;
        case 'GetAttachment':
            $status = HandleGetAttachment($backend, $protocolversion);
            break;
        case 'GetHierarchy':
            $status = HandleGetHierarchy($backend, $protocolversion, $devid);
            break;
        case 'CreateCollection':
            $status = HandleCreateCollection($backend, $protocolversion);
            break;
        case 'DeleteCollection':
            $status = HandleDeleteCollection($backend, $protocolversion);
            break;
        case 'MoveCollection':
            $status = HandleMoveCollection($backend, $protocolversion);
            break;
        case 'FolderSync':
            $status = HandleFolderSync($backend, $protocolversion);
            break;
        case 'FolderCreate':
            $status = HandleFolderCreate($backend, $protocolversion);
            break;
        case 'FolderDelete':
            $status = HandleFolderDelete($backend, $protocolversion);
            break;
        case 'FolderUpdate':
            $status = HandleFolderUpdate($backend, $protocolversion);
            break;
        case 'MoveItems':
            $status = HandleMoveItems($backend, $protocolversion);
            break;
        case 'GetItemEstimate':
            $status = HandleGetItemEstimate($backend, $protocolversion, $devid);
            break;
        case 'MeetingResponse':
            $status = HandleMeetingResponse($backend, $protocolversion);
            break;
        case 'Notify': // Used for sms-based notifications (pushmail)
            $status = HandleNotify($backend, $protocolversion);
            break;
        case 'Ping': // Used for http-based notifications (pushmail)
            $status = HandlePing($backend, $devid, $protocolversion);
            break;
        case 'Provision':
	    $status = (PROVISIONING === true) ? HandleProvision($backend, $devid, $protocolversion) : false;
            break;
        case 'Search':
            $status = HandleSearch($backend, $devid, $protocolversion);
            break;
        case 'Settings':
            $status = HandleSettings($backend, $devid, $protocolversion);
            break;
        case 'ItemOperations':
            $status = HandleItemOperations($backend, $devid, $protocolversion, $multipart);
            break;

        default:
            debugLog("unknown command - not implemented");
            $status = false;
            break;
    }

    return $status;
}

function readStream(&$input) {
    $s = "";

    while(1) {
        $data = fread($input, 4096);
        if(strlen($data) == 0)
            break;
        $s .= $data;
    }

    return $s;
}

?>