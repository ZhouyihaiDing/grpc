<?php

namespace Grpc\Internal;

/**
 * Class AbstractCall.
 * When the user pass a self-defined channel instead of Grpc\Channel, we can't
 * create a Grpc\Call by it. Thus we return an empty call contains all auguments
 * needed which allows user to create Call object inside interceptor.
 * For example, inside BaseStub::_GrpcUnaryUnary, $channel, $method, $deserialze,
 * $options are passed with __construct, and $metadata are updated with start().
 * Those 5 parameters can be accessed by the wrapper Call and creating the real grpc call.
 * @package Grpc
 */
class EmptyCall
{
  private $channel;
  private $method;
  private $deserialize;
  private $options;
  private $metadata;
  public function __construct($channel,
                              $method,
                              $deserialize,
                              $options) {
    $this->channel = $channel;
    $this->method = $method;
    $this->deserialize = $deserialize;
    $this->options = $options;
  }
  public function startBatch(array $batch) {
    $this->metadata = $batch[\Grpc\OP_SEND_INITIAL_METADATA];
  }
  public function setCredentials(\Grpc\CallCredentials $creds_obj) {}
  public function getPeer() {}
  public function cancel() {}
  public function _getChannel() {
    return $this->channel;
  }
  public function _getMethod() {
    return $this->method;
  }
  public function _getDeserialize() {
    return $this->deserialize;
  }
  public function _getOptions() {
    return $this->options;
  }
  public function _getMetadata() {
    return $this->metadata;
  }
}
