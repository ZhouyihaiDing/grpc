<?php
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

function ChannelStatusToString($status) {
  switch ($status) {
    case \Grpc\CHANNEL_IDLE:
      return "CHANNEL_IDLE";
    case \Grpc\CHANNEL_CONNECTING:
      return "CHANNEL_CONNECTING";
    case \Grpc\CHANNEL_READY:
      return "CHANNEL_READY";
    case \Grpc\CHANNEL_TRANSIENT_FAILURE:
      return "CHANNEL_TRANSIENT_FAILURE";
    case \Grpc\CHANNEL_FATAL_FAILURE:
      return "CHANNEL_FATAL_FAILURE";
    default:
      return "Status($status)";
  }
}


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
function assertChannelStatus($var1, $var2) {
  if ($var1 != $var2) {
    throw new \Exception(ChannelStatusToString($var1). " not match with ". ChannelStatusToString($var2). "\n");
  }
}

// Create fake server/client and affinity information
$server = new Grpc\Server([]);
$server->addHttp2Port('0.0.0.0:50051');
$server->start();

class SimpleRequest
{
  private $data;
  public function __construct($data)
  {
    $this->data = $data;
  }
  public function setData($data)
  {
    $this->data = $data;
  }
  public function serializeToString()
  {
    return $this->data;
  }
}

class SimpleClient extends Grpc\BaseStub
{
  public function __construct($hostname, $opts, $channel = null)
  {
    parent::__construct($hostname, $opts, $channel);
  }

  public function BindUnaryMethod(
    SimpleRequest $argument,
    $metadata = [],
    $options = []
  ) {
    return $this->_simpleRequest(
      '/bind_unary_method',
      $argument,
      [],
      $metadata,
      $options
    );
  }

  public function UnbindUnaryMethod(
    SimpleRequest $argument,
    $metadata = [],
    $options = []
  ) {
    return $this->_simpleRequest(
      '/unbind_unary_method',
      $argument,
      [],
      $metadata,
      $options
    );
  }

  public function BoundUnaryMethod(
    SimpleRequest $argument,
    $metadata = [],
    $options = []
  ) {
    return $this->_simpleRequest(
      '/bound_unary_method',
      $argument,
      [],
      $metadata,
      $options
    );
  }
}


$proto_string = '
{
  "api":[
    {
      "target":["localhost:50051"],
    "channelPool":{"maxSize":10,"maxConcurrentStreamsLowWatermark":1},
    "method":[
      {"name":["/BindUnaryMethod"],
        "affinity":{"command":"BIND", "affinityKey":"test"}},
      {"name":["/UnbindUnaryMethod"],
        "affinity":{"command":"UNBIND", "affinityKey":"test"}},
      {"name":["/BoundUnaryMethod"],
        "affinity":{"command":"BOUND", "affinityKey":"test"}}
      ]
    }
  ]
}';

// Read GCP config.
$conf = new Grpc_gcp\ExtensionConfig();
$conf->mergeFromJsonString($proto_string);
// Options for creating the gRPC channel/stub.
$credentials = \Grpc\ChannelCredentials::createInsecure();
$opts = [
  'credentials' => $credentials,
];
$hostname = 'spanner.googleapis.com';

// Enable GCP.
$gcp_channel = \Grpc_gcp\enableGrpcGcp($conf, $opts);
$stub = new SimpleClient($hostname, $opts, $gcp_channel);

$_DEFAULT_MAX_CHANNELS_PER_TARGET = 3;
$rpc_calls = array();
$req_text = 'client_request';

// getConnectivity status test
assertChannelStatus($stub->getConnectivityState(true), \Grpc\CHANNEL_IDLE);
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++){
  $bind_request = new SimpleRequest($req_text);
  $create_session_call = $stub->BindUnaryMethod($bind_request);
  assertChannelStatus($stub->getConnectivityState(false), \Grpc\CHANNEL_READY);
  assertChannelStatus($gcp_channel->getNext()->getConnectivityState(false), \Grpc\CHANNEL_READY);
  $channel_refs = $gcp_channel->getNext()->getChannelRefs();
  for ($j=0; $j<=$i; $j++) {
    assertChannelStatus($channel_refs[$j]->getRealChannel()->getConnectivityState(false), \Grpc\CHANNEL_READY);
  }
  $result = (count($channel_refs) == $i+1);
  print_r($gcp_channel->getNext()->getChannelRefs());
  assertEqual($i+1, count($gcp_channel->getNext()->getChannelRefs()));
  array_push($rpc_calls, $create_session_call);
}

function serverResponse($event) {
  $server_call = $event->call;
  $event = $server_call->startBatch([
    Grpc\OP_SEND_INITIAL_METADATA => [],
    Grpc\OP_SEND_STATUS_FROM_SERVER => [
      'metadata' => [],
      'code' => \Grpc\STATUS_OK,
      'details' => '',
    ],
    Grpc\OP_RECV_MESSAGE => true,
    Grpc\OP_RECV_CLOSE_ON_SERVER => true,
  ]);
}

for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++) {
  $event = $server->requestCall();
  var_dump($event);
  serverResponse($event);
}

$channel_refs = $gcp_channel->getNext()->getChannelRefs();

for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++){
  list($session, $status) = $rpc_calls[$i]->wait();
  assertStatusOk($status);
  assertChannelStatus($channel_refs[$i]->getRealChannel()->getConnectivityState(false), \Grpc\CHANNEL_READY);
}

//list($session, $status) = $rpc_calls[$i]->wait();

