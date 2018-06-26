<?php

namespace Grpc_gcp;

class GCPCallInterceptor extends \Grpc\Interceptor
{

  private $hostname_override;
  private $update_metadata;
  private $gcp_channel;
  private $hostname;

  public function __construct($gcp_channel, $opts) {
    $this->gcp_channel = $gcp_channel;
    $this->hostname = $gcp_channel->getTarget();
    $this->update_metadata = $opts['update_metadata'];
    $this->hostname_override = $opts['grpc.ssl_target_name_override'];
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

    if ($this->hostname_override) {
      $hostname = $this->hostname_override;
    } else {
      $hostname = $this->hostname;
    }
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

  public function interceptUnaryUnary($method,
                                      $argument,
                                      $deserialize,
                                      array $metadata = [],
                                      array $options = [],
                                      $continuation)
  {
    $call = new GCPUnaryCall($this->gcp_channel, $method, $deserialize, $options);
    $jwt_aud_uri = $this->_get_jwt_aud_uri($method);
    if (is_callable($this->update_metadata)) {
      $metadata = call_user_func(
        $this->update_metadata,
        $metadata,
        $jwt_aud_uri
      );
    }
    $metadata = $this->_validate_and_normalize_metadata(
      $metadata
    );
    var_dump($metadata);
    $call->start($argument, $metadata, $options);
    return $call;
  }

  public function interceptUnaryStream($method,
                                       $argument,
                                       $deserialize,
                                       array $metadata = [],
                                       array $options = [],
                                       $continuation
  ) {
    $call = new GCPServerStreamCall($this->gcp_channel, $method, $deserialize, $options);
    $jwt_aud_uri = $this->_get_jwt_aud_uri($method);
    if (is_callable($this->update_metadata)) {
      $metadata = call_user_func(
        $this->update_metadata,
        $metadata,
        $jwt_aud_uri
      );
    }
    $metadata = $this->_validate_and_normalize_metadata(
      $metadata
    );
    var_dump($metadata);
    $call->start($argument, $metadata, $options);
    return $call;
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
    echo "create GcpBaseCall\n";
    $this->gcp_channel = $channel;
    $this->method = $method;
    $this->deserialize = $deserialize;
    $this->options = $options;
    $this->_affinity = null;
    if (isset($this->gcp_channel->global_conf['affinity_by_method'][$method])) {
      $this->_affinity = $this->gcp_channel->global_conf['affinity_by_method'][$method];
    }
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
      echo "[getAffinityKeyFromProto] $affinity_key\n";
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
    $channel_ref = $this->rpcPreProcess($argument);
    $real_channel = $channel_ref->getRealChannel($this->gcp_channel->credentials);
    $this->real_call = $this->createRealCall($real_channel);
    $this->real_call->start($argument, $metadata, $options);
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
    $this->real_call = new \Grpc\ServerStreamingCall($channel, $this->method, $this->deserialize, $this->options);
    return $this->real_call;
  }

  public function start($argument, $metadata, $options) {
    $channel_ref = $this->rpcPreProcess($argument);
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
    $this->rpcPostProcess($status, $this->response);
    return $status;
  }
}

