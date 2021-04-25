<?php

namespace Grpc\GCP;

class GCPCallInvoker implements \Grpc\CallInvoker
{
  private $channel;
  private $affinity_conf;

  public function __construct($affinity_conf) {
    $this->affinity_conf = $affinity_conf;
  }

  public function channelFactory($hostname, $opts)
  {
    if($this->channel) {
      // call_invoker object has already created from previews PHP-FPM scripts.
      // Only need to udpate the $opts including the credentials.
      $this->channel->updateOpts($opts);
    } else {
      $opts['affinity_conf'] = $this->affinity_conf;
      $channel = new \Grpc\GCP\GrpcExtensionChannel($hostname, $opts);
      $this->channel = $channel;
    }
    return $this->channel;
  }

  // _getChannel is used for testing only.
  public function _getChannel() {
    return $this->channel;
  }

  public function UnaryCall($channel, $method, $deserialize, $options) {
    return new GCPUnaryCall($channel, $method, $deserialize, $options);
  }
  public function ClientStreamingCall($channel, $method, $deserialize, $options) {
    return new ClientStreamingCall($channel, $method, $deserialize, $options);
  }
  public function ServerStreamingCall($channel, $method, $deserialize, $options) {
    return new GCPServerStreamCall($channel, $method, $deserialize, $options);
  }
  public function BidiStreamingCall($channel, $method, $deserialize, $options) {
    return new BidiStreamingCall($channel, $method, $deserialize, $options);
  }
}

abstract class GcpBaseCall
{
  protected $gcp_channel;
  // It has the Grpc\Channel and related ref_count information for this RPC.
  protected $channel_ref;
  // If this RPC is 'UNBIND', use it instead of the one from response.
  protected $affinity_key;
  // Array of [affinity_key, command]
  protected $_affinity;

  // Information needed to create Grpc\Call object when the RPC starts.
  protected $method;
  protected $argument;
  protected $metadata;
  protected $options;

  // Get all information needed to create a Call object and start the Call.
  public function __construct($channel, $method, $deserialize, $options) {
    $this->gcp_channel = $channel;
    $this->method = $method;
    $this->deserialize = $deserialize;
    $this->options = $options;
    $this->_affinity = null;

    if (isset($this->gcp_channel->affinity_conf['affinity_by_method'][$method])) {
      $this->_affinity = $this->gcp_channel->affinity_conf['affinity_by_method'][$method];
    }
  }

  protected function _rpcPreProcess($argument) {
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

  protected function _rpcPostProcess($status, $response) {
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
    }
    $this->channel_ref->activeStreamRefDecr();
  }

  protected function getAffinityKeyFromProto($proto) {
    if($this->_affinity) {
      $names = $this->_affinity['affinityKey'];
      // TODO(ddyihai): names.split('.') because one RPC can have multiple affinityKey
      $getAttrMethod = 'get'.ucfirst($names);
      $affinity_key = call_user_func_array(array($proto, $getAttrMethod), array());
      return $affinity_key;
    }
  }
}

class GCPUnaryCall extends GcpBaseCall
{
  private function createRealCall($channel) {
    $this->real_call = new \Grpc\UnaryCall($channel, $this->method, $this->deserialize, $this->options);
    return $this->real_call;
  }

  // Public funtions are rewriting all methods inside UnaryCall
  public function start($argument, $metadata, $options) {
    $channel_ref = $this->_rpcPreProcess($argument);
    $real_channel = $channel_ref->getRealChannel($this->gcp_channel->credentials);
    $this->real_call = $this->createRealCall($real_channel);
    $this->real_call->start($argument, $metadata, $options);
  }

  public function wait() {
    list($response, $status) = $this->real_call->wait();
    $this->_rpcPostProcess($status, $response);
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
    $this->real_call = new \Grpc\ServerStreamingCall($channel, $this->method, $this->deserialize, $this->options);
    return $this->real_call;
  }

  public function start($argument, $metadata, $options) {
    $channel_ref = $this->_rpcPreProcess($argument);
    $this->real_call = $this->createRealCall($channel_ref->getRealChannel(
      $this->gcp_channel->credentials));
    $this->real_call->start($argument, $metadata, $options);
  }

  public function responses() {
    $response = $this->real_call->responses();
    // Since the last response is empty for the server streaming RPC,
    // the second last one is the last RPC response with payload.
    // Use this one for searching the affinity key.
    // The same as BidiStreaming.
    if ($response) {
      $this->response = $response;
    }
    return $response;
  }

  public function getStatus() {
    $status = $this->real_call->getStatus();
    $this->_rpcPostProcess($status, $this->response);
    return $status;
  }
}


class GCPClientStreamCall extends GcpBaseCall
{
  private $response = null;

  private function createRealCall($channel) {
    $this->real_call = new \Grpc\ClientStreamingCall($channel, $this->method, $this->deserialize, $this->options);
    return $this->real_call;
  }

  public function start(array $metadata = []) {
    $this->metadata = $metadata;
  }

  public function write($data, array $options = []) {

  }

  public function responses() {
    $response = $this->real_call->responses();
    // Since the last response is empty for the server streaming RPC,
    // the second last one is the last RPC response with payload.
    // Use this one for searching the affinity key.
    // The same as BidiStreaming.
    if ($response) {
      $this->response = $response;
    }
    return $response;
  }

  public function getStatus() {
    $status = $this->real_call->getStatus();
    $this->_rpcPostProcess($status, $this->response);
    return $status;
  }
}
