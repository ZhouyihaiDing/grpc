<?php

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
use Google\Cloud\Spanner\V1\ListSessionsRequest;

use Google\Auth\ApplicationDefaultCredentials;

$_DEFAULT_MAX_CHANNELS_PER_TARGET = 10;
$hostname = 'spanner.googleapis.com';
$string = file_get_contents("spanner.grpc.config");


$conf = new \Grpc\Gcp\ApiConfig();
$conf->mergeFromJsonString($string);
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

// Test Create List Delete Session
$create_session_request = new CreateSessionRequest();
$create_session_request->setDatabase($database);
$create_session_call = $stub->CreateSession($create_session_request);
list($session, $status) = $create_session_call->wait();
assertStatusOk($status);
assertEqual(1, count($call_invoker->_getChannel()->getChannelRefs()));
assertEqual(1, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

$list_session_request = new ListSessionsRequest();
$list_session_request->setDatabase($database);
$list_session_call = $stub->ListSessions($list_session_request);
list($list_session_response, $status) = $list_session_call->wait();
assertStatusOk($status);
//foreach ($list_session_response->getSessions() as $session) {
//  echo "session:\n";
//  echo "name - ". $session->getName(). PHP_EOL;
//}
assertEqual(1, count($call_invoker->_getChannel()->getChannelRefs()));
assertEqual(1, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

$delete_session_request = new DeleteSessionRequest();
$delete_session_request->setName($session->getName());
list($delete_session_response, $status) = $stub->DeleteSession($delete_session_request)->wait();
assertStatusOk($status);
assertEqual(1, count($call_invoker->_getChannel()->getChannelRefs()));
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

$list_session_request = new ListSessionsRequest();
$list_session_request->setDatabase($database);
$list_session_call = $stub->ListSessions($list_session_request);
list($list_session_response, $status) = $list_session_call->wait();
assertStatusOk($status);
assertEqual(1, count($call_invoker->_getChannel()->getChannelRefs()));
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

// Test eExecute Sql
$create_session_request = new CreateSessionRequest();
$create_session_request->setDatabase($database);
$create_session_call = $stub->CreateSession($create_session_request);
list($session, $status) = $create_session_call->wait();
assertEqual(1, count($call_invoker->_getChannel()->getChannelRefs()));
assertEqual(1, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

$sql_cmd = "select id from $table";
$exec_sql_request = new ExecuteSqlRequest();
$exec_sql_request->setSession($session->getName());
$exec_sql_request->setSql($sql_cmd);
$exec_sql_call = $stub->ExecuteSql($exec_sql_request);
list($exec_sql_reply, $status) = $exec_sql_call->wait();
assertStatusOk($status);
assertEqual(1, count($call_invoker->_getChannel()->getChannelRefs()));
assertEqual(1, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());
$result = ['payload'];
$i = 0;

foreach ($exec_sql_reply->getRows() as $row) {
  foreach($row->getValues() as $value) {
    assertEqual($value->getStringValue(), $result[$i]);
    $i += 1;
  }
}

$delete_session_request = new DeleteSessionRequest();
$delete_session_request->setName($session->getName());
list($delete_session_response, $status) = $stub->DeleteSession($delete_session_request)->wait();
assertStatusOk($status);
assertEqual(1, count($call_invoker->_getChannel()->getChannelRefs()));
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

// Test Execute Streaming Sql
$create_session_request = new CreateSessionRequest();
$create_session_request->setDatabase($database);
$create_session_call = $stub->CreateSession($create_session_request);
list($session, $status) = $create_session_call->wait();
assertEqual(1, count($call_invoker->_getChannel()->getChannelRefs()));
assertEqual(1, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

$sql_cmd = "select id from $table";
$stream_exec_sql_request = new ExecuteSqlRequest();
$stream_exec_sql_request->setSession($session->getName());
$stream_exec_sql_request->setSql($sql_cmd);
$stream_exec_sql_call = $stub->ExecuteStreamingSql($stream_exec_sql_request);
$features = $stream_exec_sql_call->responses();
$result = ['payload'];
$i = 0;
foreach ($features as $feature) {
  foreach ($feature->getValues() as $value) {
    assertEqual($value->getStringValue(), $result[$i]);
    $i += 1;
  }
}
$status = $stream_exec_sql_call->getStatus();
assertStatusOk($status);

