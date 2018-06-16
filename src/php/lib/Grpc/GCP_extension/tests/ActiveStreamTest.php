<?php


require_once(dirname(__FILE__).'/vendor/autoload.php');
require_once(dirname(__FILE__).'/../src/ChannelRef.php');
require_once(dirname(__FILE__).'/../src/EnableGCP.php');
require_once(dirname(__FILE__).'/../src/GCPCallInterceptor.php');
require_once(dirname(__FILE__).'/../src/GCPExtensionChannel.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/ExtensionConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/AffinityConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/AffinityConfig_Command.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/ApiConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/ChannelPoolConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/MethodConfig.php');
require_once(dirname(__FILE__).'/generated/GPBMetadata/GrpcGcp.php');

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

$_WATER_MARK = 2;


// Read GCP config.
$string = file_get_contents("spanner.grpc.config");
$conf = new Grpc_gcp\ExtensionConfig();
$conf->mergeFromJsonString($string);
$channel_pool = $conf->getApi()[0]->getChannelPool();
$channel_pool->setMaxConcurrentStreamsLowWatermark($_WATER_MARK);
// Options for creating the gRPC channel/stub.
$credentials = \Grpc\ChannelCredentials::createSsl();
$auth = ApplicationDefaultCredentials::getCredentials();
$opts = [
  'credentials' => $credentials,
  'update_metadata' => $auth->getUpdateMetadataFunc(),
];
$hostname = 'spanner.googleapis.com';

// Enable GCP.
$gcp_channel = \Grpc_gcp\enableGrpcGcp($conf, $opts);
$stub = new SpannerGrpcClient($hostname, $opts, $gcp_channel);

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
  assertEqual(1, count($gcp_channel->getNext()->getChannelRefs()));
  assertEqual($i + 1, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
  assertEqual($i, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());

  $exec_sql_request = new ExecuteSqlRequest();
  $exec_sql_request->setSession($session->getName());
  $exec_sql_request->setSql($sql_cmd);
  $exec_sql_call = $stub->ExecuteSql($exec_sql_request);
  array_push($exec_sql_calls, $exec_sql_call);
  array_push($sessions, $session);
  assertEqual(1, count($gcp_channel->getNext()->getChannelRefs()));
  assertEqual($i + 1, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
  assertEqual($i + 1, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());
  print_r($gcp_channel->getNext()->getChannelRefs());
}

$create_session_request = new CreateSessionRequest();
$create_session_request->setDatabase($database);
$create_session_call = $stub->CreateSession($create_session_request);
list($session, $status) = $create_session_call->wait();
assertStatusOk($status);
print_r($gcp_channel->getNext()->getChannelRefs());
assertEqual(2, count($gcp_channel->getNext()->getChannelRefs()));
assertEqual(2, $gcp_channel->getNext()->getChannelRefs()[1]->getAffinityRef());
assertEqual(2, $gcp_channel->getNext()->getChannelRefs()[1]->getActiveStreamRef());
assertEqual(1, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());

// The new request uses the new session id.
$exec_sql_request = new ExecuteSqlRequest();
$exec_sql_request->setSession($session->getName());
$exec_sql_request->setSql($sql_cmd);
$exec_sql_call = $stub->ExecuteSql($exec_sql_request);
assertEqual(2, count($gcp_channel->getNext()->getChannelRefs()));
assertEqual(2, $gcp_channel->getNext()->getChannelRefs()[1]->getAffinityRef());
assertEqual(2, $gcp_channel->getNext()->getChannelRefs()[1]->getActiveStreamRef());
assertEqual(1, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
assertEqual(1, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());

// Clear session and stream
list($exec_sql_reply, $status) = $exec_sql_call->wait();
assertStatusOk($status);
assertEqual($exec_sql_reply->getRows()[0]->getValues()[0]->getStringValue(), $result[0]);
assertEqual(2, count($gcp_channel->getNext()->getChannelRefs()));
assertEqual(2, $gcp_channel->getNext()->getChannelRefs()[1]->getAffinityRef());
assertEqual(2, $gcp_channel->getNext()->getChannelRefs()[1]->getActiveStreamRef());
assertEqual(1, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());

$delete_session_request = new DeleteSessionRequest();
$delete_session_request->setName($session->getName());
list($session, $status) = $stub->DeleteSession($delete_session_request)->wait();
assertStatusOk($status);
assertEqual(2, count($gcp_channel->getNext()->getChannelRefs()));
assertEqual(2, $gcp_channel->getNext()->getChannelRefs()[1]->getAffinityRef());
assertEqual(2, $gcp_channel->getNext()->getChannelRefs()[1]->getActiveStreamRef());
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());

for ($i=0; $i < $_WATER_MARK; $i++) {
  list($exec_sql_reply, $status) = $exec_sql_calls[$i]->wait();
  assertStatusOk($status);
  assertEqual($exec_sql_reply->getRows()[0]->getValues()[0]->getStringValue(), $result[0]);
  assertEqual(2, count($gcp_channel->getNext()->getChannelRefs()));
  assertEqual(2 - $i, $gcp_channel->getNext()->getChannelRefs()[1]->getAffinityRef());
  assertEqual(1 - $i, $gcp_channel->getNext()->getChannelRefs()[1]->getActiveStreamRef());
  assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
  assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());

  $delete_session_request = new DeleteSessionRequest();
  $delete_session_request->setName($sessions[$i]->getName());
  list($session, $status) = $stub->DeleteSession($delete_session_request)->wait();
  assertEqual(2, count($gcp_channel->getNext()->getChannelRefs()));
  assertEqual(1 - $i, $gcp_channel->getNext()->getChannelRefs()[1]->getAffinityRef());
  assertEqual(1 - $i, $gcp_channel->getNext()->getChannelRefs()[1]->getActiveStreamRef());
  assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getAffinityRef());
  assertEqual(0, $gcp_channel->getNext()->getChannelRefs()[0]->getActiveStreamRef());
}


