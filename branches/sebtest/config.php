<?
/***********************************************
* File      :   config.php
* Project   :   Z-Push
* Descr     :   Main configuration file
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
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
    set_include_path(BASE_PATH. "include/" . PATH_SEPARATOR .
                     BASE_PATH. PATH_SEPARATOR .
                     "/usr/share/php/" . PATH_SEPARATOR .
                     "/usr/share/php5/" . PATH_SEPARATOR .
                     "/usr/share/pear/");

    define('STATE_DIR', 'state');

    // Try to set unlimited timeout
    define('SCRIPT_TIMEOUT', 0);

    // The data providers that we are using (see configuration below)
    $BACKEND_PROVIDER = "BackendICS";

    // ************************
    //  BackendMAPI settings
    // ************************
    
    // Defines the server to which we want to connect
    define('MAPI_SERVER', 'file:///var/run/zarafa');
    
    
    // ************************
    //  BackendICS settings
    // ************************
    
    // Defines the server to which we want to connect
    // recommended to use local servers only
    define('IMAP_SERVER', 'localhost');
    // connecting to default port (143)
    define('IMAP_PORT', 143);
    // best cross-platform compatibility (see http://php.net/imap_open for options)
    define('IMAP_OPTIONS', '/notls/norsh');
    
    
    // ************************
    //  BackendMaildir settings
    // ************************
    define('MAILDIR_BASE', '/tmp');
    define('MAILDIR_SUBDIR', 'Maildir');

    // **********************
    //  BackendVCDir settings
    // **********************
    define('VCARDDIR_BASE', '/home');
    define('VCARDDIR_SUBDIR', '.kde/share/apps/kabc/stdvcf');
    
?>
