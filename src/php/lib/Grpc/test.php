<?php


require_once(dirname(__FILE__).'/vendor/autoload.php');
require_once(dirname(__FILE__).'/BaseStub.php');
require_once(dirname(__FILE__).'/AbstractCall.php');
require_once(dirname(__FILE__).'/UnaryCall.php');
require_once(dirname(__FILE__).'/ClientStreamingCall.php');
require_once(dirname(__FILE__).'/ServerStreamingCall.php');
require_once(dirname(__FILE__).'/Interceptor.php');
require_once(dirname(__FILE__).'/Interceptor.php');
require_once(dirname(__FILE__).'/Internal/InterceptorChannel.php');
require_once(dirname(__FILE__).'/Internal/EmptyCall.php');
require_once(dirname(__FILE__).'/GCPExtension.php');

use Google\Cloud\Spanner\V1\SpannerGrpcClient;
use Google\Cloud\Spanner\V1\BeginTransactionRequest;
use Google\Cloud\Spanner\V1\CommitRequest;
use Google\Cloud\Spanner\V1\CommitResponse;
use Google\Cloud\Spanner\V1\CreateSessionRequest;
use Google\Cloud\Spanner\V1\DeleteSessionRequest;
use Google\Cloud\Spanner\V1\ExecuteSqlRequest;
use Google\Cloud\Spanner\V1\GetSessionRequest;
use Google\Cloud\Spanner\V1\KeySet;
use Google\Cloud\Spanner\V1\ListSessionsRequest;
use Google\Cloud\Spanner\V1\ListSessionsResponse;
use Google\Cloud\Spanner\V1\Mutation;
use Google\Cloud\Spanner\V1\PartialResultSet;
use Google\Cloud\Spanner\V1\PartitionOptions;
use Google\Cloud\Spanner\V1\PartitionQueryRequest;
use Google\Cloud\Spanner\V1\PartitionReadRequest;
use Google\Cloud\Spanner\V1\PartitionResponse;
use Google\Cloud\Spanner\V1\ReadRequest;
use Google\Cloud\Spanner\V1\ResultSet;
use Google\Cloud\Spanner\V1\RollbackRequest;
use Google\Cloud\Spanner\V1\Session;
use Google\Cloud\Spanner\V1\Transaction;
use Google\Cloud\Spanner\V1\TransactionOptions;
use Google\Cloud\Spanner\V1\TransactionSelector;
use Google\Protobuf\GPBEmpty;
use Google\Protobuf\Struct;

use Google\Auth\ApplicationDefaultCredentials;

$string = file_get_contents("spanner.grpc.config");
$conf = new Grpc_gcp\ExtensionConfig();
$conf->mergeFromJsonString($string);

$hostname = 'spanner.googleapis.com';
$credentials = \Grpc\ChannelCredentials::createSsl();
$auth = ApplicationDefaultCredentials::getCredentials();
$opts = [
  'credentials' => $credentials,
  'update_metadata' => $auth->getUpdateMetadataFunc(),
];

$gcp_channel = \Grpc\enable_grpc_gcp($conf);
$stub = new SpannerGrpcClient($hostname, $opts, $gcp_channel);

$database = 'projects/ddyihai-firestore/instances/test-instance/databases/test-database';


function assertEqual($var1, $var2, $str = "") {
  if ($var1 != $var2) {
    throw new \Exception("$str $var1 not matches to $var2.\n");
  }
}
function assertStatusOk($status) {
  if ($status->code != \Grpc\STATUS_OK) {
    throw new \Exception("gRPC status not OK: ".$status->code."\n");
  }
}

$_DEFAULT_MAX_CHANNELS_PER_TARGET = 10;

// Test CreateSession Reuse Channel
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++){
  echo "===================================================\n";
  $create_session_request = new CreateSessionRequest();
  $create_session_request->setDatabase($database);
  $create_session_call = $stub->CreateSession($create_session_request);
  list($session, $status) = $create_session_call->wait();
  assertStatusOk($status);
  $delete_session_request = new DeleteSessionRequest();
  $delete_session_request->setName($session->getName());
  list($session, $status) = $stub->DeleteSession($delete_session_request)->wait();
  assertStatusOk($status);
  $result = (count($gcp_channel->getNext()->getChannelRefs()) == 1);
  assertEqual(1, count($gcp_channel->getNext()->getChannelRefs()));
}
print_r($gcp_channel->getNext()->getChannelRefs());

/*
// Test CreateSession New Channel
$rpc_calls = array();
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++){
  $create_session_request = new CreateSessionRequest();
  $create_session_request->setDatabase($database);
  $create_session_call = $stub->CreateSession($create_session_request);
  $result = (count($gcp_channel->getNext()->getChannelRefs()) == $i+1);
  assertEqual($i+1, count($gcp_channel->getNext()->getChannelRefs()));
  array_push($rpc_calls, $create_session_call);
}
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++) {
  list($session, $status) = $rpc_calls[$i]->wait();
  assertStatusOk($status);
  $delete_session_request = new DeleteSessionRequest();
  $delete_session_request->setName($session->getName());
  $delete_session_call = $stub->DeleteSession($delete_session_request);
  list($session, $status) = $delete_session_call->wait();
  assertStatusOk($status);
  $result = (count($gcp_channel->getNext()->getChannelRefs()) == $_DEFAULT_MAX_CHANNELS_PER_TARGET);
  assertEqual($_DEFAULT_MAX_CHANNELS_PER_TARGET,
      count($gcp_channel->getNext()->getChannelRefs()));
}


$rpc_calls = array();
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++){
  echo "feature =================================\n";
  $create_session_request = new CreateSessionRequest();
  $create_session_request->setDatabase($database);
  $create_session_call = $stub->CreateSession($create_session_request);
  $result = (count($gcp_channel->getNext()->getChannelRefs()) == $_DEFAULT_MAX_CHANNELS_PER_TARGET);
  assertEqual($_DEFAULT_MAX_CHANNELS_PER_TARGET,
      count($gcp_channel->getNext()->getChannelRefs()));
  array_push($rpc_calls, $create_session_call);
}
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++) {
  list($session, $status) = $rpc_calls[$i]->wait();
  $delete_session_request = new DeleteSessionRequest();
  $delete_session_request->setName($session->getName());
  list($session, $status) = $stub->DeleteSession($delete_session_request)->wait();
  assertStatusOk($status);
}

print_r($gcp_channel->getNext()->getChannelRefs());


// Test Create List Delete Session
$create_session_request = new CreateSessionRequest();
$create_session_request->setDatabase($database);
$create_session_call = $stub->CreateSession($create_session_request);
list($session, $status) = $create_session_call->wait();
assertStatusOk($status);
assertEqual(1, count($gcp_channel->getNext()->getChannelRefs()));
assertEqual(1, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());

$list_session_request = new ListSessionsRequest();
$list_session_request->setDatabase($database);
$list_session_call = $stub->ListSessions($list_session_request);
list($list_session_response, $status) = $list_session_call->wait();
assertStatusOk($status);
//foreach ($list_session_response->getSessions() as $session) {
//  echo "session:\n";
//  echo "name - ". $session->getName(). PHP_EOL;
//}
assertEqual(1, count($gcp_channel->getNext()->getChannelRefs()));
assertEqual(1, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());

$delete_session_request = new DeleteSessionRequest();
$delete_session_request->setName($session->getName());
list($delete_session_response, $status) = $stub->DeleteSession($delete_session_request)->wait();
assertStatusOk($status);
assertEqual(1, count($gcp_channel->getNext()->getChannelRefs()));
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());

$list_session_request = new ListSessionsRequest();
$list_session_request->setDatabase($database);
$list_session_call = $stub->ListSessions($list_session_request);
list($list_session_response, $status) = $list_session_call->wait();
assertStatusOk($status);
assertEqual(1, count($gcp_channel->getNext()->getChannelRefs()));
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());



// Test eExecute Sql
$create_session_request = new CreateSessionRequest();
$create_session_request->setDatabase($database);
$create_session_call = $stub->CreateSession($create_session_request);
list($session, $status) = $create_session_call->wait();
assertEqual(1, count($gcp_channel->getNext()->getChannelRefs()));
assertEqual(1, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());
$sql_cmd = "select FirstName from Singers";
$exec_sql_request = new ExecuteSqlRequest();
$exec_sql_request->setSession($session->getName());
$exec_sql_request->setSql($sql_cmd);
$exec_sql_call = $stub->ExecuteSql($exec_sql_request);
list($exec_sql_reply, $status) = $exec_sql_call->wait();
assertStatusOk($status);
assertEqual(1, count($gcp_channel->getNext()->getChannelRefs()));
assertEqual(1, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());
$result = ['A', 'Liangying', 'Haha', 'Jielun'];
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
assertEqual(1, count($gcp_channel->getNext()->getChannelRefs()));
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());
*/

/*
// Test Execute Streaming Sql
$create_session_request = new CreateSessionRequest();
$create_session_request->setDatabase($database);
$create_session_call = $stub->CreateSession($create_session_request);
list($session, $status) = $create_session_call->wait();
assertEqual(1, count($gcp_channel->getNext()->getChannelRefs()));
assertEqual(1, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());
$sql_cmd = "select FirstName from Singers";
$stream_exec_sql_request = new ExecuteSqlRequest();
$stream_exec_sql_request->setSession($session->getName());
$stream_exec_sql_request->setSql($sql_cmd);
$stream_exec_sql_call = $stub->ExecuteStreamingSql($stream_exec_sql_request);
$features = $stream_exec_sql_call->responses();
$result = ['A', 'Liangying', 'Haha', 'Jielun'];
$i = 0;
foreach ($features as $feature) {
  foreach ($feature->getValues() as $value) {
    assertEqual($value->getStringValue(), $result[$i]);
    $i += 1;
  }
}
$status = $stream_exec_sql_call->getStatus();
*/
