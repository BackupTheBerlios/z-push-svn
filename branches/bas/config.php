<?
/***********************************************
* File	  :   config.php
* Project   :   Z-Push
* Descr	 :   Main configuration file
*
* Created   :   01.10.2007
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/
	// Defines the default time zone
	if (function_exists("date_default_timezone_set")){
		date_default_timezone_set("Europe/Amsterdam");
	}

	// Defines the base path on the server, terminated by a slash
	define('BASE_PATH', dirname($_SERVER['SCRIPT_FILENAME']) . "/");

	// Define the include paths
	set_include_path(		 BASE_PATH. "include/" . PATH_SEPARATOR .
					 BASE_PATH. PATH_SEPARATOR .
					 get_include_path());

	define('STATE_DIR', 'state');

	// Try to set unlimited timeout
	define('SCRIPT_TIMEOUT', 0);

	// The data provider that we are using (see configuration below)
	$BACKEND_PROVIDER = "BackendCombined";

	// only allow login for these users
	$ALLOWLOGIN = array();
	
	// deny login for these users
	$DENYLOGIN = array();


	// ************************
	//  BackendICS settings
	// ************************
	
	// Defines the server to which we want to connect
	$BackendICS_config = array('MAPI_SERVER' => 'file:///var/run/zarafa');
	$BackendICSPublic_config = array(
		'MAPI_SERVER'			=>	'file:///var/run/zarafa',
		'MAPI_USE_PUBLICSTORE'	=>	true,
		'MAPI_FOLDER_TYPES'		=>	array(),
/*		'MAPI_FOLDER_TYPES'		=>	array(
			SYNC_FOLDER_TYPE_INBOX			=> "Public Folders",
			SYNC_FOLDER_TYPE_APPOINTMENT	=> "Calendar",
			SYNC_FOLDER_TYPE_CONTACT		=> "Contacts",
		)
*/
	);
	
	
	// ************************
	//  BackendIMAP settings
	// ************************

	$BackendIMAP_config = array(
		// Defines the server to which we want to connect
		// recommended to use local servers only
		'IMAP_SERVER' => 'localhost',
		// connecting to default port (143)
		'IMAP_PORT' => 143,
		// best cross-platform compatibility (see http://php.net/imap_open for options)
		'IMAP_OPTIONS' => '/notls/norsh',
		//set which folders should have specific type
		'IMAP_FOLDERS' => array(
				SYNC_FOLDER_TYPE_INBOX => 'inbox',
				SYNC_FOLDER_TYPE_DRAFTS => array('drafts', 'inbox.drafts'),
				SYNC_FOLDER_TYPE_WASTEBASKET => array('trash', 'inbox.trash', 'wastebasket'),
				SYNC_FOLDER_TYPE_SENTMAIL => array('sent', 'sent items', 'inbox.sent'),
				SYNC_FOLDER_TYPE_OUTBOX => array('outbox'),
			),
		// overwrite the "from" header if it isn't set when sending emails
		// options: %u %d
		// %u
		// %u@domain.com
		// %u@%d
		'IMAP_FORCEFROM' => '',
	);
	
	
	// ************************
	//  BackendMaildir settings
	// ************************
	$BackendMaildir_config = array(
		'MAILDIR_DIR' => '/home/%u/Maildir/cur',
	);

	// **********************
	//  BackendVCDir settings
	// **********************
	$BackendVCDir_config = array(
		'VCARDDIR_DIR' => '/home/%u/.kde/share/apps/kabc/stdvcf',
		'VCARDDIR_FOLDERNAME' => 'contacts',
	);

	$BackendFileStorage_config = array(
		'FILESTORAGE_DIR' => '/home/%u/files/',
		//display name of the folder
		'FILESTORAGE_FOLDERNAME' => 'Files',
		'FILESTORAGE_DELIMITER' => '/',
		// files with these extensions are loaded in the body of the message
		'FILESTORAGE_BODYEXTS' => array(/*'txt', 'log', 'php'*/),
	);

	$BackendDummy_config = array(
		// empty array will allow every login
		'DUMMY_LOGINS' => array(
			'user' => 'password',
		),
		// dummy folders to show
		'DUMMY_FOLDERS' => array(
			'inbox' => SYNC_FOLDER_TYPE_INBOX,
			'drafts' => SYNC_FOLDER_TYPE_DRAFTS,
			'waste' => SYNC_FOLDER_TYPE_WASTEBASKET,
			'sent' => SYNC_FOLDER_TYPE_SENTMAIL,
			'outbox' => SYNC_FOLDER_TYPE_OUTBOX,
		),
	);
	
	$BackendSerialize_config = array(
		'SERIALIZE_DIR' => '/home/%u/serialize/',
		'SERIALIZE_DELIMITER' => '/',
		//set which folders should have specific type
		'SERIALIZE_FOLDERS' => array(
			SYNC_FOLDER_TYPE_INBOX => 'inbox',
			SYNC_FOLDER_TYPE_DRAFTS => array('drafts'),
			SYNC_FOLDER_TYPE_WASTEBASKET => array('trash'),
			SYNC_FOLDER_TYPE_SENTMAIL => array('sent'),
			SYNC_FOLDER_TYPE_OUTBOX => array('outbox'),
			SYNC_FOLDER_TYPE_TASK => array('tasks'),
			SYNC_FOLDER_TYPE_APPOINTMENT => array('calendar'),
			SYNC_FOLDER_TYPE_CONTACT => array('contacts'),
			SYNC_FOLDER_TYPE_NOTE => array('notes'),
			SYNC_FOLDER_TYPE_JOURNAL => array('journal'),
		),
	);
	

	// **********************
	//  BackendCombined settings
	// **********************
	// 
	$BackendCombined_config = array(
		//the order in which the backends are loaded.
		//login only succeeds if all backend return true on login
		//when sending mail: the mail is send with first backend that is able to send the mail
		'backends' => array(
			'f' => array(
				'name' => 'BackendFileStorage',
				'config' => $BackendFileStorage_config,
				'users' => array(
					'deviceusername' => array(
						'username'=>'backendusername',
						'password'=>'backendpassword',
						'domain' => 'backenddomain'
					),
				),
				'subfolder' => 'files',
			),
//			'm' => array(
//				'name' => 'BackendICS',
//				'config' => $BackendICS_config,
//			),
//			"p" => array(
//				"name" => "BackendICS",
//				"config" => $BackendICSPublic_config,
//			),
			'i' => array(
				'name' => 'BackendIMAP',
				'config' => $BackendIMAP_config,
			),
//			'd' => array(
//				'name' => 'BackendDummy',
//				'config' => $BackendDummy_config,
//			),
			'v' => array(
				'name' => 'BackendVCDir',
				'config' => $BackendVCDir_config,
			),
			's' => array(
				'name' => 'BackendSerialize',
				'config' => $BackendSerialize_config,
			),
		),
		'delimiter' => '/',
		//force one type of folder to one backend
		'folderbackend' => array(
			SYNC_FOLDER_TYPE_INBOX => 'i',
			SYNC_FOLDER_TYPE_DRAFTS => 'i',
			SYNC_FOLDER_TYPE_WASTEBASKET => 'i',
			SYNC_FOLDER_TYPE_SENTMAIL => 'i',
			SYNC_FOLDER_TYPE_OUTBOX => 'i',
			SYNC_FOLDER_TYPE_TASK => 's',
			SYNC_FOLDER_TYPE_APPOINTMENT => 's',
			SYNC_FOLDER_TYPE_CONTACT => 'v',
			SYNC_FOLDER_TYPE_NOTE => 's',
			SYNC_FOLDER_TYPE_JOURNAL => 's',
		),
		//creating a new folder in the root folder should create a folder in one backend
		'rootcreatefolderbackend' => 'i',
	);

	eval('$BACKEND_CONFIG = $'.$BACKEND_PROVIDER.'_config;');
?>
