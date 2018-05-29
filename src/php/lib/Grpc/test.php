<?php
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
      '/dummy_method',
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
  public function StreamCall(
    $metadata = [],
    $options = []
  ) {
    return $this->_serverStreamRequest('/dummy_method', [], $metadata, $options);
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
  private $real_channel;
  private $real_call;

  public function __construct($gcp_channel, $method, $deserialize, $options) {
    echo "construct MyUnaryCall\n";
    $this->gcp_channel = $gcp_channel;
    $this->rpcPreProcess($gcp_channel);
    echo "channel: ".get_class($this->real_channel)."\n";
    echo "method: ".$method."\n";
    $this->real_call = new \Grpc\ServerStreamingCall($this->real_channel, $method, $deserialize, $options);
  }

  private function rpcPreProcess($gcp_channel) {
      $this->real_channel = $gcp_channel->getChannelRef()->getRealChannel();
  }

  private function rpcPostProcess() {

  }

  public function start($data, array $metadata = [], array $options = []) {
    echo "real_call: ".get_class($this->real_call)."\n";
    echo "data: ".get_class($data)."\n";
    $this->real_call->start($data, $metadata, $options);
  }

  public function getStatus() {
    $status = $this->real_call->getStatus();
    if($status == 0) {
        $this->rpcPostProcess();
    }
  }
}

class GrpcExtensionChannel implements Grpc\CustomChannel
{
    private $max_size;
    private $max_concurrent_streams_low_watermark;
    private $target;
    private $options;
//    private $credentials;
    private $affinity_by_method = array();
    private $affinity_key_to_channel = array();
    private $channel_refs = array();

    public function __construct($hostname, $opts) {
      $this->max_size = 10;
      $this->max_concurrent_streams_low_watermark = 1;
      $this->target = $hostname;
      $this->options = array();
//      $this->credentials = null;
      $this->affinity_by_method = $GLOBALS['affinity_by_method'];
      $this->affinity_key_to_channel = array();
      $this->channel_refs = array();
    }

    public function _UnaryStreamCallFactory() {
        echo "run _GetServerStreamCallFactory\n";
        return function($method, $deserialize, $options) {
          echo "get _GetServerStreamCallFactory\n";
            return new MyServerStreamCall($this, $method, $deserialize, $options);
        };
    }

    public function getChannelRef() {
      $channel =  new \Grpc\Channel($this->target, $this->options);
      $channel_ref = new _ChannelRef($channel);
      return $channel_ref;
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

/*
$hostname = 'localhost:'.$port;
$opts = array();
$channel = new GrpcExtensionChannel($hostname, $opts);
$stub = new SimpleClient($hostname, $opts, $channel);
$server_streaming_call = $stub->StreamCall();


$req_text = 'client_request';
$req = new SimpleRequest($req_text);
$server_streaming_call->start($req);
$event = $server->requestCall();
echo $event->method."\n";
print_r($event->metadata);
*/

