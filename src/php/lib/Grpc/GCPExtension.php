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
    return new GCPUnaryCall($continuation($method, $argument, $metadata, $options));
  }

  public function interceptUnaryStream($method,
                                       $argument,
                                       array $metadata = [],
                                       array $options = [],
                                       $continuation
  ) {
    return new GCPServerStreamCall(
      $continuation($method, $argument, $metadata, $options));
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
      $this->real_channel = new \Grpc\Channel();
    }

    $this->channel_id = $channel_id;
    $this->affinity_ref = $affinity_ref;
    $this->active_stream_ref = $active_stream_ref;
  }

  public function _getChannel() {return $this->real_channel;}
  public function getAffinityRef() {return $this->affinity_ref;}
  public function getActiveStreamRef() {return $this->active_stream_ref;}
  public function affinityRefIncr() {$this->affinity_ref += 1;}
  public function affinityRefDecr() {$this->affinity_ref -= 1;}
  public function activeStreamRefIncr() {$this->active_stream_ref += 1;}
  public function activeStreamRefDecr() {$this->active_stream_ref -= 1;}
}

// Default
class GCPServerStreamCall
{
  private $channel_ref;
  private $affinity_key;
  private $real_call;
  private $method;

  public function __construct($unary_stream_call) {
    $this->channel_ref = $unary_stream_call->_getChannel();
    $this->method = $unary_stream_call->_getMethod();
    $this->real_call = $unary_stream_call;
  }

  private function rpcPostProcess($status) {
    $gcp_channel = apcu_fetch('gcp_channel');
    if ($GLOBALS['affinity_by_method'][$this->method]['command'] == 'BIND') {
      if($status->code != \Grpc\STATUS_OK) {
        return;
      }
      $affinity_key = $GLOBALS['affinity_by_method'][$this->method]['affinityKey'];
      $gcp_channel->_bind($this->channel_ref, $affinity_key);
    } else if ($GLOBALS['affinity_by_method'][$this->method]['command'] == 'UNBIND') {
      $gcp_channel->_unbind($this->affinity_key);
    }
  }

  public function start($data, array $metadata = [], array $options = []) {
    $jwt_aud_uri = $this->_get_jwt_aud_uri($this->method);
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


class GCPUnaryCall
{
  private $channel_ref;
  private $affinity_key;
  private $real_call;
  private $method;

  public function __construct($unary_unary_call) {
    $this->channel_ref = $unary_unary_call->_getChannel();
    $this->method = $unary_unary_call->_getMethod();
    $this->real_call = $unary_unary_call;
  }

  private function rpcPostProcess($status) {
    $gcp_channel = apcu_fetch('gcp_channel');
    if ($GLOBALS['affinity_by_method'][$this->method]['command'] == 'BIND') {
      if($status->code != \Grpc\STATUS_OK) {
        return;
      }
      $affinity_key = $GLOBALS['affinity_by_method'][$this->method]['affinityKey'];
      $gcp_channel->_bind($this->channel_ref, $affinity_key);
    } else if ($GLOBALS['affinity_by_method'][$this->method]['command'] == 'UNBIND') {
      $gcp_channel->_unbind($this->affinity_key);
    }
  }

  public function start($data, array $metadata = [], array $options = []) {
    $this->real_call->start($data, $metadata, $options);
  }

  public function wait() {
    list($session, $status) = $this->real_call->wait();
    $this->rpcPostProcess($status);
    return [$session, $status];
  }

  public function getMetadata() {
    return $this->real_call->getMetadata();
  }
}

class GrpcExtensionChannel
{
  public $max_size;
  public $max_concurrent_streams_low_watermark;
  public $target;
  public $options;
//    private $credentials;
  public $affinity_by_method = array(); // <= should be global.
  public $affinity_key_to_channel_ref = array();
  public $channel_refs = array();
  public $update_metadata;

  // Get a channel for creating the call object with $method.
  public function _getChannel($method) {
    $affinity_key = null;
    if(array_key_exists($method, $GLOBALS['affinity_by_method'])) {
      $command = $GLOBALS['affinity_by_method'][$method]['command'];
      echo "[rpc pre process] find command: ". $command. "\n";
      if ($command == 'BOUND' || $command == 'UNBIND') {
        $affinity_key = $GLOBALS['affinity_by_method'][$method]['affinityKey'];
        echo "[rpc pre process] find affinity_key: ". $affinity_key. "\n";
      }
    }
    echo "[rpc pre process] find affinity_key: ". $affinity_key. "\n";
    echo "[rpc pre process] find method: ". $method. "\n";
    $channel_ref = $this->getChannelRef($affinity_key);
    $channel_ref->activeStreamRefIncr();
    return $channel_ref;
  }

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
    var_dump($opts);
    $this->affinity_by_method = $GLOBALS['affinity_by_method'];
    $this->affinity_key_to_channel_ref = array();
    $this->channel_refs = array();
  }

  public function _bind($channel_ref, $affinity_key)
  {
    if (!array_key_exists($affinity_key, $this->affinity_key_to_channel_ref)) {
      $this->affinity_key_to_channel_ref[$affinity_key] = $channel_ref;
      $channel_ref->affinityRefIncr();
      print_r($this->affinity_key_to_channel_ref);
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
  // Parse affinity protobuf object
  foreach ($conf->getApi() as $tmp) {
    $methods = $tmp->getMethod();
    foreach($methods as $method){
      echo $method->getAffinity()->getCommand()."\n";
    }
  }
  var_dump($conf->serializeToJsonString());
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
  apcu_add('gcp_channel', $channel);
  $channel_interceptor = new \Grpc\GCPCallInterceptor();
  $gcp_channel = \Grpc\Interceptor::intercept($channel, $channel_interceptor);
  return $gcp_channel;
}
