<?php
header('Content-type: text/plain');

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
use Google\Auth\ApplicationDefaultCredentials;

putenv("GOOGLE_APPLICATION_CREDENTIALS=./grpc-gcp.json");
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
  $result = (count($call_invoker->_getChannel()->getChannelRefs()) == 1);
  assertEqual(1, count($call_invoker->_getChannel()->getChannelRefs()));
}
//print_r($call_invoker->_getChannel()->getChannelRefs());


// Test CreateSession New Channel
$rpc_calls = array();
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++){
  $create_session_request = new CreateSessionRequest();
  $create_session_request->setDatabase($database);
  $create_session_call = $stub->CreateSession($create_session_request);
  $result = (count($call_invoker->_getChannel()->getChannelRefs()) == $i+1);
  print_r($call_invoker->_getChannel()->getChannelRefs());
  assertEqual($i+1, count($call_invoker->_getChannel()->getChannelRefs()));
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
  $result = (count($call_invoker->_getChannel()->getChannelRefs()) == $_DEFAULT_MAX_CHANNELS_PER_TARGET);
  assertEqual($_DEFAULT_MAX_CHANNELS_PER_TARGET,
      count($call_invoker->_getChannel()->getChannelRefs()));
}

$rpc_calls = array();
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++){
  echo "feature =================================\n";
  $create_session_request = new CreateSessionRequest();
  $create_session_request->setDatabase($database);
  $create_session_call = $stub->CreateSession($create_session_request);
  $result = (count($call_invoker->_getChannel()->getChannelRefs()) == $_DEFAULT_MAX_CHANNELS_PER_TARGET);
  assertEqual($_DEFAULT_MAX_CHANNELS_PER_TARGET,
      count($call_invoker->_getChannel()->getChannelRefs()));
  array_push($rpc_calls, $create_session_call);
}
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++) {
  list($session, $status) = $rpc_calls[$i]->wait();
  $delete_session_request = new DeleteSessionRequest();
  $delete_session_request->setName($session->getName());
  list($session, $status) = $stub->DeleteSession($delete_session_request)->wait();
  assertStatusOk($status);
}
print_r($call_invoker->_getChannel()->getChannelRefs());

// Test Bound_ Unbind with Invalid Affinity Key


