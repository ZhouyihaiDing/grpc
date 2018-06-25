<?php

namespace Grpc\GCP;

//class GrpcExtensionChannel implements \Grpc\ChannelInterface
class GrpcExtensionChannel
{
  public $max_size;
  public $max_concurrent_streams_low_watermark;
  public $target;
  public $options;
  public $affinity_by_method;
  public $affinity_key_to_channel_ref;
  public $channel_refs;
  public $credentials;
  public $affinity_conf;
  // Version is used for debugging in PHP-FPM mode.
  public $version;
  private $is_closed;

  public function getChannelRefs() {
    return $this->channel_refs;
  }

  public function __construct($hostname = null, $opts = array())
  {
    if($hostname == null || !is_array($opts)) {
      throw new \InvalidArgumentException("Expected hostname is empty");
    }
    $this->version = 0;
    $this->max_size = 10;
    $this->max_concurrent_streams_low_watermark = 100;
    if (isset($opts['affinity_conf'])) {
      if(isset($opts['affinity_conf']['channelPool'])) {
        if ($opts['affinity_conf']['channelPool']['maxSize']) {
          $this->max_size = $opts['affinity_conf']['channelPool']['maxSize'];
        }
        if ($opts['affinity_conf']['channelPool']['maxConcurrentStreamsLowWatermark']) {
          $this->max_concurrent_streams_low_watermark =
            $opts['affinity_conf']['channelPool']['maxConcurrentStreamsLowWatermark'];
        }
      }
      $this->affinity_by_method = $opts['affinity_conf']['affinity_by_method'];
      $this->affinity_conf = $opts['affinity_conf'];
    }
    $this->target = $hostname;
    $this->affinity_key_to_channel_ref = array();
    $this->channel_refs = array();
    $this->updateOpts($opts);
    // Initiate a Grpc\Channel at the beginning in order to keep the same
    // behavior as the Grpc.
    $channel_ref = $this->getChannelRef();
    $channel_ref->getRealChannel($this->credentials);
  }

  public function updateOpts($opts) {
    $this->version += 1;
    if (isset($opts['credentials'])) {
      $this->credentials = $opts['credentials'];
    }
    unset($opts['affinity_conf']);
    unset($opts['credentials']);
    $this->options = $opts;
    $this->is_closed = false;
  }

  public function _bind($channel_ref, $affinity_key)
  {
    if (!array_key_exists($affinity_key, $this->affinity_key_to_channel_ref)) {
      $this->affinity_key_to_channel_ref[$affinity_key] = $channel_ref;
    }
    $channel_ref->affinityRefIncr();
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
    if ($affinity_key) {
      if (array_key_exists($affinity_key, $this->affinity_key_to_channel_ref)) {
        return $this->affinity_key_to_channel_ref[$affinity_key];
      }
      return $this->getChannelRef();
    }
    usort($this->channel_refs, array($this, 'cmp_by_active_stream_ref'));

    if(count($this->channel_refs) > 0 && $this->channel_refs[0]->getActiveStreamRef() <
      $this->max_concurrent_streams_low_watermark) {
      return $this->channel_refs[0];
    }
    $num_channel_refs = count($this->channel_refs);
    if ($num_channel_refs < $this->max_size) {
      // grpc_target_persist_bound stands for how many channels can be persisted for
      // the same target in the C extension. It is possible that the user use the pure
      // gRPC and this GCP extension at the same time, which share the same target. In this case
      // pure gRPC channel may occupy positions in C extension, which deletes some channels created
      // by this GCP extension.
      // If that happens, it won't cause the script failure because we saves all arguments for creating
      // a channel instead of a channel itself. If we watch to fetch a GCP channel already deleted,
      // it will create a new channel. The only cons is the latency of the first RPC will high because
      // it will establish the connection again.
      if (!isset($this->options['grpc_target_persist_bound']) ||
          $this->options['grpc_target_persist_bound'] < $this->max_size) {
        $this->options['grpc_target_persist_bound'] = $this->max_size;
      }
      $cur_opts = array_merge($this->options,
        ['grpc_gcp_channel_id' => $num_channel_refs]);
      $channel_ref = new _ChannelRef($this->target, $num_channel_refs, $cur_opts);
      array_unshift($this->channel_refs, $channel_ref);
    }
    return $this->channel_refs[0];
  }


  public function getConnectivityState($try_to_connect = false) {
    // Since getRealChannel is creating a PHP Channel object. However in gRPC, when a Channel
    // object is closed, we only mark this Object to be invalid. Thus, we need a global variable
    // to mark whether this GCPExtensionChannel is close or not.
    if ($this->is_closed) {
      throw new \RuntimeException("Channel has already been closed");
    }
    $ready = 0;
    $idle = 0;
    $connecting = 0;
    $transient_failure = 0;
    $shutdown = 0;
    foreach ($this->channel_refs as $channel_ref) {
      $state = $channel_ref->getRealChannel($this->credentials)->getConnectivityState($try_to_connect);
      print_r($state);
      switch ($state) {
        case \Grpc\CHANNEL_READY:
          echo "CHANNEL_READY\n";
          $ready += 1;
          break;
        case \Grpc\CHANNEL_FATAL_FAILURE:
          echo "CHANNEL_FATAL_FAILURE\n";
          $shutdown += 1;
          continue;
        case \Grpc\CHANNEL_CONNECTING:
          echo "CHANNEL_CONNECTING\n";
          $connecting += 1;
          continue;
        case \Grpc\CHANNEL_TRANSIENT_FAILURE:
          echo "CHANNEL_TRANSIENT_FAILURE\n";
          $transient_failure += 1;
          continue;
        case \Grpc\CHANNEL_IDLE:
          echo "CHANNEL_IDLE\n";
          $idle += 1;
          continue;
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

  public function watchConnectivityState($last_state, $deadline_obj = null) {
    if($deadline_obj == null || !is_a($deadline_obj, '\Grpc\Timeval')) {
      throw new \InvalidArgumentException("");
    }
    // Since getRealChannel is creating a PHP Channel object. However in gRPC, when a Channel
    // object is closed, we only mark this Object to be invalid. Thus, we need a global variable
    // to mark whether this GCPExtensionChannel is close or not.
    if ($this->is_closed) {
      throw new \RuntimeException("Channel has already been closed");
    }
    $state = 0;
    foreach ($this->channel_refs as $channel_ref) {
      $state = $channel_ref->getRealChannel($this->credentials)->watchConnectivityState($last_state, $deadline_obj);
    }
    return $state;
  }

  public function getTarget() {
    return $this->target;
  }

  public function close() {
    foreach ($this->channel_refs as $channel_ref) {
      $channel_ref->getRealChannel($this->credentials)->close();
    }
    $this->is_closed = true;
  }
}
