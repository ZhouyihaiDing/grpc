<?php

require_once(dirname(__FILE__).'Channel.php');

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
  public function StreamCall(
    $metadata = [],
    $options = []
  ) {
    return $this->_clientStreamRequest('/dummy_method', [], $metadata, $options);
  }
}


// Reading config file will be changed to use protobuf later.
// Currently it is parsing from a json file.
$string = file_get_contents("spanner.grpc.config");
$extension_config = json_decode($string, true);
var_dump($extension_config);

$opts = ['credentials' => Grpc\ChannelCredentials::createInsecure(),
  '_config' => $extension_config];

$extension_channel = new \Grpc\Extensions\ExtensionChannel(
  $extension_config['target'][0],
  $opts);

$client = new SimpleClient("", [], $extension_channel);

