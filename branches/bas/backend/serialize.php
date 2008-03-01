<?
/***********************************************
* File      :   serialize.php
* Project   :   Z-Push
* Descr     :   This backend is for serialized
*               storage folders.
*
* Created   :   01.03.2008
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/
include_once('diffbackend.php');

class BackendSerialize extends BackendDiff {
	var $_config;
	var $_user;
	var $_devid;
	var $_protocolversion;
	
function BackendSerialize($config){
	$this->_config = $config;
		if(empty($this->_config['SERIALIZE_DELIMITER'])){
			$this->_config['SERIALIZE_DELIMITER'] = '/';
		}
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
		return false;
	}
	
	function GetWasteBasket() {
		return false;
	}
	
	function GetMessageList($folderid) {
		debugLog('Serialize::GetMessageList('.$folderid.')');
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
		debugLog('Serialize::GetFolderList()');
		$folders = array();
		$parent = false;
		$path = $this->getPath('0');
		$i = -1;
		do{
			if($i > -1){
				$path = $this->getPath($folders[$i]['id']);
				$parent = $folders[$i]['id'];
			}
			$dir = opendir($path);
			while($entry = readdir($dir)) {
				if($entry != '.' && $entry != '..' && is_dir($path.'/'.$entry)){
					if($parent){
						$d = $parent.$this->_config['SERIALIZE_DELIMITER'].$entry;
					}else{
						$d = $entry;
					}
					$folder = $this->StatFolder($d);
					$folders[] = $folder;
				}
			}
			closedir($dir);
			$i++;
		}while($i < count($folders));
		
		return $folders;
	}
	
	function GetFolder($id) {
		debugLog('Serialize::GetFolder('.$id.')');

		$folder = new SyncFolder();
		$folder->serverid = $id;
		$folder->type = SYNC_FOLDER_TYPE_OTHER;
		
		$f = explode($this->_config['SERIALIZE_DELIMITER'], $id);
		if(count($f)==1){
			$folder->parentid = "0";
			$folder->displayname = $id;
		}else{
			$folder->displayname = array_pop($f);
			$folder->parentid = implode($this->_config['SERIALIZE_DELIMITER'],$f);
		}
		$lid = strtolower($id);
		
		foreach($this->_config['SERIALIZE_FOLDERS'] as $f => $n){
			if(is_array($n)){
				foreach($n as $m){
					if(strtolower($m) == $lid){
						$folder->type = $f;
					}
				}
			}else if(strtolower($n) == $lid){
				$folder->type = $f;
			}
		}
		return $folder;
		
	}
	
	function StatFolder($id) {
		debugLog('Serialize::StatFolder('.$id.')');
		$folder = $this->GetFolder($id);
		
		$stat = array();
		$stat["id"] = $id;
		$stat["parent"] = $folder->parentid;
		$stat["mod"] = $folder->displayname;
		
		return $stat;
	}
	
	function GetAttachmentData($attname) {
		return false;
	}
	
	function StatMessage($folderid, $id) {
		debugLog('Serialize::StatMessage('.$folderid.', '.$id.')');
		
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
		debugLog('Serialize::GetMessage('.$folderid.', '.$id.', ..)');
		
		$data = file_get_contents($this->getPath($folderid) . '/' . $id);
		if($data === false)
			return;
		
		$message = unserialize($data);

		return $message;
	}
	
	function DeleteMessage($folderid, $id) {
		return unlink($this->getPath($folderid) . '/' . $id);
	}
	
	function SetReadFlag($folderid, $id, $flags) {
		return false;
	}
	
	function ChangeMessage($folderid, $id, $message) {
		if($id === false){
			$id = md5(mt_rand().mt_rand().mt_rand().mt_rand());
		}
		$data = serialize($message);
		file_put_contents($this->getPath($folderid) . '/' . $id, $data);
		
		return $id;
	}
	
	function MoveMessage($folderid, $id, $newfolderid) {
		return rename($this->getPath($folderid). '/'.$id, $this->getPath($newfolderid). '/'.$id);
	}
	
	function ChangeFolder($parent, $id, $displayname, $type){
		if($id){
			if(!rename($this->getPath($id), $this->getPath($parent). '/'.$displayname))
				return false;
		}else{
			if(!mkdir($this->getPath($parent). '/'.$displayname))
				return false;
		}
		
		return $this->StatFolder($parent.$this->_config['SERIALIZE_DELIMITER'].$displayname);
	}
	
	function getPath($folderid) {
		if($folderid == '0'){
			return str_replace('%u', $this->_user, $this->_config['SERIALIZE_DIR']);
		}
		return str_replace('%u', $this->_user, $this->_config['SERIALIZE_DIR']).'/'.str_replace($this->_config['SERIALIZE_DELIMITER'], '/',$folderid);
		return false;
	}
};


?>
