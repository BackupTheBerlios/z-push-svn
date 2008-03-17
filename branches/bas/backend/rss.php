<?
/***********************************************
* File      :   rss.php
* Project   :   Z-Push
* Descr	    :   This backend is for showing RSS 
*               feeds as mail items
*
* Created   :   17.03.2008
*
* © Michael Erkens, www.erkens.eu
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

include_once('diffbackend.php');
include_once('mimeDecode.php');

// PEAR's XML Unserializer
include_once("XML/Unserializer.php");

class BackendRSS extends BackendDiff {
	var $_config;

	function BackendRSS($config)
	{
		$this->_config = $config;
		$this->_ignoreflagchanges = true;
	}

	function Logon($username, $domain, $password) {
		if (strpos($username, "\\") !== false) {
			$username = substr($username, strpos($username, "\\")+1);
		}

		$this->__username = $username;
		$this->__domain = $domain;
		$this->__password = $password;
		
		return true;
	}
	
	function Setup($user, $devid, $protocolversion) {
		debugLog("RSS: Setup:".$user);
		$this->_user = $user;
		$this->_devid = $devid;
		$this->_protocolversion = $protocolversion;

		if (!is_file($this->getConfigFileName())){
			return false;
		}

		$cfg = file($this->getConfigFileName());
		$this->_feeds = array();
		foreach($cfg as $cfg_line){
			list($feedName, $feedURL) = explode("\t", $cfg_line, 2);
			
			if(trim($feedName)!="" && trim($feedURL)!=""){
				$this->_feeds[trim($feedName)] = str_replace(array("%u","%p"), array($this->__username, $this->__password),trim($feedURL));
			}
		}
		debugLog("RSS: vardump:".var_export($this->_feeds, 1));
		
		return true;
	}
	
	function SendMail($rfc822, $forward = false, $reply = false, $parent = false) {
		debugLog("RSS: SendMail");
		return false;
	}
	
	function GetWasteBasket() {
		debugLog("RSS: GetWasteBasket");
		return false;
	}
	
	function GetMessageList($folderid, $cutoffdate) {
		debugLog("RSS: GetMessageList(".$folderid.", ".$cutoffdate.")");

		// TODO: implement cutoffdate

		// fetch rss feed
		if (!isset($this->_feeds[$folderid])){
			return false;
		}

		$rss = $this->getRSSFeed($folderid, $this->_feeds[$folderid]);
		$rss = $rss["channel"];
		
		$messages = array();
		if (!isset($rss["item"]))
			return $messages;

		foreach($rss["item"] as $item){
			$id = $this->getMessageIdFromItem($item);
			$messages[] = array(
					"id"=>$id,
					"flags"=>0, // TODO
					"mod"=>$id
				);
		}

		return $messages;
	}
	
	function GetFolderList() {
		debugLog("RSS: GetFolderList");
		$folders = array();

		foreach($this->_feeds as $folderName=>$url){
			$sub = array();
			$sub["id"] = $folderName;
			$sub["parent"] = "0";
			$sub["mod"] = $folderName;
			$folders[]=$sub;
		}
		debugLog("RSS: ".var_export($folders,1));
		return $folders;
	}
	
	function GetFolder($id) {
		debugLog("RSS: GetFolder(".$id.")");
		if (!isset($this->_feeds[$id])){
			return false;
		}

		$folder = new SyncFolder();
		$folder->serverid = $id;
		$folder->displayname = $id;

		$folder->parentid = "0";
		if ($id==$this->_config["RSS_INBOX_FEED"]){
			$folder->type = SYNC_FOLDER_TYPE_INBOX;
		}else{
			$folder->type = SYNC_FOLDER_TYPE_OTHER;
		}

		return $folder;
	}
	
	function StatFolder($id) {
		debugLog("RSS: StatFolder(".$id.")");
		$folder = $this->GetFolder($id);
		
		$stat = array();
		$stat["id"] = $id;
		$stat["parent"] = $folder->parentid;
		$stat["mod"] = $folder->displayname;
		
		return $stat;
	}

	function GetAttachmentData($attname) {
		debugLog("RSS: GetAttachmentData(".$attname.")");
		
		return false;
	}

	function StatMessage($folderid, $id) {
		debugLog("RSS: StatMessage");
		
		$message = $this->GetMessage($folderid, $id);

		if (!$message)
			return false;

		$entry = array();
		$entry["id"] = $id;
		$entry["flags"] = 1;
		$entry["mod"] = $id;
				
		return $entry;
	}
	
	function GetMessage($folderid, $id) {
		debugLog("RSS: GetMessage");
			
		$message = $this->findMessage($folderid, $id);
		if (!$message)
			return false;
		
		// beside the message, we need also the global RSS info
		$rss = $this->getRSSFeed($folderid, $this->_feeds[$folderid]);
		$rss = $rss["channel"];

		$output = new SyncMail();

		// body 
		if (isset($message["description"])){
			$output->body = strip_tags($message["description"]);
		}elseif (isset($message["title"])){
			$output->body = strip_tags($message["title"]);
		}elseif (isset($rss["description"])){
			$output->body = strip_tags($rss["description"]);
		}elseif (isset($rss["title"])){
			$output->body = strip_tags($rss["title"]);
		}else{
			$output->body = "";
		}

		// date
		if (isset($message["pubDate"])){
			$output->datereceived = strtotime($message["pubDate"]);
		}elseif (isset($rss["pubDate"])){
			$output->datereceived = strtotime($rss["pubDate"]);
		}elseif (isset($rss["lastBuildDate"])){
			$output->datereceived = strtotime($rss["lastBuildDate"]);
		}else{
			$output->datereceived = time();
		}

		$output->bodysize = strlen($output->body);
		$output->bodytruncated = 0;

		// subject
		if (isset($message["title"])){
			$output->subject = strip_tags($message["title"]);
		}elseif (isset($message["description"])){
			$output->subject = strip_tags($message["description"]);
		}elseif (isset($rss["title"])){
			$output->subject = strip_tags($rss["title"]);
		}elseif (isset($rss["description"])){
			$output->subject = strip_tags($rss["description"]);
		}else{
			$output->subject = "";
		}
		$output->subject = trim(str_replace(array("\n\r"),array(" ",""),substr($output->subject, 0, 255)));

		$output->messageclass = "IPM.Note";

		$output->to = $this->__username;
		if (isset($message["author"])){
			$output->from = strip_tags($message["author"]);
		}elseif (isset($rss["author"])){
			$output->from = strip_tags($rss["author"]);
		}elseif (isset($rss["webmaster"])){
			$output->from = strip_tags($rss["webmaster"]);
		}elseif (isset($rss["title"])){
			$output->from = strip_tags($rss["title"]);
		}else{
			$output->from = $folder_id;
		}

		$output->read = 0; // unread
		return $output;
	}
	
	function DeleteMessage($folderid, $id) {
		debugLog("RSS: DeleteMessage");
		return false;
	}
	
	function SetReadFlag($folderid, $id, $flags) {
		debugLog("RSS: SetReadFlag folder:".$folderid." id:".$id." flags:".$flags);

		return true;
	}
	
	function ChangeMessage($folderid, $id, $message) {
		debugLog("RSS: ChangeMessage");
		return false;
	}
	
	function MoveMessage($folderid, $id, $newfolderid) {
		debugLog("RSS: MoveMessage");
		return false;
	}


	// internal functions
	function getRSSFeed($name, $url){
		// search cache for rss feed
		$cache_file = false;
		$dh = opendir($this->_config["RSS_CACHE_DIR"]);
		while(($file=readdir($dh))!== false){
			if (substr($file,0,1)==".") // ignore files starting with '.' like .htaccess, but also the two directoried . and ..
				continue;

			$parts = explode(".", $file);
			if ($parts[0] == $name){
				if ($parts[1]>=(time()-($this->_config["RSS_EXPIRES"]*60))){
					$cache_file = $file;
				}else{
					// delete feeds that are expired
					unlink($this->_config["RSS_CACHE_DIR"]."/".$file);
				}
			}
		}

		if ($cache_file !== false){
			$rss = unserialize(file_get_contents($this->_config["RSS_CACHE_DIR"]."/".$cache_file));
		}else{
			// get rss feed
			$xml = file_get_contents($url);
			$unserializer = new XML_Unserializer(array("parseAttributes"=>true,"attributesArray"=>"attributes","forceEnum"=>array()));
			$unserializer->unserialize($xml);
			$rss = $unserializer->getUnserializedData();

			// store rss feed in cache
			if (is_writable($this->_config["RSS_CACHE_DIR"])){
				$fh = fopen($this->_config["RSS_CACHE_DIR"]."/".$name.".".time(), "w");
				fwrite($fh, serialize($rss));
				fclose($fh);
			}
		}
		return $rss;
	}

	function findMessage($feedname, $id)
	{
		debugLog("RSS: findMessage(".$feedname.", ".$id.")");

		// fetch rss feed
		if (!isset($this->_feeds[$feedname])){
			return false;
		}

		$rss = $this->getRSSFeed($feedname, $this->_feeds[$feedname]);
		$rss = $rss["channel"];

		if (!isset($rss["item"]))
			return false;

		foreach($rss["item"] as $item){
			if ($this->getMessageIdFromItem($item) == $id)
				return $item;
		}
		return false;
	}

	function getMessageIdFromItem($item)
	{
		$msg_id = false;
		$tags = array("guid", "link", "title", "description");
		
		foreach($tags as $tag){
			if (isset($item[$tag])){
				if (is_array($item[$tag])){
					$msg_id = $item[$tag]["_content"];
				}else{
					$msg_id = $item[$tag];
				}
			}
			if ($msg_id!=false){
				break;
			}
		}
		
		if ($msg_id==false){
			debugLog("RSS: can't generate ID for item: " . var_export($item, true));
			$msg_id = serialize($item);
		}

		return md5($msg_id);
	}

	function getConfigFileName()
	{
		return str_replace("%u", $this->_user, $this->_config["RSS_FEEDS_CONFIG"]);
	}
};


?>
