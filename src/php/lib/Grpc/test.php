<?php
require_once(dirname(__FILE__).'/vendor/autoload.php');
require_once(dirname(__FILE__).'/BaseStub.php');
require_once(dirname(__FILE__).'/AbstractCall.php');
require_once(dirname(__FILE__).'/UnaryCall.php');
require_once(dirname(__FILE__).'/ClientStreamingCall.php');
require_once(dirname(__FILE__).'/ServerStreamingCall.php');
require_once(dirname(__FILE__).'/Interceptor.php');
require_once(dirname(__FILE__).'/CustomChannel.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/ExtensionConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/AffinityConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/AffinityConfig_Command.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/ApiConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/ChannelPoolConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/MethodConfig.php');
require_once(dirname(__FILE__).'/generated/GPBMetadata/GrpcGcp.php');

use Google\Cloud\Spanner\V1\SpannerGrpcClient;
use Google\Auth\ApplicationDefaultCredentials;
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

//require_once(dirname(__FILE__).'/../../lib/Grpc/Internal/InterceptorChannel.php');

$server = new \Grpc\Server([]);
$port = $server->addHttp2Port('0.0.0.0:0');
//$channel = new \Grpc\Channel('localhost:'.$port,
//  ['credentials' => Grpc\ChannelCredentials::createInsecure()]);
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

  /**
   * @param string $hostname hostname
   * @param array $opts channel options
   * @param Channel|InterceptorChannel $channel (optional) re-use channel object
   */
  public function __construct($hostname, $opts, $channel = null)
  {
    parent::__construct($hostname, $opts, $channel);
  }

  /**
   * A simple RPC.
   * @param SimpleRequest $argument input argument
   * @param array $metadata metadata
   * @param array $options call options
   */
  public function UnaryCall(
    SimpleRequest $argument,
    $metadata = [],
    $options = []
  ) {
    return $this->_simpleRequest(
      '/google.spanner.v1.Spanner/DeleteSession',
      $argument,
      [],
      $metadata,
      $options
    );
  }

  /**
   * A client-to-server streaming RPC.
   * @param array $metadata metadata
   * @param array $options call options
   */
  public function BindCall(
    $metadata = [],
    $options = []
  ) {
    return $this->_serverStreamRequest('/google.spanner.v1.Spanner/CreateSession', [], $metadata, $options);
  }

  public function BoundCall(
    $metadata = [],
    $options = []
  ) {
    return $this->_serverStreamRequest('/google.spanner.v1.Spanner/GetSession', [], $metadata, $options);
  }
}


class _ChannelRef
{
  private $real_channel;
  private $opts;
  private $channel_id;
  private $affinity_ref;
  private $active_stream_ref;
  public function __construct($channel, $channel_id, $affinity_ref=0, $active_stream_ref=0)
  {
    if ($channel) {
      $this->real_channel = $channel;
    } else {
      $this->opts = $opts;
//      $this->real_channel = new Grpc\Channel();
    }

    $this->channel_id = $channel_id;
    $this->affinity_ref = $affinity_ref;
    $this->active_stream_ref = $active_stream_ref;
  }

  public function getRealChannel() {return $this->real_channel;}
  public function getAffinityRef() {return $this->affinity_ref;}
  public function getActiveStreamRef() {return $this->active_stream_ref;}
  public function affinityRefIncr() {$this->affinity_ref += 1;}
  public function affinityRefDecr() {$this->affinity_ref -= 1;}
  public function activeStreamRefIncr() {$this->active_stream_ref += 1;}
  public function activeStreamRefDecr() {$this->active_stream_ref -= 1;}
}


// Default
class MyServerStreamCall
{
  private $gcp_channel;
  private $channel_ref;
  private $affinity_key;
  private $real_call;
  private $response;
  private $method;

  public function __construct($gcp_channel, $method, $deserialize, $options) {
    echo "construct MyStreamCall\n";
    $this->gcp_channel = $gcp_channel;
    $this->method = $method;
    list($channel_ref, $affinity_key) = $this->rpcPreProcess($method);
    $this->channel_ref = $channel_ref;
    $this->affinity_key = $affinity_key;
    $this->method = $method;
    echo "method: ".$method."\n";
    $this->real_call = new \Grpc\ServerStreamingCall($channel_ref->getRealChannel(), $method, $deserialize, $options);
  }

  private function rpcPreProcess($method) {
    $affinity_key = null;
    if(array_key_exists($method, $GLOBALS['affinity_by_method'])) {
      $command = $GLOBALS['affinity_by_method'][$method]['command'];
      echo "preprocess find command: ". $command. "\n";
      if ($command == 'BOUND' || $command == 'UNBIND') {
        $affinity_key = $GLOBALS['affinity_by_method'][$method]['affinityKey'];
        echo "preprocess find affinity_key: ". $affinity_key. "\n";
      }
    }
    echo "preprocess find affinity_key: ". $affinity_key. "\n";
    $channel_ref = $this->gcp_channel->getChannelRef($affinity_key);
    $channel_ref->activeStreamRefIncr();
    return [$channel_ref, $affinity_key];
  }

  private function rpcPostProcess($status) {
    if ($GLOBALS['affinity_by_method'][$this->method]['command'] == 'BIND') {
      if($status->code != Grpc\STATUS_OK) {
        return;
      }
      $affinity_key = $GLOBALS['affinity_by_method'][$this->method]['affinityKey'];
      $this->gcp_channel->_bind($this->channel_ref, $affinity_key);
    } else if ($GLOBALS['affinity_by_method'][$this->method]['command'] == 'UNBIND') {
      $this->gcp_channel->_unbind($this->affinity_key);
    }
  }

  public function start($data, array $metadata = [], array $options = []) {
    echo "real_call: ".get_class($this->real_call)."\n";
    echo "data: ".get_class($data)."\n";

    $this->real_call->start($data, $metadata, $options);
  }

  public function responses() {
    $response = $this->real_call->responses();
    return $response;
  }

  public function getStatus() {
    $status = $this->real_call->getStatus();
    $this->rpcPostProcess($status);
    return $status;
  }
}

class MyUnaryCall
{
  private $gcp_channel;
  private $channel_ref;
  private $affinity_key;
  private $real_call;
  private $response;
  private $method;

  public function __construct($gcp_channel, $method, $deserialize, $options) {
    echo "construct MyStreamCall\n";
    $this->gcp_channel = $gcp_channel;
    $this->method = $method;
    list($channel_ref, $affinity_key) = $this->rpcPreProcess($method);
    $this->channel_ref = $channel_ref;
    $this->affinity_key = $affinity_key;
    $this->method = $method;
    echo "method: ".$method."\n";
    $this->real_call = new \Grpc\UnaryCall($channel_ref->getRealChannel(), $method, $deserialize, $options);
    echo "call construct ends\n";
  }

  private function rpcPreProcess($method) {
    $affinity_key = null;
    if(array_key_exists($method, $GLOBALS['affinity_by_method'])) {
      $command = $GLOBALS['affinity_by_method'][$method]['command'];
      echo "preprocess find command: ". $command. "\n";
      if ($command == 'BOUND' || $command == 'UNBIND') {
        $affinity_key = $GLOBALS['affinity_by_method'][$method]['affinityKey'];
        echo "preprocess find affinity_key: ". $affinity_key. "\n";
      }
    }
    echo "preprocess find affinity_key: ". $affinity_key. "\n";
    $channel_ref = $this->gcp_channel->getChannelRef($affinity_key);
    $channel_ref->activeStreamRefIncr();
    return [$channel_ref, $affinity_key];
  }

  private function rpcPostProcess($status) {
    if ($GLOBALS['affinity_by_method'][$this->method]['command'] == 'BIND') {
      if($status->code != Grpc\STATUS_OK) {
        return;
      }
      $affinity_key = $GLOBALS['affinity_by_method'][$this->method]['affinityKey'];
      $this->gcp_channel->_bind($this->channel_ref, $affinity_key);
    } else if ($GLOBALS['affinity_by_method'][$this->method]['command'] == 'UNBIND') {
      $this->gcp_channel->_unbind($this->affinity_key);
    }
  }

  private function _get_jwt_aud_uri($method)
  {
    $last_slash_idx = strrpos($method, '/');
    if ($last_slash_idx === false) {
      throw new \InvalidArgumentException(
        'service name must have a slash'
      );
    }
    $service_name = substr($method, 0, $last_slash_idx);

//    if ($this->hostname_override) {
//      $hostname = $this->hostname_override;
//    } else {
      $hostname = $this->gcp_channel->getTarget();
      echo "hostname: ". $hostname."\n";
//    }

    return 'https://'.$hostname.$service_name;
  }
  private function _validate_and_normalize_metadata($metadata)
  {
    $metadata_copy = [];
    foreach ($metadata as $key => $value) {
      if (!preg_match('/^[A-Za-z\d_-]+$/', $key)) {
        throw new \InvalidArgumentException(
          'Metadata keys must be nonempty strings containing only '.
          'alphanumeric characters, hyphens and underscores'
        );
      }
      $metadata_copy[strtolower($key)] = $value;
    }

    return $metadata_copy;
  }


  public function start($data, array $metadata = [], array $options = []) {
    echo "real_call: ".get_class($this->real_call)."\n";
    echo "data: ".get_class($data)."\n";
    $jwt_aud_uri = $this->_get_jwt_aud_uri($this->method);
    echo "jwt_aud_uri: ". $jwt_aud_uri. "\n";
    if (is_callable($this->gcp_channel->update_metadata)) {
      $metadata = call_user_func(
        $this->gcp_channel->update_metadata,
        $metadata,
        $jwt_aud_uri
      );
    }
    $metadata = $this->_validate_and_normalize_metadata(
      $metadata
    );
    print_r($metadata);
    $this->real_call->start($data, $metadata, $options);
  }

  public function wait() {
    $response = $this->real_call->wait();
    return $response;
  }

  public function getStatus() {
    $status = $this->real_call->getStatus();
    $this->rpcPostProcess($status);
    return $status;
  }

  public function getMetadata() {
    return $this->real_call->getMetadata();
  }
}

class GrpcExtensionChannel implements Grpc\CustomChannel
{
    private $max_size;
    private $max_concurrent_streams_low_watermark;
    private $target;
    private $options;
//    private $credentials;
    private $affinity_by_method = array(); // <= should be global.
    private $affinity_key_to_channel_ref = array();
    private $channel_refs = array();
    public $update_metadata;

    public function __construct($hostname, $opts) {
      $this->max_size = 10;
      $this->max_concurrent_streams_low_watermark = 0;
      $this->target = $hostname;
      if (isset($opts['update_metadata'])) {
        if (is_callable($opts['update_metadata'])) {
          $this->update_metadata = $opts['update_metadata'];
        }
        unset($opts['update_metadata']);
      }
      $package_config = json_decode(
        file_get_contents(dirname(__FILE__).'/../../composer.json'),
        true
      );
      if (!empty($cur_opts['grpc.primary_user_agent'])) {
        $opts['grpc.primary_user_agent'] .= ' ';
      } else {
        $opts['grpc.primary_user_agent'] = '';
      }
      $opts['grpc.primary_user_agent'] .=
        'grpc-php/'.$package_config['version'];
      $this->options = $opts;
      var_dump($opts);
//      $this->credentials = null;
      $this->affinity_by_method = $GLOBALS['affinity_by_method'];
      $this->affinity_key_to_channel_ref = array();
      $this->channel_refs = array();
    }

    public function _UnaryStreamCallFactory() {
        echo "run _GetServerStreamCallFactory\n";
        return function($method, $deserialize, $options) {
            echo "get _GetServerStreamCallFactory\n";
            return new MyServerStreamCall($this, $method, $deserialize, $options);
        };
    }

  public function _UnaryUnaryCallFactory($deserialize) {
    echo "run _GetServerStreamCallFactory\n";
    return function($method, $argument, $metadata, $options) use ($deserialize) {
      echo "get _GetServerStreamCallFactory\n";
      $call = new MyUnaryCall($this, $method, $deserialize, $options);
      $call->start($argument, $metadata, $options);
      return $call;
    };
  }

  public function _bind($channel_ref, $affinity_key)
  {
    if (!array_key_exists($affinity_key, $this->affinity_key_to_channel_ref)) {
      echo "keyyyyyyyyyyyyyy: ".$affinity_key."\n";
      $this->affinity_key_to_channel_ref[$affinity_key] = $channel_ref;
      $channel_ref->affinityRefIncr();
    }
    return $channel_ref;
  }

  public function _unbind($affinity_key)
  {
    $channel_ref = null;
    if (array_key_exists($affinity_key, $this->affinity_key_to_channel_ref)) {
      $channel_ref =  $this->affinity_key_to_channel_ref[$affinity_key];
      $channel_ref->affinityRefDecr();
    }
    return $channel_ref;
  }

  function cmp_by_active_stream_ref($a, $b) {
    return $a->getActiveStreamRef() - $b->getActiveStreamRef();
  }


    public function getChannelRef($affinity_key = null) {
      print_r($this->affinity_key_to_channel_ref);
      if ($affinity_key) {
        if (array_key_exists($affinity_key, $this->affinity_key_to_channel_ref)) {
          echo "getChannel find Channel_ref\n";
          return $this->affinity_key_to_channel_ref[$affinity_key];
        }
        return $this->getChannelRef();
      }
      usort($this->channel_refs, array($this, 'cmp_by_active_stream_ref'));

      foreach ($this->channel_refs as $channel_ref) {
        if($channel_ref->getActiveStreamRef() <
          $this->max_concurrent_streams_low_watermark) {
          return $channel_ref;
        } else {
          break;
        }
      }
      echo "getChannel create a new Channel_ref\n";
      $num_channel_refs = count($this->channel_refs);
      if ($num_channel_refs < $this->max_size) {
        $cur_opts = array_merge($this->options,
                                ['grpc_gcp_channel_id' => $num_channel_refs,
                                  'grpc_target_persist_bound' => $this->max_size]);
        var_dump($cur_opts);
        $channel = new \Grpc\Channel($this->target, $cur_opts);
        $channel_ref = new _ChannelRef($channel, $num_channel_refs);
        array_unshift($this->channel_refs, $channel_ref);
      }
      return $this->channel_refs[0];
    }

    private function connectivityFunc($func, $args = null) {
        $ready = 0;
        $idle = 0;
        $connecting = 0;
        $transient_failure = 0;
        $shutdown = 0;
        foreach ($this->channel_refs as $channel_ref) {
            switch ($channel_ref->$func($args)) {
                case \Grpc\CHANNEL_READY:
                    $ready += 1;
                case \Grpc\CHANNEL_SHUTDOWN:
                   $shutdown += 1;
                case \Grpc\CHANNEL_CONNECTING:
                    $connecting += 1;
                case \Grpc\CHANNEL_TRANSIENT_FAILURE:
                   $transient_failure += 1;
                case \Grpc\CHANNEL_IDLE:
                   $idle += 1;
            }
        }
        if ($ready > 0) {
           return \Grpc\CHANNEL_READY;
        } else if ($idle > 0) {
            return \Grpc\CHANNEL_IDLE;
        } else if ($connecting > 0) {
           return \Grpc\CHANNEL_CONNECTING;
        } else if ($transient_failure > 0) {
            return \Grpc\CHANNEL_TRANSIENT_FAILURE;
        } else if ($shutdown > 0) {
           return \Grpc\CHANNEL_SHUTDOWN;
        }
    }

    public function getConnectivityState($try_to_connect) {
        return $this->connectivityFunc('getConnectivityState', $try_to_connect);
    }

    public function watchConnectivityState() {
       return $this->connectivityFunc('watchConnectivityState');
    }

    public function getTarget() {
        return $this->target;
    }
}

function enable_grpc_gcp($conf) {
  echo "enable_grpc_gcp\n";

//  $conf = new Grpc_gcp\ExtensionConfig();
//
//  $m1 = new Grpc_gcp\MethodConfig();
//  $aff1 = new Grpc_gcp\AffinityConfig();
//  $aff1->setAffinityKey("Aff1");
//  $m1->setName(["name1"]);
//  $m1->setAffinity($aff1);
//
//  $m2 = new Grpc_gcp\MethodConfig();
//  $aff2 = new Grpc_gcp\AffinityConfig();
//  $aff2->setAffinityKey("Aff2");
//  $aff2->setCommand(1);
//  $m2->setName(["name2"]);
//  $m2->setAffinity($aff2);
//  $apiconf = new Grpc_gcp\ApiConfig();
//  $apiconf->setTarget(["spanner.googleapis.com", "spanner.googleapis.com:443"]);
//  $apiconf->setMethod([$m1, $m2]);
//
//  $channel_pool = new Grpc_gcp\ChannelPoolConfig();
//  $channel_pool->setMaxSize(10);
//  $channel_pool->setMaxConcurrentStreamsLowWatermark(100);
//
//  $apiconf->setChannelPool($channel_pool);
//
//  $conf->setApi([$apiconf]);
//
//  var_dump($conf->serializeToJsonString());
//   $conf->mergeFromJsonString(json_encode($config));
   foreach ($conf->getApi() as $tmp) {
     $methods = $tmp->getMethod();
     foreach($methods as $method){
       echo $method->getAffinity()->getCommand()."\n";
     }
   }
   var_dump($conf->serializeToJsonString());
   $config = json_decode($conf->serializeToJsonString(), true);
   var_dump($config);
   $api_conf = $config['api'][0];
   $GLOBALS['target'] = $api_conf['target'];
   $GLOBALS['channelPool'] = $api_conf['channelPool'];
   $aff_by_method = array();
   for($i=0; $i<count($api_conf['method']); $i++) {
     // In proto3, if the value is default, eg 0 for int, it won't be serialized.
     // Thus serialized string may not have `command` if the value is default 0(BOUND).
     if (!array_key_exists('command', $api_conf['method'][$i]['affinity'])) {
       $api_conf['method'][$i]['affinity']['command'] = 'BOUND';
     }
     $aff_by_method[$api_conf['method'][$i]['name'][0]] = $api_conf['method'][$i]['affinity'];
   }
   print_r($aff_by_method);
   $GLOBALS['affinity_by_method'] = $aff_by_method;
}

echo "==============================\n";
$string = file_get_contents("spanner.grpc.config");
//$spanner_config = json_decode($string, true);
$conf = new Grpc_gcp\ExtensionConfig();
$conf->mergeFromJsonString($string);
enable_grpc_gcp($conf);


$hostname = 'localhost:'.$port;
$opts = array();
//$channel = new GrpcExtensionChannel($hostname, $opts);
/*
$stub = new SimpleClient($hostname, $opts, $channel);
$server_streaming_call = $stub->BindCall();


$req_text = 'client_request';
$req = new SimpleRequest($req_text);
$server_streaming_call->start($req);
$event = $server->requestCall();
echo $event->method."\n";

print_r($event->metadata);
$server_call = $event->call;

$reply_text = 'reply:client_server_full_request_response';
$status_text = 'status:client_server_full_response_text';
$event = $server_call->startBatch([
  Grpc\OP_SEND_INITIAL_METADATA => [],
  Grpc\OP_SEND_STATUS_FROM_SERVER => [
    'metadata' => [],
    'code' => Grpc\STATUS_OK,
    'details' => $status_text,
  ],
  Grpc\OP_RECV_CLOSE_ON_SERVER => true,
]);
$server_streaming_call->getStatus();



//$server_streaming_call2 = $stub->BoundCall();
$server_streaming_call2 = $stub->UnaryCall($req);
$server_streaming_call2->start($req);
$event = $server->requestCall();
echo $event->method."\n";
print_r($event->metadata);
*/

echo "create spenner client\n";
$hostname = "spanner.googleapis.com";
$credentials = \Grpc\ChannelCredentials::createSsl();
$auth = ApplicationDefaultCredentials::getCredentials();
$opts = [
  'credentials' => $credentials,
  'update_metadata' => $auth->getUpdateMetadataFunc(),
];
// projects/{project ID}/instances/{instance ID}/databases/{database name}
$channel = new GrpcExtensionChannel($hostname, $opts);
$database = 'projects/ddyihai-firestore/instances/test-instance/databases/test-database';
$stub = new SpannerGrpcClient($host, $opts, $channel);





$create_session_request = new CreateSessionRequest();
$create_session_request->setDatabase($database);
$create_session_call = $stub->CreateSession($create_session_request);
list($session, $status) = $create_session_call->wait();
var_dump($status);

//$list_session_request = new ListSessionsRequest();
//$list_session_request->setDatabase($database);
//$session = $stub->ListSessions($list_session_request);
//list($reply, $status) = $session->wait();
//var_dump($status);
//var_dump($reply->getSessions());
//foreach ($reply->getSessions() as $session) {
//  echo "session:\n";
//  echo "name - ". $session->getName. PHP_EOL;
//}

$sql_cmd = "select LastName from Singers";
$exec_sql_request = new ExecuteSqlRequest();
$exec_sql_request->setSession($session->getName());
$exec_sql_request->setSql($sql_cmd);
$exec_sql_call = $stub->ExecuteSql($exec_sql_request);
list($exec_sql_reply, $status) = $exec_sql_call->wait();
var_dump($status);
foreach ($exec_sql_reply->getRows() as $row) {
  foreach($row->getValues() as $value) {
    var_dump($value->getStringValue());
  }
}



