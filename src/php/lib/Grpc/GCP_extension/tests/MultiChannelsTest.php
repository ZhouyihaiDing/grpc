<?php
header('Content-type: text/plain');

require_once(dirname(__FILE__).'/vendor/autoload.php');
require_once(dirname(__FILE__).'/../src/ChannelRef.php');
require_once(dirname(__FILE__).'/../src/GCPConfig.php');
require_once(dirname(__FILE__).'/../src/GCPCallInvoker.php');
require_once(dirname(__FILE__).'/../src/GCPExtensionChannel.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/ExtensionConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/AffinityConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/AffinityConfig_Command.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/ApiConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/ChannelPoolConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/MethodConfig.php');
require_once(dirname(__FILE__).'/generated/GPBMetadata/GrpcGcp.php');

use Google\Cloud\Spanner\V1\SpannerGrpcClient;
use Google\Cloud\Spanner\V1\CreateSessionRequest;
use Google\Cloud\Spanner\V1\DeleteSessionRequest;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\ApcuAdapter;

use Google\Auth\ApplicationDefaultCredentials;

putenv("GOOGLE_APPLICATION_CREDENTIALS=./grpc-gcp.json");

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

$cacheItemPool = new FilesystemAdapter();
$config = new \Grpc\GCP\Config($conf);
$opts['grpc_call_invoker'] = $config->callInvoker();
$stub = new SpannerGrpcClient($hostname, $opts, $config->channel());
$gcp_channel = $config->channel();

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
  $result = (count($gcp_channel->getChannelRefs()) == 1);
  assertEqual(1, count($gcp_channel->getChannelRefs()));
}
//print_r($gcp_channel->getChannelRefs());


// Test CreateSession New Channel
$rpc_calls = array();
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++){
  $create_session_request = new CreateSessionRequest();
  $create_session_request->setDatabase($database);
  $create_session_call = $stub->CreateSession($create_session_request);
  $result = (count($gcp_channel->getChannelRefs()) == $i+1);
  print_r($gcp_channel->getChannelRefs());
  assertEqual($i+1, count($gcp_channel->getChannelRefs()));
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
  $result = (count($gcp_channel->getChannelRefs()) == $_DEFAULT_MAX_CHANNELS_PER_TARGET);
  assertEqual($_DEFAULT_MAX_CHANNELS_PER_TARGET,
      count($gcp_channel->getChannelRefs()));
}

$rpc_calls = array();
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++){
  echo "feature =================================\n";
  $create_session_request = new CreateSessionRequest();
  $create_session_request->setDatabase($database);
  $create_session_call = $stub->CreateSession($create_session_request);
  $result = (count($gcp_channel->getChannelRefs()) == $_DEFAULT_MAX_CHANNELS_PER_TARGET);
  assertEqual($_DEFAULT_MAX_CHANNELS_PER_TARGET,
      count($gcp_channel->getChannelRefs()));
  array_push($rpc_calls, $create_session_call);
}
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++) {
  list($session, $status) = $rpc_calls[$i]->wait();
  $delete_session_request = new DeleteSessionRequest();
  $delete_session_request->setName($session->getName());
  list($session, $status) = $stub->DeleteSession($delete_session_request)->wait();
  assertStatusOk($status);
}
//print_r($gcp_channel->getChannelRefs());


// Test Bound_ Unbind with Invalid Affinity Key


