<?
/***********************************************
* File      :   filestorage.php
* Project   :   Z-Push
* Descr     :   This backend is for file storage
*               directories.
*
* Created   :   01.03.2008
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/
include_once('diffbackend.php');

class BackendFileStorage extends BackendDiff {
	var $_config;
	var $_user;
	var $_devid;
	var $_protocolversion;
	
	function BackendFileStorage($config){
		$this->_config = $config;
	}
	
	function Logon($username, $domain, $password) {
		return true;
	}
	
	function Logoff() {
		return true;
	}
	
	function Setup($user, $devid, $protocolversion) {
		$this->_user = $user;
		$this->_devid = $devid;
		$this->_protocolversion = $protocolversion;
		
		return true;
	}
	
	function SendMail($rfc822, $forward = false, $reply = false, $parent = false) {
		debugLog('FileStorage::SendMail(..., '.$forward.', '.$reply.', '.$parent.')');
		
		$mobj = new Mail_mimeDecode($rfc822);
		$message = $mobj->decode(
				array('decode_headers' => true, 
					'decode_bodies' => true, 
					'include_bodies' => true, 
					'input' => $rfc822, 
					'crlf' => "\r\n", 
					'charset' => 'utf-8'));
					
		if($message->ctype_primary != "multipart" || $message->ctype_secondary != "mixed"){
			debugLog('FileStorage::SendMail not multipart/mixed');
			return false;
		}
		
		if(!isset($message->headers['subject']) || strtolower(substr(trim($message->headers['subject']),0,11)) != 'filestorage'){
			debugLog('FileStorage::SendMail subject not filestorage: '.$message->headers['subject']);
			return false;
		}
		
		$d = '/'.str_replace('\\', '/',substr(trim($message->headers['subject']),11)).'/';
		if(strpos($d,'/../')!==false)
			return true;
			
		debugLog('FileStorage::SendMail dir: '.$d);
		
		foreach($message->parts as $part) {
			if($part->ctype_primary == "text" || $part->ctype_primary == "multipart") {
				continue;
			}
			debugLog('FileStorage::SendMail attachment found');
			if(isset($part->ctype_parameters["name"]))
				$filename = $part->ctype_parameters["name"];
			else if(isset($part->d_parameters["name"]))
				$filename = $part->d_parameters["filename"];
			else
				return false;
			debugLog('FileStorage::SendMail saving: '.$filename);
			file_put_contents($this->getPath('root').$d.$filename, $part->body);
		}

		return true;
	}
	
	function GetWasteBasket() {
		return false;
	}
	
	function GetMessageList($folderid) {
		debugLog('FileStorage::GetMessageList('.$folderid.')');
		$messages = array();
		
		$dir = opendir($this->getPath($folderid));
		if(!$dir)
			return false;
		
		while($entry = readdir($dir)) {
			if($entry == '.' || $entry == '..' || is_dir($this->getPath($folderid) ."/".$entry))
				continue;
			
			$stat = stat($this->getPath($folderid) .'/'.$entry);
			if($stat === false)
				continue;
			
			$message = array();
			$message["id"] = $entry;
			$message["mod"] = $stat["mtime"];
			$message["flags"] = 1; // always 'read'
			
			$messages[] = $message;
		}
		
		return $messages;
	}
	
	function GetFolderList() {
		debugLog('FileStorage::GetFolderList()');
		$folders = array();
		$dirs = array('root');
		$i = 0;
		while($i < count($dirs)){
			$folder = $this->StatFolder($dirs[$i]);
			$folders[] = $folder;
			$path = $this->getPath($dirs[$i]);
			$dir = opendir($path);
			while($entry = readdir($dir)) {
				if($entry != '.' && $entry != '..' && is_dir($path.'/'.$entry)){
					$dirs[] = $dirs[$i].$this->_config['FILESTORAGE_DELIMITER'].$entry;
				}
			}
			closedir($dir);
			$i++;
		}
		
		return $folders;
	}
	
	function GetFolder($id) {
		debugLog('FileStorage::GetFolder('.$id.')');
		if(substr($id, 0, 4) != 'root')
			return false;
		
		$folder = new SyncFolder();
		$folder->serverid = $id;
		
		$f = explode($this->_config['FILESTORAGE_DELIMITER'], $id);
		if(count($f)==1){
			$folder->parentid = "0";
			$folder->displayname = $this->_config['FILESTORAGE_FOLDERNAME'];
		}else{
			$folder->displayname = array_pop($f);
			$folder->parentid = implode($this->_config['FILESTORAGE_DELIMITER'],$f);
		}
		$folder->type = SYNC_FOLDER_TYPE_OTHER;
		return $folder;
		
	}
	
	function StatFolder($id) {
		debugLog('FileStorage::StatFolder('.$id.')');
		$folder = $this->GetFolder($id);
		
		$stat = array();
		$stat["id"] = $id;
		$stat["parent"] = $folder->parentid;
		$stat["mod"] = $folder->displayname;
		
		return $stat;
	}
	
	function GetAttachmentData($attname) {
		list($folderid, $id) = unserialize(base64_decode($attname));
		if(!is_file($this->getPath($folderid) . '/' . $id))
			return false;
		$data = file_get_contents($this->getPath($folderid) . "/" . $id);
		print $data;
		return true;
	}
	
	function StatMessage($folderid, $id) {
		debugLog('FileStorage::StatMessage('.$folderid.', '.$id.')');
		
		$stat = stat($this->getPath($folderid) . "/" . $id);
		if($stat === false)
			return false;
			
		$message = array();
		$message["mod"] = $stat["mtime"];
		$message["id"] = $id;
		$message["flags"] = 1;
		
		return $message;
	}
	
	function GetMessage($folderid, $id, $truncsize) {
		debugLog('FileStorage::GetMessage('.$folderid.', '.$id.', ..)');
		if(!is_file($this->getPath($folderid) . '/' . $id))
			return false;

		$pi = pathinfo($this->getPath($folderid) . '/' . $id);
		$stat = stat($this->getPath($folderid) . '/' . $id);
		if($stat === false)
			return false;
		
		$message = new SyncMail();

		$message->subject = $id;
		$message->read = 1;
		$message->datereceived = $stat["mtime"];
		$message->displayto = null;
		$message->importance = null;
		$message->messageclass = "IPM.Note";
		$message->to = null;
		$message->cc = null;
		$message->from = null;
		$message->reply_to = null;
		if(is_array($this->_config['FILESTORAGE_BODYEXTS']) && in_array(strtolower($pi['extension']), $this->_config['FILESTORAGE_BODYEXTS'])){
			$message->bodysize = $stat['size'];
			$message->body = file_get_contents($this->getPath($folderid) . "/" . $id);
		}else{
			$message->bodysize = 0;
		}
		$message->attachments[0] = new SyncAttachment();
		$message->attachments[0]->attsize = $stat['size'];
		$message->attachments[0]->displayname = $id;
		$message->attachments[0]->attname = base64_encode(serialize(array($folderid, $id)));
		$message->attachments[0]->attmethod = 1;
		$message->attachments[0]->attoid = "";
		
		return $message;
	}
	
	function DeleteMessage($folderid, $id) {
		return unlink($this->getPath($folderid) . "/" . $id);
	}
	
	function SetReadFlag($folderid, $id, $flags) {
		return false;
	}
	
	function ChangeMessage($folderid, $id, $message) {
		return false;
	}
	
	function MoveMessage($folderid, $id, $newfolderid) {
		return rename($this->getPath($folderid). '/'.$id, $this->getPath($newfolderid). '/'.$id);
	}
	
	function ChangeFolder($parent, $id, $displayname, $type){
		if($parent == '0')
			return false;
		
		if($id){
			if(!rename($this->getPath($id), $this->getPath($parent). '/'.$displayname))
				return false;
		}else{
			if(!mkdir($this->getPath($parent). '/'.$displayname))
				return false;
		}
		
		return $this->StatFolder($parent.$this->_config['FILESTORAGE_DELIMITER'].$displayname);
	}
	
	function getPath($folderid) {
		if($folderid == 'root'){
			return str_replace('%u', $this->_user, $this->_config['FILESTORAGE_DIR']);
		}else if(substr($folderid, 0, 4)=='root'){
			return str_replace('%u', $this->_user, $this->_config['FILESTORAGE_DIR']).str_replace($this->_config['FILESTORAGE_DELIMITER'], '/',substr($folderid,4));
		}
		return false;
	}
};


?>
