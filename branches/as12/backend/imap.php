<?php
/***********************************************
* File      :   imap.php
* Project   :   Z-Push
* Descr     :   This backend is based on
*               'BackendDiff' and implements an
*               IMAP interface
*
* Created   :   10.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

include_once('diffbackend.php');

// The is an improved version of mimeDecode from PEAR that correctly
// handles charsets and charset conversion
include_once('mimeDecode.php');
require_once('z_RFC822.php');

class BackendIMAP extends BackendDiff {
    /* Called to logon a user. These are the three authentication strings that you must
     * specify in ActiveSync on the PDA. Normally you would do some kind of password
     * check here. Alternatively, you could ignore the password here and have Apache
     * do authentication via mod_auth_*
     */
    function Logon($username, $domain, $password) {
        $this->_wasteID = false;
        $this->_sentID = false;
        $this->_server = "{" . IMAP_SERVER . ":" . IMAP_PORT . "/imap" . IMAP_OPTIONS . "}";

        if (!function_exists("imap_open"))
            debugLog("ERROR BackendIMAP : PHP-IMAP module not installed!!!!!");

        // open the IMAP-mailbox
        $this->_mbox = @imap_open($this->_server , $username, $password, OP_HALFOPEN);
        $this->_mboxFolder = "";

        if ($this->_mbox) {
            debugLog("IMAP connection opened sucessfully ");
            $this->_username = $username;
            $this->_domain = $domain;
            // set serverdelimiter
             $this->_serverdelimiter = $this->getServerDelimiter();
            return true;
        }
        else {
            debugLog("IMAP can't connect: " . imap_last_error());
            return false;
        }
    }

    /* Called before shutting down the request to close the IMAP connection
     */
    function Logoff() {
        if ($this->_mbox) {
            // list all errors
            $errors = imap_errors();
            if (is_array($errors)) {
                foreach ($errors as $e)    debugLog("IMAP-errors: $e");
            }
            @imap_close($this->_mbox);
            debugLog("IMAP connection closed");
        }
    }

    /* Called directly after the logon. This specifies the client's protocol version
     * and device id. The device ID can be used for various things, including saving
     * per-device state information.
     * The $user parameter here is normally equal to the $username parameter from the
     * Logon() call. In theory though, you could log on a 'foo', and then sync the emails
     * of user 'bar'. The $user here is the username specified in the request URL, while the
     * $username in the Logon() call is the username which was sent as a part of the HTTP
     * authentication.
     */
    function Setup($user, $devid, $protocolversion) {
        $this->_user = $user;
        $this->_devid = $devid;
        $this->_protocolversion = $protocolversion;

        return true;
    }

    /* Sends a message which is passed as rfc822. You basically can do two things
     * 1) Send the message to an SMTP server as-is
     * 2) Parse the message yourself, and send it some other way
     * It is up to you whether you want to put the message in the sent items folder. If you
     * want it in 'sent items', then the next sync on the 'sent items' folder should return
     * the new message as any other new message in a folder.
     */
    function SendMail($rfc822, $forward = false, $reply = false, $parent = false, $protocolversion = false) {
        debugLog("IMAP-SendMail: " . $rfc822 . "for: $forward   reply: $reply   parent: $parent" );

        $mobj = new Mail_mimeDecode($rfc822);
        $message = $mobj->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'input' => $rfc822, 'crlf' => "\n", 'charset' => 'utf-8'));

        $toaddr = $ccaddr = $bccaddr = "";
        if(isset($message->headers["to"]))
            $toaddr = $this->parseAddr(Mail_RFC822::parseAddressList($message->headers["to"]));
        if(isset($message->headers["cc"]))
            $ccaddr = $this->parseAddr(Mail_RFC822::parseAddressList($message->headers["cc"]));
        if(isset($message->headers["bcc"]))
            $bccaddr = $this->parseAddr(Mail_RFC822::parseAddressList($message->headers["bcc"]));

        // save some headers when forwarding mails (content type & transfer-encoding)
        $headers = "";
        $forward_h_ct = "";
        $forward_h_cte = "";

        $use_orgbody = false;

        // clean up the transmitted headers
        // remove default headers because we are using imap_mail
        $changedfrom = false;
        $returnPathSet = false;
        $body_base64 = false;
        $org_charset = "";
        foreach($message->headers as $k => $v) {
            if ($k == "subject" || $k == "to" || $k == "cc" || $k == "bcc")
                continue;

            if ($k == "content-type") {
                // save the original content-type header for the body part when forwarding
                if ($forward) {
                    $forward_h_ct = $v;
                    continue;
                }

                // set charset always to utf-8
                $org_charset = $v;
                $v = preg_replace("/charset=([A-Za-z0-9-\"']+)/", "charset=\"utf-8\"", $v);
            }

            if ($k == "content-transfer-encoding") {
                // if the content was base64 encoded, encode the body again when sending
                if (trim($v) == "base64") $body_base64 = true;

                // save the original encoding header for the body part when forwarding
                if ($forward) {
                    $forward_h_cte = $v;
                    continue;
                }
            }

            // if the message is a multipart message, then we should use the sent body
            if (!$forward && $k == "content-type" && preg_match("/multipart/i", $v)) {
                $use_orgbody = true;
            }

            // check if "from"-header is set
            if ($k == "from" && ! trim($v) && IMAP_DEFAULTFROM) {
                $changedfrom = true;
                if      (IMAP_DEFAULTFROM == 'username') $v = $this->_username;
                else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->_domain;
                else $v = $this->_username . IMAP_DEFAULTFROM;
            }

            // check if "Return-Path"-header is set
            if ($k == "return-path") {
                $returnPathSet = true;
                if (! trim($v) && IMAP_DEFAULTFROM) {
                    if      (IMAP_DEFAULTFROM == 'username') $v = $this->_username;
                    else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->_domain;
                    else $v = $this->_username . IMAP_DEFAULTFROM;
                }
            }

            // all other headers stay
            if ($headers) $headers .= "\n";
            $headers .= ucfirst($k) . ": ". $v;
        }

        // set "From" header if not set on the device
        if(IMAP_DEFAULTFROM && !$changedfrom){
            if      (IMAP_DEFAULTFROM == 'username') $v = $this->_username;
            else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->_domain;
            else $v = $this->_username . IMAP_DEFAULTFROM;
            if ($headers) $headers .= "\n";
            $headers .= 'From: '.$v;
        }

        // set "Return-Path" header if not set on the device
        if(IMAP_DEFAULTFROM && !$returnPathSet){
            if      (IMAP_DEFAULTFROM == 'username') $v = $this->_username;
            else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->_domain;
            else $v = $this->_username . IMAP_DEFAULTFROM;
            if ($headers) $headers .= "\n";
            $headers .= 'Return-Path: '.$v;
        }

        // if this is a multipart message with a boundary, we must use the original body
        if ($use_orgbody) {
            list(,$body) = $mobj->_splitBodyHeader($rfc822);
        }
        else
            $body = $this->getBody($message);

        // reply
        if (isset($reply) && isset($parent) &&  $reply && $parent) {
            $this->imap_reopenFolder($parent);
            // receive entire mail (header + body) to decode body correctly
            $origmail = @imap_fetchheader($this->_mbox, $reply, FT_PREFETCHTEXT | FT_UID) . @imap_body($this->_mbox, $reply, FT_PEEK | FT_UID);
            $mobj2 = new Mail_mimeDecode($origmail);
            // receive only body
            $body .= $this->getBody($mobj2->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'input' => $origmail, 'crlf' => "\n", 'charset' => 'utf-8')));
            // unset mimedecoder & origmail - free memory
            unset($mobj2);
            unset($origmail);
        }

        // encode the body to base64 if it was sent originally in base64 by the pda
        // the encoded body is included in the forward
        if ($body_base64) $body = base64_encode($body);


        // forward
        if (isset($forward) && isset($parent) && $forward && $parent) {
            $this->imap_reopenFolder($parent);
            // receive entire mail (header + body)
            $origmail = @imap_fetchheader($this->_mbox, $forward, FT_PREFETCHTEXT | FT_UID) . @imap_body($this->_mbox, $forward, FT_PEEK | FT_UID);

            // build a new mime message, forward entire old mail as file
            list($aheader, $body) = $this->mail_attach("forwarded_message.eml",strlen($origmail),$origmail, $body, $forward_h_ct, $forward_h_cte);

            // unset origmail - free memory
            unset($origmail);

            // add boundary headers
            $headers .= "\n" . $aheader;
        }

        //advanced debugging
        //debugLog("IMAP-SendMail: parsed message: ". print_r($message,1));
        //debugLog("IMAP-SendMail: headers: $headers");
        //debugLog("IMAP-SendMail: subject: {$message->headers["subject"]}");
        //debugLog("IMAP-SendMail: body: $body");

        $send =  @imap_mail ( $toaddr, $message->headers["subject"], $body, $headers, $ccaddr, $bccaddr);

        // email sent?
        if (!$send) {
            debugLog("The email could not be sent. Last-IMAP-error: ". imap_last_error());
        }

        // add message to the sent folder
        // build complete headers
        $cheaders  = "To: " . $toaddr. "\n";
        $cheaders .= "Subject: " . $message->headers["subject"] . "\n";
        $cheaders .= "Cc: " . $ccaddr . "\n";
        $cheaders .= $headers;

        $asf = false;
        if ($this->_sentID) {
            $asf = $this->addSentMessage($this->_sentID, $cheaders, $body);
        }
        else if (IMAP_SENTFOLDER) {
            $asf = $this->addSentMessage(IMAP_SENTFOLDER, $cheaders, $body);
            debugLog("IMAP-SendMail: Outgoing mail saved in configured 'Sent' folder '".IMAP_SENTFOLDER."': ". (($asf)?"success":"failed"));
        }
        // No Sent folder set, try defaults
        else {
            debugLog("IMAP-SendMail: No Sent mailbox set");
            if($this->addSentMessage("INBOX.Sent", $cheaders, $body)) {
                debugLog("IMAP-SendMail: Outgoing mail saved in 'INBOX.Sent'");
                $asf = true;
            }
            else if ($this->addSentMessage("Sent", $cheaders, $body)) {
                debugLog("IMAP-SendMail: Outgoing mail saved in 'Sent'");
                $asf = true;
            }
            else if ($this->addSentMessage("Sent Items", $cheaders, $body)) {
                debugLog("IMAP-SendMail: Outgoing mail saved in 'Sent Items'");
                $asf = true;
            }
        }

        // unset mimedecoder - free memory
        unset($mobj);
        return ($send && $asf);
    }

    /* Should return a wastebasket folder if there is one. This is used when deleting
     * items; if this function returns a valid folder ID, then all deletes are handled
     * as moves and are sent to your backend as a move. If it returns FALSE, then deletes
     * are always handled as real deletes and will be sent to your importer as a DELETE
     */
    function GetWasteBasket() {
        return $this->_wasteID;
    }

    /* Should return a list (array) of messages, each entry being an associative array
     * with the same entries as StatMessage(). This function should return stable information; ie
     * if nothing has changed, the items in the array must be exactly the same. The order of
     * the items within the array is not important though.
     *
     * The cutoffdate is a date in the past, representing the date since which items should be shown.
     * This cutoffdate is determined by the user's setting of getting 'Last 3 days' of e-mail, etc. If
     * you ignore the cutoffdate, the user will not be able to select their own cutoffdate, but all
     * will work OK apart from that.
     */

    function GetMessageList($folderid, $cutoffdate) {
        debugLog("IMAP-GetMessageList: (fid: '$folderid'  cutdate: '$cutoffdate' )");

        $messages = array();
        $this->imap_reopenFolder($folderid, true);

        $sequence = "1:*";
        if ($cutoffdate > 0) {
            $search = @imap_search($this->_mbox, "SINCE ". date("d-M-Y", $cutoffdate));
            if ($search !== false)
                $sequence = implode(",", $search);
        }
        $overviews = @imap_fetch_overview($this->_mbox, $sequence);

        if (!$overviews) {
            debugLog("IMAP-GetMessageList: Failed to retrieve overview");
        } else {
            foreach($overviews as $overview) {
                $date = "";
                $vars = get_object_vars($overview);
                if (array_key_exists( "date", $vars)) {
                    // message is out of range for cutoffdate, ignore it
                    if(strtotime($overview->date) < $cutoffdate) continue;
                    $date = $overview->date;
                }

                // cut of deleted messages
                if (array_key_exists( "deleted", $vars) && $overview->deleted)
                    continue;

                if (array_key_exists( "uid", $vars)) {
                    $message = array();
                    $message["mod"] = $date;
                    $message["id"] = $overview->uid;
                    // 'seen' aka 'read' is the only flag we want to know about
                    $message["flags"] = 0;

                    if(array_key_exists( "seen", $vars) && $overview->seen)
                        $message["flags"] = 1;

                    array_push($messages, $message);
                }
            }
        }
        return $messages;
    }

    /* This function is analogous to GetMessageList.
     *
     */
    function GetFolderList() {
        $folders = array();

        $list = @imap_getmailboxes($this->_mbox, $this->_server, "*");
        if (is_array($list)) {
            // reverse list to obtain folders in right order
            $list = array_reverse($list);
            foreach ($list as $val) {
                $box = array();

                // cut off serverstring
                $box["id"] = imap_utf7_decode(substr($val->name, strlen($this->_server)));

                // always use "." as folder delimiter
                $box["id"] = imap_utf7_encode(str_replace($val->delimiter, ".", $box["id"]));

                // explode hierarchies
                $fhir = explode(".", $box["id"]);
                if (count($fhir) > 1) {
                    $box["mod"] = imap_utf7_encode(array_pop($fhir)); // mod is last part of path
                    $box["parent"] = imap_utf7_encode(implode(".", $fhir)); // parent is all previous parts of path
                }
                else {
                    $box["mod"] = imap_utf7_encode($box["id"]);
                    $box["parent"] = "0";
                }

                $folders[]=$box;
            }
        }
        else {
            debugLog("GetFolderList: imap_list failed: " . imap_last_error());
        }

        return $folders;
    }

    /* GetFolder should return an actual SyncFolder object with all the properties set. Folders
     * are pretty simple really, having only a type, a name, a parent and a server ID.
     */

    function GetFolder($id) {
        $folder = new SyncFolder();
        $folder->serverid = $id;

        // explode hierarchy
          $fhir = explode(".", $id);

        // compare on lowercase strings
        $lid = strtolower($id);

        if($lid == "inbox") {
            $folder->parentid = "0"; // Root
            $folder->displayname = "Inbox";
            $folder->type = SYNC_FOLDER_TYPE_INBOX;
        }
        // Zarafa IMAP-Gateway outputs
        else if($lid == "drafts") {
            $folder->parentid = "0";
            $folder->displayname = "Drafts";
            $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
        }
        else if($lid == "trash") {
            $folder->parentid = "0";
            $folder->displayname = "Trash";
            $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
            $this->_wasteID = $id;
        }
        else if($lid == "sent" || $lid == "sent items" || $lid == IMAP_SENTFOLDER) {
            $folder->parentid = "0";
            $folder->displayname = "Sent";
            $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
            $this->_sentID = $id;
        }
        // courier-imap outputs
        else if($lid == "inbox.drafts") {
            $folder->parentid = $fhir[0];
            $folder->displayname = "Drafts";
            $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
        }
        else if($lid == "inbox.trash") {
            $folder->parentid = $fhir[0];
            $folder->displayname = "Trash";
            $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
            $this->_wasteID = $id;
        }
        else if($lid == "inbox.sent") {
            $folder->parentid = $fhir[0];
            $folder->displayname = "Sent";
            $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
            $this->_sentID = $id;
        }

        // define the rest as other-folders
        else {
               if (count($fhir) > 1) {
                   $folder->displayname = windows1252_to_utf8(imap_utf7_decode(array_pop($fhir)));
                   $folder->parentid = implode(".", $fhir);
               }
               else {
                $folder->displayname = windows1252_to_utf8(imap_utf7_decode($id));
                $folder->parentid = "0";
               }
            $folder->type = SYNC_FOLDER_TYPE_OTHER;
        }

           //advanced debugging
           //debugLog("IMAP-GetFolder(id: '$id') -> " . print_r($folder, 1));

        return $folder;
    }

    /* Return folder stats. This means you must return an associative array with the
     * following properties:
     * "id" => The server ID that will be used to identify the folder. It must be unique, and not too long
     *         How long exactly is not known, but try keeping it under 20 chars or so. It must be a string.
     * "parent" => The server ID of the parent of the folder. Same restrictions as 'id' apply.
     * "mod" => This is the modification signature. It is any arbitrary string which is constant as long as
     *          the folder has not changed. In practice this means that 'mod' can be equal to the folder name
     *          as this is the only thing that ever changes in folders. (the type is normally constant)
     */
    function StatFolder($id) {
        $folder = $this->GetFolder($id);

        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $folder->parentid;
        $stat["mod"] = $folder->displayname;

        return $stat;
    }

    /* Creates or modifies a folder
     * "folderid" => id of the parent folder
     * "oldid" => if empty -> new folder created, else folder is to be renamed
     * "displayname" => new folder name (to be created, or to be renamed to)
     * "type" => folder type, ignored in IMAP
     *
     */
    function ChangeFolder($folderid, $oldid, $displayname, $type){
        debugLog("ChangeFolder: (parent: '$folderid'  oldid: '$oldid'  displayname: '$displayname'  type: '$type')");

        // go to parent mailbox
        $this->imap_reopenFolder($folderid);

        // build name for new mailbox
        $newname = $this->_server . str_replace(".", $this->_serverdelimiter, $folderid) . $this->_serverdelimiter . $displayname;

        $csts = false;
        // if $id is set => rename mailbox, otherwise create
        if ($oldid) {
            // rename doesn't work properly with IMAP
            // the activesync client doesn't support a 'changing ID'
            //$csts = imap_renamemailbox($this->_mbox, $this->_server . imap_utf7_encode(str_replace(".", $this->_serverdelimiter, $oldid)), $newname);
        }
        else {
            $csts = @imap_createmailbox($this->_mbox, $newname);
        }
        if ($csts) {
            return $this->StatFolder($folderid . "." . $displayname);
        }
        else
            return false;
    }

    /* Should return attachment data for the specified attachment. The passed attachment identifier is
     * the exact string that is returned in the 'AttName' property of an SyncAttachment. So, you should
     * encode any information you need to find the attachment in that 'attname' property.
     */
    function GetAttachmentData($attname) {
        debugLog("getAttachmentDate: (attname: '$attname')");

        list($folderid, $id, $part) = explode(":", $attname);

        $this->imap_reopenFolder($folderid);
        $mail = @imap_fetchheader($this->_mbox, $id, FT_PREFETCHTEXT | FT_UID) . @imap_body($this->_mbox, $id, FT_PEEK | FT_UID);

        $mobj = new Mail_mimeDecode($mail);
        $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'input' => $mail, 'crlf' => "\n", 'charset' => 'utf-8'));

        if (isset($message->parts[$part]->body))
            print $message->parts[$part]->body;

        // unset mimedecoder & mail
        unset($mobj);
        unset($mail);
        return true;
    }

    /* StatMessage should return message stats, analogous to the folder stats (StatFolder). Entries are:
     * 'id'     => Server unique identifier for the message. Again, try to keep this short (under 20 chars)
     * 'flags'     => simply '0' for unread, '1' for read
     * 'mod'    => modification signature. As soon as this signature changes, the item is assumed to be completely
     *             changed, and will be sent to the PDA as a whole. Normally you can use something like the modification
     *             time for this field, which will change as soon as the contents have changed.
     */

    function StatMessage($folderid, $id) {
        debugLog("IMAP-StatMessage: (fid: '$folderid'  id: '$id' )");

        $this->imap_reopenFolder($folderid);
        $overview = @imap_fetch_overview( $this->_mbox , $id , FT_UID);

        if (!$overview) {
            debugLog("IMAP-StatMessage: Failed to retrieve overview: ". imap_last_error());
            return false;
        }

        else {
            // check if variables for this overview object are available
            $vars = get_object_vars($overview[0]);

            // without uid it's not a valid message
            if (! array_key_exists( "uid", $vars)) return false;


            $entry = array();
            $entry["mod"] = (array_key_exists( "date", $vars)) ? $overview[0]->date : "";
            $entry["id"] = $overview[0]->uid;
            // 'seen' aka 'read' is the only flag we want to know about
            $entry["flags"] = 0;

            if(array_key_exists( "seen", $vars) && $overview[0]->seen)
                $entry["flags"] = 1;

            //advanced debugging
            //debugLog("IMAP-StatMessage-parsed: ". print_r($entry,1));

            return $entry;
        }
    }

    /* GetMessage should return the actual SyncXXX object type. You may or may not use the '$folderid' parent folder
     * identifier here.
     * Note that mixing item types is illegal and will be blocked by the engine; ie returning an Email object in a
     * Tasks folder will not do anything. The SyncXXX objects should be filled with as much information as possible,
     * but at least the subject, body, to, from, etc.
     */
    function GetMessage($folderid, $id, $truncsize, $mimesupport = 0) {
        debugLog("IMAP-GetMessage: (fid: '$folderid'  id: '$id'  truncsize: $truncsize)");

        // Get flags, etc
        $stat = $this->StatMessage($folderid, $id);

        if ($stat) {
            $this->imap_reopenFolder($folderid);
            $mail = @imap_fetchheader($this->_mbox, $id, FT_PREFETCHTEXT | FT_UID) . @imap_body($this->_mbox, $id, FT_PEEK | FT_UID);

            $mobj = new Mail_mimeDecode($mail);
            $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'input' => $mail, 'crlf' => "\n", 'charset' => 'utf-8'));

            $output = new SyncMail();

            $body = $this->getBody($message);
            // truncate body, if requested
            if(strlen($body) > $truncsize) {
                $body = utf8_truncate($body, $truncsize);
                $output->bodytruncated = 1;
            } else {
                $body = $body;
                $output->bodytruncated = 0;
            }
            $body = str_replace("\n","\r\n", str_replace("\r","",$body));

            $output->bodysize = strlen($body);
            $output->body = $body;
            $output->datereceived = isset($message->headers["date"]) ? strtotime($message->headers["date"]) : null;
            $output->displayto = isset($message->headers["to"]) ? $message->headers["to"] : null;
            $output->importance = isset($message->headers["x-priority"]) ? preg_replace("/\D+/", "", $message->headers["x-priority"]) : null;
            $output->messageclass = "IPM.Note";
            $output->subject = isset($message->headers["subject"]) ? $message->headers["subject"] : "";
            $output->read = $stat["flags"];
            $output->to = isset($message->headers["to"]) ? $message->headers["to"] : null;
            $output->cc = isset($message->headers["cc"]) ? $message->headers["cc"] : null;
            $output->from = isset($message->headers["from"]) ? $message->headers["from"] : null;
            $output->reply_to = isset($message->headers["reply-to"]) ? $message->headers["reply-to"] : null;

            // Attachments are only searched in the top-level part
            $n = 0;
            if(isset($message->parts)) {
                foreach($message->parts as $part) {
                    if(isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline")) {
                        $attachment = new SyncAttachment();

                        if (isset($part->body))
                            $attachment->attsize = strlen($part->body);

                        if(isset($part->d_parameters['filename']))
                            $attname = $part->d_parameters['filename'];
                        else if(isset($part->ctype_parameters['name']))
                            $attname = $part->ctype_parameters['name'];
                        else if(isset($part->headers['content-description']))
                            $attname = $part->headers['content-description'];
                        else $attname = "unknown attachment";

                        $attachment->displayname = $attname;
                        $attachment->attname = $folderid . ":" . $id . ":" . $n;
                        $attachment->attmethod = 1;
                        $attachment->attoid = isset($part->headers['content-id']) ? $part->headers['content-id'] : "";
                        array_push($output->attachments, $attachment);
                    }
                    $n++;
                }
            }
            // unset mimedecoder & mail
            unset($mobj);
            unset($mail);
            return $output;
        }
        return false;
    }

    /* This function is called when the user has requested to delete (really delete) a message. Usually
     * this means just unlinking the file its in or somesuch. After this call has succeeded, a call to
     * GetMessageList() should no longer list the message. If it does, the message will be re-sent to the PDA
     * as it will be seen as a 'new' item. This means that if you don't implement this function, you will
     * be able to delete messages on the PDA, but as soon as you sync, you'll get the item back
     */
    function DeleteMessage($folderid, $id) {
        debugLog("IMAP-DeleteMessage: (fid: '$folderid'  id: '$id' )");

        $this->imap_reopenFolder($folderid);
        $s1 = @imap_delete ($this->_mbox, $id, FT_UID);
        $s11 = @imap_setflag_full($this->_mbox, $id, "\\Deleted", FT_UID);
        $s2 = @imap_expunge($this->_mbox);

         debugLog("IMAP-DeleteMessage: s-delete: $s1   s-expunge: $s2    setflag: $s11");

        return ($s1 && $s2 && $s11);
    }

    /* This should change the 'read' flag of a message on disk. The $flags
     * parameter can only be '1' (read) or '0' (unread). After a call to
     * SetReadFlag(), GetMessageList() should return the message with the
     * new 'flags' but should not modify the 'mod' parameter. If you do
     * change 'mod', simply setting the message to 'read' on the PDA will trigger
     * a full resync of the item from the server
     */
    function SetReadFlag($folderid, $id, $flags) {
        debugLog("IMAP-SetReadFlag: (fid: '$folderid'  id: '$id'  flags: '$flags' )");

        $this->imap_reopenFolder($folderid);

        if ($flags == 0) {
            // set as "Unseen" (unread)
            $status = @imap_clearflag_full ( $this->_mbox, $id, "\\Seen", ST_UID);
        } else {
            // set as "Seen" (read)
            $status = @imap_setflag_full($this->_mbox, $id, "\\Seen",ST_UID);
        }

        debugLog("IMAP-SetReadFlag -> set as " . (($flags) ? "read" : "unread") . "-->". $status);

        return $status;
    }

    /* This function is called when a message has been changed on the PDA. You should parse the new
     * message here and save the changes to disk. The return value must be whatever would be returned
     * from StatMessage() after the message has been saved. This means that both the 'flags' and the 'mod'
     * properties of the StatMessage() item may change via ChangeMessage().
     * Note that this function will never be called on E-mail items as you can't change e-mail items, you
     * can only set them as 'read'.
     */
    function ChangeMessage($folderid, $id, $message) {
        return false;
    }

    /* This function is called when the user moves an item on the PDA. You should do whatever is needed
     * to move the message on disk. After this call, StatMessage() and GetMessageList() should show the items
     * to have a new parent. This means that it will disappear from GetMessageList() will not return the item
     * at all on the source folder, and the destination folder will show the new message
     *
     */
    function MoveMessage($folderid, $id, $newfolderid) {
        debugLog("IMAP-MoveMessage: (sfid: '$folderid'  id: '$id'  dfid: '$newfolderid' )");

        $this->imap_reopenFolder($folderid);

        // read message flags
        $overview = @imap_fetch_overview ( $this->_mbox , $id, FT_UID);

        if (!$overview) {
            debugLog("IMAP-MoveMessage: Failed to retrieve overview");
            return false;
        }
        else {
            // move message
            $s1 = imap_mail_move($this->_mbox, $id, str_replace(".", $this->_serverdelimiter, $newfolderid), FT_UID);

            // delete message in from-folder
            $s2 = imap_expunge($this->_mbox);

            // open new folder
            $this->imap_reopenFolder($newfolderid);

            // remove all flags
            $s3 = @imap_clearflag_full ($this->_mbox, $id, "\\Seen \\Answered \\Flagged \\Deleted \\Draft", FT_UID);
            $newflags = "";
            if ($overview[0]->seen) $newflags .= "\\Seen";
            if ($overview[0]->flagged) $newflags .= " \\Flagged";
            if ($overview[0]->answered) $newflags .= " \\Answered";
            $s4 = @imap_setflag_full ($this->_mbox, $id, $newflags, FT_UID);

            debugLog("MoveMessage: (" . $folderid . "->" . $newfolderid . ") s-move: $s1   s-expunge: $s2    unset-Flags: $s3    set-Flags: $s4");

            return ($s1 && $s2 && $s3 && $s4);
        }
    }

    // new ping mechanism for the IMAP-Backend
    function AlterPing() {
        return true;
    }

    // returns a changes array using imap_status
    // if changes occurr default diff engine computes the actual changes
    function AlterPingChanges($folderid, &$syncstate) {
        debugLog("AlterPingChanges on $folderid stat: ". $syncstate);
        $this->imap_reopenFolder($folderid);

        // courier-imap only cleares the status cache after checking
        @imap_check($this->_mbox);

        $status = imap_status($this->_mbox, $this->_server . str_replace(".", $this->_serverdelimiter, $folderid), SA_ALL);
        if (!$status) {
            debugLog("AlterPingChanges: could not stat folder $folderid : ". imap_last_error());
            return false;
        }
        else {
            $newstate = "M:". $status->messages ."-R:". $status->recent ."-U:". $status->unseen;

            // message number is different - change occured
            if ($syncstate != $newstate) {
                $syncstate = $newstate;
                debugLog("AlterPingChanges: Change FOUND!");
                // build a dummy change
                return array(array("type" => "fakeChange"));
            }
        }

        return array();
    }

    // ----------------------------------------
    // imap-specific internals

    /* Parse the message and return only the plaintext body
     */
    function getBody($message) {
        $body = "";
        $htmlbody = "";

        $this->getBodyRecursive($message, "plain", $body);

        if(!isset($body) || $body === "") {
            $this->getBodyRecursive($message, "html", $body);
            // remove css-style tags
            $body = preg_replace("/<style.*?<\/style>/is", "", $body);
            // remove all other html
            $body = strip_tags($body);
        }

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

    // save the serverdelimiter for later folder (un)parsing
    function getServerDelimiter() {
        $list = @imap_getmailboxes($this->_mbox, $this->_server, "*");
        if (is_array($list)) {
            $val = $list[0];

            return $val->delimiter;
        }
        return "."; // default "."
    }

    // speed things up
    // remember what folder is currently open and only change if necessary
    function imap_reopenFolder($folderid, $force = false) {
        // to see changes, the folder has to be reopened!
           if ($this->_mboxFolder != $folderid || $force) {
               $s = @imap_reopen($this->_mbox, $this->_server . str_replace(".", $this->_serverdelimiter, $folderid));
               if (!$s) debugLog("failed to change folder: ". implode(", ", imap_errors()));
            $this->_mboxFolder = $folderid;
        }
    }


    // build a multipart email, embedding body and one file (for attachments)
    function mail_attach($filenm,$filesize,$file_cont,$body, $body_ct, $body_cte) {

        $boundary = strtoupper(md5(uniqid(time())));

        $mail_header = "Content-Type: multipart/mixed; boundary=$boundary\n";

        // build main body with the sumitted type & encoding from the pda
        $mail_body  = "This is a multi-part message in MIME format\n\n";
        $mail_body .= "--$boundary\n";
        $mail_body .= "Content-Type:$body_ct\n";
        $mail_body .= "Content-Transfer-Encoding:$body_cte\n\n";
        $mail_body .= "$body\n\n";

        $mail_body .= "--$boundary\n";
        $mail_body .= "Content-Type: text/plain; name=\"$filenm\"\n";
        $mail_body .= "Content-Transfer-Encoding: base64\n";
        $mail_body .= "Content-Disposition: attachment; filename=\"$filenm\"\n";
        $mail_body .= "Content-Description: $filenm\n\n";
        $mail_body .= base64_encode($file_cont) . "\n\n";

        $mail_body .= "--$boundary--\n\n";

        return array($mail_header, $mail_body);
    }

    // adds a message as seen to a specified folder (used for saving sent mails)
    function addSentMessage($folderid, $header, $body) {
        $header_body = str_replace("\n", "\r\n", str_replace("\r", "", $header . "\n\n" . $body));

        return @imap_append($this->_mbox, $this->_server . $folderid, $header_body, "\\Seen");
    }


    // parses address objects back to a simple "," separated string
    function parseAddr($ad) {
        $addr_string = "";
        if (isset($ad) && is_array($ad)) {
            foreach($ad as $addr) {
                if ($addr_string) $addr_string .= ",";
                    $addr_string .= $addr->mailbox . "@" . $addr->host;
            }
        }
        return $addr_string;
    }

};

?>