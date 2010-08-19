<?php
/***********************************************
* File      :   debug.php
* Project   :   Z-Push
* Descr     :   Debuging functions
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

global $debugstr;

function debug($str) {
    global $debugstr;
    $debugstr .= "$str\n";
}

function getDebugInfo() {
    global $debugstr;
    
    return $debugstr;
}

function debugLog($message) {
    global $auth_user;
    $user = (isset($auth_user))?"[". $auth_user ."] ":"";
    @$fp = fopen(BASE_PATH . "/debug.txt","a");
    @$date = strftime("%x %X");
    @fwrite($fp, "$date [". getmypid() ."] ". $user . "$message\n");
    @fclose($fp);
}

function zarafa_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {    
    $bt = debug_backtrace();
    debugLog("------------------------- ERROR BACKTRACE -------------------------");
    debugLog("trace error: $errfile:$errline $errstr ($errno) - backtrace: ". (count($bt)-1) . " steps");
    for($i = 1, $bt_length = count($bt); $i < $bt_length; $i++)
        debugLog("trace: $i:". $bt[$i]['file']. ":" . $bt[$i]['line']. " - " . ((isset($bt[$i]['class']))? $bt[$i]['class'] . $bt[$i]['type']:""). $bt[$i]['function']. "()");
}

error_reporting(E_ALL);
set_error_handler("zarafa_error_handler");

?>