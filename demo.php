<?php

// php -d extension=grpc.so -d extension=protobuf.so demo.php
require_once(dirname(__FILE__).'/src/php/lib/Grpc/BaseStub.php');

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
class InterceptorClient extends Grpc\BaseStub
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
    return $this->_clientStreamRequest('/dummy_method', [], $metadata, $options);
  }
}


$string = file_get_contents("spanner.grpc.config");
$spanner_config = json_decode($string, true);
var_dump($spanner_config);
// Enable the gcp support
// TODO: read file to protobuf object.
// I still in favor of reading from json directly. Otherwise I
// have to convert protobuf object into this array again,
// because grpc extension doesn't have the class information about the protobuf.
// Can't parse it in the C level.
enable_grpc_gcp($spanner_config);

$opts = ['credentials' => Grpc\ChannelCredentials::createInsecure(),
  '_config' => $spanner_config];
