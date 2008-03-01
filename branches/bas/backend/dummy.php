<?
/***********************************************
* File      :   dummy.php
* Project   :   Z-Push
* Descr     :   This backend is for dummy
*               folders and logins.
*
* Created   :   01.03.2008
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/
include_once('diffbackend.php');

class BackendDummy extends BackendDiff {
	var $_config;
	var $_user;
	var $_devid;
	var $_protocolversion;
	
	function BackendDummy($config){
		$this->_config = $config;
	}
	
	function Logon($username, $domain, $password) {
		if(!isset($this->_config['DUMMY_LOGINS']))
			return true;
		
		if(is_array($this->_config['DUMMY_LOGINS'])){
			return (isset($this->_config['DUMMY_LOGINS'][$username]) && ($this->_config['DUMMY_LOGINS'][$username] == $password));
		}
		return false;
	}
	
	// completing protocol
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
		debugLog('Dummy::GetMessageList('.$folderid.')');
		$messages = array();
		return $messages;
	}
	
	function GetFolderList() {
		debugLog('Dummy::GetFolderList()');
		$folders = array();
		foreach($this->_config['DUMMY_FOLDERS'] as $n => $t){
			$folder = $this->StatFolder($n);
			$folders[] = $folder;
		}
		return $folders;
	}
	
	function GetFolder($id) {
		debugLog('Dummy::GetFolder('.$id.')');
		if(isset($this->_config['DUMMY_FOLDERS'][$id])){
			$folder = new SyncFolder();
			$folder->serverid = $id;
			$folder->parentid = "0";
			$folder->displayname = $id;
			$folder->type = $this->_config['DUMMY_FOLDERS'][$id];
			return $folder;
		}
		return false;
	}
	
	function StatFolder($id) {
		debugLog('Dummy::StatFolder('.$id.')');
		$folder = $this->GetFolder($id);
		
		$stat = array();
		$stat["id"] = $id;
		$stat["parent"] = $folder->parentid;
		$stat["mod"] = $folder->displayname;
		
		return $stat;
	}
	
	function GetAttachmentData($attname) {
		list($folderid, $id, $attachnum) = explode(":", $attname);
		return false;
	}
	
	function StatMessage($folderid, $id) {
		debugLog('Dummy::StatMessage('.$folderid.', '.$id.')');
		return false;
	}
	
	function GetMessage($folderid, $id, $truncsize) {
		debugLog('Dummy::GetMessage('.$folderid.', '.$id.', ..)');
		return false;
	}
	
	function DeleteMessage($folderid, $id) {
		return false;
	}
	
	function SetReadFlag($folderid, $id, $flags) {
		return false;
	}
	
	function ChangeMessage($folderid, $id, $message) {
		return false;
	}
	
};


?>
