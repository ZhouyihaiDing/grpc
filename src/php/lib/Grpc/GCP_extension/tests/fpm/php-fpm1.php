<?php
header('Content-type: text/plain');
putenv("GOOGLE_APPLICATION_CREDENTIALS=./grpc-gcp.json");

require_once(dirname(__FILE__).'/vendor/autoload.php');
require_once(dirname(__FILE__).'/../src/ChannelRef.php');
require_once(dirname(__FILE__).'/../src/GCPConfig.php');
require_once(dirname(__FILE__).'/../src/GCPCallInvoker.php');
require_once(dirname(__FILE__).'/../src/GCPExtensionChannel.php');
require_once(dirname(__FILE__).'/generated/Grpc/Gcp/AffinityConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc/Gcp/AffinityConfig_Command.php');
require_once(dirname(__FILE__).'/generated/Grpc/Gcp/ApiConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc/Gcp/ChannelPoolConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc/Gcp/MethodConfig.php');
require_once(dirname(__FILE__).'/generated/GPBMetadata/GrpcGcp.php');

use Google\Cloud\Spanner\V1\SpannerGrpcClient;
use Google\Cloud\Spanner\V1\CreateSessionRequest;
use Google\Cloud\Spanner\V1\DeleteSessionRequest;
use Google\Cloud\Spanner\V1\ExecuteSqlRequest;

use Google\Auth\ApplicationDefaultCredentials;

$_DEFAULT_MAX_CHANNELS_PER_TARGET = 10;
$_WATER_MARK = 2;

$hostname = 'spanner.googleapis.com';
$string = file_get_contents("spanner.grpc.config");


$conf = new \Grpc\Gcp\ApiConfig();
$conf->mergeFromJsonString($string);
$channel_pool = $conf->getChannelPool();
$channel_pool->setMaxConcurrentStreamsLowWatermark($_WATER_MARK);
$config = new \Grpc\GCP\Config($hostname, $conf);

$credentials = \Grpc\ChannelCredentials::createSsl();
$auth = ApplicationDefaultCredentials::getCredentials();
$opts = [
  'credentials' => $credentials,
  'update_metadata' => $auth->getUpdateMetadataFunc(),
  'grpc_call_invoker' => $config->callInvoker(),
];

$stub = new SpannerGrpcClient($hostname, $opts);
$call_invoker = $config->callInvoker();

$database = 'projects/grpc-gcp/instances/sample/databases/benchmark';
$table = 'storage';
$data = 'payload';


function assertEqual($var1, $var2, $str = "") {
  if ($var1 != $var2) {
    throw new \Exception("$str $var1 not matches to $var2.\n");
  }
}
function assertStatusOk($status) {
  if ($status->code != \Grpc\STATUS_OK) {
    var_dump($status);
    throw new \Exception("gRPC status not OK: ".$status->code."\n");
  }
}

// Test Concurrent Streams Watermark
$sql_cmd = "select id from $table";
$result = ['payload'];
$exec_sql_calls = array();
$sessions = array();
for ($i=0; $i < $_WATER_MARK; $i++) {
  $create_session_request = new CreateSessionRequest();
  $create_session_request->setDatabase($database);
  $create_session_call = $stub->CreateSession($create_session_request);
  list($session, $status) = $create_session_call->wait();
  assertStatusOk($status);
//  print_r($call_invoker->_getChannel()->getChannelRefs());
  assertEqual(1, count($call_invoker->_getChannel()->getChannelRefs()));
  assertEqual($i + 1, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
  assertEqual($i, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

  $exec_sql_request = new ExecuteSqlRequest();
  $exec_sql_request->setSession($session->getName());
  $exec_sql_request->setSql($sql_cmd);
  $exec_sql_call = $stub->ExecuteSql($exec_sql_request);
  array_push($exec_sql_calls, $exec_sql_call);
  array_push($sessions, $session);
  assertEqual(1, count($call_invoker->_getChannel()->getChannelRefs()));
  assertEqual($i + 1, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
  assertEqual($i + 1, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());
}

$create_session_request = new CreateSessionRequest();
$create_session_request->setDatabase($database);
$create_session_call = $stub->CreateSession($create_session_request);
list($session, $status) = $create_session_call->wait();
assertStatusOk($status);
print_r($call_invoker->_getChannel()->getChannelRefs());
assertEqual(2, count($call_invoker->_getChannel()->getChannelRefs()));
assertEqual(2, $call_invoker->_getChannel()->getChannelRefs()[1]->getAffinityRef());
assertEqual(2, $call_invoker->_getChannel()->getChannelRefs()[1]->getActiveStreamRef());
assertEqual(1, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

// The new request uses the new session id.
$exec_sql_request = new ExecuteSqlRequest();
$exec_sql_request->setSession($session->getName());
$exec_sql_request->setSql($sql_cmd);
$exec_sql_call = $stub->ExecuteSql($exec_sql_request);
assertEqual(2, count($call_invoker->_getChannel()->getChannelRefs()));
assertEqual(2, $call_invoker->_getChannel()->getChannelRefs()[1]->getAffinityRef());
assertEqual(2, $call_invoker->_getChannel()->getChannelRefs()[1]->getActiveStreamRef());
assertEqual(1, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
assertEqual(1, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

// Clear session and stream
list($exec_sql_reply, $status) = $exec_sql_call->wait();
assertStatusOk($status);
assertEqual($exec_sql_reply->getRows()[0]->getValues()[0]->getStringValue(), $result[0]);
assertEqual(2, count($call_invoker->_getChannel()->getChannelRefs()));
assertEqual(2, $call_invoker->_getChannel()->getChannelRefs()[1]->getAffinityRef());
assertEqual(2, $call_invoker->_getChannel()->getChannelRefs()[1]->getActiveStreamRef());
assertEqual(1, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

$delete_session_request = new DeleteSessionRequest();
$delete_session_request->setName($session->getName());
list($session, $status) = $stub->DeleteSession($delete_session_request)->wait();
assertStatusOk($status);
assertEqual(2, count($call_invoker->_getChannel()->getChannelRefs()));
assertEqual(2, $call_invoker->_getChannel()->getChannelRefs()[1]->getAffinityRef());
assertEqual(2, $call_invoker->_getChannel()->getChannelRefs()[1]->getActiveStreamRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

for ($i=0; $i < $_WATER_MARK; $i++) {
  list($exec_sql_reply, $status) = $exec_sql_calls[$i]->wait();
  assertStatusOk($status);
  assertEqual($exec_sql_reply->getRows()[0]->getValues()[0]->getStringValue(), $result[0]);
  assertEqual(2, count($call_invoker->_getChannel()->getChannelRefs()));
  assertEqual(2 - $i, $call_invoker->_getChannel()->getChannelRefs()[1]->getAffinityRef());
  assertEqual(1 - $i, $call_invoker->_getChannel()->getChannelRefs()[1]->getActiveStreamRef());
  assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
  assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

  $delete_session_request = new DeleteSessionRequest();
  $delete_session_request->setName($sessions[$i]->getName());
  list($session, $status) = $stub->DeleteSession($delete_session_request)->wait();
  assertEqual(2, count($call_invoker->_getChannel()->getChannelRefs()));
  assertEqual(1 - $i, $call_invoker->_getChannel()->getChannelRefs()[1]->getAffinityRef());
  assertEqual(1 - $i, $call_invoker->_getChannel()->getChannelRefs()[1]->getActiveStreamRef());
  assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
  assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());
}
