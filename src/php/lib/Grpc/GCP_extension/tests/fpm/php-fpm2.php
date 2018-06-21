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

assertEqual(2, count($call_invoker->_getChannel()->getChannelRefs()));
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[1]->getAffinityRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[1]->getActiveStreamRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getAffinityRef());
assertEqual(0, $call_invoker->_getChannel()->getChannelRefs()[0]->getActiveStreamRef());

