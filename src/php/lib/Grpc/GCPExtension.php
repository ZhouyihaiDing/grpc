<?php

namespace Grpc;

require_once(dirname(__FILE__).'/generated/Grpc_gcp/ExtensionConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/AffinityConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/AffinityConfig_Command.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/ApiConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/ChannelPoolConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc_gcp/MethodConfig.php');
require_once(dirname(__FILE__).'/generated/GPBMetadata/GrpcGcp.php');


use Google\Auth\ApplicationDefaultCredentials;

class GCPCallInterceptor extends \Grpc\Interceptor
{
  public function interceptUnaryUnary($method,
                                      $argument,
                                      array $metadata = [],
                                      array $options = [],
                                      $continuation)
  {
    $call = new GCPUnaryCall(
        $continuation($method, $argument, $metadata, $options),
        $argument, $metadata);
    $call->start();
    return $call;
  }

  public function interceptUnaryStream($method,
                                       $argument,
                                       array $metadata = [],
                                       array $options = [],
                                       $continuation
  ) {
    $call = new GCPServerStreamCall(
        $continuation($method, $argument, $metadata, $options),
        $argument, $metadata);
    $call->start();
    return $call;
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
//      $this->opts = $opts;
      $this->real_channel = new \Grpc\Channel();
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


class GCPUnaryCall extends GcpBaseCall
{
  private function createRealCall($channel) {
    $this->real_call = new UnaryCall($channel, $this->method, $this->deserialize, $this->options);
    return $this->real_call;
  }

  public function start() {
    $channel_ref = $this->rpcPreProcess($this->argument);
    $this->real_call = $this->createRealCall($channel_ref->getRealChannel());
    $this->real_call->start($this->argument, $this->metadata, $this->options);
  }

  public function wait() {
    list($response, $status) = $this->real_call->wait();
    $this->rpcPostProcess($status, $response);
    return [$response, $status];
  }

  public function getMetadata() {
    return $this->real_call->getMetadata();
  }
}

class GCPServerStreamCall extends GcpBaseCall
{
  private $response = null;

  private function createRealCall($channel) {
    $this->real_call = new ServerStreamingCall($channel, $this->method, $this->deserialize, $this->options);
    return $this->real_call;
  }

  public function start() {
    $channel_ref = $this->rpcPreProcess($this->argument);
    $this->real_call = $this->createRealCall($channel_ref->getRealChannel());
    $this->real_call->start($this->argument, $this->metadata, $this->options);
  }

  public function responses() {
    $response = $this->real_call->responses();
    if($response) {
      $this->response = $response;
    }
    return $response;
  }

  public function getStatus() {
    $status = $this->real_call->getStatus();
    $this->rpcPostProcess($status, $this->response);
    return $status;
  }
}

abstract class GcpBaseCall
{
  protected $gcp_channel;
  protected $channel_ref;
  protected $affinity_key;
  protected $_affinity;

  protected $method;
  protected $argument;
  protected $metadata;
  protected $options;

  // Get all information needed to create a Call object and start the Call.
  public function __construct($empty_unary_unary_call,
                              $argument,
                              $metadata) {
    $empty_call = $empty_unary_unary_call->_getCall();
    $this->gcp_channel = $empty_call->_getChannel();
    $this->method = $empty_call->_getMethod();
    $this->deserialize = $empty_call->_getDeserialize();
    $this->options = $empty_call->_getOptions();
    $this->metadata = $empty_call->_getMetadata();
    $this->argument = $argument;
    $this->_affinity = $GLOBALS['affinity_by_method'][$this->method];
  }

  protected function rpcPreProcess($argument) {
    $this->affinity_key = null;
    if($this->_affinity) {
      $command = $this->_affinity['command'];
      if ($command == 'BOUND' || $command == 'UNBIND') {
        $this->affinity_key = $this->getAffinityKeyFromProto($argument);
      }
    }
    $this->channel_ref = $this->gcp_channel->getChannelRef($this->affinity_key);
    $this->channel_ref->activeStreamRefIncr();
    return $this->channel_ref;
  }

  protected function rpcPostProcess($status, $response) {
//    $gcp_channel = $GLOBALS['gcp_channel'.getmypid()];
    if($this->_affinity) {
      $command = $this->_affinity['command'];
      if ($command == 'BIND') {
        if ($status->code != \Grpc\STATUS_OK) {
          return;
        }
        $affinity_key = $this->getAffinityKeyFromProto($response);
        $this->gcp_channel->_bind($this->channel_ref, $affinity_key);
      } else if ($command == 'UNBIND') {
        $this->gcp_channel->_unbind($this->affinity_key);
      }
//      $GLOBALS['gcp_channel' . getmypid()] = $gcp_channel;
    }
    $this->channel_ref->activeStreamRefDecr();
  }

  protected function getAffinityKeyFromProto($proto) {
    if($this->_affinity) {
      $names = $this->_affinity['affinityKey'];
      // TODO: names.split('.')
      $getAttrMethod = 'get'.ucfirst($names);
      $affinity_key = call_user_func_array(array($proto, $getAttrMethod), array());
      echo "[getAffinityKeyFromProto] $affinity_key\n";
      return $affinity_key;
    }
  }
}

class GrpcExtensionChannel
{
  public $max_size;
  public $max_concurrent_streams_low_watermark;
  public $target;
  public $options;
  public $affinity_by_method = array(); // <= should be global.
  public $affinity_key_to_channel_ref;
  public $channel_refs = array();
  public $update_metadata;

  public function getChannelRefs() {
    return $this->channel_refs;
  }

  public function __construct($hostname, $opts) {
    $this->max_size = 10;
    $this->max_concurrent_streams_low_watermark = 1;
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
    $this->affinity_by_method = $GLOBALS['affinity_by_method'];
    $this->affinity_key_to_channel_ref = array();
    $this->channel_refs = array();
  }

  public function _bind($channel_ref, $affinity_key)
  {
    if (!array_key_exists($affinity_key, $this->affinity_key_to_channel_ref)) {
      $this->affinity_key_to_channel_ref[$affinity_key] = $channel_ref;
      echo "[bind]\n";
    }
    $channel_ref->affinityRefIncr();
//    print_r($this->channel_refs);
    return $channel_ref;
  }

  public function _unbind($affinity_key)
  {
//    print_r($this->affinity_key_to_channel_ref);
//    print_r($this->channel_refs);
    $channel_ref = null;
    if (array_key_exists($affinity_key, $this->affinity_key_to_channel_ref)) {
      echo "[unbind]\n";
      $channel_ref =  $this->affinity_key_to_channel_ref[$affinity_key];
      $channel_ref->affinityRefDecr();
    }
    return $channel_ref;
  }

  function cmp_by_active_stream_ref($a, $b) {
    return $a->getActiveStreamRef() - $b->getActiveStreamRef();
  }


  public function getChannelRef($affinity_key = null) {
//    echo "[getChannelRef] with key $affinity_key\n";
    if ($affinity_key) {
      if (array_key_exists($affinity_key, $this->affinity_key_to_channel_ref)) {
        return $this->affinity_key_to_channel_ref[$affinity_key];
      }
      return $this->getChannelRef();
    }
    usort($this->channel_refs, array($this, 'cmp_by_active_stream_ref'));

    foreach ($this->channel_refs as $channel_ref) {
      if($channel_ref->getActiveStreamRef() <
        $this->max_concurrent_streams_low_watermark) {
//        echo "[getChannelRef] max_concurrent_streams_low_watermark\n";
        return $channel_ref;
      } else {
        break;
      }
    }
    $num_channel_refs = count($this->channel_refs);
    if ($num_channel_refs < $this->max_size) {
      $cur_opts = array_merge($this->options,
        ['grpc_gcp_channel_id' => $num_channel_refs,
          'grpc_target_persist_bound' => $this->max_size]);
      $channel = new \Grpc\Channel($this->target, $cur_opts);
      $channel_ref = new _ChannelRef($channel, $num_channel_refs);
      array_unshift($this->channel_refs, $channel_ref);
    }
    echo "[getChannelRef] channel_refs ";
//    print_r($this->channel_refs);
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
  // Parse affinity protobuf object
  $config = json_decode($conf->serializeToJsonString(), true);
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
  $GLOBALS['affinity_by_method'] = $aff_by_method;

  // Create GCP channel based on the information.
  $hostname = $api_conf['target'][0];
  $credentials = \Grpc\ChannelCredentials::createSsl();
  $auth = ApplicationDefaultCredentials::getCredentials();
  $opts = [
    'credentials' => $credentials,
    'update_metadata' => $auth->getUpdateMetadataFunc(),
  ];
  $channel = new \Grpc\GrpcExtensionChannel($hostname, $opts);
  $channel_interceptor = new \Grpc\GCPCallInterceptor();
  $gcp_channel = \Grpc\Interceptor::intercept($channel, $channel_interceptor);

  // Push channel into the pool.
  apcu_add('gcp_channel'.getmypid(), $channel);

  $GLOBALS['gcp_channel'.getmypid()] = $channel;
  return $gcp_channel;
}
