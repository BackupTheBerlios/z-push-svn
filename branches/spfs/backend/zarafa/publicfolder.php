#!/usr/bin/php
<?php
// this script lists folders in the public folder available for synchronization

if (!isset($_SERVER["TERM"])) {
    echo "This script should not be called in a browser.\n";
    exit(1);
}

if (!isset($_SERVER["LOGNAME"])) {
    echo "This script should not be called in a browser.\n";
    exit(1);
}

define("MAPI_PATH", "/usr/share/php/mapi/");
define("ZARAFA_SERVER", "file:///var/run/zarafa");
define("ZARAFA_USER", "SYSTEM");
define("ZARAFA_PASS", "");

require(MAPI_PATH."mapi.util.php");
require(MAPI_PATH."mapicode.php");
require(MAPI_PATH."mapidefs.php");
require(MAPI_PATH."mapitags.php");
require(MAPI_PATH."mapiguid.php");

$supported_classes = array (
    "IPF.Note"          => "SYNC_FOLDER_TYPE_USER_MAIL",
    "IPF.Task"          => "SYNC_FOLDER_TYPE_USER_TASK",
    "IPF.Appointment"   => "SYNC_FOLDER_TYPE_USER_APPOINTMENT",
    "IPF.Contact"       => "SYNC_FOLDER_TYPE_USER_CONTACT",
    "IPF.StickyNote"    => "SYNC_FOLDER_TYPE_USER_NOTE"
);


$session = @mapi_logon_zarafa(ZARAFA_USER, ZARAFA_PASS, ZARAFA_SERVER);
if (!$session)
    die ("Login to Zarafa failed\n");

$storetable = @mapi_getmsgstorestable($session);
$storeslist = @mapi_table_queryallrows($storetable, array(PR_ENTRYID, PR_MDB_PROVIDER));
for ($i = 0; $i < count($storeslist); $i++) {
    if ($storeslist[$i][PR_MDB_PROVIDER] == ZARAFA_STORE_PUBLIC_GUID) {
            $publicstore = @mapi_openmsgstore($session, $storeslist[$i][PR_ENTRYID]);
            break;
    }
}

if (! isset($publicstore))
    die("Public folder not available");

$pub_folder = @mapi_msgstore_openentry($publicstore);
$h_table = @mapi_folder_gethierarchytable($pub_folder, CONVENIENT_DEPTH);
$subfolders = @mapi_table_queryallrows($h_table, array(PR_ENTRYID, PR_DISPLAY_NAME, PR_CONTAINER_CLASS, PR_SOURCE_KEY));

echo "Available folders in public folder:\n" . str_repeat("-", 50) . "\n";
foreach($subfolders as $folder) {
    if (isset($folder[PR_CONTAINER_CLASS]) && array_key_exists($folder[PR_CONTAINER_CLASS], $supported_classes)) {
        echo "Name:\t\t". $folder[PR_DISPLAY_NAME] . "\n";
        echo "Sync-class:\t". $supported_classes[$folder[PR_CONTAINER_CLASS]] . "\n";
        echo "PUID:\t\t". bin2hex($folder[PR_SOURCE_KEY]) . "\n\n";
    }
}
?>