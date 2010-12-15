<?php

require_once("HTTP/Request.php");
require_once("Services/JSON.php");

include_once("backend.php");
include_once("proto.php");


class BackendOX extends Backend {
	public function __construct() {
		$this->json = new Services_JSON(SERVICES_JSON_LOOSE_TYPE);
	}

	public function SendRequest($data) {
		if (defined('OX_DEBUG') && OX_DEBUG == 1) {		
			ob_start();
			var_dump($data);
			$rdata = ob_get_contents();
			ob_end_clean();
			debugLog("request: $rdata");
		}

		$request = new HTTP_Request(OX_URL . "/multiple", array("method" => "PUT"));

		foreach ($this->cookies as $cookie) {
			$request->addCookie($cookie["name"], $cookie["value"]);
		}

		$request->addQueryString("session", $this->session);
		//$request->addHeader("Content-Type", "text/javascript");
		
		$request->setBody("[" . $this->json->encodeUnsafe($data) . "]");

		try {
			$request->sendRequest();

			if ($request->getResponseCode() == 200) {
				$reply = $this->json->decode($request->getResponseBody());

				if (defined('OX_DEBUG') && OX_DEBUG == 1) {		
					ob_start();
					var_dump($reply);
					$rdata = ob_get_contents();
					ob_end_clean();
					debugLog("reply: $rdata");
				}
			
				return $reply[0];
			}
			else {
				return false;
			}
		}
		catch (HTTP_Exception $e) {
			echo $e->getMessage();
			return false;
		}
	}

	private function SetTimezone() {
		$request = new HTTP_Request(OX_URL . "/config/timezone", array("method" => "GET"));

		foreach ($this->cookies as $cookie) {
			$request->addCookie($cookie["name"], $cookie["value"]);
		}

		$request->addQueryString("session", $this->session);

		try {
			$request->sendRequest();

			if ($request->getResponseCode() == 200) {
				$reply = $this->json->decode($request->getResponseBody());
				$timezone = $reply["data"];

				date_default_timezone_set($timezone);
				
				$this->timezone = $timezone;
				$this->offset = date("Z");
				
				//date_default_timezone_set("UTC");
			}
			else {
				return false;
			}
		}
		catch (HTTP_Exception $e) {
			echo $e->getMessage();
			return false;
		}
	}

    public function GetExporter($folderid = false) {
		debugLog("BackendOX::GetExporter");
        return new ExporterOX($this, $folderid);
    }

    public function GetHierarchyImporter() {
		debugLog("BackendOX::GetHierarchyImporter");
        return new HierarchyImporterOX($this);
    }

    public function GetContentsImporter($folderid) {
		debugLog("BackendOX::GetContentsImporter");
        return new ContentsImporterOX($this, $folderid);
    }

    public function GetWasteBasket() {
		debugLog("BackendOX::GetWasteBasket");
        return false;
    }

	public function Logon($username, $domain, $password) {
		debugLog("BackendOX::Logon ($username, $password)");

		$request = new HTTP_Request(OX_URL . "/login?action=login", array("method" => "POST"));

		$request->addPostData("name", $username);
		$request->addPostData("password", $password);

		try {
			$request->sendRequest();

			if ($request->getResponseCode() == 200) {
				$reply = $this->json->decode($request->getResponseBody());
				
				if (isset($reply["session"])) {	
					$this->session = $reply["session"];
					$this->cookies = $request->getResponseCookies();

					return true;
				}
				else 
					return false;
			}
			else {
				return false;
			}
		}
		catch (HTTP_Exception $e) {
			return false;
		}
    }

    public function Setup($user, $devid, $protocol) {
		debugLog("BackendOX::Setup");

		$this->user = $user;
        $this->devid = $devid;
		$this->protocol = $protocol;

		$this->SetTimezone();
		debugLog("settimezone");

        return true;
    }

	public function Logoff() {
		debugLog("BackendOX::Logoff");

		$request = new HTTP_Request(OX_URL . "/login?action=logout", array("method" => "GET"));

		$request->addPostData("session", $this->session);

		try {
			$request->sendRequest();

			if ($request->getResponseCode() == 200) {
				return true;
			}
			else {
				return false;
			}
		}
		catch (HTTP_Exception $e) {
			return false;
		}
    }

	public function GetHierarchy() {
		debugLog("BackendOX::GetHierarchy");

		return false;
    }

    public function SendMail($rfc822, $forward = false, $reply = false, $parent = false) {
		debugLog("BackendOX::SendMail");
        return false;
    }
};


class ExporterOX {
	private $syncstate;
	private $backend;
	private $folderid;

    function __construct($backend, $folderid) {
        $this->backend = $backend;
		$this->folderid = $folderid;
    }

    function Config(&$importer, $folderid, $restrict, $syncstate, $flags, $truncation) {
		debugLog("ExporterOX::Config");

        $this->importer = &$importer;
        $this->restrict = $restrict;
        $this->syncstate = ($syncstate ? unserialize($syncstate) : array());
        $this->flags = $flags;
		$this->truncation = $truncation;
	}

	private function GetFolders($folderid = false) {
		debugLog("ExporterOX::GetFolders");

		$result = array();

		if ($folderid == false)
			$request = array("module" => "folders", "action" => "root", "columns" => "1,300,301,302,304");
		else
			$request = array("module" => "folders", "action" => "list", "columns" => "1,300,301,302,304", "parent" => $folderid);

		$reply = $this->backend->SendRequest($request);
		
		if ($reply != false) {
			foreach ($reply["data"] as $tfolder) {
				$folder = new SyncFolder();
				
				$folder->serverid = (string)$tfolder[0];
				$folder->parentid = "0";
				$folder->displayname = $tfolder[1];

				if ($tfolder[2] == "contacts")
					$folder->type = SYNC_FOLDER_TYPE_CONTACT;
				else if ($tfolder[2] == "calendar")
					$folder->type = SYNC_FOLDER_TYPE_APPOINTMENT;
				else if ($tfolder[2] == "tasks")
					$folder->type = SYNC_FOLDER_TYPE_TASK;
				else if ($tfolder[2] == "mail")
					$folder->type = SYNC_FOLDER_TYPE_INBOX;
				else
					$folder->type = SYNC_FOLDER_TYPE_OTHER;

				
				if (!defined('OX_FILTER_FOLDERTYPES') && defined('OX_FILTER_FOLDERIDS') && in_array($tfolder[0], explode(",", OX_FILTER_FOLDERIDS)))
					$result[] = $folder;
				else if (!defined('OX_FILTER_FOLDERIDS') && defined('OX_FILTER_FOLDERTYPES') && in_array($tfolder[3], explode(",", OX_FILTER_FOLDERTYPES)))
					$result[] = $folder;
				else if (defined('OX_FILTER_FOLDERIDS') && defined('OX_FILTER_FOLDERTYPES') && in_array($tfolder[0], explode(",", OX_FILTER_FOLDERIDS)) && in_array($tfolder[3], explode(",", OX_FILTER_FOLDERTYPES)))
					$result[] = $folder;
				else
					$result[] = $folder;


				if ($tfolder[4] == true) 
					$result = array_merge($result, $this->GetFolders($tfolder[0]));
			}	
		}

		return $result;
	}

	private function GetFolderType() {
		debugLog("ExporterOX::GetFolderType");

		$request = array("module" => "folders", "action" => "get", "columns" => "301", "id" => $this->folderid);
		$reply = $this->backend->SendRequest($request);

		if ($reply != false)
			return $reply["data"]["module"];	
	}

	private function ConvertContact($mapping, $data) {
		$contact = new SyncContact();

		$c = 0;
		foreach ($mapping as $key => $value) {
			if ($value != null && !empty($data[$c]))
				$contact->$value = $data[$c];
			$c++;	
		}

		if (isset($contact->birthday))
			$contact->birthday = $contact->birthday / 1000;
		
		if (isset($contact->anniversary))
			$contact->birthday = $contact->anniversary / 1000;

		if (isset($contact->body)) {
			$contact->bodysize = strlen($contact->body);
			$contact->bodytruncated = 0;
		}

		return $contact;
	}

	private function ConvertTask($mapping, $data) {
		$task = new SyncTask();

		$c = 0;
		foreach ($mapping as $key => $value) {
			if ($value != null & !empty($data[$c]))
				$task->$value = $data[$c];
			$c++;	
		}
		
		if (isset($task->startdate)) {
			$task->startdate = $task->startdate / 1000;
			$task->utcstartdate = $task->startdate / 1000 - $this->backend->offset;
		}
		
		if (isset($task->duedate)) {
			$task->duedate = $task->duedate / 1000;
			$task->utcduedate = $task->duedate / 1000 - $this->backend->offset;
		}

		if (isset($task->remindertime)) {
			$task->reminderset = "1";
			$task->remindertime = $task->remindertime / 1000 - $this->backend->offset;
		}

		if ($data[7] == 3) {
			$task->complete = "1";
			$task->datecompleted = time() - $this->backend->offset;
		}

		if (isset($task->importance))
			$task->importance--;

		return $task;
	}

	private function ConvertAppointment($mapping, $data, $exceptions = false) {
		$appointment = new SyncAppointment();

		$c = 0;
		foreach ($mapping as $key => $value) {
			if ($value != null && !empty($data[$c]))
				$appointment->$value = $data[$c];
			$c++;	
		}

		// Convert all the dates to UTC, so that every device may apply it's 
		// timezone.
		// For a description of this struct see:
		// http://msdn.microsoft.com/en-us/library/ms725481.aspx
		$appointment->timezone = base64_encode(pack("la64vvvvvvvv" . "la64vvvvvvvv" . "l",
									0,
									"",
									0, 0, 0, 0, 0, 0, 0, 0,
									0,  
									"",
									0, 0, 0, 0, 0, 0, 0, 0,
									0));

		$appointment->dtstamp = $data[11] / 1000 - $this->backend->offset;
		$appointment->starttime = $appointment->starttime / 1000 - $this->backend->offset;
		$appointment->endtime = $appointment->endtime / 1000 - $this->backend->offset;

		if ($appointment->busystatus != null) {
			switch ($appointment->busystatus) {
				case 1:
					$appointment->busystatus = 2;
					break;
				case 2:
					$appointment->busystatus = 1;
					break;
				case 4:
					$appointment->busystatus = 0;
					break;
			}
		}

		if ($data[12] > 0)
			$appointment->exceptionstarttime = $data[12] / 1000 - $this->backend->offset;

		if ($data[13] > 0) {
			$recurrence = new SyncRecurrence();

			$recurrence->type = $data[13] ? (string)$data[13]-1 : null;
			if ($recurrence->type == "3")
				$recurrent->type = "5";

			$recurrence->dayofweek = $data[15] ? (string)$data[15] : null;
			$recurrence->dayofmonth = $data[16] ? (string)$data[16] : null;
			$recurrence->monthofyear = $data[17] ? (string)$data[17]+1 : null;
			$recurrence->interval = $data[18] ? (string)$data[18] : null;
			$recurrence->until = $data[19] ? $data[19] / 1000 - $this->backend->offset : null;
			$recurrence->occurrences = $data[20] ? (string)$data[20] : null;

			if (!isset($recurrence->until) && !isset($recurrence->occurrences))
				$recurrence->until = mktime(23, 59, 59, 12, 31, date("y") + 10);

			$appointment->recurrence = $recurrence;
		}

		if (is_array($data[14])) {
			$appointment->exceptions = array();

			foreach ($data[14] as $time) {
				$deleted = new SyncAppointment();

				$deleted->deleted = "1";
				$deleted->exceptionstarttime = $time / 1000;

				$appointment->exceptions[] = $deleted;
			}
		}

		if ($exceptions != false) {
			if (!isset($appointment->exceptions))
				$appointment->exceptions = array();

			foreach ($exceptions as $exception) {
				$exception->exceptionstarttime = mktime(date("H", $appointment->starttime), date("i", $appointment->starttime), date("s", $appointment->starttime), date("n", $exception->starttime), date("j", $exception->starttime), date("Y", $exception->starttime));
				$appointment->exceptions[] = $exception;
			}
		}

		return $appointment;
	}

	private function GetItems() {
		debugLog("ExporterOX::GetItems");
		
		$this->syncstate["items"] = array();
		$this->syncstate["items_deleted"] = array();

		$foldertype = $this->GetFolderType();

		if (!$foldertype)
			return false;

		if ($foldertype == "contacts") {
			// Original
			//$mapping = array(1 => null, 501 => "firstname", 502 => "lastname", 503 => "middlename", 504 => "suffix", 505 => "title", 506 => "homestreet", 507 => "homepostalcode", 508 => "homecity", 509 => "homestate", 510 => "homecountry", 511 => "birthday", 513 => "children", 515 => "nickname", 516 => "spouse", 517 => "anniversary", 518 => "body", 519 => "department", 520 => "jobtitle", 522 => "officelocation", 523 => "businessstreet", 525 => "businesspostalcode", 526 => "businesscity", 527 => "businessstate", 528 => "businesscountry", 536 => "managername", 537 => "assistantname", 538 => "otherstreet", 539 => "othercity", 540 => "otherpostalcode", 541 => "othercountry", 542 => "businessphonenumber", 543 => "business2phonenumber", 544 => "businessfaxnumber", 546 => "carphonenumber", 547 => "companymainphone", 548 => "homephonenumber", 549 => "home2phonenumber", 550 => "homefaxnumber", 551 => "mobilephonenumber", 555 => "email1address", 556 => "email2address", 557 => "email3address", 558 => "webpage", 560 => "pagernumber", 562 => "radiophonenumber", 565 => "imaddress", 566 => "imaddress2", 568 => "assistnamephonenumber", 569 => "companyname", 598 => "otherstate", 599 => "fileas");
			// Nokia
			$mapping = array(1 => null, 501 => "firstname", 502 => "lastname", 503 => "middlename", 504 => "suffix", 505 => "title", 506 => "homestreet", 507 => "homepostalcode", 508 => "homecity", 509 => "homestate", 510 => "homecountry", 511 => "birthday", 513 => "children", 515 => "nickname", 516 => "spouse", 517 => "anniversary", 518 => "body", 519 => "department", 520 => "jobtitle", 522 => "officelocation", 523 => "businessstreet", 525 => "businesspostalcode", 526 => "businesscity", 527 => "businessstate", 528 => "businesscountry", 536 => "managername", 537 => "assistantname", 538 => "otherstreet", 539 => "othercity", 540 => "otherpostalcode", 541 => "othercountry", 542 => "businessphonenumber", 544 => "businessfaxnumber", 546 => "carphonenumber", 547 => "companymainphone", 548 => "homephonenumber", 550 => "homefaxnumber", 551 => "business2phonenumber", 552 => "mobilephonenumber", 553 => "home2phonenumber", 555 => "email2address", 556 => "email3address", 557 => "email1address", 558 => "webpage", 560 => "pagernumber", 562 => "radiophonenumber", 565 => "imaddress", 566 => "imaddress2", 568 => "assistnamephonenumber", 569 => "companyname", 598 => "otherstate", 599 => "fileas");

			$columns = implode(",", array_keys($mapping));

			$requesta = array("module" => "contacts", "action" => "all", "columns" => "1", "folder" => $this->folderid);
			$requestu = array("module" => "contacts", "action" => "updates", "columns" => $columns, "folder" => $this->folderid, "timestamp" => (string)$this->syncstate[$this->folderid]["lastmod"]);
		}
		else if ($foldertype == "tasks") {
			// TODO: recurrence, 
			$mapping = array(1 => null, 200 => "subject", 201 => "startdate", 202 => "duedate", 203 => "body", 204 => "remindertime", 309 => "importance", 300 => null);

			$columns = implode(",", array_keys($mapping));
			
			$requesta = array("module" => "tasks", "action" => "all", "columns" => "1", "folder" => $this->folderid);
			$requestu = array("module" => "tasks", "action" => "updates", "columns" => $columns, "folder" => $this->folderid, "timestamp" => (string)$this->syncstate[$this->folderid]["lastmod"]);
		}
		else if ($foldertype == "calendar") {
			$mapping = array(1 => null, 206 => null, 200 => "subject", 201 => "starttime", 202 => "endtime", 203 => "body", 204 => "reminder", 223 => "uid", 400 => "location", 401 => "alldayevent", 402 => "busystatus", 4 => null, 208 => null, 209 => null, 211 => null, 212 => null, 213 => null, 214 => null, 215 => null, 216 => null, 222 => null);
			
			$columns = implode(",", array_keys($mapping));
			
			$requesta = array("module" => "calendar", "action" => "all", "columns" => "1", "folder" => $this->folderid, "start" => "0", "end" => mktime(0,0,0,31,12,date("y")+20) * 1000, "recurrence_master" => true);
			$requestu = array("module" => "calendar", "action" => "updates", "columns" => $columns, "folder" => $this->folderid, "timestamp" => (string)$this->syncstate[$this->folderid]["lastmod"], "recurrence_master" => true, "ignore" => "deleted");
		}
		else
			return false;

	
		$replya = $this->backend->SendRequest($requesta);
		$replyu = $this->backend->SendRequest($requestu);

		if ($replya != false && $replyu != false) {
			if (count($replyu["data"]) > 0) {
				if ($foldertype == "calendar") {
					$exceptions = array();

					foreach ($replyu["data"] as $titem) {
						if ($titem[0] != $titem[1]) {
							if (!isset($exceptions[$titem[1]]))
								$exceptions[$titem[1]] = array();

							$exceptions[$titem[1]][] = $this->ConvertAppointment($mapping, $titem);
						}
					}
					
					foreach ($replyu["data"] as $titem) {
						$item = array();

						if ($titem[0] == $titem[1]) {
							$item["id"] = $titem[0];
							$item["data"] = $this->ConvertAppointment($mapping, $titem, array_key_exists($titem[0], $exceptions) ? $exceptions[$titem[0]] : false);

							$this->syncstate["items"][] = $item;
							$this->syncstate["items_seen"][$this->folderid][] = $titem[0];
						}
					}
				}
				else {
					foreach ($replyu["data"] as $titem) {
						$item = array();
						
						$item["id"] = $titem[0];

						if ($foldertype == "contacts")
							$item["data"] = $this->ConvertContact($mapping, $titem);
						if ($foldertype == "tasks")
							$item["data"] = $this->ConvertTask($mapping, $titem);

						$this->syncstate["items"][] = $item;
						$this->syncstate["items_seen"][$this->folderid][] = $titem[0];
					}
				}
			}

			if ($this->syncstate[$this->folderid]["lastmod"] != 0) {
				if (count($replya["data"] > 0)) {
					$all = array();

					foreach ($replya["data"] as $titem) {
						$all[] = $titem[0];
					}

					$deleted = array_diff($this->syncstate["items_seen"][$this->folderid], $all);

					if (count($deleted) > 0) {
						foreach ($deleted as $titem) {
							$item = array();
		
							$item["id"] = $titem;
		
							$this->syncstate["items_deleted"][] = $item;
						}

						$this->syncstate["items_seen"][$this->folderid] = array_diff($this->syncstate["items_seen"][$this->folderid], $deleted);
					}
				}
			}
			
			$this->syncstate[$this->folderid]["lastmod"] = $replyu["timestamp"];
		}
	}

    function Synchronize() {
		debugLog("ExporterOX::Synchronize");

        if (!$this->importer)
            return false;

		if ($this->folderid == false) {
			if (isset($this->syncstate["folders"])) {
				$folders = $this->GetFolders();
				$newfolders = array_diff($this->syncstate["folders"], $folders);

				if (count($newfolders) > 0) {
					foreach ($newfolders as $folder) {
						$this->importer->ImportFolderChange($folder);
					}
				}
				
				$this->syncstate["folders"] = $folders;

                return true;
			} 
			else {
				$folders = $this->GetFolders();

				if (count($folders) > 0) {
					foreach ($folders as $folder) {
						$this->importer->ImportFolderChange($folder);
					}

					$this->syncstate["folders"] = $folders;

					return true;
				}
				else {
					// Needed?
					return false;
				}
            }
		} 
		else {
			if (count($this->syncstate["items"]) > 0) {
				$item = array_pop($this->syncstate["items"]);

				$this->importer->ImportMessageChange($item["id"], $item["data"]);
			}
			else if (count($this->syncstate["items_deleted"]) > 0) {
				$item = array_pop($this->syncstate["items_deleted"]);

				$this->importer->ImportMessageDeletion($item["id"]);
			}
			else {
				unset($this->syncstate["items"]);
				unset($this->syncstate["items_deleted"]);

				// This is required to make it STOP after the last item. 
				// Otherwise it will repeatedly make calls to Synchronize, and 
				// doesn't care about the GetChangeCount information.
                return true;
            }
		}

		// This is needed, otherwise clients will only sync 1 item per call to 
		// synchronize and probably fail.
		return array();
	}

    function GetState() {
		debugLog("ExporterOX::GetState");
        return serialize($this->syncstate);
    }

    function GetChangeCount() {
		debugLog("ExporterOX::GetChangeCount");

		if ($this->folderid != false) {
			if (!isset($this->syncstate["items"]) && !isset($this->syncstate["items_deleted"])) {
				if (!isset($this->syncstate[$this->folderid]["lastmod"]))
					$this->syncstate[$this->folderid]["lastmod"] = 0;

				$this->GetItems();
			}

			if (isset($this->syncstate["items"]) || isset($this->syncstate["items_deleted"])) {
				$result = 0;

				if (isset($this->syncstate["items"]))
					$result += count($this->syncstate["items"]);

				if (isset($this->syncstate["items_deleted"]))
					$result += count($this->syncstate["items_deleted"]);

				return $result;
			}
		}
		else
			return 0;
    }
};


class HierarchyImporterOX {
    function __construct($backend) {
        $this->backend = $backend;
    }    

    function Config($state) {
        debugLog("HierarchyImporterOX::Config");   
        $this->syncstate = unserialize($state);
    }

    function ImportFolderChange($folder) {
        debugLog("HierarchyImporterOX::ImportFolderChange");   
    }

    function ImportFolderDeletion($folder) {
        debugLog("HierarchyImporterOX::ImportFolderDeletion"); 
    }

    function GetState() {
        debugLog("HierarchyImporterOX::GetState");   
        return serialize($this->syncstate);
    }
};


class ContentsImporterOX {
    function __construct($backend, $folderid) {
        $this->backend = $backend;
		$this->folderid = $folderid;
    }

    function Config($state, $flags = 0) {
		debugLog("ContentsImporterOX::Config");   

        $this->syncstate = unserialize($state);
		$this->flags = $flags;
    }
	
	private function GetFolderType() {
		debugLog("ExporterOX::GetFolderType");

		$request = array("module" => "folders", "action" => "get", "columns" => "301", "id" => $this->folderid);
		$reply = $this->backend->SendRequest($request);

		if ($reply != false)
			return $reply["data"]["module"];	
	}

	private function ConvertContact($mapping, $contact) {
		$data = array();

		foreach ($mapping as $key => $value)
			if ($contact->$key != null)
				$data[$value] = $contact->$key;

		if (isset($data["birthday"]))
			$data["birthday"] = $data["birthday"] * 1000;
		
		if (isset($data["anniversary"]))
			$data["anniversary"] = $data["anniversary"] * 1000;
		
		return $data;
	}

	private function ConvertTask($mapping, $task) {
		$data = array();

		foreach ($mapping as $key => $value)
			if ($task->$key != null)
				$data[$value] = $task->$key;

		if (!empty($data["start_date"])) 
			$data["start_date"] = ($data["start_date"] + $this->backend->offset) * 1000;
		
		if (!empty($data["end_date"]))
			$data["end_date"] = ($data["end_date"] + $this->backend->offset) * 1000;

		if (!empty($data["alarm"]))
			$data["alarm"] = ($data["alarm"] + $this->backend->offset) * 1000;
		else
			unset($data["alarm"]);

		if (!empty($task->complete)) {
			$data["status"] = "3";
			$data["percent_completed"] = "100";
		}

		if (isset($data["priority"]))
			$data["priority"]++;
		
		return $data;
	}

	private function ConvertAppointment($mapping, $appointment) {
		$data = array();

		foreach ($mapping as $key => $value)
			if ($appointment->$key != null)
				$data[$value] = $appointment->$key;

		$data["start_date"] = ($data["start_date"] + $this->backend->offset) * 1000;
		$data["end_date"] = ($data["end_date"] + $this->backend->offset) * 1000;

		if (isset($data["full_time"]))
			$data["full_time"] = "true";

		if (isset($data["shown_as"])) {
			switch ($data["shown_as"]) {
				case 0:
					$data["shown_as"] = 4;
					break;
				case 1:
					$data["shown_as"] = 2;
					break;
				case 2:
					$data["shown_as"] = 1;
					break;
			}
		}
		else
			$data["shown_as"] = 1;

		if ($appointment->recurrence != null) {
			$data["recurrence_type"] = $appointment->recurrence->type+1;
			if ($data["recurrence_type"] == 6)
				$data["recurrence_type"] = 4;

			if ($appointment->recurrence->dayofweek != null)
				$data["days"] = $appointment->recurrence->dayofweek;

			if ($appointment->recurrence->dayofmonth != null)
				$data["day_in_month"] = $appointment->recurrence->dayofmonth;

			if ($appointment->recurrence->monthofyear != null)
				$data["month"] = $appointment->recurrence->monthofyear-1;

			if ($appointment->recurrence->interval != null)
				$data["interval"] = $appointment->recurrence->interval;
			
			if ($appointment->recurrence->until != null)
				$data["until"] = ($appointment->recurrence->until + $this->backend->offset) * 1000;

			if ($appointment->recurrence->occurrences != null)
				$data["occurrences"] = $appointment->recurrence->occurrences;
		}

		return $data;
	}

	function GetRecurrenceInfo($id, $starttime) {
        $request = array("module" => "calendar", "action" => "all", "columns" => "1,201,206,207", "start" => mktime(0, 0, 0, date("n", $starttime / 1000), date("j", $starttime / 1000), date("Y", $starttime / 1000)) * 1000, "end" => mktime(23,59,59, date("n", $starttime / 1000), date("j", $starttime / 1000), date("Y", $starttime / 1000)) * 1000, "folder" => $this->folderid, "recurrence_master" => false); 

        $reply = $this->backend->SendRequest($request);

        if ($reply != false) 
            foreach ($reply["data"] as $treply) {
                if ($treply[2] == $id)
                    return array($treply[0], $treply[3]);
            }   
       
        return false;
	}


    function ImportMessageChange($id, $message) {
		debugLog("ContentsImporterOX::ImportMessageChange");

		$foldertype = $this->GetFolderType();

		if (empty($foldertype))
			return false;

		if ($foldertype == "calendar") {
			$mapping = array("subject" => "title", "starttime" => "start_date", "endtime" => "end_date", "body" => "note", "reminder" => "alarm", "location" => "location", "alldayevent" => "full_time", "busystatus" => "shown_as");

			$data = $this->ConvertAppointment($mapping, $message);

			if (empty($id)) {
				$data["folder_id"] = $this->folderid;

				$request = array("module" => "calendar", "action" => "new", "folder" => "$this->folderid", "data" => $data);
			}
			else
				$request = array("module" => "calendar", "action" => "update", "timestamp" => (time() + $this->backend->offset) * 1000, "folder" => "$this->folderid", "id" => "$id", "data" => $data);

			$reply = $this->backend->SendRequest($request);

			if ($reply == false)
				return false;
			
			if (empty($id))
				$tid = $reply["data"]["id"];
			else
				$tid = $id;

			foreach ($message->exceptions as $exception) {
				$einfo = $this->GetRecurrenceInfo($tid, ($exception->exceptionstarttime + $this->backend->offset) * 1000);

				if ($einfo != false) {
					if ($einfo[0] != $tid) {
						if ($exception->deleted == 1)	
							$request = array("module" => "calendar", "action" => "delete", "timestamp" => (time() + $this->backend->offset) * 1000, "data" => array("folder" => "$this->folderid", "id" => $einfo[0]));
						else {
							$data = $this->ConvertAppointment($mapping, $exception);
							$request = array("module" => "calendar", "action" => "update", "timestamp" => (time() + $this->backend->offset) * 1000, "folder" => "$this->folderid", "id" => $einfo[0], "data" => $data);
						}

						$reply = $this->backend->SendRequest($request);

						if ($reply == false)
							return false;
					}
					else {
						if ($exception->deleted == 1)	
							$request = array("module" => "calendar", "action" => "delete", "timestamp" => (time() + $this->backend->offset) * 1000, "data" => array("folder" => $this->folderid, "id" => $tid, "recurrence_position" => $einfo[1]));
						else {
							$request = array("module" => "calendar", "action" => "update", "timestamp" => (time() + $this->backend->offset) * 1000, "folder" => $this->folderid,"id" => $tid, "data" => array("folder_id" => $this->folderid, "id" => $tid, "recurrence_position" => $einfo[1], "recurrence_type" => 0));

							$request["data"] = array_merge($request["data"], $this->ConvertAppointment($mapping, $exception));
						}

						$reply = $this->backend->SendRequest($request);

						if ($reply == false)
							return false;
					}
				}	
			}

			if (empty($id))
				return $tid;
			else
				return true;
		}
		else if ($foldertype == "contacts") {
			// Original
			//$mapping = array("firstname" => "first_name", "lastname" => "last_name", "middlename" => "second_name", "suffix" => "suffix", "title" => "title",  "homestreet" => "street_home", "homepostalcode" => "postal_code_home", "homecity" => "city_home" , "homestate" => "state_home", "homecountry" => "country_home", "birthday" => "birthday", "children" => "number_of_children", "nickname" => "nickname", "spouse" => "spouse_name", "anniversary" => "anniversary", "body" => "note", "department" => "department", "job_title" => "position", "officelocation" => "room_number", "businessstreet" => "street_business", "businesspostalcode" => "postal_code_business", "businesscity" => "city_business", "businessstate" => "state_business", "businesscountry" => "country_business", "managername" => "manager_name", "assistantname" => "assistant_name", "otherstreet" => "street_other", "othercity" => "city_other", "otherpostalcode" => "postal_code_other", "othercountry" => "country_other", "businessphonenumber" => "telephone_business1", "business2phonenumber" => "telephone_business2", "businessfaxnumber" => "fax_business", "carphonenumber" => "telephone_car", "companymainphone" => "telephone_company", "homephonenumber" => "telephone_home1", "home2phonenumber" => "telephone_home2", "homefaxnumber" => "fax_home",  "mobilephonenumber" => "cellular_telephone1", "email1address" => "email3", "email2address" => "email1", "email3address" => "email2", "webpage" => "url", "pagernumber" => "telephone_pager", "radiophonenumber" => "telephone_radio", "imaddress" => "instant_messenger1", "imaddress2" => "instant_messenger2", "assistnamephonenumber" => "telephone_assistant", "companyname" => "company", "otherstate" => "state_other", "fileas" => "file_as");
			// Nokia
			$mapping = array("firstname" => "first_name", "lastname" => "last_name", "middlename" => "second_name", "suffix" => "suffix", "title" => "title",  "homestreet" => "street_home", "homepostalcode" => "postal_code_home", "homecity" => "city_home" , "homestate" => "state_home", "homecountry" => "country_home", "birthday" => "birthday", "children" => "number_of_children", "nickname" => "nickname", "spouse" => "spouse_name", "anniversary" => "anniversary", "body" => "note", "department" => "department", "job_title" => "position", "officelocation" => "room_number", "businessstreet" => "street_business", "businesspostalcode" => "postal_code_business", "businesscity" => "city_business", "businessstate" => "state_business", "businesscountry" => "country_business", "managername" => "manager_name", "assistantname" => "assistant_name", "otherstreet" => "street_other", "othercity" => "city_other", "otherpostalcode" => "postal_code_other", "othercountry" => "country_other", "businessphonenumber" => "telephone_business1", "business2phonenumber" => "cellular_telephone1", "businessfaxnumber" => "fax_business", "carphonenumber" => "telephone_car", "companymainphone" => "telephone_company", "homephonenumber" => "telephone_home1", "home2phonenumber" => "telephone_other", "homefaxnumber" => "fax_home",  "mobilephonenumber" => "cellular_telephone2", "email1address" => "email3", "email2address" => "email1", "email3address" => "email2", "webpage" => "url", "pagernumber" => "telephone_pager", "radiophonenumber" => "telephone_radio", "imaddress" => "instant_messenger1", "imaddress2" => "instant_messenger2", "assistnamephonenumber" => "telephone_assistant", "companyname" => "company", "otherstate" => "state_other", "fileas" => "file_as");
			
			$columns = implode(",", array_keys($mapping));

			$data = $this->ConvertContact($mapping, $message);

			if (empty($id)) {
				$data["folder_id"] = $this->folderid;

				$request = array("module" => "contacts", "action" => "new", "folder" => "$this->folderid", "data" => $data);
			}
			else
				$request = array("module" => "contacts", "action" => "update", "timestamp" => (time() + $this->backend->offset) * 1000, "folder" => "$this->folderid", "id" => "$id", "data" => $data);
		}
		else if ($foldertype == "tasks") {
			$mapping = array("subject" => "title", "utcstartdate" => "start_date", "utcduedate" => "end_date", "body" => "note", "remindertime" => "alarm", "importance" => "priority");
			
			$data = $this->ConvertTask($mapping, $message);

			if (empty($id)) {
				$data["folder_id"] = $this->folderid;

				$request = array("module" => "tasks", "action" => "new", "folder" => "$this->folderid", "data" => $data);
			}
			else
				$request = array("module" => "tasks", "action" => "update", "timestamp" => (time() + $this->backend->offset) * 1000, "folder" => "$this->folderid", "id" => "$id", "data" => $data);
		}
		else
			return false;

		$reply = $this->backend->SendRequest($request);

		if ($reply != false) {
			if (empty($id))
				return $reply["data"]["id"];
			else
				return true;
		}
		else
			return false;
    }

    function ImportMessageDeletion($id) {
		debugLog("ContentsImporterOX::ImportMessageDeletetion");

		$foldertype = $this->GetFolderType();

		if (empty($foldertype))
			return false;

		if ($foldertype == "contacts")
			$request = array("module" => "contacts", "action" => "delete", "timestamp" => (time() + $this->backend->offset) * 1000, "data" => array("folder" => "$this->folderid", "id" => "$id"));
		else if ($foldertype == "tasks")
			$request = array("module" => "tasks", "action" => "delete", "timestamp" => (time() + $this->backend->offset) * 1000, "data" => array("folder" => "$this->folderid", "id" => "$id"));
		else if ($foldertype == "calendar")
			$request = array("module" => "calendar", "action" => "delete", "timestamp" => (time() + $this->backend->offset) * 1000, "data" => array("folder" => "$this->folderid", "id" => "$id"));
		else
			return false;

		$reply = $this->backend->SendRequest($request);
		
		if ($reply != false) {
			return true;
		}
		else
			return false;
	}
    
    function ImportMessageReadFlag($id, $flags) {
        debugLog("ContentsImporterOX::ImportMessageReadFlag");
		// only possible for mail
    }

    function ImportMessageMove($message) {
		debugLog("ContentsImporterOX::ImportMessageMove");
		// only possible for mail
    }

    function GetState() {
		debugLog("ContentsImporterOX::GetState");
        return serialize($this->syncstate);
    }
};

?>
