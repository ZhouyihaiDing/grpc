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

// Read GCP config.
$string = file_get_contents("spanner.grpc.config");
$conf = new Grpc_gcp\ExtensionConfig();
$conf->mergeFromJsonString($string);
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

$_DEFAULT_MAX_CHANNELS_PER_TARGET = 2;

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


// Test CreateSession New Channel
$rpc_calls = array();
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++){
  $create_session_request = new CreateSessionRequest();
  $create_session_request->setDatabase($database);
  $create_session_call = $stub->CreateSession($create_session_request);
  $result = (count($gcp_channel->getNext()->getChannelRefs()) == $i+1);
  print_r($gcp_channel->getNext()->getChannelRefs());
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
//print_r($gcp_channel->getNext()->getChannelRefs());


