<?php

/*

  Use this script as an endpoint for dynamic DNS updates, e. g. for your home DSL router.
  Here is HTTP basic authentication used, "admin" as username and the in the config.inc.php defined "adminapikey" as password.
  
  Call it like so: https://nsedit/dynamic_update.php?zone=domain.tld&record=your-a-record.domain.tld&ip=127.0.0.1
  
  Currently only IPv4 is supported!
  If the "ip=" parameter is empty, the calling remote address is used.
  
  AVM FritzBox example:
  Update-URL: https://nsedit/dynamic_update.php?zone=domain.tld&record=<domain>&ip=<ipaddr>
  Domain name: your-a.record.domain.tld
  Username: admin
  Password: the in the config.inc.php defined "adminapikey"

*/


include_once('includes/config.inc.php');
include_once('includes/session.inc.php');
include_once('includes/misc.inc.php');
include_once('includes/class/PdnsApi.php');
include_once('includes/class/Zone.php');

$clientInfo = get_client_info();

if (!isset($adminapikey))
{
  writelog('Tried to use dynamic_update.php without set adminapikey, client was ' . $clientInfo['ip'] . ' (' . $clientInfo['host'] . ')');
  header('HTTP/1.0 403 Forbidden');
  echo 'Not allowed';
  exit(0);
}

global $adminapikey;
$username = $_SERVER['PHP_AUTH_USER'];
$password = $_SERVER['PHP_AUTH_PW'];

if (isset($_SERVER['PHP_AUTH_USER']) && ($username === 'admin' && $password === $adminapikey))
{
  _set_current_user('admin', true, true, true);
  
  if (!isset($_GET['zone']))
  {
    wrong_call('Missing parameter "zone"');
  }
  
  if (!isset($_GET['record']))
  {
    wrong_call('Missing parameter "record"');
  }
  
  if (!isset($_GET['ip']))
  {
    $requestedNewIp = $clientInfo['ip'];
  }
  else
  {
    $requestedNewIp = $_GET['ip'];
  }
  
  $requestedZone = string_suffix($_GET['zone'], '.');
  $requestedRecord = string_suffix($_GET['record'], '.');
  
  $api = new PdnsAPI();
  
  $zones = $api->listzones();
  foreach ($zones as $zone)
  {
    $foundZone = array();
    if ($zone['id'] == $requestedZone)
    {
      $foundZone = $zone;
      break;
    }
  }

  if (empty($foundZone))
  {
    wrong_call("Zone $requestedZone not found");
  }
  else
  {
    $zone = $api->loadzone($foundZone['id']);
    $doneUpdate = false;
    $recordRrsetIndex = 0;
    foreach ($zone['rrsets'] as $rrset)
    {
      if ($rrset['name'] == $requestedRecord)
      {
        $doUpdate = true;
        if ($rrset['type'] !== 'A')
        {
          wrong_call("Record $requestedRecord in zone $requestedZone is not a type A record");
        }
        
        $rr = $rrset['records'][0];
        if ($rr['disabled'] == true)
        {
          wrong_call("Record $requestedRecord in zone $requestedZone is disabled");
        }
        
        if ($rr['content'] == $requestedNewIp)
        {
          echo "Record $requestedRecord in zone $requestedZone points already to $requestedNewIp, nothing to do";
          exit(0);
        }
        
        $zone['rrsets'][$recordRrsetIndex]['records'][0]['content'] = $requestedNewIp;
        $parsedZone = new Zone();
        $parsedZone->parse($zone);
        
        $apiResponse = $api->savezone($parsedZone->export());
        $newSerial = $apiResponse['serial'];
        echo "Record $requestedRecord in zone $requestedZone successfully updated to $requestedNewIp, new zone serial = $newSerial";
        writelog("dynamic_update: Updated record $requestedRecord in zone $requestedZone to $requestedNewIp");
        $doneUpdate = true;
        
        break;
      }
      
      $recordRrsetIndex++;
    }
    
    if ($doneUpdate === false)
    {
      wrong_call("Record $requestedRecord in zone $requestedZone not found");
    }
  }
}
else
{
  writelog('Tried to use dynamic_update.php without valid credentials, client was ' . $clientInfo['ip'] . ' (' . $clientInfo['host'] . ')');
  header('WWW-Authenticate: Basic realm="nsedit"');
  header('HTTP/1.0 401 Unauthorized');
  echo 'Autentication error';
  exit(0);
}

function wrong_call($message)
{
  header('HTTP/1.0 406 Not Acceptable');
  echo $message;
  exit(0);
}
